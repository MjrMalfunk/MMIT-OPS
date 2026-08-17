<?php
declare(strict_types=1);

define('SYNCRO_ENABLED', false);
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');

$GLOBALS['syncro_archived_mode_mock_calls'] = 0;
$GLOBALS['syncro_api_request_mock'] = static function (): array {
    $GLOBALS['syncro_archived_mode_mock_calls']++;
    return ['ok' => true];
};

require_once __DIR__ . '/../inc/syncro.php';

$failed = [];
$assert = static function (bool $condition, string $label) use (&$failed): void {
    if (!$condition) {
        $failed[] = $label;
    }
};

$assert(syncro_is_enabled() === false, 'feature flag disables Syncro');
$assert(syncro_is_configured() === true, 'archived credentials remain detectable without enabling integration');

$api = syncro_api_request('GET', 'customers');
$assert(($api['status'] ?? '') === 'SYNCRO_DISABLED', 'central API request is blocked');
$assert(($api['syncro_disabled'] ?? false) === true, 'central API response identifies archived mode');
$assert(($api['ok'] ?? true) === false, 'direct API block fails closed');
$assert($GLOBALS['syncro_archived_mode_mock_calls'] === 0, 'disabled guard runs before request mocks/network');

$clientSync = syncro_sync_client(77);
$assert(($clientSync['ok'] ?? false) === true, 'workflow client sync becomes a successful skip');
$assert(($clientSync['skipped'] ?? false) === true, 'workflow client sync reports skipped');
$assert(($clientSync['status'] ?? '') === 'SYNCRO_DISABLED', 'workflow client sync reports archived status');

$contractSync = syncro_contract_activation_sync(88);
$assert(($contractSync['ok'] ?? false) === true, 'contract activation does not fail when Syncro is archived');
$assert(($contractSync['skipped'] ?? false) === true, 'contract activation skips Syncro cleanly');

$badge = syncro_status_badge_html('SYNCED', 1234);
$assert(str_contains($badge, 'ARCHIVED'), 'UI badge shows archived state');
$assert(str_contains($badge, 'Last status: SYNCED'), 'UI badge preserves historical status');
$assert(str_contains($badge, 'Customer #1234'), 'UI badge preserves historical customer ID');

if ($failed) {
    fwrite(STDERR, 'Syncro archived mode smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro archived mode smoke check passed.' . PHP_EOL;
