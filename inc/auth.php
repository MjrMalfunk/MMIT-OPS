<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';

function session_regenerate_safe(bool $force = false): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    // Shared-hosting reality check:
    // aggressive session ID rotation during login can race the next redirect
    // and drop the authenticated session on some stacks.
    // Default behavior here is to keep the current session stable and simply
    // refresh our internal timestamp. Only force a real ID rotation when a
    // caller explicitly asks for it.
    $_SESSION['__regen_at'] = time();

    if ($force) {
        @session_regenerate_id(false);
        $_SESSION['__regen_at'] = time();
    }
}

function auth_session_commit(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['__last_auth_write'] = time();
        session_write_close();
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function current_user_is_internal(): bool {
    $user = current_user();

    return is_array($user)
        && strtoupper(trim((string)($user['user_type'] ?? ''))) === 'INTERNAL'
        && (int)($user['user_id'] ?? 0) > 0;
}

function require_login(): void {
    if (!current_user()) {
        $next = $_SERVER['REQUEST_URI'] ?? '/dashboard/index.php';
        if (!is_string($next) || $next === '') {
            $next = '/dashboard/index.php';
        }
        if ($next[0] !== '/' || str_starts_with($next, '//')) {
            $next = '/dashboard/index.php';
        }
        header('Location: ' . BASE_URL . '/login.php?next=' . rawurlencode($next));
        exit;
    }

    if (!current_user_is_internal()) {
        http_response_code(403);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'This account is not authorized to access MMIT OPS.';
        exit;
    }
}

function auth_logout(): void {
    $_SESSION = [];
    unset($_SESSION['mfa_recent_at'], $_SESSION['mfa_pending_user_id'], $_SESSION['mfa_pending']);
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            SESSION_NAME,
            '',
            time() - 3600,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            (bool)($params['secure'] ?? false),
            (bool)($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

/**
 * Best-effort security logging. Never blocks auth flow.
 */
function record_login_attempt(string $email, bool $success): void {
    try {
        $stmt = db()->prepare(
            "INSERT INTO auth_login_attempt (email, ip, user_agent, success) VALUES (?, INET6_ATON(?), ?, ?)"
        );
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt->execute([strtolower(trim($email)), $ip, $ua, $success ? 1 : 0]);
    } catch (Throwable $e) {
        error_log('record_login_attempt failed: ' . $e->getMessage());
    }
}

/**
 * Audit events.
 *
 * Supports BOTH call styles:
 *  - audit_event($uid, 'EVENT', $ipString, $metaArray)
 *  - audit_event($uid, 'EVENT', $metaArray)   <-- meta passed as 3rd arg
 */
function audit_event($userId, string $eventType, $ip = null, $meta = null): void {
    try {
        // Allow meta array as 3rd arg (modern calls)
        if (is_array($ip) && $meta === null) {
            $meta = $ip;
            $ip = null;
        }

        $stmt = db()->prepare(
            "INSERT INTO audit_event (user_id, event_type, ip, meta, created_at)
             VALUES (?, ?, INET6_ATON(?), ?, NOW())"
        );

        $uid = ($userId === null) ? null : (int)$userId;

        $ipStr = (is_string($ip) && $ip !== '')
            ? $ip
            : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        $metaJson = $meta === null
            ? null
            : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt->execute([$uid, $eventType, $ipStr, $metaJson]);
    } catch (Throwable $e) {
        error_log('audit_event failed: ' . $e->getMessage());
    }
}

// ----- Step-up (recent MFA) helpers -----

function mark_mfa_recent(): void {
    $_SESSION['mfa_recent_at'] = time();
}

function mfa_is_recent(int $ttlSeconds = 600): bool {
    $t = (int)($_SESSION['mfa_recent_at'] ?? 0);
    return $t > 0 && (time() - $t) <= $ttlSeconds;
}

/**
 * Generate backup codes for a user.
 * Stores hashes only. Returns plaintext codes ONCE.
 */
function mfa_generate_backup_codes(int $userId, int $count = 10, bool $revokeOldUnused = true): array {
    $count = max(1, min(50, $count));
    $codes = [];

    if ($revokeOldUnused) {
        db()->prepare(
            'UPDATE mfa_backup_code SET used_at = NOW()
             WHERE user_id = ? AND used_at IS NULL'
        )->execute([$userId]);
    }

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    $make = function() use ($alphabet): string {
        $raw = '';
        for ($i = 0; $i < 8; $i++) {
            $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    };

    $ins = db()->prepare(
        'INSERT INTO mfa_backup_code (user_id, code_hash, created_at)
         VALUES (?, ?, NOW())'
    );

    for ($i = 0; $i < $count; $i++) {
        $code = $make();
        $norm = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
        $hash = password_hash($norm, PASSWORD_DEFAULT);
        $ins->execute([$userId, $hash]);
        $codes[] = $code;
    }

    audit_event($userId, 'BACKUP_CODES_GENERATED', [
        'count' => $count,
        'revoked_old' => $revokeOldUnused ? 1 : 0
    ]);

    return $codes;
}

/**
 * Require recent MFA for sensitive actions.
 * Redirects to /mfa/verify.php?stepup=1&next=...
 */
function require_recent_mfa(?string $next = null, int $ttlSeconds = 600): void {
    if (mfa_is_recent($ttlSeconds)) {
        return;
    }

    audit_event(
        $_SESSION['user']['user_id'] ?? null,
        'STEPUP_REQUIRED',
        null,
        ['next' => $next ?? ($_SERVER['REQUEST_URI'] ?? null)]
    );

    $next = $next ?? ($_SERVER['REQUEST_URI'] ?? '/dashboard/');
    $q = http_build_query([
        'stepup' => 1,
        'next'   => $next,
    ]);

    header('Location: ' . BASE_URL . '/mfa/verify.php?' . $q);
    exit;
}

/**
 * Compatibility shim: mfa/verify.php calls complete_mfa_code($code)
 * and expects 'ok' on success.
 */
function complete_mfa_code(string $code): string {
    $res = auth_mfa_verify($code);
    return !empty($res['ok']) ? 'ok' : 'error';
}

/**
 * Accepts a backup code (from mfa_backup_code table) as an alternative to TOTP.
 * Returns 'ok' on success, 'error' otherwise.
 */
function complete_mfa_backup_code(string $code): string {
    // Normalize: allow letters/digits, ignore dashes/spaces
    $raw = strtoupper(preg_replace('/[^A-Z0-9]/', '', $code));
    if ($raw === '' || strlen($raw) < 6) return 'error';

    $pendingUserId = null;
    if (!empty($_SESSION['mfa_pending_user_id'])) {
        $pendingUserId = (int)$_SESSION['mfa_pending_user_id'];
    } elseif (!empty($_SESSION['mfa_pending']['user_id'])) {
        $pendingUserId = (int)$_SESSION['mfa_pending']['user_id'];
    }
    if (!$pendingUserId) return 'error';

    try {
        $stmt = db()->prepare('SELECT code_id, code_hash FROM mfa_backup_code WHERE user_id = ? AND used_at IS NULL');
        $stmt->execute([$pendingUserId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            if (!empty($r['code_hash']) && password_verify($raw, (string)$r['code_hash'])) {
                // Mark used
                db()->prepare('UPDATE mfa_backup_code SET used_at = NOW() WHERE code_id = ?')
                    ->execute([(int)$r['code_id']]);

                // Promote session the same way as TOTP success should
                session_regenerate_safe();

                if (!empty($_SESSION['mfa_pending'])) {
                    $_SESSION['user'] = $_SESSION['mfa_pending'];
                }
                $_SESSION['user_id'] = $pendingUserId;

                unset($_SESSION['mfa_pending'], $_SESSION['mfa_pending_user_id']);

                $_SESSION['auth_method'] = 'password+mfa';

                // Mark step-up / recent MFA window
                $_SESSION['mfa_recent_at'] = time();

                audit_event($pendingUserId, 'BACKUP_CODE_USED', null, ['method' => 'backup_code']);
                audit_event($pendingUserId, 'MFA_SUCCESS', null, ['method' => 'backup_code']);
                audit_event($pendingUserId, 'LOGIN_SUCCESS', null, ['via' => 'backup_code']);

                // Best effort login timestamps
                try {
                    db()->prepare('UPDATE portal_user SET last_login_at = NOW(), last_login_ip = INET6_ATON(?) WHERE user_id = ?')
                        ->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), $pendingUserId]);
                } catch (Throwable $e) {
                    // ignore
                }

                return 'ok';
            }
        }

        audit_event($pendingUserId, 'MFA_FAIL', null, ['reason' => 'invalid_backup_code']);
    } catch (Throwable $e) {
        error_log('complete_mfa_backup_code failed: ' . $e->getMessage());
    }

    return 'error';
}

function auth_login_attempt(string $email, string $password): array {
    $email = strtolower(trim($email));

    $stmt = db()->prepare(
        'SELECT user_id, email, display_name, password_hash, user_type, is_active
         FROM portal_user WHERE email = ? AND user_type = \'INTERNAL\' LIMIT 1'
    );
    $stmt->execute([$email]);
    $u = $stmt->fetch();

    if (!$u || (int)$u['is_active'] !== 1) {
        record_login_attempt($email, false);
        audit_event(null, 'LOGIN_FAIL', null, ['email' => $email]);
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    if (!password_verify($password, (string)$u['password_hash'])) {
        record_login_attempt($email, false);
        audit_event((int)$u['user_id'], 'LOGIN_FAIL', null, null);
        return ['ok' => false, 'error' => 'Invalid email or password.'];
    }

    // Is TOTP enabled?
    $m = db()->prepare('SELECT totp_enabled, totp_secret FROM portal_user_mfa WHERE user_id = ? LIMIT 1');
    $m->execute([(int)$u['user_id']]);
    $row = $m->fetch();

    session_regenerate_safe();

    // Track how this session authenticated
    $_SESSION['auth_method'] = 'password';

    if ($row && (int)$row['totp_enabled'] === 1 && !empty($row['totp_secret'])) {
        // MFA required. Store pending user in session.
        $_SESSION['mfa_pending_user_id'] = (int)$u['user_id'];
        $_SESSION['mfa_pending'] = [
            'user_id' => (int)$u['user_id'],
            'email' => (string)$u['email'],
            'display_name' => (string)$u['display_name'],
            'user_type' => (string)$u['user_type'],
        ];
        record_login_attempt($email, true);
        audit_event((int)$u['user_id'], 'MFA_REQUIRED', null, null);
        return ['ok' => true, 'mfa_required' => true, 'user_id' => (int)$u['user_id']];
    }

    // No MFA: log in directly.
    $_SESSION['user'] = [
        'user_id' => (int)$u['user_id'],
        'email' => (string)$u['email'],
        'display_name' => (string)$u['display_name'],
        'user_type' => (string)$u['user_type'],
    ];

    record_login_attempt($email, true);
    audit_event((int)$u['user_id'], 'LOGIN_SUCCESS', null, ['via' => 'password']);

    try {
        db()->prepare('UPDATE portal_user SET last_login_at = NOW(), last_login_ip = INET6_ATON(?) WHERE user_id = ?')
            ->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), (int)$u['user_id']]);
    } catch (Throwable $e) {
        // ignore
    }

    return ['ok' => true, 'mfa_required' => false];
}

function auth_mfa_verify(string $code): array {
    $pending = $_SESSION['mfa_pending'] ?? null;
    if (!$pending) {
        return ['ok' => false, 'error' => 'MFA session expired. Please log in again.'];
    }

   $stmt = db()->prepare('SELECT totp_enabled, totp_secret FROM portal_user_mfa
                       WHERE user_id = ? LIMIT 1');
    $stmt->execute([(int)$pending['user_id']]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['totp_enabled'] !== 1 || empty($row['totp_secret'])) {
        return ['ok' => false, 'error' => 'MFA is not enabled for this account.'];
    }

    if (!totp_verify((string)$row['totp_secret'], $code, 1)) {
        audit_event((int)$pending['user_id'], 'MFA_FAIL', null, ['reason' => 'invalid_code']);
        return ['ok' => false, 'error' => 'Invalid code.'];
    }

    session_regenerate_safe();
    $_SESSION['user'] = $pending;
    unset($_SESSION['mfa_pending'], $_SESSION['mfa_pending_user_id']);
    $_SESSION['auth_method'] = 'password+mfa';

    try {
        db()->prepare('UPDATE portal_user SET last_login_at = NOW(), last_login_ip = INET6_ATON(?) WHERE user_id = ?')
            ->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), (int)$pending['user_id']]);
        db()->prepare('UPDATE portal_user_mfa SET last_used_at = NOW() WHERE user_id = ?')
            ->execute([(int)$pending['user_id']]);
    } catch (Throwable $e) {
        // ignore
    }

    // Mark step-up window
    $_SESSION['mfa_recent_at'] = time();

    audit_event((int)$pending['user_id'], 'MFA_SUCCESS', null, ['method' => 'totp']);
    audit_event((int)$pending['user_id'], 'LOGIN_SUCCESS', null, ['via' => 'mfa']);

    return ['ok' => true];
}


// ----- Security enrollment policy -----
// Policy:
//  - If you log in with a PASSKEY, treat as fully authenticated (no TOTP required).
//  - If you log in with PASSWORD (or anything else), require TOTP. If not enrolled, force setup.

function security_user_has_passkey(int $userId): bool {
    try {
        $st = db()->prepare('SELECT 1 FROM webauthn_credential WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function security_user_has_totp(int $userId): bool {
    try {
        $st = db()->prepare('SELECT totp_enabled, totp_secret FROM portal_user_mfa WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        return (bool)($row && (int)$row['totp_enabled'] === 1 && !empty($row['totp_secret']));
    } catch (Throwable $e) {
        return false;
    }
}

function security_session_is_passkey(): bool {
    return (string)($_SESSION['auth_method'] ?? '') === 'passkey';
}

/**
 * Enforce "secure by default" onboarding + login policy.
 * Call this AFTER bootstrap/auth is loaded.
 *
 * - Passkey sessions: allowed through
 * - Password sessions: must have TOTP enrolled (or will be forced to setup)
 */
function enforce_security_enrollment_policy(): void {
    $u = current_user();
    if (!$u) return;

    // Allowlist: don't block the setup + auth endpoints
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $allow = [
        '/mfa/setup.php',
        '/mfa/verify.php',
        '/webauthn/',
        '/passkeys/',
        '/invite/accept.php',
        '/logout.php',
        '/index.php',
    ];

    foreach ($allow as $prefix) {
        if ($prefix === $path) return;
        if (substr($prefix, -1) === '/' && str_starts_with($path, $prefix)) return;
    }

    // Passkey login is strong auth; do not require TOTP
    if (security_session_is_passkey()) return;

    // Password (or other) sessions must have TOTP enrolled
    $uid = (int)($u['user_id'] ?? 0);
    if ($uid <= 0) return;

    if (!security_user_has_totp($uid)) {
        $next = $_SERVER['REQUEST_URI'] ?? '/dashboard/';
        $q = http_build_query(['required' => 1, 'next' => $next]);
        header('Location: ' . BASE_URL . '/mfa/setup.php?' . $q);
        exit;
    }
}

