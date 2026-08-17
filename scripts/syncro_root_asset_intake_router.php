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
    return "Usage: php scripts/syncro_root_asset_intake_router.php --client-id=123 [--customer-id=456] [--host=ops.example.com] [--apply]\n"
        . "       php scripts/syncro_root_asset_intake_router.php --customer-id=456 --root-folder-id=789 --deploy-workstations-folder-id=1 --deploy-servers-folder-id=2 --production-workstations-folder-id=3 --production-servers-folder-id=4 [--host=ops.example.com] [--apply]\n\n"
        . "Default mode is dry-run. Apply mode performs PUT /api/v1/customer_assets/{asset_id}; no DELETE calls are used.\n";
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

function syncro_root_asset_router_normalize_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^https?:\/\//', '', $host) ?? $host;
    $host = preg_replace('#[\\\\/].*$#', '', $host) ?? $host;
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return trim($host, '.');
}

function syncro_root_asset_router_host_looks_valid(string $host): bool
{
    if ($host === '' || strlen($host) > 253 || !str_contains($host, '.')) {
        return false;
    }
    return (bool)preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
}

function syncro_root_asset_router_infer_host_from_app_root(): string
{
    $appRoot = basename(dirname(__DIR__));
    $host = syncro_root_asset_router_normalize_host($appRoot);
    return syncro_root_asset_router_host_looks_valid($host) ? $host : '';
}

function syncro_root_asset_router_resolve_cli_host(array $options): string
{
    $explicitHost = syncro_root_asset_router_option_value($options, 'host');
    if ($explicitHost !== null) {
        $host = syncro_root_asset_router_normalize_host($explicitHost);
        return syncro_root_asset_router_host_looks_valid($host) ? $host : '';
    }

    $envHost = getenv('MMIT_CLI_HTTP_HOST');
    if (is_string($envHost) && syncro_root_asset_router_host_looks_valid(syncro_root_asset_router_normalize_host($envHost))) {
        return syncro_root_asset_router_normalize_host($envHost);
    }

    $inferredHost = syncro_root_asset_router_infer_host_from_app_root();
    if ($inferredHost !== '') {
        return $inferredHost;
    }

    $existingHost = syncro_root_asset_router_normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (syncro_root_asset_router_host_looks_valid($existingHost)) {
        return $existingHost;
    }

    return '';
}

function syncro_root_asset_router_bootstrap_cli_server(array $options): void
{
    $host = syncro_root_asset_router_resolve_cli_host($options);
    if ($host === '') {
        fwrite(STDERR, "Unable to resolve an HTTP host for CLI bootstrap. Provide --host=ops.example.com or set MMIT_CLI_HTTP_HOST; alternatively run from an app root directory named like a hostname.\n" . syncro_root_asset_router_usage());
        exit(1);
    }

    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
}


function syncro_root_asset_router_main(array $argv = []): int
{
    $options = getopt('', [
        'client-id:',
        'customer-id:',
        'root-folder-id:',
        'deploy-workstations-folder-id:',
        'deploy-servers-folder-id:',
        'production-workstations-folder-id:',
        'production-servers-folder-id:',
        'host:',
        'apply',
        'help',
    ]);
    if (!is_array($options)) {
        $options = [];
    }

    if (isset($options['help'])) {
        echo syncro_root_asset_router_usage();
        return 0;
    }

    $requiredValueOptions = [
        'client-id',
        'customer-id',
        'root-folder-id',
        'deploy-workstations-folder-id',
        'deploy-servers-folder-id',
        'production-workstations-folder-id',
        'production-servers-folder-id',
        'host',
    ];
    foreach ($requiredValueOptions as $requiredValueOption) {
        if (syncro_root_asset_router_option_value($options, $requiredValueOption) === '') {
            fwrite(STDERR, "Option --{$requiredValueOption} requires a value.\n" . syncro_root_asset_router_usage());
            return 1;
        }
    }

    $clientId = syncro_root_asset_router_option_int($options, 'client-id');
    $syncroCustomerId = syncro_root_asset_router_option_int($options, 'customer-id');

    if ($clientId <= 0 && $syncroCustomerId <= 0) {
        fwrite(STDERR, "Either --client-id or --customer-id is required.\n" . syncro_root_asset_router_usage());
        return 1;
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
            return 1;
        }
    }

    syncro_root_asset_router_bootstrap_cli_server($options);

    require_once __DIR__ . '/../inc/bootstrap.php';
    require_once __DIR__ . '/../inc/syncro.php';

    if (!syncro_is_enabled()) {
        echo syncro_disabled_message(), PHP_EOL;
        return 0;
    }

    $dryRun = !isset($options['apply']);
    $client = [];
    $folderMap = [];

    if ($clientId > 0) {
        $client = client_get_by_id($clientId) ?: [];
        if (!$client) {
            fwrite(STDERR, "Client #{$clientId} was not found.\n");
            return 1;
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
        return 1;
    }
    if (!syncro_folder_map_complete($folderMap)) {
        fwrite(STDERR, "Complete Deploy/Production folder IDs are required before asset routing. No asset moves attempted.\n" . syncro_root_asset_router_usage());
        return 1;
    }

    $rootFolderId = syncro_root_asset_router_option_int($options, 'root-folder-id');
    if ($rootFolderId <= 0) {
        $listedFolders = syncro_list_customer_policy_folders($syncroCustomerId);
        if (empty($listedFolders['ok'])) {
            fwrite(STDERR, "Unable to list Syncro policy folders to resolve customer root: " . implode(' ', (array)($listedFolders['errors'] ?? [])) . "\n");
            return 1;
        }
        $root = syncro_resolve_customer_root_policy_folder((array)($listedFolders['folders'] ?? []), $client);
        if (empty($root['ok'])) {
            fwrite(STDERR, (string)($root['message'] ?? 'Unable to resolve customer root policy folder.') . "\n");
            return 1;
        }
        $rootFolderId = (int)($root['root']['id'] ?? 0);
    }

    printf("Syncro root asset intake router V1 starting for customer #%d root folder #%d in %s mode.\n", $syncroCustomerId, $rootFolderId, $dryRun ? 'dry-run' : 'apply');
    if (!$dryRun) {
        echo syncro_staging_write_status_message() . PHP_EOL;
    }

    $listedAssets = syncro_list_customer_assets($syncroCustomerId);
    if (empty($listedAssets['ok'])) {
        fwrite(STDERR, "Unable to list Syncro customer assets: " . implode(' ', (array)($listedAssets['errors'] ?? [])) . "\n");
        return 1;
    }

    $results = [];
    foreach ((array)($listedAssets['assets'] ?? []) as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $result = syncro_route_root_asset_intake($asset, $folderMap, $rootFolderId, $dryRun, $client);
        $results[] = $result;
        printf(
            "[%s] #%d %s: %s\n",
            (string)($result['status'] ?? 'UNKNOWN'),
            (int)($result['asset_id'] ?? 0),
            (string)($result['asset_name'] ?? 'unknown asset'),
            (string)($result['message'] ?? '')
        );
        if ($dryRun && !empty($result['onboarding_fields'])) {
            echo '  Onboarding fields: ' . json_encode($result['onboarding_fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_update_payload_keys'])) {
            echo '  API field payload keys: ' . json_encode($result['field_update_payload_keys'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_update_value_sources'])) {
            echo '  API field value sources: ' . json_encode($result['field_update_value_sources'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_persistence'])) {
            echo '  Field persistence: ' . json_encode($result['field_persistence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if ($dryRun && !empty($result['onboarding_fields'])) {
            echo '  Target move payload: ' . json_encode($result['asset_update_payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }

    $failed = array_values(array_filter($results, static fn(array $result): bool => empty($result['ok']) && empty($result['staging_blocked'])));
    printf("Syncro root asset intake router V1 complete: %d assets evaluated, %d failed.\n", count($results), count($failed));
    return $failed ? 1 : 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(syncro_root_asset_router_main($argv ?? []));
}
