<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/onedrive.php';
require_login();
csrf_check();

$returnTo = trim((string)($_SESSION['onedrive_oauth_return_to'] ?? (BASE_URL . '/accounting/bills.php')));
if ($returnTo === '' || !str_starts_with($returnTo, BASE_URL)) {
    $returnTo = BASE_URL . '/accounting/bills.php';
}

$state = trim((string)($_GET['state'] ?? ''));
$expectedState = trim((string)($_SESSION['onedrive_oauth_state'] ?? ''));
unset($_SESSION['onedrive_oauth_state'], $_SESSION['onedrive_oauth_return_to']);

if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
    header('Location: ' . onedrive_append_query($returnTo, ['error' => 'OneDrive sign-in state check failed. Please try again.']));
    exit;
}

if (!empty($_GET['error'])) {
    $msg = trim((string)($_GET['error_description'] ?? $_GET['error']));
    header('Location: ' . onedrive_append_query($returnTo, ['error' => 'OneDrive connection was not completed: ' . $msg]));
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '') {
    header('Location: ' . onedrive_append_query($returnTo, ['error' => 'OneDrive did not return an authorization code.']));
    exit;
}

$exchange = onedrive_exchange_code($code);
if (empty($exchange['ok']) || !is_array($exchange['json'])) {
    header('Location: ' . onedrive_append_query($returnTo, ['error' => (string)($exchange['error'] ?? 'Unable to connect OneDrive.')]));
    exit;
}

$tokenResponse = $exchange['json'];
$accessToken = trim((string)($tokenResponse['access_token'] ?? ''));
$profile = [];
if ($accessToken !== '') {
    $me = onedrive_get_profile($accessToken);
    if (!empty($me['ok']) && is_array($me['json'])) {
        $profile = $me['json'];
    }
}

$saved = onedrive_build_saved_token_payload($tokenResponse, $profile);
$save = onedrive_save_token_data($saved);
if (!empty($save['persisted'])) {
    $saved['_persisted'] = true;
}
audit_event((int)(current_user()['user_id'] ?? 0), 'ONEDRIVE_CONNECTED', [
    'account' => $saved['account_label'],
    'persisted' => !empty($save['persisted']) ? 1 : 0,
    'has_refresh_token' => trim((string)($saved['refresh_token'] ?? '')) !== '' ? 1 : 0,
]);

$message = 'OneDrive connected for receipt uploads.';
if (empty($save['persisted'])) {
    $message .= ' Session connection is live, but token persistence failed. Check write access on storage/onedrive.';
}
header('Location: ' . onedrive_append_query($returnTo, ['message' => $message]));
exit;
