<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/auth.php';


function portal_access_rbac_definitions(): array
{
    return [
        'permissions' => [
            'PORTAL_DASHBOARD_VIEW' => 'View portal dashboard',
            'PORTAL_ASSETS_VIEW' => 'View portal assets',
            'PORTAL_SUPPORT_VIEW' => 'View portal support',
            'PORTAL_BILLING_VIEW' => 'View portal billing',
        ],
        'roles' => [
            'CLIENT_ADMIN' => [
                'name' => 'Client Admin',
                'description' => 'Full client portal access across dashboard, assets, support, and billing.',
                'permissions' => ['PORTAL_DASHBOARD_VIEW', 'PORTAL_ASSETS_VIEW', 'PORTAL_SUPPORT_VIEW', 'PORTAL_BILLING_VIEW'],
            ],
            'CLIENT_MANAGER' => [
                'name' => 'Manager',
                'description' => 'Operational visibility without billing access.',
                'permissions' => ['PORTAL_DASHBOARD_VIEW', 'PORTAL_ASSETS_VIEW', 'PORTAL_SUPPORT_VIEW'],
            ],
            'CLIENT_BILLING' => [
                'name' => 'Billing',
                'description' => 'Billing-only portal access for invoices, balances, and payment history.',
                'permissions' => ['PORTAL_BILLING_VIEW'],
            ],
            'CLIENT_READ_ONLY' => [
                'name' => 'Read Only',
                'description' => 'Read-only operational visibility for dashboard and assets.',
                'permissions' => ['PORTAL_DASHBOARD_VIEW', 'PORTAL_ASSETS_VIEW'],
            ],
        ],
    ];
}

function portal_access_rbac_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!db_table_exists('portal_access_invite') || !db_table_exists('role') || !db_table_exists('permission') || !db_table_exists('role_permission')) {
        $done = true;
        return;
    }

    if (!db_column_exists('portal_access_invite', 'role_id')) {
        try {
            db()->exec('ALTER TABLE portal_access_invite ADD COLUMN role_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER default_service');
        } catch (Throwable $e) {
            error_log('portal_access_rbac_ensure add role_id failed: ' . $e->getMessage());
        }
    }
    try {
        db()->exec('ALTER TABLE portal_access_invite ADD INDEX idx_portal_access_invite_role (role_id)');
    } catch (Throwable $e) {
        // ignore duplicate index errors
    }

    $defs = portal_access_rbac_definitions();
    $pdo = db();

    $permIds = [];
    foreach ($defs['permissions'] as $permCode => $permName) {
        $st = $pdo->prepare('SELECT perm_id FROM permission WHERE perm_code = ? LIMIT 1');
        $st->execute([$permCode]);
        $permId = (int) ($st->fetchColumn() ?: 0);
        if ($permId <= 0) {
            $ins = $pdo->prepare('INSERT INTO permission (perm_code, perm_name) VALUES (?, ?)');
            $ins->execute([$permCode, $permName]);
            $permId = (int) $pdo->lastInsertId();
        }
        $permIds[$permCode] = $permId;
    }

    foreach ($defs['roles'] as $roleKey => $roleDef) {
        $roleName = (string) ($roleDef['name'] ?? $roleKey);
        $roleDesc = (string) ($roleDef['description'] ?? '');
        $st = $pdo->prepare('SELECT role_id FROM role WHERE name = ? LIMIT 1');
        $st->execute([$roleName]);
        $roleId = (int) ($st->fetchColumn() ?: 0);
        if ($roleId <= 0) {
            $ins = $pdo->prepare('INSERT INTO role (name, description, is_system) VALUES (?, ?, 1)');
            $ins->execute([$roleName, $roleDesc !== '' ? $roleDesc : null]);
            $roleId = (int) $pdo->lastInsertId();
        } else {
            $upd = $pdo->prepare('UPDATE role SET description = ? WHERE role_id = ?');
            $upd->execute([$roleDesc !== '' ? $roleDesc : null, $roleId]);
        }

        $pdo->prepare('DELETE FROM role_permission WHERE role_id = ?')->execute([$roleId]);
        foreach ((array) ($roleDef['permissions'] ?? []) as $permCode) {
            $permId = (int) ($permIds[$permCode] ?? 0);
            if ($permId > 0) {
                $pdo->prepare('INSERT INTO role_permission (role_id, perm_id) VALUES (?, ?)')->execute([$roleId, $permId]);
            }
        }
    }

    $done = true;
}

function portal_access_role_options(): array
{
    portal_access_rbac_ensure();
    $defs = portal_access_rbac_definitions();
    $options = [];
    foreach ($defs['roles'] as $roleKey => $roleDef) {
        $st = db()->prepare('SELECT role_id, name, description FROM role WHERE name = ? LIMIT 1');
        $st->execute([(string) $roleDef['name']]);
        $row = $st->fetch();
        if (!$row) {
            continue;
        }
        $options[] = [
            'key' => $roleKey,
            'role_id' => (int) ($row['role_id'] ?? 0),
            'name' => (string) ($row['name'] ?? $roleDef['name']),
            'description' => (string) ($row['description'] ?? $roleDef['description']),
            'permissions' => (array) ($roleDef['permissions'] ?? []),
        ];
    }
    return $options;
}

function portal_access_default_role_id(): int
{
    foreach (portal_access_role_options() as $role) {
        if (($role['key'] ?? '') === 'CLIENT_ADMIN') {
            return (int) ($role['role_id'] ?? 0);
        }
    }
    return 0;
}

function portal_access_resolve_role_id(?int $roleId): int
{
    $roleId = (int) $roleId;
    foreach (portal_access_role_options() as $role) {
        if ((int) ($role['role_id'] ?? 0) === $roleId) {
            return $roleId;
        }
    }
    return portal_access_default_role_id();
}

function portal_access_role_label(array $invite): string
{
    portal_access_rbac_ensure();
    $roleId = portal_access_resolve_role_id((int) ($invite['role_id'] ?? 0));
    if ($roleId <= 0) {
        return 'Client Admin';
    }
    $st = db()->prepare('SELECT name FROM role WHERE role_id = ? LIMIT 1');
    $st->execute([$roleId]);
    $name = (string) ($st->fetchColumn() ?: 'Client Admin');
    return $name !== '' ? $name : 'Client Admin';
}


function portal_access_table_ready(): bool
{
    return db_table_exists('portal_access_invite');
}

function portal_access_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function portal_access_safe_service(?string $service, string $default = 'DASHBOARD'): string
{
    $service = strtoupper(trim((string) $service));
    $allowed = ['DASHBOARD', 'WORKSPACE', 'BILLING'];
    return in_array($service, $allowed, true) ? $service : $default;
}

function portal_access_hmac_key(): string
{
    $seed = (string) (defined('APP_ENC_KEY_B64') ? APP_ENC_KEY_B64 : 'mmit-portal-access');
    return hash('sha256', $seed . '|portal-access|v1', true);
}

function portal_access_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function portal_access_base64url_decode(string $encoded): ?string
{
    $encoded = trim($encoded);
    if ($encoded === '') {
        return null;
    }
    $padded = strtr($encoded, '-_', '+/');
    $pad = strlen($padded) % 4;
    if ($pad > 0) {
        $padded .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode($padded, true);
    return $decoded === false ? null : $decoded;
}

function portal_access_create_token(array $inviteRow, string $service, int $ttlSeconds = 1200): string
{
    $service = portal_access_safe_service($service, (string) ($inviteRow['default_service'] ?? 'DASHBOARD'));
    $payload = [
        'purpose' => 'portal_access',
        'invite_id' => (int) ($inviteRow['invite_id'] ?? 0),
        'email' => portal_access_normalize_email((string) ($inviteRow['invite_email'] ?? '')),
        'service' => $service,
        'exp' => time() + max(300, $ttlSeconds),
    ];
    $encodedPayload = portal_access_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $signature = hash_hmac('sha256', $encodedPayload, portal_access_hmac_key(), true);
    return $encodedPayload . '.' . portal_access_base64url_encode($signature);
}

function portal_access_verify_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !str_contains($token, '.')) {
        return null;
    }
    [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
    $payloadJson = portal_access_base64url_decode($encodedPayload);
    $signature = portal_access_base64url_decode($encodedSignature);
    if (!is_string($payloadJson) || !is_string($signature)) {
        return null;
    }

    $expected = hash_hmac('sha256', $encodedPayload, portal_access_hmac_key(), true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    if ((string) ($payload['purpose'] ?? '') !== 'portal_access') {
        return null;
    }

    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp < time()) {
        return null;
    }

    $inviteId = (int) ($payload['invite_id'] ?? 0);
    $email = portal_access_normalize_email((string) ($payload['email'] ?? ''));
    if ($inviteId <= 0 || $email === '') {
        return null;
    }

    $invite = portal_access_get_invite($inviteId);
    if (!$invite || portal_access_normalize_email((string) ($invite['invite_email'] ?? '')) !== $email) {
        return null;
    }
    if (!empty($invite['revoked_at'])) {
        return null;
    }

    return [
        'invite' => $invite,
        'email' => $email,
        'service' => portal_access_safe_service((string) ($payload['service'] ?? ''), (string) ($invite['default_service'] ?? 'DASHBOARD')),
        'expires_at' => $exp,
    ];
}

function portal_access_get_invite(int $inviteId): ?array
{
    if ($inviteId <= 0 || !portal_access_table_ready()) {
        return null;
    }
    portal_access_rbac_ensure();
    $sql = "SELECT pai.*, c.client_code, c.legal_name, c.dba_name, r.name AS role_name, r.description AS role_description
            FROM portal_access_invite pai
            LEFT JOIN clients c ON c.client_id = pai.client_id
            LEFT JOIN role r ON r.role_id = pai.role_id
            WHERE pai.invite_id = ?
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$inviteId]);
    $row = $st->fetch();
    return $row ?: null;
}

function portal_access_active_invites(?int $clientId = null, int $limit = 100): array
{
    if (!portal_access_table_ready()) {
        return [];
    }
    portal_access_rbac_ensure();
    $limit = max(1, min(250, $limit));
    $where = '';
    $params = [];
    if ($clientId !== null && $clientId > 0) {
        $where = 'WHERE pai.client_id = ?';
        $params[] = $clientId;
    }
    $sql = "SELECT pai.*, c.client_code, c.legal_name, c.dba_name,
                   pu.display_name AS created_by_name, pu2.display_name AS revoked_by_name,
                   r.name AS role_name, r.description AS role_description
            FROM portal_access_invite pai
            LEFT JOIN clients c ON c.client_id = pai.client_id
            LEFT JOIN portal_user pu ON pu.user_id = pai.created_by
            LEFT JOIN portal_user pu2 ON pu2.user_id = pai.revoked_by
            LEFT JOIN role r ON r.role_id = pai.role_id
            {$where}
            ORDER BY (pai.revoked_at IS NULL) DESC, pai.created_at DESC, pai.invite_id DESC
            LIMIT {$limit}";
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function portal_access_find_active_invite_by_email(string $email): ?array
{
    if (!portal_access_table_ready()) {
        return null;
    }
    portal_access_rbac_ensure();
    $email = portal_access_normalize_email($email);
    if ($email === '') {
        return null;
    }
    $sql = "SELECT pai.*, c.client_code, c.legal_name, c.dba_name, r.name AS role_name, r.description AS role_description
            FROM portal_access_invite pai
            LEFT JOIN clients c ON c.client_id = pai.client_id
            LEFT JOIN role r ON r.role_id = pai.role_id
            WHERE LOWER(TRIM(pai.invite_email)) = ?
              AND pai.revoked_at IS NULL
            ORDER BY pai.accepted_at DESC, pai.created_at DESC, pai.invite_id DESC
            LIMIT 1";
    $st = db()->prepare($sql);
    $st->execute([$email]);
    $row = $st->fetch();
    return $row ?: null;
}

function portal_access_client_options(): array
{
    if (!db_table_exists('clients')) {
        return [];
    }
    $sql = "SELECT client_id, client_code, legal_name, dba_name
            FROM clients
            ORDER BY COALESCE(NULLIF(dba_name, ''), legal_name) ASC, client_id ASC";
    return db()->query($sql)->fetchAll();
}

function portal_access_client_label(array $row): string
{
    $name = trim((string) ($row['dba_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($row['legal_name'] ?? ''));
    }
    $code = trim((string) ($row['client_code'] ?? ''));
    return $code !== '' ? ($name . ' [' . $code . ']') : $name;
}

function portal_access_create_invite(array $data, int $createdBy): array
{
    if (!portal_access_table_ready()) {
        return ['ok' => false, 'error' => 'Portal access invite table is not installed yet.'];
    }
    portal_access_rbac_ensure();

    $email = portal_access_normalize_email((string) ($data['email'] ?? ''));
    $name = trim((string) ($data['name'] ?? ''));
    $clientId = (int) ($data['client_id'] ?? 0);
    $scope = strtoupper(trim((string) ($data['scope_code'] ?? 'PORTAL')));
    $defaultService = portal_access_safe_service((string) ($data['default_service'] ?? 'DASHBOARD'), 'DASHBOARD');
    $note = trim((string) ($data['note'] ?? ''));
    $roleId = portal_access_resolve_role_id((int) ($data['role_id'] ?? 0));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address for the invite.'];
    }

    if (!in_array($scope, ['PORTAL', 'WORKSPACE', 'BILLING'], true)) {
        $scope = 'PORTAL';
    }

    $sql = "INSERT INTO portal_access_invite
                (client_id, invite_email, invite_name, scope_code, default_service, role_id, note, created_by, last_sent_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    db()->prepare($sql)->execute([
        $clientId > 0 ? $clientId : null,
        $email,
        $name !== '' ? $name : null,
        $scope,
        $defaultService,
        $roleId > 0 ? $roleId : null,
        $note !== '' ? $note : null,
        $createdBy > 0 ? $createdBy : null,
    ]);

    $inviteId = (int) db()->lastInsertId();
    $invite = portal_access_get_invite($inviteId);
    if (!$invite) {
        return ['ok' => false, 'error' => 'Invite record was created but could not be reloaded.'];
    }

    $mail = portal_access_send_invite_email($invite, $defaultService, true);
    if (!$mail['ok']) {
        return ['ok' => false, 'error' => (string) ($mail['error'] ?? 'Invite email could not be sent.'), 'invite_id' => $inviteId];
    }

    audit_event($createdBy > 0 ? $createdBy : null, 'PORTAL_INVITE_CREATED', ['invite_id' => $inviteId, 'email' => $email, 'service' => $defaultService]);
    return ['ok' => true, 'invite' => $invite, 'sent' => true];
}

function portal_access_delete_customer_identity_by_email(string $email): array
{
    $email = portal_access_normalize_email($email);
    if ($email === '') {
        return ['deleted_user' => false, 'deleted_user_id' => 0, 'deleted_security_rows' => 0];
    }

    $user = portal_access_load_user_by_email($email);
    $userId = (int) ($user['user_id'] ?? 0);
    if ($userId <= 0 || strtoupper((string) ($user['user_type'] ?? '')) !== 'CUSTOMER') {
        return ['deleted_user' => false, 'deleted_user_id' => 0, 'deleted_security_rows' => 0];
    }

    $pdo = db();
    $deletedSecurityRows = 0;
    foreach ([
        ['user_role', 'user_id'],
        ['portal_user_mfa', 'user_id'],
        ['mfa_backup_code', 'user_id'],
        ['user_passkey', 'user_id'],
        ['webauthn_credential', 'user_id'],
    ] as [$table, $column]) {
        if (db_table_exists($table) && db_column_exists($table, $column)) {
            $st = $pdo->prepare(sprintf('DELETE FROM `%s` WHERE `%s` = ?', $table, $column));
            $st->execute([$userId]);
            $deletedSecurityRows += $st->rowCount();
        }
    }

    $deletedUser = false;
    if (db_table_exists('portal_user')) {
        $st = $pdo->prepare("DELETE FROM portal_user WHERE user_id = ? AND user_type = 'CUSTOMER'");
        $st->execute([$userId]);
        $deletedUser = $st->rowCount() > 0;
    }

    return ['deleted_user' => $deletedUser, 'deleted_user_id' => $userId, 'deleted_security_rows' => $deletedSecurityRows];
}

function portal_access_delete_invite(int $inviteId, int $deletedBy): array
{
    if (!portal_access_table_ready()) {
        return ['ok' => false, 'error' => 'Portal access invite table is not installed yet.'];
    }

    $invite = portal_access_get_invite($inviteId);
    if (!$invite) {
        return ['ok' => false, 'error' => 'Invite not found.'];
    }

    $email = portal_access_normalize_email((string) ($invite['invite_email'] ?? ''));
    if ($email === '') {
        return ['ok' => false, 'error' => 'Invite email is missing.'];
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $inviteIds = [];
        $st = $pdo->prepare('SELECT invite_id FROM portal_access_invite WHERE LOWER(TRIM(invite_email)) = ? ORDER BY invite_id ASC');
        $st->execute([$email]);
        $inviteIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $deletedInviteRows = 0;
        if ($inviteIds !== []) {
            $del = $pdo->prepare('DELETE FROM portal_access_invite WHERE LOWER(TRIM(invite_email)) = ?');
            $del->execute([$email]);
            $deletedInviteRows = $del->rowCount();
        }

        $identity = portal_access_delete_customer_identity_by_email($email);
        $pdo->commit();

        audit_event($deletedBy > 0 ? $deletedBy : null, 'PORTAL_INVITE_DELETED', [
            'invite_id' => $inviteId,
            'invite_ids' => $inviteIds,
            'email' => $email,
            'deleted_invite_rows' => $deletedInviteRows,
            'deleted_user' => !empty($identity['deleted_user']) ? 1 : 0,
            'deleted_user_id' => (int) ($identity['deleted_user_id'] ?? 0),
        ]);

        return [
            'ok' => true,
            'email' => $email,
            'deleted_invite_rows' => $deletedInviteRows,
            'deleted_user' => !empty($identity['deleted_user']),
            'deleted_user_id' => (int) ($identity['deleted_user_id'] ?? 0),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Delete failed: ' . $e->getMessage()];
    }
}

function portal_access_resend_invite(int $inviteId, string $service = ''): array
{
    if (!portal_access_table_ready()) {
        return ['ok' => false, 'error' => 'Portal access invite table is not installed yet.'];
    }
    $invite = portal_access_get_invite($inviteId);
    if (!$invite) {
        return ['ok' => false, 'error' => 'Invite not found.'];
    }
    if (!empty($invite['revoked_at'])) {
        return ['ok' => false, 'error' => 'That invite has already been revoked.'];
    }
    $service = portal_access_safe_service($service, (string) ($invite['default_service'] ?? 'DASHBOARD'));
    $result = portal_access_send_invite_email($invite, $service, false);
    if (!empty($result['ok'])) {
        audit_event(current_user()['user_id'] ?? null, 'PORTAL_INVITE_RESENT', ['invite_id' => $inviteId, 'email' => (string) ($invite['invite_email'] ?? ''), 'service' => $service]);
    }
    return $result;
}

function portal_access_send_magic_link(string $email, string $service = 'DASHBOARD'): array
{
    $invite = portal_access_find_active_invite_by_email($email);
    if (!$invite) {
        return ['ok' => true, 'sent' => false];
    }
    return portal_access_send_invite_email($invite, $service, false);
}

function portal_access_send_invite_email(array $invite, string $service, bool $isFirstSend): array
{
    $email = portal_access_normalize_email((string) ($invite['invite_email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invite email address is invalid.'];
    }

    $service = portal_access_safe_service($service, (string) ($invite['default_service'] ?? 'DASHBOARD'));
    $token = portal_access_create_token($invite, $service, 1200);
    $accessUrl = rtrim(portal_access_portal_base_url(), '/') . '/?invite=' . rawurlencode($token);

    $clientLabel = trim((string) ($invite['dba_name'] ?? ''));
    if ($clientLabel === '') {
        $clientLabel = trim((string) ($invite['legal_name'] ?? ''));
    }
    if ($clientLabel === '') {
        $clientLabel = 'your Midwest Managed IT workspace';
    }

    $greetingName = trim((string) ($invite['invite_name'] ?? ''));
    $subject = $isFirstSend
        ? 'Your secure Midwest Managed IT portal invitation'
        : 'Your secure Midwest Managed IT access link';

    $message = "Hello" . ($greetingName !== '' ? (' ' . $greetingName) : '') . ",\n\n";
    if ($isFirstSend) {
        $message .= "You have been invited to use the Midwest Managed IT client portal for {$clientLabel}.\n\n";
    } else {
        $message .= "A fresh secure access link was requested for {$clientLabel}.\n\n";
    }
    $message .= "Open this secure link to continue:\n{$accessUrl}\n\n";
    $message .= "When the portal opens, we will send a one-time sign-in code to this same email address.\n\n";
    $message .= "This link expires in 20 minutes. If you did not expect this email, you can ignore it.\n\nMidwest Managed IT\n";

    $send = ops_mail_send([
        'sender_channel' => 'noreply',
        'to' => $email,
        'subject' => $subject,
        'text_body' => $message,
    ]);
    if (empty($send['ok'])) {
        error_log('portal_access_send_invite_email failed for ' . $email . ': ' . (string) ($send['error'] ?? 'unknown mail error'));
        return ['ok' => false, 'error' => 'The secure invite email could not be sent right now.'];
    }

    db()->prepare('UPDATE portal_access_invite SET last_sent_at = NOW() WHERE invite_id = ?')->execute([(int) ($invite['invite_id'] ?? 0)]);
    audit_event(current_user()['user_id'] ?? null, 'PORTAL_INVITE_SENT', ['invite_id' => (int) ($invite['invite_id'] ?? 0), 'email' => $email, 'service' => $service]);
    return ['ok' => true, 'sent' => true, 'transport' => (string) ($send['transport'] ?? 'mail')];
}

function portal_access_session_set(array $invite, string $service): void
{
    $_SESSION['portal_access'] = [
        'invite_id' => (int) ($invite['invite_id'] ?? 0),
        'email' => portal_access_normalize_email((string) ($invite['invite_email'] ?? '')),
        'service' => portal_access_safe_service($service, (string) ($invite['default_service'] ?? 'DASHBOARD')),
        'expires_at' => time() + 14400,
    ];
}

function portal_access_session_clear(): void
{
    unset($_SESSION['portal_access']);
}

function portal_access_session(): ?array
{
    $data = $_SESSION['portal_access'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $expiresAt = (int) ($data['expires_at'] ?? 0);
    $inviteId = (int) ($data['invite_id'] ?? 0);
    $email = portal_access_normalize_email((string) ($data['email'] ?? ''));
    if ($inviteId <= 0 || $email === '' || $expiresAt < time()) {
        portal_access_session_clear();
        return null;
    }
    return $data;
}

function portal_access_mark_accepted(int $inviteId): void
{
    if ($inviteId <= 0 || !portal_access_table_ready()) {
        return;
    }
    db()->prepare('UPDATE portal_access_invite SET accepted_at = COALESCE(accepted_at, NOW()) WHERE invite_id = ?')->execute([$inviteId]);
}

function portal_access_current_invite(): ?array
{
    $session = portal_access_session();
    if (!$session) {
        return null;
    }
    $invite = portal_access_get_invite((int) ($session['invite_id'] ?? 0));
    if (!$invite || !empty($invite['revoked_at'])) {
        portal_access_session_clear();
        return null;
    }
    return $invite;
}

function portal_access_service_copy(string $service): array
{
    $service = portal_access_safe_service($service, 'DASHBOARD');
    $map = [
        'DASHBOARD' => ['label' => 'Portal access', 'headline' => 'Your secure Midwest Managed IT front door'],
        'WORKSPACE' => ['label' => 'Workspace lane', 'headline' => 'Secure handoff to the Syncro workspace'],
        'BILLING' => ['label' => 'Billing lane', 'headline' => 'Secure handoff to invoices and payment visibility'],
    ];
    return $map[$service];
}

function portal_access_security_snapshot(): array
{
    $user = current_user();
    $uid = (int) ($user['user_id'] ?? 0);
    return [
        'auth_method' => (string) ($_SESSION['auth_method'] ?? 'unknown'),
        'totp_enabled' => $uid > 0 ? security_user_has_totp($uid) : false,
        'passkey_enabled' => $uid > 0 ? security_user_has_passkey($uid) : false,
        'stepup_recent' => function_exists('mfa_is_recent') ? mfa_is_recent() : false,
    ];
}



function portal_access_portal_base_url(): string
{
    $configured = '';

    if (defined('CLIENT_PORTAL_BASE_URL')) {
        $configured = trim((string) CLIENT_PORTAL_BASE_URL);
    } elseif (defined('PORTAL_BASE_URL')) {
        $configured = trim((string) PORTAL_BASE_URL);
    }

    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $base = rtrim((string) BASE_URL, '/');
    $host = strtolower((string) parse_url($base, PHP_URL_HOST));
    $scheme = (string) (parse_url($base, PHP_URL_SCHEME) ?: 'https');

    return match ($host) {
        'ops-test.midwestmanagedit.com' => 'https://portal-test.midwestmanagedit.com',
        'ops.midwestmanagedit.com' => 'https://portal.midwestmanagedit.com',
        default => $scheme . '://' . preg_replace('/^ops(-test)?\./', 'portal$1.', $host),
    };
}


function portal_access_safe_next_path(?string $next, string $default = '/client-preview.php'): string
{
    $next = trim((string) $next);
    if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
        return $default;
    }
    $parts = parse_url($next);
    $path = (string) ($parts['path'] ?? '/');
    $allowed = [
        '/',
        '/client-preview.php',
        '/client-assets.php',
        '/client-billing.php',
        '/client-support.php',
        '/client-auth-plan.php',
    ];
    if (!in_array($path, $allowed, true)) {
        return $default;
    }
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $path . $query;
}

function portal_access_target_url(string $service, string $next = ''): string
{
    $service = portal_access_safe_service($service, 'DASHBOARD');
    $base = portal_access_portal_base_url();
    $fallback = match ($service) {
        'WORKSPACE' => '/client-assets.php',
        'BILLING' => '/client-billing.php',
        default => '/client-preview.php',
    };
    $path = portal_access_safe_next_path($next, $fallback);
    return rtrim($base, '/') . $path;
}

function portal_access_append_query(string $url, array $params): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return $url;
    }
    $existing = [];
    if (!empty($parts['query'])) {
        parse_str((string) $parts['query'], $existing);
    }
    foreach ($params as $k => $v) {
        if ($v === null || $v === '') {
            unset($existing[$k]);
        } else {
            $existing[$k] = $v;
        }
    }
    $scheme = isset($parts['scheme']) ? ($parts['scheme'] . '://') : '';
    $host = $parts['host'] ?? '';
    $port = isset($parts['port']) ? (':' . (int) $parts['port']) : '';
    $path = $parts['path'] ?? '';
    $query = $existing ? ('?' . http_build_query($existing)) : '';
    $fragment = isset($parts['fragment']) ? ('#' . $parts['fragment']) : '';
    return $scheme . $host . $port . $path . $query . $fragment;
}

function portal_access_safe_return_url(?string $url, string $default = ''): string
{
    $url = trim((string) $url);
    if ($url === '') {
        return $default;
    }
    if ($url[0] === '/' && !str_starts_with($url, '//')) {
        return portal_access_target_url('DASHBOARD', $url);
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return $default;
    }
    $portalHost = (string) parse_url(portal_access_portal_base_url(), PHP_URL_HOST);
    $host = strtolower((string) $parts['host']);
    if ($portalHost === '' || $host != strtolower($portalHost)) {
        return $default;
    }
    return $url;
}

function portal_access_load_user_by_id(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    $st = db()->prepare('SELECT user_id, email, display_name, user_type, is_active FROM portal_user WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    return ($row && (int) ($row['is_active'] ?? 0) === 1) ? $row : null;
}

function portal_access_load_user_by_email(string $email): ?array
{
    $email = portal_access_normalize_email($email);
    if ($email === '') {
        return null;
    }
    $st = db()->prepare('SELECT user_id, email, display_name, user_type, is_active FROM portal_user WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $row = $st->fetch();
    return ($row && (int) ($row['is_active'] ?? 0) === 1) ? $row : null;
}

function portal_access_user_has_totp(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $st = db()->prepare('SELECT totp_enabled FROM portal_user_mfa WHERE user_id = ? LIMIT 1');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row && (int) ($row['totp_enabled'] ?? 0) === 1;
}

function portal_access_user_has_passkey(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    return security_user_has_passkey($userId);
}

function portal_access_user_security_ready(int $userId): bool
{
    return portal_access_user_has_totp($userId) && portal_access_user_has_passkey($userId);
}

function portal_access_login_user(array $user, string $via = 'email_link'): void
{
    session_regenerate_safe();
    $_SESSION['user'] = [
        'user_id' => (int) ($user['user_id'] ?? 0),
        'email' => (string) ($user['email'] ?? ''),
        'display_name' => (string) ($user['display_name'] ?? ''),
        'user_type' => (string) ($user['user_type'] ?? 'CUSTOMER'),
    ];
    $_SESSION['auth_method'] = $via;
    $_SESSION['client_security_ready'] = portal_access_user_security_ready((int) ($user['user_id'] ?? 0));
    $_SESSION['__regen_at'] = time();

    try {
        db()->prepare('UPDATE portal_user SET last_login_at = NOW(), last_login_ip = INET6_ATON(?) WHERE user_id = ?')
            ->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), (int) ($user['user_id'] ?? 0)]);
    } catch (Throwable $e) {
        // ignore
    }
}

function portal_access_logout_customer_session(): void
{
    unset(
        $_SESSION['user'],
        $_SESSION['auth_method'],
        $_SESSION['client_security_ready'],
        $_SESSION['portal_access_pending_verify'],
        $_SESSION['portal_access'],
        $_SESSION['mfa_pending'],
        $_SESSION['mfa_pending_user_id']
    );
}

function portal_access_pending_verify_set(array $data): void
{
    $_SESSION['portal_access_pending_verify'] = [
        'user_id' => (int) ($data['user_id'] ?? 0),
        'invite_id' => (int) ($data['invite_id'] ?? 0),
        'service' => portal_access_safe_service((string) ($data['service'] ?? 'DASHBOARD'), 'DASHBOARD'),
        'next_url' => portal_access_safe_next_path((string) ($data['next_url'] ?? '/client-preview.php'), '/client-preview.php'),
        'expires_at' => time() + 900,
    ];
}

function portal_access_pending_verify_get(): ?array
{
    $row = $_SESSION['portal_access_pending_verify'] ?? null;
    if (!is_array($row)) {
        return null;
    }
    if ((int) ($row['expires_at'] ?? 0) < time() || (int) ($row['user_id'] ?? 0) <= 0 || (int) ($row['invite_id'] ?? 0) <= 0) {
        unset($_SESSION['portal_access_pending_verify']);
        return null;
    }
    return $row;
}

function portal_access_pending_verify_clear(): void
{
    unset($_SESSION['portal_access_pending_verify']);
}

