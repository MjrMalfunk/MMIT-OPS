<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';
require_once __DIR__ . '/../inc/cove.php';

function cove_sync_usage(): string
{
    return "Usage: php scripts/cove_sync_devices_cron.php [--apply] [--client-id=123] [--limit=500]\n";
}

function cove_sync_arg_value(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

$apply = in_array('--apply', $argv, true);
$clientIdFilter = max(0, (int)(cove_sync_arg_value($argv, '--client-id=') ?? 0));
$limit = max(1, min(500, (int)(cove_sync_arg_value($argv, '--limit=') ?? 500)));

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo cove_sync_usage();
    exit(0);
}

echo 'Cove device sync starting in ' . ($apply ? 'apply' : 'dry-run') . " mode.\n";
echo 'DB: ' . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";

$missing = cove_missing_config();
if ($missing !== []) {
    echo 'Cove API is not configured. Missing: ' . implode(', ', $missing) . "\n";
    exit(0);
}

$where = "vcl.vendor_code = 'cove' AND vcl.link_status = 'ACTIVE'";
$params = [];

if ($clientIdFilter > 0) {
    $where .= ' AND vcl.client_id = ?';
    $params[] = $clientIdFilter;
}

$sql = "
    SELECT
        vcl.*,
        c.legal_name,
        c.dba_name,
        c.client_code
    FROM vendor_client_links vcl
    INNER JOIN clients c ON c.client_id = vcl.client_id
    WHERE {$where}
    ORDER BY vcl.client_id
";

$st = db()->prepare($sql);
$st->execute($params);
$links = $st->fetchAll(PDO::FETCH_ASSOC);

if ($links === []) {
    echo "No active Cove client links found. Nothing to sync.\n";
    echo "Create rows in vendor_client_links first.\n";
    exit(0);
}

$runId = vendor_sync_run_start('cove', $apply ? 'cron_apply' : 'cron_dry_run', [
    'client_id' => $clientIdFilter ?: null,
    'limit' => $limit,
]);

$result = [
    'status' => 'COMPLETE',
    'clients_seen' => 0,
    'devices_seen' => 0,
    'devices_updated' => 0,
    'error_count' => 0,
    'message' => 'Cove device sync completed.',
    'raw' => [
        'apply' => $apply,
        'client_id' => $clientIdFilter ?: null,
        'limit' => $limit,
    ],
];

try {
    echo "Attempting Cove Login...\n";
    $session = cove_login();
    $visa = (string)$session['visa'];
    echo "Cove Login: OK\n";

    foreach ($links as $link) {
        $clientId = (int)$link['client_id'];
        $partnerId = (int)$link['vendor_org_id'];
        $clientName = (string)($link['legal_name'] ?: $link['dba_name'] ?: ('Client #' . $clientId));

        $result['clients_seen']++;

        echo "\nClient #{$clientId}: {$clientName}\n";
        echo "Cove PartnerId: {$partnerId}\n";

        if ($partnerId <= 0) {
            echo "Skipping: invalid Cove PartnerId.\n";
            $result['error_count']++;
            continue;
        }

        $response = cove_enumerate_account_statistics($visa, $partnerId, 0, $limit);
        $rows = cove_account_statistics_rows($response);

        echo 'Rows returned: ' . count($rows) . "\n";
        $result['devices_seen'] += count($rows);

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $settings = cove_settings_map($row);

            $deviceName = trim((string)($settings['I18'] ?? ''));
            if ($deviceName === '') {
                $deviceName = trim((string)($settings['I1'] ?? ''));
            }
            if ($deviceName === '') {
                $deviceName = 'Cove Account ' . (string)($row['AccountId'] ?? 'unknown');
            }

            $role = cove_device_role_from_os_type($settings['I32'] ?? null) ?? 'unknown';
            $usedBytes = cove_storage_bytes($settings['I14'] ?? null);

            $payload = [
                'vendor_org_id' => (string)$partnerId,
                'vendor_device_id' => (string)($row['AccountId'] ?? ('cove-' . vendor_telemetry_normalize_device_key($deviceName))),
                'device_name' => $deviceName,
                'device_role' => $role,
                'status' => 'REPORTED',
                'status_label' => 'Reported by Cove',
                'status_detail' => 'Cove backup device statistics are available.',
                'last_seen_at' => vendor_telemetry_now(),
                'last_success_at' => vendor_telemetry_now(),
                'storage_used_bytes' => $usedBytes,
                'raw_summary' => [
                    'source' => 'cove_account_statistics',
                    'partner_id' => $partnerId,
                    'account_id_present' => trim((string)($row['AccountId'] ?? '')) !== '',
                    'os_type' => $settings['I32'] ?? null,
                    'storage_used_bytes' => $usedBytes,
                ],
            ];

            echo json_encode([
                'client_id' => $clientId,
                'vendor_device_id' => $payload['vendor_device_id'],
                'device_name' => $payload['device_name'],
                'role' => $payload['device_role'],
                'storage_used_bytes' => $payload['storage_used_bytes'],
                'apply' => $apply,
            ], JSON_UNESCAPED_SLASHES) . "\n";

            if ($apply) {
                vendor_device_status_upsert($clientId, 'cove', $payload);
                $result['devices_updated']++;
            }
        }

        if ($apply) {
            vendor_client_link_upsert($clientId, 'cove', (string)$partnerId, [
                'vendor_org_name' => (string)($link['vendor_org_name'] ?: $clientName),
                'link_status' => 'ACTIVE',
                'matched_by' => (string)($link['matched_by'] ?: 'manual'),
                'notes' => (string)($link['notes'] ?: ''),
                'last_sync_at' => vendor_telemetry_now(),
                'last_error' => '',
            ]);
        }
    }

    if ($apply) {
        vendor_integration_update_status('cove', 'SYNCED');
    }

    vendor_sync_run_finish($runId, $result);

    echo "\nCove device sync complete.\n";
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    $message = cove_mask_sensitive($e->getMessage());

    $result['status'] = 'ERROR';
    $result['error_count']++;
    $result['message'] = $message;

    vendor_sync_run_finish($runId, $result);

    if ($apply) {
        vendor_integration_update_status('cove', 'ERROR', $message);
    }

    fwrite(STDERR, 'Cove device sync failed: ' . $message . "\n");
    exit(1);
}
