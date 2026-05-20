<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
$reason = (string) ($_GET['reason'] ?? '');
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
}
session_destroy();
$target = BASE_URL . '/';
if ($reason === 'inactive') {
    $target .= '?timeout=1';
}
header('Location: ' . $target);
exit;
