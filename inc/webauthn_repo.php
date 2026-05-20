<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Base64url helpers (RFC 4648 §5)
 */
function b64u_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64u_decode(string $b64u): string {
    $pad = strlen($b64u) % 4;
    if ($pad) $b64u .= str_repeat('=', 4 - $pad);
    $out = base64_decode(strtr($b64u, '-_', '+/'), true);
    return $out === false ? '' : $out;
}

/**
 * Relying Party config derived from BASE_URL.
 */
function webauthn_rp_id(): string {
    $host = (string)parse_url(BASE_URL, PHP_URL_HOST);
    if ($host === '') {
        throw new RuntimeException('BASE_URL has no host; cannot determine rpId.');
    }
    return $host;
}
function webauthn_origin(): string {
    $scheme = (string)parse_url(BASE_URL, PHP_URL_SCHEME);
    $host   = (string)parse_url(BASE_URL, PHP_URL_HOST);
    $port   = parse_url(BASE_URL, PHP_URL_PORT);

    if ($scheme === '' || $host === '') {
        throw new RuntimeException('BASE_URL missing scheme/host; cannot determine origin.');
    }

    $origin = $scheme . '://' . $host;
    if ($port && !in_array((int)$port, [80, 443], true)) {
        $origin .= ':' . (int)$port;
    }
    return $origin;
}

/**
 * Look up user by email (same normalization as auth.php).
 */
function webauthn_find_user_by_email(?string $email): ?array {
    if (!$email) return null;
    $email = strtolower(trim($email));
    if ($email === '') return null;

    $stmt = db()->prepare(
        'SELECT user_id, email, display_name, user_type, is_active
         FROM portal_user WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u || (int)$u['is_active'] !== 1) return null;

    return $u;
}

/**
 * Fetch all credential ids for a user (binary) and return as base64url for the browser.
 * Used for assertion allowCredentials (login).
 */
function webauthn_allow_credentials_for_user(int $userId): array {
    $stmt = db()->prepare(
        'SELECT credential_id, transports FROM webauthn_credential WHERE user_id = ?'
    );
    $stmt->execute([$userId]);

    $out = [];
    while ($r = $stmt->fetch()) {
        $bin = (string)$r['credential_id'];
        if ($bin === '') continue;

        $item = [
            'type' => 'public-key',
            'id'   => b64u_encode($bin),
        ];

        // Optional, if you store transports as JSON text.
        if (!empty($r['transports'])) {
            $t = json_decode((string)$r['transports'], true);
            if (is_array($t) && $t) $item['transports'] = $t;
        }

        $out[] = $item;
    }
    return $out;
}

/**
 * List credentials for account-management UI.
 */
function webauthn_list_credentials_for_user(int $userId): array {
    $stmt = db()->prepare(
        'SELECT credential_id, label, created_at, last_used_at
           FROM webauthn_credential
          WHERE user_id = ?
          ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);

    $rows = [];
    while ($r = $stmt->fetch()) {
        $rows[] = [
            'credential_id_bin' => (string)$r['credential_id'],
            'credential_id'     => b64u_encode((string)$r['credential_id']),
            'label'             => (string)($r['label'] ?? ''),
            'created_at'        => (string)($r['created_at'] ?? ''),
            'last_used_at'      => (string)($r['last_used_at'] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Delete a credential (scoped to a user).
 */
function webauthn_delete_credential_for_user(int $userId, string $credentialIdB64u): bool {
    $bin = b64u_decode($credentialIdB64u);
    if ($bin === '') return false;

    $stmt = db()->prepare(
        'DELETE FROM webauthn_credential WHERE user_id = ? AND credential_id = ?'
    );
    $stmt->execute([$userId, $bin]);
    return $stmt->rowCount() > 0;
}

/**
 * Find one credential row by credential_id (binary).
 */
function webauthn_find_credential_row(string $credentialIdBin): ?array {
    $stmt = db()->prepare(
        'SELECT credential_id, user_id, public_key, sign_count
           FROM webauthn_credential
          WHERE credential_id = ? LIMIT 1'
    );
    $stmt->execute([$credentialIdBin]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/**
 * Credential existence check (useful for "excludeCredentials" or UI).
 */
function webauthn_credential_exists_for_user(int $userId, string $credentialIdBin): bool {
    $stmt = db()->prepare(
        'SELECT 1 FROM webauthn_credential WHERE user_id = ? AND credential_id = ? LIMIT 1'
    );
    $stmt->execute([$userId, $credentialIdBin]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Insert/Update credential (registration verify step).
 * Assumes credential_id is binary and unique.
 */
function webauthn_upsert_credential(
    int $userId,
    string $credentialIdBin,
    string $publicKeyJson,
    int $signCount = 0,
    ?string $aaguid = null,
    ?string $label = null,
    ?string $transportsJson = null
): void {
    // Label sanity
    if ($label !== null) {
        $label = trim($label);
        if ($label === '') $label = null;
        if ($label !== null && strlen($label) > 80) $label = substr($label, 0, 80);
    }

    db()->prepare(
        "INSERT INTO webauthn_credential
            (credential_id, user_id, public_key, sign_count, transports, aaguid, label)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            public_key = VALUES(public_key),
            sign_count = VALUES(sign_count),
            transports = VALUES(transports),
            aaguid     = VALUES(aaguid),
            label      = VALUES(label)"
    )->execute([
        $credentialIdBin,
        $userId,
        $publicKeyJson,
        $signCount,
        $transportsJson,
        $aaguid,
        $label
    ]);
}

function webauthn_touch_credential(string $credentialIdBin): void {
    try {
        db()->prepare(
            'UPDATE webauthn_credential
                SET last_used_at = NOW(),
                    last_used_ip = INET6_ATON(?)
              WHERE credential_id = ?'
        )->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $credentialIdBin]);
    } catch (Throwable $e) {
        // best effort
    }
}

/**
 * Check if this user requires TOTP MFA after successful primary auth.
 */
function webauthn_user_requires_mfa(int $userId, string $loginMethod = 'password'): bool {
    // Passkey is phishing-resistant; treat as strong auth.
    if ($loginMethod === 'passkey') {
        return false;
    }

    $m = db()->prepare(
        'SELECT totp_enabled, totp_secret FROM portal_user_mfa WHERE user_id = ? LIMIT 1'
    );
    $m->execute([$userId]);
    $row = $m->fetch();
    return (bool)($row && (int)$row['totp_enabled'] === 1 && !empty($row['totp_secret']));
}
