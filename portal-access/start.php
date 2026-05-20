<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/portal_access.php';

$service = portal_access_safe_service((string) ($_POST['service'] ?? $_GET['service'] ?? 'DASHBOARD'), 'DASHBOARD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_access_session_clear();
    $email = portal_access_normalize_email((string) ($_POST['email'] ?? ''));
    $result = portal_access_send_magic_link($email, $service);

    $next = portal_access_safe_next_path((string) ($_POST['next'] ?? '/client-preview.php'), '/client-preview.php');
    $returnUrl = portal_access_safe_return_url((string) ($_POST['return_url'] ?? ''), portal_access_target_url('DASHBOARD', '/?next=' . rawurlencode($next)));
    if (!empty($result['ok'])) {
        $returnUrl = portal_access_append_query($returnUrl, ['link' => 'sent']);
    } else {
        $returnUrl = portal_access_append_query($returnUrl, ['link' => 'error']);
    }

    header('Location: ' . $returnUrl, true, 302);
    exit;
}

header('Location: ' . portal_access_target_url($service, '/'), true, 302);
exit;
