<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/portal_access.php';
require_once __DIR__ . '/../inc/webauthn.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$token = (string)($_GET['token'] ?? '');
$state = webauthn_verify_state($token);
if (!$state || (($state['kind'] ?? '') !== 'passkey_login')) {
    header('Location: ' . BASE_URL . '/login.php?error=expired', true, 302);
    exit;
}

$userId = (int)($state['user_id'] ?? 0);
$next = trim((string)($state['next'] ?? '/dashboard/index.php'));
if ($userId <= 0) {
    header('Location: ' . BASE_URL . '/login.php?error=expired', true, 302);
    exit;
}
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
    $next = '/dashboard/index.php';
}

$st = db()->prepare('SELECT user_id, email, display_name, user_type, is_active FROM portal_user WHERE user_id = ? LIMIT 1');
$st->execute([$userId]);
$u = $st->fetch();
if (!$u || (int)$u['is_active'] !== 1) {
    header('Location: ' . BASE_URL . '/login.php?error=expired', true, 302);
    exit;
}

session_regenerate_safe();
$_SESSION['user'] = [
    'user_id' => (int)$u['user_id'],
    'email' => (string)$u['email'],
    'display_name' => (string)$u['display_name'],
    'user_type' => (string)$u['user_type'],
];
$_SESSION['mfa_recent_at'] = time();
$_SESSION['auth_method'] = 'passkey';
$_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$u['user_id']);
$_SESSION['__regen_at'] = time();

try {
    db()->prepare('UPDATE portal_user SET last_login_at = NOW(), last_login_ip = INET6_ATON(?) WHERE user_id = ?')
        ->execute([($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), (int)$u['user_id']]);
} catch (Throwable $e) {
    // ignore
}

auth_session_commit();
header('Location: ' . BASE_URL . $next, true, 302);
exit;
