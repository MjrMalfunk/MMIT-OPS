<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mail.php';

function ops_users_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function ops_user_invite_table_ready(): bool
{
    try {
        $stmt = db()->query("SHOW TABLES LIKE 'ops_user_invite'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ops_user_invite_token(): string
{
    return bin2hex(random_bytes(32));
}

function ops_user_invite_url(string $token): string
{
    return rtrim((string) BASE_URL, '/')
        . '/ops-access/accept.php?token='
        . rawurlencode($token);
}

function ops_user_invite_send(array $invite, string $token): array
{
    $email = ops_users_normalize_email((string) ($invite['email'] ?? ''));
    $name = trim((string) ($invite['display_name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'The invitation email address is invalid.'];
    }

    if (
        ops_is_staging_env()
        && ops_mail_sandbox_enabled()
        && !ops_mail_sandbox_allows_recipient($email)
    ) {
        return [
            'ok' => false,
            'error' => 'This staging recipient is not on the real-mail allowlist.',
        ];
    }

    $url = ops_user_invite_url($token);
    $message = 'Hello' . ($name !== '' ? ' ' . $name : '') . ",\n\n";
    $message .= "You have been invited to the Midwest Managed IT OPS testing environment.\n\n";
    $message .= "Open this one-time link to create your password:\n{$url}\n\n";
    $message .= "After creating your password, you must configure an authenticator app and save your recovery codes. You may then register a passkey for passwordless sign-in.\n\n";
    $message .= "This invitation expires in 24 hours and can be used only once. If you did not expect it, ignore this message.\n\n";
    $message .= "Midwest Managed IT\n";

    return ops_mail_send([
        'sender_channel' => 'noreply',
        'to' => $email,
        'subject' => 'Your MMIT OPS testing invitation',
        'text_body' => $message,
    ]);
}

function ops_user_invite_create(
    string $email,
    string $displayName,
    int $createdBy
): array {
    if (!ops_user_invite_table_ready()) {
        return ['ok' => false, 'error' => 'The OPS invitation table is not installed.'];
    }

    $email = ops_users_normalize_email($email);
    $displayName = trim($displayName);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    if ($displayName === '' || mb_strlen($displayName) > 150) {
        return ['ok' => false, 'error' => 'Enter a display name up to 150 characters.'];
    }

    if ($createdBy <= 0) {
        return ['ok' => false, 'error' => 'The inviting operator could not be identified.'];
    }

    $existing = db()->prepare(
        'SELECT user_id, user_type FROM portal_user WHERE email = ? LIMIT 1'
    );
    $existing->execute([$email]);
    if ($existing->fetch()) {
        return ['ok' => false, 'error' => 'An account already exists for that email address.'];
    }

    $token = ops_user_invite_token();
    $tokenHash = hash('sha256', $token);
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $revoke = $pdo->prepare(
            'UPDATE ops_user_invite
             SET revoked_at = NOW()
             WHERE email = ?
               AND accepted_at IS NULL
               AND revoked_at IS NULL'
        );
        $revoke->execute([$email]);

        $insert = $pdo->prepare(
            'INSERT INTO ops_user_invite
                (email, display_name, token_hash, expires_at, created_by)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR), ?)'
        );
        $insert->execute([$email, $displayName, $tokenHash, $createdBy]);
        $inviteId = (int) $pdo->lastInsertId();

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('ops_user_invite_create failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The OPS invitation could not be created.'];
    }

    $invite = [
        'invite_id' => $inviteId,
        'email' => $email,
        'display_name' => $displayName,
    ];
    $send = ops_user_invite_send($invite, $token);

    if (empty($send['ok'])) {
        db()->prepare(
            'UPDATE ops_user_invite SET revoked_at = NOW() WHERE invite_id = ?'
        )->execute([$inviteId]);

        audit_event($createdBy, 'OPS_USER_INVITE_SEND_FAILED', [
            'invite_id' => $inviteId,
            'email' => $email,
        ]);

        return [
            'ok' => false,
            'error' => (string) ($send['error'] ?? 'The invitation email could not be sent.'),
        ];
    }

    db()->prepare(
        'UPDATE ops_user_invite SET last_sent_at = NOW() WHERE invite_id = ?'
    )->execute([$inviteId]);

    audit_event($createdBy, 'OPS_USER_INVITE_SENT', [
        'invite_id' => $inviteId,
        'email' => $email,
    ]);

    return [
        'ok' => true,
        'invite_id' => $inviteId,
        'transport' => (string) ($send['transport'] ?? 'mail'),
    ];
}

function ops_user_invite_resend(int $inviteId, int $actorId): array
{
    $stmt = db()->prepare(
        'SELECT invite_id, email, display_name
         FROM ops_user_invite
         WHERE invite_id = ?
           AND accepted_at IS NULL
           AND revoked_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$inviteId]);
    $invite = $stmt->fetch();

    if (!$invite) {
        return ['ok' => false, 'error' => 'That pending invitation is unavailable.'];
    }

    $token = ops_user_invite_token();
    $tokenHash = hash('sha256', $token);

    db()->prepare(
        'UPDATE ops_user_invite
         SET token_hash = ?,
             expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR)
         WHERE invite_id = ?'
    )->execute([$tokenHash, $inviteId]);

    $send = ops_user_invite_send($invite, $token);
    if (empty($send['ok'])) {
        audit_event($actorId, 'OPS_USER_INVITE_RESEND_FAILED', [
            'invite_id' => $inviteId,
            'email' => (string) $invite['email'],
        ]);

        return [
            'ok' => false,
            'error' => (string) ($send['error'] ?? 'The invitation email could not be resent.'),
        ];
    }

    db()->prepare(
        'UPDATE ops_user_invite SET last_sent_at = NOW() WHERE invite_id = ?'
    )->execute([$inviteId]);

    audit_event($actorId, 'OPS_USER_INVITE_RESENT', [
        'invite_id' => $inviteId,
        'email' => (string) $invite['email'],
    ]);

    return ['ok' => true];
}

function ops_user_invite_revoke(int $inviteId, int $actorId): array
{
    $stmt = db()->prepare(
        'UPDATE ops_user_invite
         SET revoked_at = NOW()
         WHERE invite_id = ?
           AND accepted_at IS NULL
           AND revoked_at IS NULL'
    );
    $stmt->execute([$inviteId]);

    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'That invitation is no longer pending.'];
    }

    audit_event($actorId, 'OPS_USER_INVITE_REVOKED', [
        'invite_id' => $inviteId,
    ]);

    return ['ok' => true];
}

function ops_user_invite_find_by_token(string $token, bool $forUpdate = false): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $sql = 'SELECT invite_id, email, display_name, expires_at
            FROM ops_user_invite
            WHERE token_hash = ?
              AND accepted_at IS NULL
              AND revoked_at IS NULL
              AND expires_at > NOW()
            LIMIT 1';

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = db()->prepare($sql);
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function ops_users_list_internal(): array
{
    $stmt = db()->query(
        "SELECT
             u.user_id,
             u.email,
             u.display_name,
             u.is_active,
             u.created_at,
             u.last_login_at,
             COALESCE(m.totp_enabled, 0) AS totp_enabled,
             EXISTS(
                 SELECT 1
                 FROM webauthn_credential w
                 WHERE w.user_id = u.user_id
             ) AS passkey_enabled
         FROM portal_user u
         LEFT JOIN portal_user_mfa m ON m.user_id = u.user_id
         WHERE u.user_type = 'INTERNAL'
         ORDER BY u.is_active DESC, u.display_name, u.email"
    );

    return $stmt->fetchAll() ?: [];
}

function ops_user_invites_list(): array
{
    $stmt = db()->query(
        'SELECT
             i.invite_id,
             i.email,
             i.display_name,
             i.expires_at,
             i.accepted_at,
             i.revoked_at,
             i.created_at,
             i.last_sent_at,
             u.display_name AS created_by_name,
             u.email AS created_by_email
         FROM ops_user_invite i
         LEFT JOIN portal_user u ON u.user_id = i.created_by
         ORDER BY i.invite_id DESC
         LIMIT 50'
    );

    return $stmt->fetchAll() ?: [];
}

function ops_user_set_active(int $userId, bool $active, int $actorId): array
{
    if ($userId <= 0 || $actorId <= 0) {
        return ['ok' => false, 'error' => 'The user could not be identified.'];
    }

    if ($userId === $actorId && !$active) {
        return ['ok' => false, 'error' => 'You cannot disable your own OPS account.'];
    }

    $stmt = db()->prepare(
        "UPDATE portal_user
         SET is_active = ?
         WHERE user_id = ?
           AND user_type = 'INTERNAL'"
    );
    $stmt->execute([$active ? 1 : 0, $userId]);

    if ($stmt->rowCount() !== 1) {
        return ['ok' => false, 'error' => 'The internal user was not changed.'];
    }

    audit_event($actorId, $active ? 'OPS_USER_ENABLED' : 'OPS_USER_DISABLED', [
        'target_user_id' => $userId,
    ]);

    return ['ok' => true];
}
