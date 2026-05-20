<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/onedrive.php';
require_login();
csrf_check();

$returnTo = trim((string)($_POST['return_to'] ?? $_GET['return_to'] ?? (BASE_URL . '/accounting/bills.php')));
if ($returnTo === '' || !str_starts_with($returnTo, BASE_URL)) {
    $returnTo = BASE_URL . '/accounting/bills.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}

onedrive_clear_token_data();
audit_event((int)(current_user()['user_id'] ?? 0), 'ONEDRIVE_DISCONNECTED');
header('Location: ' . $returnTo . '?message=' . urlencode('OneDrive connection removed.'));
exit;
