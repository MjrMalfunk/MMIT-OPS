<?php
declare(strict_types=1);

// Smoke checks for Syncro folder map configuration helpers. This intentionally
// avoids bootstrap/config secrets, database writes, and external Syncro calls.
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');

require_once __DIR__ . '/../inc/syncro.php';

$failed = [];
$tree = syncro_policy_folder_standard_tree();
$policies = syncro_policy_assignment_map();

if (($tree['Deploy'] ?? null) !== ['Workstations', 'Servers']) {
    $failed[] = 'Deploy folder tree';
}
if (($tree['Production'] ?? null) !== ['Workstations', 'Servers']) {
    $failed[] = 'Production folder tree';
}
foreach ([
    'manage.deploy.workstations',
    'manage.deploy.servers',
    'manage.production.workstations',
    'manage.production.servers',
    'protect.deploy.workstations',
    'protect.deploy.servers',
    'protect.production.workstations',
    'protect.production.servers',
    'govern.deploy.workstations',
    'govern.deploy.servers',
    'govern.production.workstations',
    'govern.production.servers',
] as $key) {
    if (!array_key_exists($key, $policies)) {
        $failed[] = 'Missing policy placeholder ' . $key;
    }
}
if (syncro_policy_assignment_status() !== 'PENDING_MANUAL') {
    $failed[] = 'Policy assignment defaults pending manual';
}
if (count(syncro_policy_assignment_missing_ids()) !== count($policies)) {
    $failed[] = 'Policy assignment missing-ID helper reports all placeholders';
}
if (!str_contains(syncro_policy_assignment_status_message(), 'PENDING_MANUAL')) {
    $failed[] = 'Policy assignment missing-ID message is admin-visible';
}

$blocked = syncro_api_request('DELETE', 'customers/123/policy_folders/456');
if (($blocked['status'] ?? null) !== 'STAGING_BLOCKED' || empty($blocked['staging_blocked'])) {
    $failed[] = 'Staging guard blocks folder write-shaped requests';
}

if ($failed) {
    fwrite(STDERR, 'Syncro folder map smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro folder map smoke check passed.' . PHP_EOL;
