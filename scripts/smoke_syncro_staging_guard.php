<?php
declare(strict_types=1);

// Smoke check for the central Syncro staging write guard. This intentionally
// avoids bootstrap/config secrets and must not perform any network requests.

function smoke_run_php(string $code): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    return ['status' => $status, 'output' => implode("\n", $output)];
}

$bootstrap = <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
require_once getcwd() . '/inc/syncro.php';
PHP_CODE;

$defaultCode = $bootstrap . <<<'PHP_CODE'
$failed = [];
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    $result = syncro_api_request($method, 'customers/123/policy_assignments', [], ['smoke' => true]);
    if (($result['status'] ?? null) !== 'STAGING_BLOCKED' || empty($result['staging_blocked'])) {
        $failed[] = $method . ' not blocked by default';
    }
}
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;

$overrideCode = <<<'PHP_CODE'
define('SYNCRO_ALLOW_STAGING_WRITES', true);
PHP_CODE . $bootstrap . <<<'PHP_CODE'
$GLOBALS['syncro_api_request_mock_count'] = 0;
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload): array {
    $GLOBALS['syncro_api_request_mock_count']++;
    return ['ok' => true, 'status' => 299, 'method' => $method, 'path' => $path, 'payload' => $payload];
};
$failed = [];
foreach (['POST', 'PUT', 'PATCH'] as $method) {
    $result = syncro_api_request($method, 'customers/123/policy_assignments', [], ['smoke' => true]);
    if (($result['ok'] ?? null) !== true || ($result['status'] ?? null) !== 299) {
        $failed[] = $method . ' not allowed with override';
    }
}
$delete = syncro_api_request('DELETE', 'customers/123/policy_folders/456');
if (($delete['status'] ?? null) !== 'STAGING_BLOCKED' || empty($delete['staging_blocked'])) {
    $failed[] = 'DELETE not blocked with override';
}
if (($GLOBALS['syncro_api_request_mock_count'] ?? 0) !== 3) {
    $failed[] = 'unexpected mock call count ' . (string)($GLOBALS['syncro_api_request_mock_count'] ?? 0);
}
if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;

$checks = [
    'default blocks POST/PUT/PATCH/DELETE' => smoke_run_php($defaultCode),
    'override allows POST/PUT/PATCH and blocks DELETE' => smoke_run_php($overrideCode),
];

$failed = [];
foreach ($checks as $name => $result) {
    if (($result['status'] ?? 1) !== 0) {
        $failed[] = $name . ': ' . ($result['output'] ?? '');
    }
}

if ($failed) {
    fwrite(STDERR, 'Syncro staging guard smoke check failed: ' . implode(' | ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro staging guard smoke check passed.' . PHP_EOL;
