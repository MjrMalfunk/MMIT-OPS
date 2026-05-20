<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/onedrive.php';
require_login();
csrf_check();

$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? (BASE_URL . '/accounting/bills.php')));
if ($returnTo === '' || !str_starts_with($returnTo, BASE_URL)) {
    $returnTo = BASE_URL . '/accounting/bills.php';
}

if (!onedrive_is_configured()) {
    header('Location: ' . BASE_URL . '/accounting/bills.php?error=' . urlencode('Add your OneDrive client ID and secret in inc/config.php first.'));
    exit;
}

header('Location: ' . onedrive_authorize_url($returnTo));
exit;
