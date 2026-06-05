<?php
declare(strict_types=1);

// Smoke checks for Syncro root asset intake routing V1. This intentionally
// avoids bootstrap/config secrets, database writes, and external Syncro calls.
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');

$GLOBALS['smoke_syncro_staging_mode'] = false;
function ops_is_staging_env(): bool
{
    return !empty($GLOBALS['smoke_syncro_staging_mode']);
}

require_once __DIR__ . '/../inc/syncro.php';

$failed = [];
$folderMap = [
    'deploy_workstations_folder_id' => 5029833,
    'deploy_servers_folder_id' => 5029834,
    'production_workstations_folder_id' => 5029835,
    'production_servers_folder_id' => 5029836,
];
$rootFolderId = 4955419;

function smoke_router_cli(array $args): array
{
    $command = array_merge([PHP_BINARY, __DIR__ . '/syncro_root_asset_intake_router.php'], $args);
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes);
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

function smoke_check(bool $condition, string $message, array &$failed): void
{
    if (!$condition) {
        $failed[] = $message;
    }
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

$windows11 = syncro_route_root_asset_intake(smoke_asset(101, 'WIN11-ROOT', 'Microsoft Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, true);
smoke_check(($windows11['status'] ?? null) === 'DRY_RUN_READY', 'Windows 11 root asset should be dry-run ready', $failed);
smoke_check(($windows11['target_policy_folder_id'] ?? null) === 5029833, 'Windows 11 root asset should route to Deploy / Workstations', $failed);
smoke_check(($windows11['classification']['platform'] ?? null) === 'windows' && ($windows11['classification']['role'] ?? null) === 'workstation', 'Windows 11 classification should be windows workstation', $failed);

$server = syncro_route_root_asset_intake(smoke_asset(102, 'SRV-ROOT', 'Windows Server 2022 Standard', $rootFolderId), $folderMap, $rootFolderId, true);
smoke_check(($server['status'] ?? null) === 'DRY_RUN_READY', 'Windows Server root asset should be dry-run ready', $failed);
smoke_check(($server['target_policy_folder_id'] ?? null) === 5029834, 'Windows Server root asset should route to Deploy / Servers', $failed);
smoke_check(($server['classification']['platform'] ?? null) === 'windows' && ($server['classification']['role'] ?? null) === 'server', 'Windows Server classification should be windows server', $failed);

$mac = syncro_route_root_asset_intake(smoke_asset(103, 'MAC-ROOT', 'macOS Sonoma 14.5', $rootFolderId), $folderMap, $rootFolderId, true);
smoke_check(($mac['status'] ?? null) === 'MANUAL_REVIEW', 'macOS root asset should remain unmoved for manual review', $failed);
smoke_check(($mac['action'] ?? null) === 'manual_review' && ($mac['classification']['platform'] ?? null) === 'macos', 'macOS classification/manual review action', $failed);

$linux = syncro_route_root_asset_intake(smoke_asset(104, 'LINUX-ROOT', 'Ubuntu Linux Server 24.04', $rootFolderId), $folderMap, $rootFolderId, true);
smoke_check(($linux['status'] ?? null) === 'MANUAL_REVIEW', 'Linux root asset should remain unmoved for manual review', $failed);
smoke_check(($linux['classification']['platform'] ?? null) === 'linux' && ($linux['classification']['role'] ?? null) === 'server', 'Linux server classification should be structured but not actionable', $failed);

$unknown = syncro_route_root_asset_intake(smoke_asset(105, 'UNKNOWN-ROOT', '', $rootFolderId), $folderMap, $rootFolderId, true);
smoke_check(($unknown['status'] ?? null) === 'MANUAL_REVIEW', 'Unknown/blank OS should remain unmoved for manual review', $failed);
smoke_check(($unknown['classification']['platform'] ?? null) === 'unknown', 'Blank OS classification should be unknown', $failed);

$alreadyDeploy = syncro_route_root_asset_intake(smoke_asset(106, 'WIN11-DEPLOY', 'Windows 11 Pro', 5029833), $folderMap, $rootFolderId, true);
smoke_check(($alreadyDeploy['status'] ?? null) === 'UNCHANGED_DEPLOY', 'Asset already in Deploy should remain unchanged', $failed);

$alreadyProduction = syncro_route_root_asset_intake(smoke_asset(107, 'WIN11-PROD', 'Windows 11 Pro', 5029835), $folderMap, $rootFolderId, true);
smoke_check(($alreadyProduction['status'] ?? null) === 'UNCHANGED_PRODUCTION', 'Asset already in Production should remain unchanged', $failed);

$calls = [];
$GLOBALS['syncro_api_request_mock'] = static function (string $method, string $path, array $query, ?array $payload) use (&$calls): array {
    $calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];
    if ($method === 'PUT' && $path === 'customer_assets/108' && ($payload['policy_folder_id'] ?? null) === 5029833) {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => 108, 'policy_folder_id' => 5029833]], 'request' => ['method' => 'PUT', 'path' => '/api/v1/customer_assets/108']];
    }
    return ['ok' => false, 'status' => 599, 'errors' => ['Unexpected ' . $method . ' ' . $path]];
};
$apply = syncro_route_root_asset_intake(smoke_asset(108, 'WIN11-APPLY', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false);
unset($GLOBALS['syncro_api_request_mock']);
smoke_check(($apply['status'] ?? null) === 'MOVED', 'Apply mode should move supported Windows root asset', $failed);
smoke_check(count($calls) === 1 && ($calls[0]['method'] ?? null) === 'PUT' && ($calls[0]['path'] ?? null) === 'customer_assets/108', 'Apply mode should use PUT /customer_assets/{asset_id}', $failed);
smoke_check(!array_filter($calls, static fn(array $call): bool => ($call['method'] ?? '') === 'DELETE'), 'Router should not issue DELETE calls', $failed);

$GLOBALS['smoke_syncro_staging_mode'] = true;
$stagingBlocked = syncro_route_root_asset_intake(smoke_asset(109, 'WIN11-STAGING', 'Windows 11 Pro', $rootFolderId), $folderMap, $rootFolderId, false);
$GLOBALS['smoke_syncro_staging_mode'] = false;
smoke_check(($stagingBlocked['status'] ?? null) === 'STAGING_BLOCKED', 'Staging default should block apply write', $failed);
smoke_check(!empty($stagingBlocked['response']['staging_blocked']), 'Staging blocked response should be surfaced', $failed);

if ($failed) {
    fwrite(STDERR, 'Syncro root asset intake router smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro root asset intake router smoke check passed.' . PHP_EOL;
