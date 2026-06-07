<?php
declare(strict_types=1);

// Scheduled Syncro root asset intake runner. Dry-run is the default.
// Apply mode requires --apply and still honors the central Syncro staging guard.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/syncro_root_asset_intake_router.php';

function syncro_root_asset_intake_cron_usage(): string
{
    return "Usage: php scripts/syncro_root_asset_intake_cron.php [--host=ops.example.com] [--client-id=123] [--limit=25] [--apply]\n\n"
        . "Scans active OPS clients with Syncro customer IDs and complete Syncro folder maps, then routes supported Windows root assets.\n"
        . "Default mode is dry-run. Apply mode performs PUT /api/v1/customer_assets/{asset_id}; no DELETE calls are used.\n";
}

function syncro_root_asset_intake_cron_option_int(array $options, string $name): int
{
    return syncro_root_asset_router_option_int($options, $name);
}

function syncro_root_asset_intake_cron_storage_lock_path(): string
{
    $override = getenv('SYNCRO_ROOT_ASSET_INTAKE_CRON_LOCK_PATH');
    if (is_string($override) && trim($override) !== '') {
        return $override;
    }
    return dirname(__DIR__) . '/storage/locks/syncro_root_asset_intake_cron.lock';
}

function syncro_root_asset_intake_cron_stale_seconds(): int
{
    $override = getenv('SYNCRO_ROOT_ASSET_INTAKE_CRON_LOCK_STALE_SECONDS');
    if (is_string($override) && trim($override) !== '' && (int)$override > 0) {
        return max(60, (int)$override);
    }
    return 3600;
}

function syncro_root_asset_intake_cron_acquire_lock(?string $path = null, ?int $staleSeconds = null): array
{
    $path = $path ?: syncro_root_asset_intake_cron_storage_lock_path();
    $staleSeconds = $staleSeconds ?: syncro_root_asset_intake_cron_stale_seconds();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'status' => 'LOCK_DIR_FAILED', 'message' => 'Unable to create lock directory: ' . $dir];
    }

    $existingMtime = is_file($path) ? (int)@filemtime($path) : 0;
    $existingAge = $existingMtime > 0 ? (time() - $existingMtime) : null;
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        return ['ok' => false, 'status' => 'LOCK_OPEN_FAILED', 'message' => 'Unable to open lock file: ' . $path];
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        $ageText = $existingAge === null ? 'unknown age' : ((string)$existingAge . 's old');
        return ['ok' => false, 'status' => 'LOCK_HELD', 'message' => 'Another Syncro root asset intake cron run is already active; lock file is ' . $ageText . ': ' . $path];
    }

    $stale = $existingAge !== null && $existingAge > $staleSeconds;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode([
        'pid' => getmypid(),
        'started_at' => date('c'),
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'stale_lock_replaced' => $stale,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    fflush($handle);

    return ['ok' => true, 'status' => $stale ? 'STALE_LOCK_REPLACED' : 'LOCK_ACQUIRED', 'path' => $path, 'handle' => $handle, 'stale' => $stale];
}

function syncro_root_asset_intake_cron_release_lock(array $lock): void
{
    $handle = $lock['handle'] ?? null;
    if (is_resource($handle)) {
        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $path = (string)($lock['path'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function syncro_root_asset_intake_cron_active_clients(?int $clientId = null, int $limit = 0): array
{
    if (!empty($GLOBALS['syncro_root_asset_intake_cron_clients_mock']) && is_callable($GLOBALS['syncro_root_asset_intake_cron_clients_mock'])) {
        $clients = (array)call_user_func($GLOBALS['syncro_root_asset_intake_cron_clients_mock'], $clientId, $limit);
        return array_values(array_filter($clients, static fn($client): bool => is_array($client)));
    }

    $params = [];
    $successfulSyncStatuses = ['SYNCED', 'READY', 'SUCCESS', 'COMPLETED', 'COMPLETE', 'OK'];
    $successfulSyncPlaceholders = implode(',', array_fill(0, count($successfulSyncStatuses), '?'));
    $folderMapReadySql = "fm.client_id IS NOT NULL
        AND COALESCE(fm.deploy_workstations_folder_id, 0) > 0
        AND COALESCE(fm.deploy_servers_folder_id, 0) > 0
        AND COALESCE(fm.production_workstations_folder_id, 0) > 0
        AND COALESCE(fm.production_servers_folder_id, 0) > 0
        AND UPPER(COALESCE(fm.provision_status, '')) = 'READY'
        AND UPPER(COALESCE(fm.policy_assignment_status, '')) = 'READY'";

    $where = '';
    if ($clientId !== null && $clientId > 0) {
        $where = 'WHERE c.client_id = ?';
        $params[] = $clientId;
    } else {
        $where = "WHERE UPPER(COALESCE(c.status, '')) = 'ACTIVE'
            AND COALESCE(c.syncro_customer_id, 0) > 0
            AND UPPER(COALESCE(c.syncro_sync_status, '')) IN ({$successfulSyncPlaceholders})
            AND {$folderMapReadySql}";
        array_push($params, ...$successfulSyncStatuses);
    }

    $sql = 'SELECT c.*, fm.syncro_customer_id AS folder_map_syncro_customer_id,
            fm.deploy_workstations_folder_id, fm.deploy_servers_folder_id,
            fm.production_workstations_folder_id, fm.production_servers_folder_id,
            fm.provision_status AS folder_map_provision_status,
            fm.policy_assignment_status AS folder_map_policy_assignment_status,
            (SELECT COUNT(*) FROM contract ctr WHERE ctr.client_id = c.client_id AND ctr.status = "ACTIVE") AS active_contract_count
        FROM clients c
        LEFT JOIN client_syncro_folder_map fm ON fm.client_id = c.client_id
        ' . $where . '
        ORDER BY c.legal_name ASC, c.client_id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function syncro_root_asset_intake_cron_client_label(array $client): string
{
    $name = trim((string)($client['legal_name'] ?? $client['dba_name'] ?? $client['name'] ?? ''));
    $id = (int)($client['client_id'] ?? 0);
    return ($name !== '' ? $name : 'Client') . ($id > 0 ? ' (#' . $id . ')' : '');
}

function syncro_root_asset_intake_cron_empty_totals(): array
{
    return [
        'clients_scanned' => 0,
        'clients_processed' => 0,
        'clients_skipped_no_customer_id' => 0,
        'clients_skipped_incomplete_folder_map' => 0,
        'clients_skipped_not_ready' => 0,
        'clients_failed' => 0,
        'assets_evaluated' => 0,
        'assets_ready_or_moved' => 0,
        'assets_manual_review' => 0,
        'assets_skipped' => 0,
        'assets_failed' => 0,
        'staging_blocked' => 0,
    ];
}

function syncro_root_asset_intake_cron_process_client(array $client, bool $dryRun): array
{
    $clientId = (int)($client['client_id'] ?? 0);
    $label = syncro_root_asset_intake_cron_client_label($client);
    $syncroCustomerId = (int)($client['syncro_customer_id'] ?? 0);
    $summary = syncro_root_asset_intake_cron_empty_totals();
    $summary['clients_scanned'] = 1;

    if ($syncroCustomerId <= 0) {
        $summary['clients_skipped_no_customer_id'] = 1;
        echo "Client {$label}: SKIP no syncro_customer_id.\n";
        return ['ok' => true, 'summary' => $summary];
    }

    if (!empty($GLOBALS['syncro_root_asset_intake_cron_folder_map_mock']) && is_callable($GLOBALS['syncro_root_asset_intake_cron_folder_map_mock'])) {
        $folderMap = (array)call_user_func($GLOBALS['syncro_root_asset_intake_cron_folder_map_mock'], $clientId);
    } else {
        $folderMap = syncro_get_client_folder_map($clientId) ?: [];
    }
    if (!syncro_folder_map_complete($folderMap)) {
        $summary['clients_skipped_incomplete_folder_map'] = 1;
        echo "Client {$label}: SKIP incomplete Syncro folder map.\n";
        return ['ok' => true, 'summary' => $summary];
    }

    $provisionStatus = strtoupper(trim((string)($folderMap['provision_status'] ?? $client['folder_map_provision_status'] ?? '')));
    $policyAssignmentStatus = strtoupper(trim((string)($folderMap['policy_assignment_status'] ?? $client['folder_map_policy_assignment_status'] ?? '')));
    if ($provisionStatus !== 'READY' || $policyAssignmentStatus !== 'READY') {
        $summary['clients_skipped_not_ready'] = 1;
        $provisionText = $provisionStatus !== '' ? $provisionStatus : 'UNKNOWN';
        $policyText = $policyAssignmentStatus !== '' ? $policyAssignmentStatus : 'UNKNOWN';
        echo "Client {$label}: SKIP Syncro folder/policy readiness not READY (provision_status={$provisionText}, policy_assignment_status={$policyText}).\n";
        return ['ok' => true, 'summary' => $summary];
    }

    echo "Client {$label}: customer #{$syncroCustomerId} scanning.\n";
    $listedFolders = syncro_list_customer_policy_folders($syncroCustomerId);
    if (empty($listedFolders['ok'])) {
        $summary['clients_failed'] = 1;
        echo "Client {$label}: ERROR unable to list policy folders: " . implode(' ', (array)($listedFolders['errors'] ?? [])) . "\n";
        return ['ok' => false, 'summary' => $summary];
    }
    $root = syncro_resolve_customer_root_policy_folder((array)($listedFolders['folders'] ?? []), $client);
    if (empty($root['ok'])) {
        $summary['clients_failed'] = 1;
        echo "Client {$label}: ERROR " . (string)($root['message'] ?? 'Unable to resolve customer root policy folder.') . "\n";
        return ['ok' => false, 'summary' => $summary];
    }
    $rootFolderId = (int)($root['root']['id'] ?? 0);

    $listedAssets = syncro_list_customer_assets($syncroCustomerId);
    if (empty($listedAssets['ok'])) {
        $summary['clients_failed'] = 1;
        echo "Client {$label}: ERROR unable to list customer assets: " . implode(' ', (array)($listedAssets['errors'] ?? [])) . "\n";
        return ['ok' => false, 'summary' => $summary];
    }

    $summary['clients_processed'] = 1;
    foreach ((array)($listedAssets['assets'] ?? []) as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $result = syncro_route_root_asset_intake($asset, $folderMap, $rootFolderId, $dryRun, $client);
        $summary['assets_evaluated']++;
        $status = (string)($result['status'] ?? 'UNKNOWN');
        if (in_array($status, ['DRY_RUN_READY', 'MOVED'], true)) {
            $summary['assets_ready_or_moved']++;
        } elseif ($status === 'MANUAL_REVIEW') {
            $summary['assets_manual_review']++;
        } elseif (str_starts_with($status, 'UNCHANGED_')) {
            $summary['assets_skipped']++;
        } elseif ($status === 'STAGING_BLOCKED') {
            $summary['staging_blocked']++;
        } elseif (empty($result['ok'])) {
            $summary['assets_failed']++;
        }

        printf(
            "Client %s: [%s] #%d %s: %s\n",
            $label,
            $status,
            (int)($result['asset_id'] ?? 0),
            (string)($result['asset_name'] ?? 'unknown asset'),
            (string)($result['message'] ?? '')
        );
        if ($dryRun && !empty($result['onboarding_fields'])) {
            echo 'Client ' . $label . ': onboarding fields ' . json_encode($result['onboarding_fields'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_update_payload_keys'])) {
            echo 'Client ' . $label . ': API field payload keys ' . json_encode($result['field_update_payload_keys'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_update_value_sources'])) {
            echo 'Client ' . $label . ': API field value sources ' . json_encode($result['field_update_value_sources'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if (!empty($result['field_persistence'])) {
            echo 'Client ' . $label . ': field persistence ' . json_encode($result['field_persistence'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
        if ($dryRun && !empty($result['onboarding_fields'])) {
            echo 'Client ' . $label . ': target move payload ' . json_encode($result['asset_update_payload'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    }

    printf(
        "Client %s: complete, %d assets evaluated, %d ready/moved, %d manual review, %d skipped, %d failed, %d staging blocked.\n",
        $label,
        $summary['assets_evaluated'],
        $summary['assets_ready_or_moved'],
        $summary['assets_manual_review'],
        $summary['assets_skipped'],
        $summary['assets_failed'],
        $summary['staging_blocked']
    );
    return ['ok' => $summary['assets_failed'] === 0, 'summary' => $summary];
}

function syncro_root_asset_intake_cron_merge_summary(array &$totals, array $summary): void
{
    foreach ($totals as $key => $_) {
        $totals[$key] += (int)($summary[$key] ?? 0);
    }
}

function syncro_root_asset_intake_cron_main(array $argv = []): int
{
    $options = getopt('', ['host:', 'client-id:', 'limit:', 'apply', 'help']);
    if (!is_array($options)) {
        $options = [];
    }

    if (isset($options['help'])) {
        echo syncro_root_asset_intake_cron_usage();
        return 0;
    }

    foreach (['host', 'client-id', 'limit'] as $requiredValueOption) {
        if (syncro_root_asset_router_option_value($options, $requiredValueOption) === '') {
            fwrite(STDERR, "Option --{$requiredValueOption} requires a value.\n" . syncro_root_asset_intake_cron_usage());
            return 1;
        }
    }

    $clientId = syncro_root_asset_intake_cron_option_int($options, 'client-id');
    $limit = syncro_root_asset_intake_cron_option_int($options, 'limit');
    if ($limit < 0) {
        fwrite(STDERR, "Option --limit must be zero or greater.\n" . syncro_root_asset_intake_cron_usage());
        return 1;
    }
    $dryRun = !isset($options['apply']);

    syncro_root_asset_router_bootstrap_cli_server($options);
    require_once __DIR__ . '/../inc/bootstrap.php';
    require_once __DIR__ . '/../inc/syncro.php';

    $lock = syncro_root_asset_intake_cron_acquire_lock();
    if (empty($lock['ok'])) {
        fwrite(STDERR, (string)($lock['message'] ?? 'Unable to acquire cron lock.') . "\n");
        return 1;
    }

    try {
        printf("Syncro root asset intake cron V1 starting in %s mode%s%s.\n", $dryRun ? 'dry-run' : 'apply', $clientId > 0 ? ' for client #' . $clientId : '', $limit > 0 ? ' with limit ' . $limit : '');
        if (!empty($lock['stale'])) {
            echo "Lock note: stale lock was replaced safely after acquiring the file lock.\n";
        }
        if (!$dryRun) {
            echo syncro_staging_write_status_message() . PHP_EOL;
        }

        $clients = syncro_root_asset_intake_cron_active_clients($clientId > 0 ? $clientId : null, $limit);
        $totals = syncro_root_asset_intake_cron_empty_totals();
        foreach ($clients as $client) {
            $result = syncro_root_asset_intake_cron_process_client($client, $dryRun);
            syncro_root_asset_intake_cron_merge_summary($totals, (array)($result['summary'] ?? []));
        }

        printf(
            "Syncro root asset intake cron V1 complete: %d clients scanned, %d processed, %d skipped no customer ID, %d skipped incomplete folder map, %d skipped not ready, %d client failures, %d assets evaluated, %d ready/moved, %d manual review, %d skipped, %d asset failures, %d staging blocked.\n",
            $totals['clients_scanned'],
            $totals['clients_processed'],
            $totals['clients_skipped_no_customer_id'],
            $totals['clients_skipped_incomplete_folder_map'],
            $totals['clients_skipped_not_ready'],
            $totals['clients_failed'],
            $totals['assets_evaluated'],
            $totals['assets_ready_or_moved'],
            $totals['assets_manual_review'],
            $totals['assets_skipped'],
            $totals['assets_failed'],
            $totals['staging_blocked']
        );

        return ($totals['clients_failed'] > 0 || $totals['assets_failed'] > 0) ? 1 : 0;
    } finally {
        syncro_root_asset_intake_cron_release_lock($lock);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(syncro_root_asset_intake_cron_main($argv ?? []));
}
