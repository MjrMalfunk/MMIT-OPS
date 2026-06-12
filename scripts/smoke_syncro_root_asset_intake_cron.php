<?php
declare(strict_types=1);

// Smoke checks for the scheduled Syncro root asset intake cron runner.
require_once __DIR__ . '/syncro_root_asset_intake_cron.php';

$failed = [];

function smoke_cron_check(bool $condition, string $message, array &$failed): void
{
    if (!$condition) {
        $failed[] = $message;
    }
}

function smoke_cron_temp_path(string $name): string
{
    $path = tempnam(sys_get_temp_dir(), $name);
    if ($path === false) {
        fwrite(STDERR, 'Unable to create temporary smoke file.' . PHP_EOL);
        exit(1);
    }
    return $path;
}

function smoke_cron_cli(array $args, array $env = [], array $phpOptions = []): array
{
    $command = array_merge([PHP_BINARY], $phpOptions, [__DIR__ . '/syncro_root_asset_intake_cron.php'], $args);
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), $env ? array_merge($_ENV, $env) : null);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start cron CLI process.'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function smoke_cron_mock_prepend(string $callsPath, bool $allowStagingWrites = false): array
{
    $configPath = smoke_cron_temp_path('cron-config-');
    $prependPath = smoke_cron_temp_path('cron-prepend-');
    $configCode = <<<'PHP'
<?php
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SESSION_NAME', 'MMIT_OPS_CRON_SMOKE');
define('COOKIE_SECURE', true);
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Lax');
define('SESSION_TTL_SECONDS', 3600);
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'smoke');
define('DB_USER', 'smoke');
define('DB_PASS', 'smoke');
define('DB_CHARSET', 'utf8mb4');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('MMIT_SYNCRO_SERVICE_TIER_OPTION_IDS', '{"Manage":"2001","Protect":"2002","Govern":"2003"}');
define('MFA_TOTP_PERIOD', 30);
define('MFA_TOTP_DIGITS', 6);
define('MFA_TOTP_ISSUER', 'MMIT OPS Cron Smoke');
PHP;
    if ($allowStagingWrites) {
        $configCode .= "define('SYNCRO_ALLOW_STAGING_WRITES', true);\n";
    }
    file_put_contents($configPath, $configCode);

    $prependCode = "<?php\n"
        . "define('OPS_CONFIG_FILE', " . var_export($configPath, true) . ");\n"
        . "\$GLOBALS['smoke_cron_calls'] = [];\n"
        . "\$GLOBALS['smoke_cron_stamped'] = [];\n"
        . "\$GLOBALS['syncro_custom_field_option_definitions_mock'] = [];\n"
        . "register_shutdown_function(static function (): void { file_put_contents(" . var_export($callsPath, true) . ", json_encode(\$GLOBALS['smoke_cron_calls'] ?? [])); });\n"
        . "\$GLOBALS['syncro_root_asset_intake_cron_clients_mock'] = static function (?int \$clientId, int \$limit): array {\n"
        . "    \$clients = [\n"
        . "        ['client_id' => 7, 'legal_name' => 'No Syncro LLC', 'status' => 'ACTIVE', 'syncro_customer_id' => null, 'syncro_sync_status' => 'SYNCED'],\n"
        . "        ['client_id' => 8, 'legal_name' => 'Incomplete Folder LLC', 'status' => 'ACTIVE', 'syncro_customer_id' => 108, 'syncro_sync_status' => 'SYNCED'],\n"
        . "        ['client_id' => 9, 'legal_name' => 'Ready Client LLC', 'status' => 'ACTIVE', 'syncro_customer_id' => 109, 'syncro_sync_status' => 'SYNCED', 'active_contract_count' => 1, 'service_tier' => 'Protect', 'services' => []],\n"
        . "        ['client_id' => 10, 'legal_name' => 'Pre Activation LLC', 'status' => 'ACTIVE', 'syncro_customer_id' => 110, 'syncro_sync_status' => 'SYNCED', 'active_contract_count' => 0, 'service_tier' => 'Manage', 'services' => []],\n"
        . "        ['client_id' => 11, 'legal_name' => 'Policy Pending LLC', 'status' => 'ACTIVE', 'syncro_customer_id' => 111, 'syncro_sync_status' => 'SYNCED'],\n"
        . "    ];\n"
        . "    if (\$clientId !== null && \$clientId > 0) { \$clients = array_values(array_filter(\$clients, static fn(array \$client): bool => (int)\$client['client_id'] === \$clientId)); }\n"
        . "    return \$limit > 0 ? array_slice(\$clients, 0, \$limit) : \$clients;\n"
        . "};\n"
        . "\$GLOBALS['syncro_root_asset_intake_cron_folder_map_mock'] = static function (int \$clientId): array {\n"
        . "    if (\$clientId === 8) { return ['deploy_workstations_folder_id' => 5029833]; }\n"
        . "    if (\$clientId === 9 || \$clientId === 10) { return ['deploy_workstations_folder_id' => 5029833, 'deploy_servers_folder_id' => 5029834, 'production_workstations_folder_id' => 5029835, 'production_servers_folder_id' => 5029836, 'provision_status' => 'READY', 'policy_assignment_status' => 'READY']; }\n"
        . "    if (\$clientId === 11) { return ['deploy_workstations_folder_id' => 5029833, 'deploy_servers_folder_id' => 5029834, 'production_workstations_folder_id' => 5029835, 'production_servers_folder_id' => 5029836, 'provision_status' => 'READY', 'policy_assignment_status' => 'PENDING_MANUAL']; }\n"
        . "    return [];\n"
        . "};\n"
        . "\$GLOBALS['syncro_api_request_mock'] = static function (string \$method, string \$path, array \$query, ?array \$payload): array {\n"
        . "    \$GLOBALS['smoke_cron_calls'][] = ['method' => \$method, 'path' => \$path, 'query' => \$query, 'payload' => \$payload];\n"
        . "    if (\$method === 'GET' && \$path === 'policy_folders') { \$customerId = (int)(\$query['customer_id'] ?? 0); \$rootName = \$customerId === 110 ? 'Pre Activation LLC' : (\$customerId === 111 ? 'Policy Pending LLC' : 'Ready Client LLC'); return ['ok' => true, 'status' => 200, 'data' => ['policy_folders' => [['id' => 4955419, 'name' => \$rootName, 'parent_id' => null]]]]; }\n"
        . "    if (\$method === 'GET' && \$path === 'customer_assets') { return ['ok' => true, 'status' => 200, 'data' => ['customer_assets' => [\n"
        . "        ['id' => 101, 'name' => 'WIN11-ROOT', 'os' => 'Microsoft Windows 11 Pro', 'policy_folder_id' => 4955419],\n"
        . "        ['id' => 102, 'name' => 'SRV-ROOT', 'os' => 'Windows Server 2022 Standard', 'policy_folder_id' => 4955419],\n"
        . "        ['id' => 103, 'name' => 'MAC-ROOT', 'os' => 'macOS Sonoma 14.5', 'policy_folder_id' => 4955419],\n"
        . "        ['id' => 104, 'name' => 'LINUX-ROOT', 'os' => 'Ubuntu Linux Server 24.04', 'policy_folder_id' => 4955419],\n"
        . "        ['id' => 105, 'name' => 'UNKNOWN-ROOT', 'os' => '', 'policy_folder_id' => 4955419],\n"
        . "        ['id' => 106, 'name' => 'WIN11-DEPLOY', 'os' => 'Windows 11 Pro', 'policy_folder_id' => 5029833],\n"
        . "        ['id' => 107, 'name' => 'WIN11-PROD', 'os' => 'Windows 11 Pro', 'policy_folder_id' => 5029835],\n"
        . "    ]]]; }\n"
        . "    if (\$method === 'PUT' && preg_match('/^customer_assets\\/(101|102)$/', \$path) && isset(\$payload['properties'])) { \$GLOBALS['smoke_cron_stamped'][(int)basename(\$path)] = (array)\$payload['properties']; return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => (int)basename(\$path)]]]; }\n"
        . "    if (\$method === 'GET' && preg_match('/^customer_assets\\/(101|102)$/', \$path)) { \$id = (int)basename(\$path); return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => \$id, 'properties' => \$GLOBALS['smoke_cron_stamped'][\$id] ?? []]]]; }\n"
        . "    if (\$method === 'PUT' && \$path === 'customer_assets/101' && (\$payload['policy_folder_id'] ?? null) === 5029833) { return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 101, 'policy_folder_id' => 5029833]]]; }\n"
        . "    if (\$method === 'PUT' && \$path === 'customer_assets/102' && (\$payload['policy_folder_id'] ?? null) === 5029834) { return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 102, 'policy_folder_id' => 5029834]]]; }\n"
        . "    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected mock request ' . \$method . ' ' . \$path]];\n"
        . "};\n";
    file_put_contents($prependPath, $prependCode);
    return [$prependPath, $configPath];
}

$lockPath = smoke_cron_temp_path('cron-lock-');
@unlink($lockPath);
$lockOne = syncro_root_asset_intake_cron_acquire_lock($lockPath, 60);
$lockTwo = syncro_root_asset_intake_cron_acquire_lock($lockPath, 60);
smoke_cron_check(!empty($lockOne['ok']), 'First lock acquisition should succeed', $failed);
smoke_cron_check(empty($lockTwo['ok']) && ($lockTwo['status'] ?? null) === 'LOCK_HELD', 'Second lock acquisition should be prevented while first lock is held', $failed);
syncro_root_asset_intake_cron_release_lock($lockOne);
@unlink($lockPath);
file_put_contents($lockPath, 'old lock');
touch($lockPath, time() - 7200);
$staleLock = syncro_root_asset_intake_cron_acquire_lock($lockPath, 60);
smoke_cron_check(!empty($staleLock['ok']) && !empty($staleLock['stale']), 'Stale lock file should be replaced after file lock acquisition', $failed);
syncro_root_asset_intake_cron_release_lock($staleLock);
@unlink($lockPath);

$help = smoke_cron_cli(['--help']);
smoke_cron_check(($help['exit_code'] ?? null) === 0, 'Cron --help should exit 0', $failed);
smoke_cron_check(str_contains((string)($help['stdout'] ?? ''), 'Usage: php scripts/syncro_root_asset_intake_cron.php'), 'Cron --help should print usage', $failed);

$callsPath = smoke_cron_temp_path('cron-calls-');
$lockPath = smoke_cron_temp_path('cron-run-lock-');
@unlink($lockPath);
[$prepend, $config] = smoke_cron_mock_prepend($callsPath, false);
$env = ['SYNCRO_ROOT_ASSET_INTAKE_CRON_LOCK_PATH' => $lockPath, 'SYNCRO_ROOT_ASSET_INTAKE_CRON_LOCK_STALE_SECONDS' => '60'];
$dryRun = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
$calls = json_decode((string)@file_get_contents($callsPath), true) ?: [];
smoke_cron_check(($dryRun['exit_code'] ?? null) === 0, 'Dry-run default should complete successfully', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'in dry-run mode'), 'Dry-run default should print dry-run mode', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'SKIP no syncro_customer_id'), 'Clients without Syncro customer ID should be skipped', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'SKIP incomplete Syncro folder map'), 'Clients without complete folder map should be skipped', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'policy_assignment_status=PENDING_MANUAL'), 'Clients without READY policy assignment should be reported clearly', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'Ready Client LLC (#9): customer #109 scanning'), 'Scheduled scan should include ACTIVE synced clients with READY folder/policy assignment', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'Pre Activation LLC (#10): customer #110 scanning'), 'Scheduled scan should include pre-activation clients with READY folder map and no ACTIVE contract', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '[DRY_RUN_READY] #101'), 'Supported workstation root asset should be routed in dry-run', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '[DRY_RUN_READY] #102'), 'Supported server root asset should be routed in dry-run', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '"MMIT Service Tier":"Protect"'), 'Dry-run output should include MMIT Service Tier in onboarding fields', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), 'API field value sources') && str_contains((string)($dryRun['stdout'] ?? ''), 'MMIT Service Tier'), 'Dry-run output should show best-effort Service Tier field value source metadata', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '"api_value":"Protect IT"') && str_contains((string)($dryRun['stdout'] ?? ''), '"used_option_id":false'), 'Dry-run output should show Service Tier Protect resolved to the writable dropdown label', $failed);
smoke_cron_check(substr_count((string)($dryRun['stdout'] ?? ''), '[MANUAL_REVIEW]') === 6, 'macOS/Linux/unknown root assets should remain manual review', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '[UNCHANGED_DEPLOY] #106'), 'Deploy assets should be skipped', $failed);
smoke_cron_check(str_contains((string)($dryRun['stdout'] ?? ''), '[UNCHANGED_PRODUCTION] #107'), 'Production assets should be skipped', $failed);
smoke_cron_check(!array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'PUT' || ($call['method'] ?? '') === 'DELETE'), 'Dry-run should not issue writes or DELETE calls', $failed);

$clientOnly = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--client-id=10'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
smoke_cron_check(($clientOnly['exit_code'] ?? null) === 0 && str_contains((string)($clientOnly['stdout'] ?? ''), '1 clients scanned') && str_contains((string)($clientOnly['stdout'] ?? ''), 'Pre Activation LLC (#10): customer #110 scanning'), '--client-id should include eligible pre-activation clients without ACTIVE contracts', $failed);

$clientNoSyncro = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--client-id=7'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
smoke_cron_check(($clientNoSyncro['exit_code'] ?? null) === 0 && str_contains((string)($clientNoSyncro['stdout'] ?? ''), 'SKIP no syncro_customer_id'), '--client-id should retain the no Syncro customer ID skip message', $failed);

$clientIncompleteMap = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--client-id=8'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
smoke_cron_check(($clientIncompleteMap['exit_code'] ?? null) === 0 && str_contains((string)($clientIncompleteMap['stdout'] ?? ''), 'SKIP incomplete Syncro folder map'), '--client-id should retain the incomplete folder map skip message', $failed);

$limited = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--limit=2'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
smoke_cron_check(($limited['exit_code'] ?? null) === 0 && str_contains((string)($limited['stdout'] ?? ''), '2 clients scanned') && !str_contains((string)($limited['stdout'] ?? ''), 'Ready Client LLC: customer'), '--limit should cap scanned clients', $failed);

$stagingBlocked = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--client-id=9', '--apply'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
smoke_cron_check(($stagingBlocked['exit_code'] ?? null) === 0, 'Explicit apply with staging default should complete with blocked writes surfaced but not fail cron', $failed);
smoke_cron_check(str_contains((string)($stagingBlocked['stdout'] ?? ''), 'OPS staging Syncro writes are blocked by default.'), 'Apply mode should print staging default guard status', $failed);
smoke_cron_check(str_contains((string)($stagingBlocked['stdout'] ?? ''), '[STAGING_BLOCKED]'), 'Staging default should block controlled PUT writes', $failed);

@unlink($prepend);
@unlink($config);
@unlink($callsPath);
@unlink($lockPath);

$callsPath = smoke_cron_temp_path('cron-calls-allow-');
$lockPath = smoke_cron_temp_path('cron-run-lock-allow-');
@unlink($lockPath);
[$prepend, $config] = smoke_cron_mock_prepend($callsPath, true);
$env['SYNCRO_ROOT_ASSET_INTAKE_CRON_LOCK_PATH'] = $lockPath;
$apply = smoke_cron_cli(['--host=ops-test.midwestmanagedit.com', '--client-id=9', '--apply'], $env, ['-d', 'auto_prepend_file=' . $prepend]);
$calls = json_decode((string)@file_get_contents($callsPath), true) ?: [];
smoke_cron_check(($apply['exit_code'] ?? null) === 0, 'Explicit apply with staging override should complete successfully', $failed);
smoke_cron_check(str_contains((string)($apply['stdout'] ?? ''), 'WARNING: OPS staging Syncro POST/PUT/PATCH writes are enabled'), 'Staging override should print controlled write warning', $failed);
smoke_cron_check(str_contains((string)($apply['stdout'] ?? ''), '[MOVED] #101') && str_contains((string)($apply['stdout'] ?? ''), '[MOVED] #102'), 'Staging override should allow controlled PUT moves', $failed);
smoke_cron_check(count(array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'PUT')) === 6, 'Apply override should issue expected required-field, optional Service Tier, and move PUT calls', $failed);
$applyFieldCalls = array_values(array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'PUT' && isset($call['payload']['properties'])));
smoke_cron_check(count($applyFieldCalls) === 4, 'Apply override should stamp required onboarding fields and best-effort Service Tier fields before folder moves', $failed);
smoke_cron_check(!array_key_exists('MMIT Service Tier', $applyFieldCalls[0]['payload']['properties'] ?? []) && (($applyFieldCalls[1]['payload']['properties']['MMIT Service Tier'] ?? null) === 'Protect IT'), 'Apply override should write Service Tier Protect writable dropdown label as a separate best-effort field payload', $failed);
smoke_cron_check(($applyFieldCalls[0]['payload']['properties']['MMIT Onboarding Status'] ?? null) === 'NOT_READY', 'Apply override required field payload should preserve onboarding status NOT_READY', $failed);
smoke_cron_check(array_key_exists('MMIT Asset Role', $applyFieldCalls[0]['payload']['properties'] ?? []), 'Apply override required field payload should preserve Asset Role field', $failed);
smoke_cron_check(!array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'DELETE'), 'Cron runner should never issue DELETE calls', $failed);

@unlink($prepend);
@unlink($config);
@unlink($callsPath);
@unlink($lockPath);

if ($failed) {
    fwrite(STDERR, 'Syncro root asset intake cron smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro root asset intake cron smoke check passed.' . PHP_EOL;
