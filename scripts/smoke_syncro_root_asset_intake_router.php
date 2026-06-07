<?php
declare(strict_types=1);

// Smoke checks for Syncro root asset intake routing V1. This intentionally
// avoids bootstrap/config secrets, database writes, and external Syncro calls.
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('MMIT_SYNCRO_SERVICE_TIER_OPTION_IDS', '{"Manage":"2001","Protect":"2002","Govern":"2003"}');

$GLOBALS['smoke_syncro_staging_mode'] = false;
function ops_is_staging_env(): bool
{
    return !empty($GLOBALS['smoke_syncro_staging_mode']);
}

require_once __DIR__ . '/../inc/syncro.php';

$GLOBALS['syncro_custom_field_option_definitions_mock'] = [];

$failed = [];
$folderMap = [
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
$rootFolderId = 4955419;
$manageClient = ['service_tier' => 'Manage', 'legal_name' => 'Acme Manage'];
$manageDnsClient = ['service_tier' => 'Manage', 'legal_name' => 'Acme DNS', 'services' => [['item_code' => 'DNS-FLTR', 'item_name' => 'DNS Filtering', 'description' => 'ScoutDNS filtering add-on', 'status' => 'PAUSED']]];
$manageBackupClient = ['service_tier' => 'Manage', 'legal_name' => 'Acme Backup', 'services' => [['item_code' => 'EP-BKUP', 'item_name' => 'Endpoint Backup', 'description' => 'Workstation backup add-on', 'status' => 'ACTIVE']]];
$protectClient = ['service_tier' => 'Protect', 'legal_name' => 'Acme Protect'];
$protectServerBackupClient = ['service_tier' => 'Protect', 'legal_name' => 'Acme Protect Servers', 'services' => [['item_code' => 'SRVR-BK-500', 'item_name' => 'Server Backup 500GB', 'description' => 'Server backup add-on', 'status' => 'PAUSED']]];
$governClient = ['service_tier' => 'Govern', 'legal_name' => 'Acme Govern'];

function smoke_router_cli(array $args, array $env = [], array $phpOptions = []): array
{
    $command = array_merge([PHP_BINARY], $phpOptions, [__DIR__ . '/syncro_root_asset_intake_router.php'], $args);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, dirname(__DIR__), $env ? array_merge($_ENV, $env) : null);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Unable to start router CLI process.'];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}


function smoke_router_temp_path(string $name): string
{
    $path = tempnam(sys_get_temp_dir(), $name);
    if ($path === false) {
        fwrite(STDERR, 'Unable to create temporary smoke file.' . PHP_EOL);
        exit(1);
    }
    return $path;
}

function smoke_router_mock_prepend(string $serverCapturePath, string $configServerCapturePath): array
{
    $configPath = smoke_router_temp_path('router-config-');
    $prependPath = smoke_router_temp_path('router-prepend-');

    $configCode = <<<'PHP'
<?php
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SESSION_NAME', 'MMIT_OPS_SMOKE');
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
define('MFA_TOTP_PERIOD', 30);
define('MFA_TOTP_DIGITS', 6);
define('MFA_TOTP_ISSUER', 'MMIT OPS Smoke');
PHP;
    $configCode .= "\nfile_put_contents(" . var_export($configServerCapturePath, true) . ", json_encode([\n"
        . "    'HTTP_HOST' => \$_SERVER['HTTP_HOST'] ?? null,\n"
        . "    'SERVER_NAME' => \$_SERVER['SERVER_NAME'] ?? null,\n"
        . "    'HTTPS' => \$_SERVER['HTTPS'] ?? null,\n"
        . "    'REQUEST_URI' => \$_SERVER['REQUEST_URI'] ?? null,\n"
        . "]));\n";
    file_put_contents($configPath, $configCode);

    $prependCode = "<?php\n"
        . "define('OPS_CONFIG_FILE', " . var_export($configPath, true) . ");\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    file_put_contents(" . var_export($serverCapturePath, true) . ", json_encode([\n"
        . "        'HTTP_HOST' => \$_SERVER['HTTP_HOST'] ?? null,\n"
        . "        'SERVER_NAME' => \$_SERVER['SERVER_NAME'] ?? null,\n"
        . "        'HTTPS' => \$_SERVER['HTTPS'] ?? null,\n"
        . "        'REQUEST_URI' => \$_SERVER['REQUEST_URI'] ?? null,\n"
        . "    ]));\n"
        . "});\n"
        . "\$GLOBALS['syncro_api_request_mock'] = static function (string \$method, string \$path, array \$query, ?array \$payload): array {\n"
        . "    if (\$method === 'GET' && \$path === 'customer_assets') {\n"
        . "        return ['ok' => true, 'status' => 200, 'data' => ['customer_assets' => []], 'request' => ['method' => \$method, 'path' => '/api/v1/customer_assets']];\n"
        . "    }\n"
        . "    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected mock request ' . \$method . ' ' . \$path]];\n"
        . "};\n";
    file_put_contents($prependPath, $prependCode);

    return [$prependPath, $configPath];
}

function smoke_check(bool $condition, string $message, array &$failed): void
{
    if (!$condition) {
        $failed[] = $message;
    }
}

function smoke_contains_value(mixed $value, mixed $needle): bool
{
    if (is_array($value)) {
        foreach ($value as $item) {
            if (smoke_contains_value($item, $needle)) {
                return true;
            }
        }
        return false;
    }
    return $value === $needle;
}


function smoke_field_payload_has_exact_keys(array $payload, array $required, array &$failed, string $label): void
{
    $keys = array_keys($payload);
    foreach ($required as $field) {
        smoke_check(array_key_exists($field, $payload), $label . ' should include ' . $field, $failed);
        smoke_check(in_array($field, $keys, true), $label . ' payload keys should list ' . $field, $failed);
    }
    smoke_check(!array_key_exists('MIMIT Service Tier', $payload), $label . ' should not emit typo MIMIT Service Tier', $failed);
    smoke_check(!array_key_exists('MITT Service Tier', $payload), $label . ' should not emit typo MITT Service Tier', $failed);
}

function smoke_asset(int $id, string $name, string $os, int $folderId): array
{
    return ['id' => $id, 'name' => $name, 'os' => $os, 'policy_folder_id' => $folderId];
}

$helpCli = smoke_router_cli(['--help']);
smoke_check(($helpCli['exit_code'] ?? null) === 0, 'Router --help should exit 0', $failed);
smoke_check(str_contains((string)($helpCli['stdout'] ?? ''), 'Usage: php scripts/syncro_root_asset_intake_router.php'), 'Router --help should print usage to STDOUT', $failed);
smoke_check((string)($helpCli['stderr'] ?? '') === '', 'Router --help should not print STDERR output', $failed);

$missingArgsCli = smoke_router_cli([]);
smoke_check(($missingArgsCli['exit_code'] ?? null) === 1, 'Router with missing required arguments should exit 1', $failed);
smoke_check(str_contains((string)($missingArgsCli['stderr'] ?? ''), 'Either --client-id or --customer-id is required.'), 'Router with missing required arguments should print a clear STDERR error', $failed);
smoke_check(str_contains((string)($missingArgsCli['stderr'] ?? ''), 'Usage: php scripts/syncro_root_asset_intake_router.php'), 'Router with missing required arguments should print usage to STDERR', $failed);
smoke_check((string)($missingArgsCli['stdout'] ?? '') === '', 'Router with missing required arguments should not print STDOUT output', $failed);

$validRouterArgs = [
    '--customer-id=123',
    '--root-folder-id=' . (string)$rootFolderId,
    '--deploy-workstations-folder-id=5029833',
    '--deploy-servers-folder-id=5029834',
    '--production-workstations-folder-id=5029835',
    '--production-servers-folder-id=5029836',
];

$hostServerCapture = smoke_router_temp_path('router-server-host-');
$hostConfigCapture = smoke_router_temp_path('router-config-server-host-');
[$hostPrepend, $hostConfig] = smoke_router_mock_prepend($hostServerCapture, $hostConfigCapture);
$hostCli = smoke_router_cli(array_merge($validRouterArgs, ['--host=ops-test.midwestmanagedit.com']), [], ['-d', 'auto_prepend_file=' . $hostPrepend]);
$hostServer = json_decode((string)@file_get_contents($hostServerCapture), true) ?: [];
$hostConfigServer = json_decode((string)@file_get_contents($hostConfigCapture), true) ?: [];
@unlink($hostPrepend);
@unlink($hostConfig);
@unlink($hostServerCapture);
@unlink($hostConfigCapture);
smoke_check(($hostCli['exit_code'] ?? null) === 0, 'Router should accept --host with valid direct folder arguments', $failed);
smoke_check(str_contains((string)($hostCli['stdout'] ?? ''), 'Syncro root asset intake router V1 starting for customer #123 root folder #' . (string)$rootFolderId . ' in dry-run mode.'), 'Router --host invocation should print startup line before asset intake', $failed);
smoke_check(($hostServer['HTTP_HOST'] ?? null) === 'ops-test.midwestmanagedit.com', 'Router --host should initialize HTTP_HOST', $failed);
smoke_check(($hostServer['SERVER_NAME'] ?? null) === 'ops-test.midwestmanagedit.com', 'Router --host should initialize SERVER_NAME', $failed);
smoke_check(($hostServer['HTTPS'] ?? null) === 'on', 'Router --host should initialize HTTPS', $failed);
smoke_check(($hostServer['REQUEST_URI'] ?? null) === '/', 'Router --host should initialize REQUEST_URI', $failed);
smoke_check($hostConfigServer === $hostServer, 'Router should initialize CLI-safe server values before app bootstrap', $failed);

$envServerCapture = smoke_router_temp_path('router-server-env-');
$envConfigCapture = smoke_router_temp_path('router-config-server-env-');
[$envPrepend, $envConfig] = smoke_router_mock_prepend($envServerCapture, $envConfigCapture);
$envCli = smoke_router_cli($validRouterArgs, ['MMIT_CLI_HTTP_HOST' => 'ops-test.midwestmanagedit.com'], ['-d', 'auto_prepend_file=' . $envPrepend]);
$envServer = json_decode((string)@file_get_contents($envServerCapture), true) ?: [];
@unlink($envPrepend);
@unlink($envConfig);
@unlink($envServerCapture);
@unlink($envConfigCapture);
smoke_check(($envCli['exit_code'] ?? null) === 0, 'Router should accept MMIT_CLI_HTTP_HOST with valid direct folder arguments', $failed);
smoke_check(str_contains((string)($envCli['stdout'] ?? ''), 'Syncro root asset intake router V1 starting for customer #123 root folder #' . (string)$rootFolderId . ' in dry-run mode.'), 'Router MMIT_CLI_HTTP_HOST invocation should print startup line', $failed);
smoke_check(($envServer['HTTP_HOST'] ?? null) === 'ops-test.midwestmanagedit.com', 'Router MMIT_CLI_HTTP_HOST should initialize HTTP_HOST', $failed);

$applyServerCapture = smoke_router_temp_path('router-server-apply-');
$applyConfigCapture = smoke_router_temp_path('router-config-server-apply-');
[$applyPrepend, $applyConfig] = smoke_router_mock_prepend($applyServerCapture, $applyConfigCapture);
$applyCli = smoke_router_cli(array_merge($validRouterArgs, ['--host=ops-test.midwestmanagedit.com', '--apply']), [], ['-d', 'auto_prepend_file=' . $applyPrepend]);
@unlink($applyPrepend);
@unlink($applyConfig);
@unlink($applyServerCapture);
@unlink($applyConfigCapture);
smoke_check(($applyCli['exit_code'] ?? null) === 0, 'Router apply mode should run with mocked empty assets', $failed);
smoke_check(str_contains((string)($applyCli['stdout'] ?? ''), 'Syncro root asset intake router V1 starting for customer #123 root folder #' . (string)$rootFolderId . ' in apply mode.'), 'Router apply mode should print startup line', $failed);
smoke_check(str_contains((string)($applyCli['stdout'] ?? ''), 'OPS staging Syncro writes are blocked by default.'), 'Router apply mode should keep staging write guard status output', $failed);

$windows11 = syncro_route_root_asset_intake(smoke_asset(101, 'WIN11-ROOT', 'Microsoft Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($windows11['status'] ?? null) === 'DRY_RUN_READY', 'Windows 11 root asset should be dry-run ready', $failed);
smoke_check(($windows11['target_policy_folder_id'] ?? null) === 5029833, 'Windows 11 root asset should route to Deploy / Workstations', $failed);
smoke_check(($windows11['classification']['platform'] ?? null) === 'windows' && ($windows11['classification']['role'] ?? null) === 'workstation', 'Windows 11 classification should be windows workstation', $failed);

$requiredOnboardingFields = syncro_required_asset_onboarding_field_names();
smoke_field_payload_has_exact_keys((array)($windows11['field_update_payload']['properties'] ?? []), $requiredOnboardingFields, $failed, 'Manage dry-run API field payload');
smoke_check(($windows11['field_update_payload']['properties']['MMIT Service Tier'] ?? null) === '2001', 'Dry-run API field payload should stamp Service Tier Manage option ID', $failed);
smoke_check(in_array('MMIT Service Tier', (array)($windows11['field_update_payload_keys'] ?? []), true), 'Dry-run API field payload keys should show MMIT Service Tier', $failed);

smoke_check(($windows11['onboarding_fields']['MMIT Service Tier'] ?? null) === 'Manage', 'Manage workstation should stamp service tier Manage', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT Asset Role'] ?? null) === 'Workstation', 'Manage workstation should stamp role Workstation', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT DNS Filtering Required'] ?? null) === 'No', 'Manage workstation without DNS add-on should not require DNS', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT Backup Required'] ?? null) === 'No', 'Manage workstation without backup add-on should not require backup', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT Production Folder Target'] ?? null) === 'Production / Workstations', 'Workstation should target Production / Workstations', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT Onboarding Status'] ?? null) === 'NOT_READY', 'Intake payload should stamp onboarding status NOT_READY', $failed);
smoke_check(($windows11['onboarding_fields']['MMIT Ready To Move'] ?? null) === 'No', 'Intake payload should keep Ready To Move No', $failed);
smoke_check(str_contains((string)($windows11['onboarding_fields']['MMIT Onboarding Result'] ?? ''), 'mode=dry-run would stamp and move'), 'Dry-run onboarding result should include mode detail', $failed);
smoke_check(($windows11['field_update_payload']['properties']['MMIT Onboarding Status'] ?? null) === 'NOT_READY', 'Dry-run API field payload should stamp onboarding status NOT_READY', $failed);
smoke_check(($windows11['field_update_payload']['properties']['MMIT Backup Required'] ?? null) === false, 'API field payload should use boolean false for Backup Required checkbox', $failed);
smoke_check(($windows11['field_update_payload']['properties']['MMIT DNS Filtering Required'] ?? null) === false, 'API field payload should use boolean false for DNS Filtering Required checkbox', $failed);
smoke_check(($windows11['field_update_payload']['properties']['MMIT Lab Asset'] ?? null) === false, 'API field payload should use boolean false for Lab Asset checkbox', $failed);
smoke_check(!smoke_contains_value($windows11, 'IN_PROGRESS'), 'No dry-run intake payload value should use IN_PROGRESS onboarding status', $failed);

$manageDns = syncro_route_root_asset_intake(smoke_asset(110, 'WIN11-DNS', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, true, $manageDnsClient);
smoke_check(($manageDns['onboarding_fields']['MMIT DNS Filtering Required'] ?? null) === 'Yes', 'Manage workstation with DNS add-on should require DNS', $failed);

$manageBackup = syncro_route_root_asset_intake(smoke_asset(111, 'WIN11-BACKUP', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, true, $manageBackupClient);
smoke_check(($manageBackup['onboarding_fields']['MMIT Backup Required'] ?? null) === 'Yes', 'Manage workstation with backup add-on should require backup', $failed);

$protectWorkstation = syncro_route_root_asset_intake(smoke_asset(112, 'WIN11-PROTECT', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, true, $protectClient);
smoke_check(($protectWorkstation['onboarding_fields']['MMIT Service Tier'] ?? null) === 'Protect', 'Protect workstation should stamp Service Tier Protect', $failed);
smoke_check(($protectWorkstation['field_update_payload']['properties']['MMIT Service Tier'] ?? null) === '2002', 'Protect API field payload should stamp Service Tier Protect option ID', $failed);
smoke_field_payload_has_exact_keys((array)($protectWorkstation['field_update_payload']['properties'] ?? []), $requiredOnboardingFields, $failed, 'Protect dry-run API field payload');
smoke_check(($protectWorkstation['onboarding_fields']['MMIT DNS Filtering Required'] ?? null) === 'Yes', 'Protect workstation should require DNS', $failed);
smoke_check(($protectWorkstation['onboarding_fields']['MMIT Backup Required'] ?? null) === 'Yes', 'Protect workstation should require workstation backup by package rule', $failed);

$protectServerNoBackup = syncro_route_root_asset_intake(smoke_asset(113, 'SRV-NOBACKUP', 'Windows Server 2022 Standard', $rootFolderId), $folderMap, $rootFolderId, true, $protectClient);
smoke_check(($protectServerNoBackup['onboarding_fields']['MMIT Asset Role'] ?? null) === 'Server', 'Protect server should stamp role Server', $failed);
smoke_check(($protectServerNoBackup['onboarding_fields']['MMIT DNS Filtering Required'] ?? null) === 'Yes', 'Protect server should require DNS', $failed);
smoke_check(($protectServerNoBackup['onboarding_fields']['MMIT Backup Required'] ?? null) === 'No', 'Protect server without server backup add-on should not require backup', $failed);
smoke_check(($protectServerNoBackup['onboarding_fields']['MMIT Production Folder Target'] ?? null) === 'Production / Servers', 'Server should target Production / Servers', $failed);

$governServer = syncro_route_root_asset_intake(smoke_asset(114, 'SRV-GOVERN', 'Windows Server 2022 Standard', $rootFolderId), $folderMap, $rootFolderId, true, $governClient);
smoke_check(($governServer['onboarding_fields']['MMIT Service Tier'] ?? null) === 'Govern', 'Govern server should stamp Service Tier Govern', $failed);
smoke_check(($governServer['field_update_payload']['properties']['MMIT Service Tier'] ?? null) === '2003', 'Govern API field payload should stamp Service Tier Govern option ID', $failed);
smoke_field_payload_has_exact_keys((array)($governServer['field_update_payload']['properties'] ?? []), $requiredOnboardingFields, $failed, 'Govern dry-run API field payload');
smoke_check(($governServer['onboarding_fields']['MMIT DNS Filtering Required'] ?? null) === 'Yes', 'Govern server should require DNS', $failed);
smoke_check(($governServer['onboarding_fields']['MMIT Backup Required'] ?? null) === 'Yes', 'Govern server should require backup', $failed);

$server = syncro_route_root_asset_intake(smoke_asset(102, 'SRV-ROOT', 'Windows Server 2022 Standard', $rootFolderId), $folderMap, $rootFolderId, true, $protectServerBackupClient);
smoke_check(($server['status'] ?? null) === 'DRY_RUN_READY', 'Windows Server root asset should be dry-run ready', $failed);
smoke_check(($server['target_policy_folder_id'] ?? null) === 5029834, 'Windows Server root asset should route to Deploy / Servers', $failed);
smoke_check(($server['classification']['platform'] ?? null) === 'windows' && ($server['classification']['role'] ?? null) === 'server', 'Windows Server classification should be windows server', $failed);

smoke_check(($server['onboarding_fields']['MMIT Backup Required'] ?? null) === 'Yes', 'Protect server with Server Backup add-on should require backup', $failed);
smoke_check(($server['onboarding_fields']['MMIT Production Folder Target'] ?? null) === 'Production / Servers', 'Protect server with Server Backup add-on should target Production / Servers', $failed);

$mac = syncro_route_root_asset_intake(smoke_asset(103, 'MAC-ROOT', 'macOS Sonoma 14.5', $rootFolderId), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($mac['status'] ?? null) === 'MANUAL_REVIEW', 'macOS root asset should remain unmoved for manual review', $failed);
smoke_check(($mac['action'] ?? null) === 'manual_review' && ($mac['classification']['platform'] ?? null) === 'macos', 'macOS classification/manual review action', $failed);

$linux = syncro_route_root_asset_intake(smoke_asset(104, 'LINUX-ROOT', 'Ubuntu Linux Server 24.04', $rootFolderId), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($linux['status'] ?? null) === 'MANUAL_REVIEW', 'Linux root asset should remain unmoved for manual review', $failed);
smoke_check(($linux['classification']['platform'] ?? null) === 'linux' && ($linux['classification']['role'] ?? null) === 'server', 'Linux server classification should be structured but not actionable', $failed);

$unknown = syncro_route_root_asset_intake(smoke_asset(105, 'UNKNOWN-ROOT', '', $rootFolderId), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($unknown['status'] ?? null) === 'MANUAL_REVIEW', 'Unknown/blank OS should remain unmoved for manual review', $failed);
smoke_check(($unknown['classification']['platform'] ?? null) === 'unknown', 'Blank OS classification should be unknown', $failed);

$alreadyDeploy = syncro_route_root_asset_intake(smoke_asset(106, 'WIN11-DEPLOY', 'Windows 11 Pro', 5029833), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($alreadyDeploy['status'] ?? null) === 'UNCHANGED_DEPLOY', 'Asset already in Deploy should remain unchanged', $failed);

$alreadyProduction = syncro_route_root_asset_intake(smoke_asset(107, 'WIN11-PROD', 'Windows 11 Pro', 5029835), $folderMap, $rootFolderId, true, $manageClient);
smoke_check(($alreadyProduction['status'] ?? null) === 'UNCHANGED_PRODUCTION', 'Asset already in Production should remain unchanged', $failed);

$calls = [];
$stampedProperties = [];
$GLOBALS['syncro_custom_field_option_definitions_mock'] = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$calls, &$stampedProperties): array {
    $calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'PUT' && $path === 'customer_assets/108' && isset($payload['properties'])) {
        $stampedProperties = (array)$payload['properties'];
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 108]], 'request' => ['method' => 'PUT', 'path' => '/api/v1/customer_assets/108']];
    }
    if ($method === 'GET' && $path === 'customer_assets/108') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 108, 'properties' => $stampedProperties]], 'request' => ['method' => 'GET', 'path' => '/api/v1/customer_assets/108']];
    }
    if ($method === 'PUT' && $path === 'customer_assets/108' && ($payload['policy_folder_id'] ?? null) === 5029833) {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 108, 'policy_folder_id' => 5029833]], 'request' => ['method' => 'PUT', 'path' => '/api/v1/customer_assets/108']];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
};
$apply = syncro_route_root_asset_intake(smoke_asset(108, 'WIN11-APPLY', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, $manageClient);
unset($GLOBALS['syncro_api_request_mock']);
smoke_check(($apply['status'] ?? null) === 'MOVED', 'Apply mode should move supported Windows root asset', $failed);
smoke_check(count($calls) === 3 && isset($calls[0]['payload']['properties']) && ($calls[1]['method'] ?? null) === 'GET' && isset($calls[2]['payload']['policy_folder_id']), 'Apply mode should use PUT fields, GET verification, then PUT folder move', $failed);
smoke_check(isset($calls[0]['payload']['properties']) && ($calls[1]['method'] ?? null) === 'GET' && isset($calls[2]['payload']['policy_folder_id']), 'Apply mode should stamp fields and verify persistence before moving', $failed);
smoke_check(!empty($apply['field_persistence']['ok']), 'Apply mode should expose successful required field persistence status', $failed);
smoke_field_payload_has_exact_keys((array)($calls[0]['payload']['properties'] ?? []), $requiredOnboardingFields, $failed, 'Apply API field payload');
smoke_check(($calls[0]['payload']['properties']['MMIT Service Tier'] ?? null) === '2001', 'Apply field stamp should write Service Tier Manage option ID', $failed);
smoke_check(($apply['field_update_payload']['properties']['MMIT Service Tier'] ?? null) === '2001', 'Apply result should expose Service Tier Manage option ID in field update payload', $failed);
smoke_check(in_array('MMIT Service Tier', (array)($apply['field_update_payload_keys'] ?? []), true), 'Apply result should expose MMIT Service Tier in API field payload keys', $failed);
smoke_check(($calls[0]['payload']['properties']['MMIT Onboarding Status'] ?? null) === 'NOT_READY', 'Apply field stamp should use onboarding status NOT_READY', $failed);
smoke_check(($calls[0]['payload']['properties']['MMIT Ready To Move'] ?? null) === 'No', 'Apply field stamp should keep Ready To Move No', $failed);
smoke_check(str_contains((string)($calls[0]['payload']['properties']['MMIT Onboarding Result'] ?? ''), 'mode=apply stamping before move'), 'Apply onboarding result should include mode detail', $failed);
smoke_check(($calls[0]['payload']['properties']['MMIT Backup Required'] ?? null) === false, 'Apply field stamp should use boolean false for Backup Required checkbox', $failed);
smoke_check(($calls[0]['payload']['properties']['MMIT DNS Filtering Required'] ?? null) === false, 'Apply field stamp should use boolean false for DNS Filtering Required checkbox', $failed);
smoke_check(($calls[0]['payload']['properties']['MMIT Lab Asset'] ?? null) === false, 'Apply field stamp should use boolean false for Lab Asset checkbox', $failed);
smoke_check(!smoke_contains_value($calls, 'IN_PROGRESS'), 'No apply payload should use IN_PROGRESS onboarding status', $failed);
smoke_check(!array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'DELETE'), 'Router should not issue DELETE calls', $failed);

$failureCalls = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$failureCalls): array {
    $failureCalls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'PUT' && $path === 'customer_assets/115' && isset($payload['properties'])) {
        return ['ok' => false, 'status' => 422, 'errors' => ['field write rejected']];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected request after failed stamp']];
};
$fieldFailure = syncro_route_root_asset_intake(smoke_asset(115, 'WIN11-FIELD-FAIL', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, $manageClient);
unset($GLOBALS['syncro_api_request_mock']);
smoke_check(($fieldFailure['status'] ?? null) === 'FIELD_STAMP_FAILED', 'Field stamping failure should prevent move', $failed);
smoke_check(count($failureCalls) === 1 && isset($failureCalls[0]['payload']['properties']), 'Field stamping failure should not call folder move', $failed);
smoke_check(($failureCalls[0]['payload']['properties']['MMIT Service Tier'] ?? null) === '2001', 'Failed field stamp payload should include required Service Tier Manage option ID', $failed);
smoke_check(($failureCalls[0]['payload']['properties']['MMIT Onboarding Status'] ?? null) === 'NOT_READY', 'Failed field stamp payload should still use onboarding status NOT_READY', $failed);
smoke_check(!smoke_contains_value($failureCalls, 'IN_PROGRESS'), 'No failed field-stamp payload should use IN_PROGRESS onboarding status', $failed);

$persistenceFailureCalls = [];
$persistenceProperties = [];
$GLOBALS['syncro_custom_field_option_definitions_mock'] = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$persistenceFailureCalls, &$persistenceProperties): array {
    $persistenceFailureCalls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'PUT' && $path === 'customer_assets/117' && isset($payload['properties'])) {
        $persistenceProperties = (array)$payload['properties'];
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 117]]];
    }
    if ($method === 'GET' && $path === 'customer_assets/117') {
        unset($persistenceProperties['MMIT Service Tier']);
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 117, 'properties' => $persistenceProperties]]];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected request after persistence failure']];
};
$persistenceFailure = syncro_route_root_asset_intake(smoke_asset(117, 'WIN11-PERSIST-FAIL', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, $manageClient);
unset($GLOBALS['syncro_api_request_mock']);
smoke_check(($persistenceFailure['status'] ?? null) === 'FIELD_STAMP_FAILED', 'Field persistence verification should fail when Service Tier is omitted by Syncro', $failed);
smoke_check(count($persistenceFailureCalls) === 2 && ($persistenceFailureCalls[0]['method'] ?? null) === 'PUT' && ($persistenceFailureCalls[1]['method'] ?? null) === 'GET', 'Persistence failure should stamp and verify but not move', $failed);
smoke_check(in_array('MMIT Service Tier', (array)($persistenceFailure['field_persistence']['missing'] ?? []), true), 'Persistence failure should name missing Service Tier', $failed);

$serviceTierOptionMap = [syncro_normalize_match_text('MMIT Service Tier') => ['2001' => 'Manage', '2002' => 'Protect', '2003' => 'Govern']];
foreach ([['client' => $protectClient, 'asset_id' => 118, 'label' => 'Protect', 'id' => '2002'], ['client' => $manageClient, 'asset_id' => 119, 'label' => 'Manage', 'id' => '2001'], ['client' => $governClient, 'asset_id' => 120, 'label' => 'Govern', 'id' => '2003']] as $case) {
    $optionCalls = [];
    $optionProperties = [];
    $GLOBALS['syncro_custom_field_option_definitions_mock'] = $serviceTierOptionMap;
    $GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$optionCalls, &$optionProperties, $case): array {
        $optionCalls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
        $assetPath = 'customer_assets/' . (string)$case['asset_id'];
        if ($method === 'PUT' && $path === $assetPath && isset($payload['properties'])) {
            $optionProperties = (array)$payload['properties'];
            return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => $case['asset_id']]]];
        }
        if ($method === 'GET' && $path === $assetPath) {
            $persisted = $optionProperties;
            $persisted['MMIT Service Tier'] = $case['id'];
            return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => $case['asset_id'], 'properties' => $persisted]]];
        }
        if ($method === 'PUT' && $path === $assetPath && isset($payload['policy_folder_id'])) {
            return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => $case['asset_id'], 'policy_folder_id' => $payload['policy_folder_id']]]];
        }
        return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected option case request']];
    };
    $optionResult = syncro_route_root_asset_intake(smoke_asset((int)$case['asset_id'], 'WIN11-' . strtoupper((string)$case['label']), 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, (array)$case['client']);
    unset($GLOBALS['syncro_api_request_mock']);
    smoke_check(($optionResult['status'] ?? null) === 'MOVED', (string)$case['label'] . ' Service Tier option ID should verify and move', $failed);
    smoke_check(($optionCalls[0]['payload']['properties']['MMIT Service Tier'] ?? null) === $case['id'], (string)$case['label'] . ' Service Tier should stamp as dropdown option ID', $failed);
    smoke_check(($optionResult['field_persistence']['required']['MMIT Service Tier']['persisted_display'] ?? null) === $case['label'], (string)$case['label'] . ' Service Tier persisted option ID should resolve to display label', $failed);
}
$GLOBALS['syncro_custom_field_option_definitions_mock'] = [];

$omissionCalls = [];
$GLOBALS['syncro_root_asset_intake_field_payload_filter'] = static function (array $fields): array {
    unset($fields['MMIT Service Tier']);
    return $fields;
};
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$omissionCalls): array {
    $omissionCalls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    return ['ok' => false, 'status' => 599, 'errors' => ['No Syncro calls expected when required Service Tier is omitted.']];
};
$serviceTierOmitted = syncro_route_root_asset_intake(smoke_asset(116, 'WIN11-NO-TIER', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, $manageClient);
unset($GLOBALS['syncro_root_asset_intake_field_payload_filter'], $GLOBALS['syncro_api_request_mock']);
smoke_check(($serviceTierOmitted['status'] ?? null) === 'FIELD_STAMP_FAILED', 'Missing Service Tier field should fail stamping before move', $failed);
smoke_check(in_array('MMIT Service Tier', (array)($serviceTierOmitted['field_validation']['missing'] ?? []), true), 'Missing Service Tier should be named in field validation failure', $failed);
smoke_check($omissionCalls === [], 'Missing Service Tier should prevent Syncro field and move API calls', $failed);

$GLOBALS['smoke_syncro_staging_mode'] = true;
$stagingBlocked = syncro_route_root_asset_intake(smoke_asset(109, 'WIN11-STAGING', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false, $manageClient);
$GLOBALS['smoke_syncro_staging_mode'] = false;
smoke_check(($stagingBlocked['status'] ?? null) === 'STAGING_BLOCKED', 'Staging default should block apply write', $failed);
smoke_check(!empty($stagingBlocked['response']['staging_blocked']), 'Staging blocked response should be surfaced', $failed);

if ($failed) {
    fwrite(STDERR, 'Syncro root asset intake router smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro root asset intake router smoke check passed.' . PHP_EOL;
