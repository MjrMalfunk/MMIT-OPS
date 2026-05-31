<?php
declare(strict_types=1);

// Smoke check for the central Syncro staging write guard. This intentionally
// avoids bootstrap/config secrets and must not perform any network requests.
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');

require_once __DIR__ . '/../inc/syncro.php';

$result = syncro_api_request('POST', 'customers', [], ['business_name' => 'Smoke Test']);
$expectedMessage = 'Staging mode: Syncro write skipped.';

$checks = [
    'ok false' => ($result['ok'] ?? null) === false,
    'skipped true' => ($result['skipped'] ?? null) === true,
    'staging_blocked true' => ($result['staging_blocked'] ?? null) === true,
    'status STAGING_BLOCKED' => ($result['status'] ?? null) === 'STAGING_BLOCKED',
    'message' => ($result['message'] ?? null) === $expectedMessage,
    'errors' => in_array($expectedMessage, (array)($result['errors'] ?? []), true),
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'Syncro staging guard smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    fwrite(STDERR, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo 'Syncro staging guard smoke check passed.' . PHP_EOL;
