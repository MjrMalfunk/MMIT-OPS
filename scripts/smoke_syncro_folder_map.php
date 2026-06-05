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
    'manage.standard.root',
    'manage.deploy.workstations',
    'manage.deploy.servers',
    'manage.production.workstations',
    'manage.production.servers',
    'protect.standard.root',
    'protect.deploy.workstations',
    'protect.deploy.servers',
    'protect.production.workstations',
    'protect.production.servers',
    'govern.standard.root',
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
    'manage.standard.root' => 'MMIT-Test-1100',
    'manage.deploy.workstations' => 'MMIT-Test-1101',
    'manage.deploy.servers' => 'MMIT-Test-1102',
    'manage.production.workstations' => 'MMIT-Test-1103',
    'manage.production.servers' => 'MMIT-Test-1104',
    'protect.standard.root' => 'MMIT-Test-2100',
    'protect.deploy.workstations' => 'MMIT-Test-2101',
    'protect.deploy.servers' => 'MMIT-Test-2102',
    'protect.production.workstations' => 'MMIT-Test-2103',
    'protect.production.servers' => 'MMIT-Test-2104',
    'govern.standard.root' => 'MMIT-Test-3100',
    'govern.deploy.workstations' => 'MMIT-Test-3101',
    'govern.deploy.servers' => 'MMIT-Test-3102',
    'govern.production.workstations' => 'MMIT-Test-3103',
    'govern.production.servers' => 'MMIT-Test-3104',
];
$completeFolderMap = [
    'root_policy_folder_id' => 4955419,
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
foreach (['manage' => 1100, 'protect' => 2100, 'govern' => 3100] as $tier => $basePolicyId) {
    $payload = syncro_build_selected_tier_policy_assignment_payload($tier, $completeFolderMap, $completePolicyMap);
    if (empty($payload['ok']) || count((array)($payload['assignments'] ?? [])) !== 5) {
        $failed[] = ucfirst($tier) . ' tier builds exactly 5 assignments';
        continue;
    }
    $folderIds = array_column((array)$payload['assignments'], 'policy_folder_id');
    $policyIds = array_column((array)$payload['assignments'], 'partial_policy_id');
    if (count(array_unique(array_map('intval', $folderIds))) !== 5) {
        $failed[] = ucfirst($tier) . ' tier has 5 unique assignment folders';
    }
    if (array_map('strval', $policyIds) !== ['MMIT-Test-' . $basePolicyId, 'MMIT-Test-' . ($basePolicyId + 1), 'MMIT-Test-' . ($basePolicyId + 2), 'MMIT-Test-' . ($basePolicyId + 3), 'MMIT-Test-' . ($basePolicyId + 4)]) {
        $failed[] = ucfirst($tier) . ' tier uses selected tier standard/root and child policies as partial_policy_id';
    }
}
$unknownTierPayload = syncro_build_selected_tier_policy_assignment_payload('unknown', $completeFolderMap, $completePolicyMap);
if (!empty($unknownTierPayload['ok']) || !str_contains((string)($unknownTierPayload['message'] ?? ''), 'PENDING_MANUAL')) {
    $failed[] = 'Missing/unknown tier fails closed';
}
$missingPolicyMap = $completePolicyMap;
unset($missingPolicyMap['protect.standard.root']);
$missingPolicyPayload = syncro_build_selected_tier_policy_assignment_payload('protect', $completeFolderMap, $missingPolicyMap);
if (!empty($missingPolicyPayload['ok']) || !in_array('protect.standard.root', (array)($missingPolicyPayload['missing'] ?? []), true)) {
    $failed[] = 'Missing selected-tier root standard policy ID fails closed';
}

$unsafeStagingPolicyMap = $completePolicyMap;
$unsafeStagingPolicyMap['manage.standard.root'] = 1100;
$unsafeStagingPayload = syncro_build_selected_tier_policy_assignment_payload('manage', $completeFolderMap, $unsafeStagingPolicyMap);
if (!empty($unsafeStagingPayload['ok']) || !str_contains((string)($unsafeStagingPayload['message'] ?? ''), 'MMIT-Test-*')) {
    $failed[] = 'Staging policy IDs must use MMIT-Test policies';
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
if (!empty($duplicateFolderPayload['ok']) || !str_contains((string)($duplicateFolderPayload['message'] ?? ''), 'customer root plus four unique OPS-managed folders')) {
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
            ['id' => 4955419, 'name' => 'LnK Consulting, LLC', 'parent_id' => null, 'partial_policy_id' => 0],
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


function smoke_run_php(string $code): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    $output = [];
    $status = 0;
    exec($cmd . ' 2>&1', $output, $status);
    return ['status' => $status, 'output' => implode("\n", $output)];
}


$productionNumericSmoke = <<<'PHP_CODE'
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
require_once getcwd() . '/inc/syncro.php';
$policyMap = [
    'manage.standard.root' => 1100,
    'manage.deploy.workstations' => 1101,
    'manage.deploy.servers' => 1102,
    'manage.production.workstations' => 1103,
    'manage.production.servers' => 1104,
];
$folderMap = [
    'root_policy_folder_id' => 4955419,
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
$payload = syncro_build_selected_tier_policy_assignment_payload('manage', $folderMap, $policyMap);
if (empty($payload['ok'])) { fwrite(STDERR, (string)($payload['message'] ?? 'production numeric payload failed')); exit(1); }
$policyIds = array_map('intval', array_column((array)$payload['assignments'], 'partial_policy_id'));
if ($policyIds !== [1100, 1101, 1102, 1103, 1104]) { fwrite(STDERR, 'production numeric policy IDs changed'); exit(1); }
PHP_CODE;
$productionNumericResult = smoke_run_php($productionNumericSmoke);
if (($productionNumericResult['status'] ?? 1) !== 0) {
    $failed[] = 'Production numeric policy ID smoke: ' . ($productionNumericResult['output'] ?? '');
}

$assignmentSmoke = <<<'PHP_CODE'
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('SYNCRO_ALLOW_STAGING_WRITES', true);
define('SYNCRO_POLICY_ASSIGNMENTS', [
    'manage.standard.root' => 'MMIT-Test-1100',
    'manage.deploy.workstations' => 'MMIT-Test-1101',
    'manage.deploy.servers' => 'MMIT-Test-1102',
    'manage.production.workstations' => 'MMIT-Test-1103',
    'manage.production.servers' => 'MMIT-Test-1104',
    'protect.standard.root' => 'MMIT-Test-2100',
    'protect.deploy.workstations' => 'MMIT-Test-2101',
    'protect.deploy.servers' => 'MMIT-Test-2102',
    'protect.production.workstations' => 'MMIT-Test-2103',
    'protect.production.servers' => 'MMIT-Test-2104',
    'govern.standard.root' => 'MMIT-Test-3100',
    'govern.deploy.workstations' => 'MMIT-Test-3101',
    'govern.deploy.servers' => 'MMIT-Test-3102',
    'govern.production.workstations' => 'MMIT-Test-3103',
    'govern.production.servers' => 'MMIT-Test-3104',
]);
require_once getcwd() . '/inc/syncro.php';
$failed = [];
$folderMap = [
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
$baseFolders = [
    ['id' => 4955419, 'name' => 'LnK Consulting, LLC', 'parent_id' => null, 'partial_policy_id' => 0],
    ['id' => 7001, 'name' => 'Deploy', 'parent_id' => 4955419],
    ['id' => 7002, 'name' => 'Production', 'parent_id' => 4955419],
    ['id' => 5029833, 'name' => 'Workstations', 'parent_id' => 7001, 'partial_policy_id' => 0],
    ['id' => 5029834, 'name' => 'Servers', 'parent_id' => 7001, 'partial_policy_id' => 0],
    ['id' => 5029835, 'name' => 'Workstations', 'parent_id' => 7002, 'partial_policy_id' => 0],
    ['id' => 5029836, 'name' => 'Servers', 'parent_id' => 7002, 'partial_policy_id' => 0],
    ['id' => 9999999, 'name' => 'Non OPS Folder', 'parent_id' => 4955419, 'partial_policy_id' => 123],
];
foreach (['manage' => 1100, 'protect' => 2100, 'govern' => 3100] as $tier => $basePolicyId) {
    $calls = [];
    $GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$calls, $baseFolders): array {
        $calls[] = ['method' => $method, 'path' => $path, 'query' => $query, 'payload' => $payload];
        if ($method === 'GET' && $path === 'policy_folders') {
            return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => $baseFolders], 'request' => ['method' => $method, 'path' => '/api/v1/policy_folders?customer_id=' . (int)($query['customer_id'] ?? 0)]];
        }
        if ($method === 'PUT' && str_starts_with($path, 'policy_folders/')) {
            return ['ok' => true, 'status' => 200, 'data' => ['policy_folder' => [
                'id' => (int)basename($path),
                'name' => (string)($payload['name'] ?? ''),
                'customer_id' => (int)($payload['customer_id'] ?? 0),
                'parent_id' => (int)($payload['parent_id'] ?? 0),
                'partial_policy_id' => (string)($payload['partial_policy_id'] ?? ''),
            ]], 'request' => ['method' => $method, 'path' => '/api/v1/' . $path]];
        }
        return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
    };
    $result = syncro_assign_policies_to_folder_tree(35690276, $folderMap, ['legal_name' => 'LnK Consulting LLC', 'syncro_policy_tier' => $tier]);
    unset($GLOBALS['syncro_api_request_mock']);
    $puts = array_values(array_filter($calls, static fn(array $call): bool => $call['method'] === 'PUT'));
    if (($result['status'] ?? null) !== 'READY' || count($puts) !== 5) {
        $failed[] = $tier . ' did not build customer root plus four child policy folder PUT updates';
        continue;
    }
    foreach ($puts as $offset => $call) {
        $expectedPolicyId = 'MMIT-Test-' . ($basePolicyId + $offset);
        if (!str_starts_with((string)$call['path'], 'policy_folders/')) {
            $failed[] = $tier . ' used wrong PUT path';
        }
        if (array_key_exists('policy_id', (array)$call['payload'])) {
            $failed[] = $tier . ' PUT payload used legacy policy_id';
        }
        if ((string)($call['payload']['partial_policy_id'] ?? '') !== $expectedPolicyId) {
            $failed[] = $tier . ' PUT payload missing intended partial_policy_id ' . $expectedPolicyId;
        }
        if (($call['payload']['name'] ?? '') === '') {
            $failed[] = $tier . ' PUT payload did not preserve name';
        }
        if ($offset === 0 && array_key_exists('parent_id', (array)$call['payload'])) {
            $failed[] = $tier . ' root PUT payload should not assign a parent_id';
        }
        if ($offset > 0 && (int)($call['payload']['parent_id'] ?? 0) <= 0) {
            $failed[] = $tier . ' child PUT payload did not preserve parent_id';
        }
    }
    foreach ($calls as $call) {
        if ($call['method'] === 'PATCH' || str_contains((string)$call['path'], 'policy_assignments')) {
            $failed[] = $tier . ' used legacy policy_assignments route';
        }
    }
}

$correctFolders = $baseFolders;
$correctFolders[0]['partial_policy_id'] = 'MMIT-Test-2100';
$correctFolders[3]['partial_policy_id'] = 'MMIT-Test-2101';
$correctFolders[4]['partial_policy_id'] = 'MMIT-Test-2102';
$correctFolders[5]['partial_policy_id'] = 'MMIT-Test-2103';
$correctFolders[6]['partial_policy_id'] = 'MMIT-Test-2104';
$calls = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$calls, $correctFolders): array {
    $calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'GET' && $path === 'policy_folders') {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => $correctFolders], 'request' => ['method' => $method, 'path' => '/api/v1/policy_folders']];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected write on idempotent path']];
};
$idempotent = syncro_assign_policies_to_folder_tree(35690276, $folderMap, ['syncro_policy_tier' => 'protect']);
unset($GLOBALS['syncro_api_request_mock']);
$putCount = count(array_filter($calls, static fn(array $call): bool => $call['method'] === 'PUT'));
if (($idempotent['status'] ?? null) !== 'READY' || $putCount !== 0 || (int)($idempotent['already_correct_count'] ?? 0) !== 5) {
    $failed[] = 'Already-correct folder policy assignment is not idempotent success';
}

$calls = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$calls, $baseFolders): array {
    $calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'GET' && $path === 'policy_folders') {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => $baseFolders], 'request' => ['method' => $method, 'path' => '/api/v1/policy_folders']];
    }
    if ($method === 'PUT' && str_starts_with($path, 'policy_folders/')) {
        return ['ok' => true, 'status' => 200, 'data' => ['policy_folder' => [
            'id' => (int)basename($path),
            'name' => (string)($payload['name'] ?? ''),
            'parent_id' => (int)($payload['parent_id'] ?? 0),
            'partial_policy_id' => 'MMIT-Test-Wrong',
        ]], 'request' => ['method' => $method, 'path' => '/api/v1/' . $path]];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
};
$wrongPolicy = syncro_assign_policies_to_folder_tree(35690276, $folderMap, ['syncro_policy_tier' => 'protect']);
unset($GLOBALS['syncro_api_request_mock']);
if (($wrongPolicy['status'] ?? null) !== 'PENDING_MANUAL' || !str_contains((string)($wrongPolicy['message'] ?? ''), 'returned partial_policy_id')) {
    $failed[] = 'PUT response with wrong partial_policy_id does not fail closed';
}

if ($failed) { fwrite(STDERR, implode(', ', $failed)); exit(1); }
PHP_CODE;
$assignmentSmokeResult = smoke_run_php($assignmentSmoke);
if (($assignmentSmokeResult['status'] ?? 1) !== 0) {
    $failed[] = 'Policy folder assignment PUT smoke: ' . ($assignmentSmokeResult['output'] ?? '');
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
