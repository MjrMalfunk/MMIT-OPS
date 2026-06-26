<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';

$apply = in_array('--apply', $argv, true);
$pdo = db();

echo 'Vendor telemetry foundation smoke starting in ' . ($apply ? 'apply' : 'dry-run') . " mode.\n";
echo 'DB: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";

foreach (['vendor_integrations', 'vendor_client_links', 'vendor_device_status', 'vendor_sync_runs'] as $table) {
    if (!db_table_exists($table)) {
        fwrite(STDERR, "Missing table: {$table}\n");
        exit(1);
    }
}

$integrations = $pdo->query('SELECT vendor_code, display_name, enabled, status FROM vendor_integrations ORDER BY vendor_code')->fetchAll(PDO::FETCH_ASSOC);
echo 'Integrations: ' . json_encode($integrations, JSON_UNESCAPED_SLASHES) . "\n";

$client = $pdo->query('SELECT client_id, legal_name, dba_name FROM clients ORDER BY client_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    fwrite(STDERR, "No client rows available for smoke test.\n");
    exit(1);
}

$clientId = (int)$client['client_id'];
$clientName = (string)($client['legal_name'] ?: $client['dba_name'] ?: ('Client #' . $clientId));
echo "Smoke client: #{$clientId} {$clientName}\n";

$pdo->beginTransaction();

try {
    $runId = vendor_sync_run_start('cove', 'smoke', ['mode' => $apply ? 'apply' : 'dry-run']);
    echo "Started run: {$runId}\n";

    $link = vendor_client_link_upsert($clientId, 'cove', 'smoke-cove-org-' . $clientId, [
        'vendor_org_name' => $clientName,
        'matched_by' => 'smoke',
        'notes' => 'Smoke test link. Rolled back unless --apply is used.',
    ]);
    echo 'Upserted link: ' . json_encode([
        'link_id' => $link['link_id'] ?? null,
        'client_id' => $link['client_id'] ?? null,
        'vendor_code' => $link['vendor_code'] ?? null,
        'vendor_org_id' => $link['vendor_org_id'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";

    $status = vendor_device_status_upsert($clientId, 'cove', [
        'vendor_org_id' => 'smoke-cove-org-' . $clientId,
        'vendor_device_id' => 'smoke-cove-device-' . $clientId,
        'device_name' => 'SMOKE-COVE-DEVICE',
        'device_role' => 'workstation',
        'status' => 'HEALTHY',
        'status_label' => 'Smoke healthy',
        'status_detail' => 'Foundation upsert path validated.',
        'last_seen_at' => vendor_telemetry_now(),
        'last_success_at' => vendor_telemetry_now(),
        'storage_used_bytes' => 123456789,
        'storage_quota_bytes' => 250 * 1024 * 1024 * 1024,
        'raw' => ['source' => 'smoke', 'apply' => $apply],
    ]);
    echo 'Upserted device status: ' . json_encode([
        'status_id' => $status['status_id'] ?? null,
        'client_id' => $status['client_id'] ?? null,
        'vendor_code' => $status['vendor_code'] ?? null,
        'device_name' => $status['device_name'] ?? null,
        'status' => $status['status'] ?? null,
    ], JSON_UNESCAPED_SLASHES) . "\n";

    vendor_sync_run_finish($runId, [
        'status' => 'COMPLETE',
        'clients_seen' => 1,
        'devices_seen' => 1,
        'devices_updated' => 1,
        'message' => 'Vendor telemetry foundation smoke completed.',
        'raw' => ['apply' => $apply],
    ]);

    vendor_integration_update_status('cove', 'SMOKE_OK');

    if ($apply) {
        $pdo->commit();
        echo "Committed smoke rows.\n";
    } else {
        $pdo->rollBack();
        echo "Dry-run complete. Rolled back smoke rows.\n";
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Vendor telemetry foundation smoke complete.\n";
