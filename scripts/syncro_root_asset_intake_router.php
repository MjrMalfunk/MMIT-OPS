<?php
declare(strict_types=1);

// CLI runner for Syncro root asset intake routing. Dry-run is the default.
// Apply mode requires --apply and still honors the central Syncro staging guard.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function syncro_root_asset_router_usage(): string
{
    return "Usage: php scripts/syncro_root_asset_intake_router.php --client-id=123 [--customer-id=456] [--apply]\n"
        . "       php scripts/syncro_root_asset_intake_router.php --customer-id=456 --root-folder-id=789 --deploy-workstations-folder-id=1 --deploy-servers-folder-id=2 --production-workstations-folder-id=3 --production-servers-folder-id=4 [--apply]\n\n"
        . "Default mode is dry-run. Apply mode performs PUT /api/v1/customer_assets/{asset_id}; no DELETE calls are used.\n";
}

$options = getopt('', [
    'client-id:',
    'customer-id:',
    'root-folder-id:',
    'deploy-workstations-folder-id:',
    'deploy-servers-folder-id:',
    'production-workstations-folder-id:',
    'production-servers-folder-id:',
    'apply',
    'help',
]);

if (isset($options['help'])) {
    echo syncro_root_asset_router_usage();
    exit(0);
}

function syncro_root_asset_router_option_value(array $options, string $name): ?string
{
    if (!array_key_exists($name, $options)) {
        return null;
    }
    $value = $options[$name];
    if (is_array($value)) {
        $value = end($value);
    }
    if ($value === false || $value === '') {
        return '';
    }
    return (string)$value;
}

function syncro_root_asset_router_option_int(array $options, string $name): int
{
    $value = syncro_root_asset_router_option_value($options, $name);
    return $value === null ? 0 : (int)$value;
}

$requiredValueOptions = [
    'client-id',
    'customer-id',
    'root-folder-id',
    'deploy-workstations-folder-id',
    'deploy-servers-folder-id',
    'production-workstations-folder-id',
    'production-servers-folder-id',
];
foreach ($requiredValueOptions as $requiredValueOption) {
    if (syncro_root_asset_router_option_value($options, $requiredValueOption) === '') {
        fwrite(STDERR, "Option --{$requiredValueOption} requires a value.\n" . syncro_root_asset_router_usage());
        exit(1);
    }
}

$clientId = syncro_root_asset_router_option_int($options, 'client-id');
$syncroCustomerId = syncro_root_asset_router_option_int($options, 'customer-id');

if ($clientId <= 0 && $syncroCustomerId <= 0) {
    fwrite(STDERR, "Either --client-id or --customer-id is required.\n" . syncro_root_asset_router_usage());
    exit(1);
}

if ($clientId <= 0) {
    $missingFolderOptions = [];
    foreach ([
        'deploy-workstations-folder-id',
        'deploy-servers-folder-id',
        'production-workstations-folder-id',
        'production-servers-folder-id',
    ] as $folderOption) {
        if (syncro_root_asset_router_option_int($options, $folderOption) <= 0) {
            $missingFolderOptions[] = '--' . $folderOption;
        }
    }
    if ($missingFolderOptions) {
        fwrite(STDERR, "Missing required folder option(s): " . implode(', ', $missingFolderOptions) . ".\n" . syncro_root_asset_router_usage());
        exit(1);
    }
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/syncro.php';

$dryRun = !isset($options['apply']);
$client = [];
$folderMap = [];

if ($clientId > 0) {
    $client = client_get_by_id($clientId) ?: [];
    if (!$client) {
        fwrite(STDERR, "Client #{$clientId} was not found.\n");
        exit(1);
    }
    if ($syncroCustomerId <= 0 && (int)($client['syncro_customer_id'] ?? 0) > 0) {
        $syncroCustomerId = (int)$client['syncro_customer_id'];
    }
    $folderMap = syncro_get_client_folder_map($clientId) ?: [];
}

foreach ([
    'deploy-workstations-folder-id' => 'deploy_workstations_folder_id',
    'deploy-servers-folder-id' => 'deploy_servers_folder_id',
    'production-workstations-folder-id' => 'production_workstations_folder_id',
    'production-servers-folder-id' => 'production_servers_folder_id',
] as $option => $key) {
    $folderOptionValue = syncro_root_asset_router_option_int($options, $option);
    if ($folderOptionValue > 0) {
        $folderMap[$key] = $folderOptionValue;
    }
}

if ($syncroCustomerId <= 0) {
    fwrite(STDERR, "A Syncro customer ID is required.\n" . syncro_root_asset_router_usage());
    exit(1);
}
if (!syncro_folder_map_complete($folderMap)) {
    fwrite(STDERR, "Complete Deploy/Production folder IDs are required before asset routing. No asset moves attempted.\n" . syncro_root_asset_router_usage());
    exit(1);
}

$rootFolderId = syncro_root_asset_router_option_int($options, 'root-folder-id');
if ($rootFolderId <= 0) {
    $listedFolders = syncro_list_customer_policy_folders($syncroCustomerId);
    if (empty($listedFolders['ok'])) {
        fwrite(STDERR, "Unable to list Syncro policy folders to resolve customer root: " . implode(' ', (array)($listedFolders['errors'] ?? [])) . "\n");
        exit(1);
    }
    $root = syncro_resolve_customer_root_policy_folder((array)($listedFolders['folders'] ?? []), $client);
    if (empty($root['ok'])) {
        fwrite(STDERR, (string)($root['message'] ?? 'Unable to resolve customer root policy folder.') . "\n");
        exit(1);
    }
    $rootFolderId = (int)($root['root']['id'] ?? 0);
}

$listedAssets = syncro_list_customer_assets($syncroCustomerId);
if (empty($listedAssets['ok'])) {
    fwrite(STDERR, "Unable to list Syncro customer assets: " . implode(' ', (array)($listedAssets['errors'] ?? [])) . "\n");
    exit(1);
}

printf("Syncro root asset intake router V1 starting for customer #%d root folder #%d in %s mode.\n", $syncroCustomerId, $rootFolderId, $dryRun ? 'dry-run' : 'apply');
if (!$dryRun) {
    echo syncro_staging_write_status_message() . PHP_EOL;
}

$results = [];
foreach ((array)($listedAssets['assets'] ?? []) as $asset) {
    if (!is_array($asset)) {
        continue;
    }
    $result = syncro_route_root_asset_intake($asset, $folderMap, $rootFolderId, $dryRun);
    $results[] = $result;
    printf(
        "[%s] #%d %s: %s\n",
        (string)($result['status'] ?? 'UNKNOWN'),
        (int)($result['asset_id'] ?? 0),
        (string)($result['asset_name'] ?? 'unknown asset'),
        (string)($result['message'] ?? '')
    );
}

$failed = array_values(array_filter($results, static fn(array $result): bool => empty($result['ok']) && empty($result['staging_blocked'])));
printf("Syncro root asset intake router V1 complete: %d assets evaluated, %d failed.\n", count($results), count($failed));
exit($failed ? 1 : 0);
