<?php
declare(strict_types=1);

// Smoke checks for Syncro folder map configuration helpers. This intentionally
// avoids bootstrap/config secrets, database writes, and external Syncro calls.
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('SYNCRO_ALLOW_STAGING_WRITES', true);

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




$completePolicyMap = [
    'manage.deploy.workstations' => 1101,
    'manage.deploy.servers' => 1102,
    'manage.production.workstations' => 1103,
    'manage.production.servers' => 1104,
    'protect.deploy.workstations' => 2101,
    'protect.deploy.servers' => 2102,
    'protect.production.workstations' => 2103,
    'protect.production.servers' => 2104,
    'govern.deploy.workstations' => 3101,
    'govern.deploy.servers' => 3102,
    'govern.production.workstations' => 3103,
    'govern.production.servers' => 3104,
];
$completeFolderMap = [
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
foreach (['manage' => 1100, 'protect' => 2100, 'govern' => 3100] as $tier => $basePolicyId) {
    $payload = syncro_build_selected_tier_policy_assignment_payload($tier, $completeFolderMap, $completePolicyMap);
    if (empty($payload['ok']) || count((array)($payload['assignments'] ?? [])) !== 4) {
        $failed[] = ucfirst($tier) . ' tier builds exactly 4 assignments';
        continue;
    }
    $folderIds = array_column((array)$payload['assignments'], 'policy_folder_id');
    $policyIds = array_column((array)$payload['assignments'], 'policy_id');
    if (count(array_unique(array_map('intval', $folderIds))) !== 4) {
        $failed[] = ucfirst($tier) . ' tier has 4 unique assignment folders';
    }
    if (array_map('intval', $policyIds) !== [$basePolicyId + 1, $basePolicyId + 2, $basePolicyId + 3, $basePolicyId + 4]) {
        $failed[] = ucfirst($tier) . ' tier uses only selected tier policies';
    }
}
$unknownTierPayload = syncro_build_selected_tier_policy_assignment_payload('unknown', $completeFolderMap, $completePolicyMap);
if (!empty($unknownTierPayload['ok']) || !str_contains((string)($unknownTierPayload['message'] ?? ''), 'PENDING_MANUAL')) {
    $failed[] = 'Missing/unknown tier fails closed';
}
$missingPolicyMap = $completePolicyMap;
unset($missingPolicyMap['protect.production.servers']);
$missingPolicyPayload = syncro_build_selected_tier_policy_assignment_payload('protect', $completeFolderMap, $missingPolicyMap);
if (!empty($missingPolicyPayload['ok']) || !in_array('protect.production.servers', (array)($missingPolicyPayload['missing'] ?? []), true)) {
    $failed[] = 'Missing selected-tier policy ID fails closed';
}
$missingFolderMap = $completeFolderMap;
unset($missingFolderMap['production_servers_folder_id']);
$missingFolderPayload = syncro_build_selected_tier_policy_assignment_payload('govern', $missingFolderMap, $completePolicyMap);
if (!empty($missingFolderPayload['ok']) || !in_array('production_servers_folder_id', (array)($missingFolderPayload['missing'] ?? []), true)) {
    $failed[] = 'Missing folder ID fails closed';
}
$duplicateFolderMap = $completeFolderMap;
$duplicateFolderMap['production_servers_folder_id'] = $duplicateFolderMap['production_workstations_folder_id'];
$duplicateFolderPayload = syncro_build_selected_tier_policy_assignment_payload('manage', $duplicateFolderMap, $completePolicyMap);
if (!empty($duplicateFolderPayload['ok']) || !str_contains((string)($duplicateFolderPayload['message'] ?? ''), 'four unique OPS-managed folders')) {
    $failed[] = 'Duplicate policy_folder_id payload fails closed before API call';
}
foreach ([
    'Manage IT' => 'manage',
    'ESSENTIAL' => 'manage',
    'Protect IT' => 'protect',
    'SECURE' => 'protect',
    'Govern IT' => 'govern',
    'COMPLETE' => 'govern',
] as $input => $expectedTier) {
    if (syncro_normalize_policy_tier($input) !== $expectedTier) {
        $failed[] = 'Tier normalization failed for ' . $input;
    }
}

$singleRoot = syncro_resolve_customer_root_policy_folder([
    ['id' => 9001, 'name' => 'LnK Consulting, LLC', 'parent_id' => null],
    ['id' => 9002, 'name' => 'Workstations', 'parent_id' => 9001],
], ['legal_name' => 'Different Name']);
if (empty($singleRoot['ok']) || (int)($singleRoot['root']['id'] ?? 0) !== 9001) {
    $failed[] = 'Single parent_id null folder resolves as customer root';
}

$matchedRoot = syncro_resolve_customer_root_policy_folder([
    ['id' => 9101, 'name' => 'Unrelated Root', 'parent_id' => null],
    ['id' => 9102, 'name' => 'LnK Consulting, LLC', 'parent_id' => null],
], ['legal_name' => 'LnK Consulting LLC']);
if (empty($matchedRoot['ok']) || (int)($matchedRoot['root']['id'] ?? 0) !== 9102) {
    $failed[] = 'Client legal name resolves matching root among multiple roots';
}

$createdFolders = [];
$nextFolderId = 10000;
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$createdFolders, &$nextFolderId): array {
    if ($method === 'GET' && $path === 'policy_folders' && (int)($query['customer_id'] ?? 0) === 35690276) {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => [
            ['id' => 4955419, 'name' => 'LnK Consulting, LLC', 'parent_id' => null],
        ]]];
    }
    if ($method === 'POST' && $path === 'policy_folders') {
        $createdFolders[] = $payload;
        $nextFolderId++;
        return ['ok' => true, 'status' => 201, 'data' => ['policy_folder' => [
            'id' => $nextFolderId,
            'name' => (string)($payload['name'] ?? ''),
            'parent_id' => (int)($payload['parent_id'] ?? 0),
        ]]];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected Syncro smoke call ' . $method . ' ' . $path]];
};
$createdTree = syncro_ensure_customer_policy_folder_tree(35690276, [], true, ['legal_name' => 'LnK Consulting LLC']);
unset($GLOBALS['syncro_api_request_mock']);
if (empty($createdTree['ok']) || (int)($createdTree['root']['id'] ?? 0) !== 4955419) {
    $failed[] = 'Provision tree resolves returned customer root';
}
if (count($createdFolders) !== 6) {
    $failed[] = 'Provision tree creates Deploy/Production plus child folders';
} else {
    if (($createdFolders[0]['name'] ?? null) !== 'Deploy' || (int)($createdFolders[0]['parent_id'] ?? 0) !== 4955419) {
        $failed[] = 'Deploy is created under resolved customer root parent_id';
    }
    if (($createdFolders[1]['name'] ?? null) !== 'Production' || (int)($createdFolders[1]['parent_id'] ?? 0) !== 4955419) {
        $failed[] = 'Production is created under resolved customer root parent_id';
    }
    if (($createdFolders[2]['name'] ?? null) !== 'Workstations' || (int)($createdFolders[2]['parent_id'] ?? 0) !== 10001) {
        $failed[] = 'Deploy/Workstations is created under Deploy parent_id';
    }
    if (($createdFolders[5]['name'] ?? null) !== 'Servers' || (int)($createdFolders[5]['parent_id'] ?? 0) !== 10002) {
        $failed[] = 'Production/Servers is created under Production parent_id';
    }
}
if (!str_contains((string)($createdTree['message'] ?? ''), '#4955419 "LnK Consulting, LLC"')) {
    $failed[] = 'Successful provision message includes resolved root ID/name';
}

$ambiguousRoot = syncro_resolve_customer_root_policy_folder([
    ['id' => 9201, 'name' => 'Root A', 'parent_id' => null],
    ['id' => 9202, 'name' => 'Root B', 'parent_id' => null],
], ['legal_name' => 'Root C']);
if (!empty($ambiguousRoot['ok']) || !str_contains((string)($ambiguousRoot['message'] ?? ''), 'POLICY_FOLDER_PROVISION_PENDING_MANUAL')) {
    $failed[] = 'Ambiguous multiple root folders fail closed pending manual';
}

$noRoot = syncro_resolve_customer_root_policy_folder([
    ['id' => 9301, 'name' => 'Workstations', 'parent_id' => 123],
], ['legal_name' => 'LnK Consulting LLC']);
if (!empty($noRoot['ok']) || !str_contains((string)($noRoot['message'] ?? ''), 'no customer root folder')) {
    $failed[] = 'No root folder fails closed pending manual';
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
