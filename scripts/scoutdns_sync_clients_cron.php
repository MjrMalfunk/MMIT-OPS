<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';
require_once __DIR__ . '/../inc/scoutdns.php';

function scoutdns_sync_arg_value(array $argv, string $prefix): ?string { foreach ($argv as $arg) { if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix)); } return null; }
function scoutdns_sync_safe(mixed $v, int $max = 160): string { $s = is_scalar($v) ? trim((string)$v) : ''; $s = preg_replace('/[\r\n\t]+/', ' ', $s) ?? ''; return mb_substr($s, 0, $max, 'UTF-8'); }
function scoutdns_sync_dt(mixed $v): ?string { $s = scoutdns_sync_safe($v, 80); if ($s === '') return null; try { return (new DateTimeImmutable($s))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); } catch (Throwable) { return null; } }
function scoutdns_sync_status(array $item): array {
    $enabled = $item['enabled'] ?? $item['active'] ?? $item['isActive'] ?? null;
    $last = scoutdns_sync_dt($item['lastSeen'] ?? $item['last_seen'] ?? $item['lastCheckin'] ?? $item['updatedAt'] ?? null);
    if ($enabled === false || $enabled === 0 || $enabled === '0') return ['DISABLED', 'Disabled', false, $last];
    return ['REPORTED', 'Reported by ScoutDNS', null, $last];
}
function scoutdns_sync_vendor_org_id(array $item): string { foreach (['organizationId','organization_id','siteId','site_id','customerId','customer_id','clientId','client_id','accountId','account_id'] as $k) { $v = scoutdns_sync_safe($item[$k] ?? ''); if ($v !== '') return $v; } return ''; }
function scoutdns_sync_device_id(array $item, string $device): string { foreach (['id','clientId','client_id','deviceId','device_id','agentId','agent_id','roamingClientId'] as $k) { $v = scoutdns_sync_safe($item[$k] ?? ''); if ($v !== '') return $v; } return 'scoutdns-' . vendor_telemetry_normalize_device_key($device); }

$apply = in_array('--apply', $argv, true);
$limit = max(1, min(1000, (int)(scoutdns_sync_arg_value($argv, '--limit=') ?? 1000)));
$clientIdFilter = max(0, (int)(scoutdns_sync_arg_value($argv, '--client-id=') ?? 0));
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) { echo "Usage: php scripts/scoutdns_sync_clients_cron.php [--apply] [--client-id=123] [--limit=1000]\n"; exit(0); }

echo 'ScoutDNS getClients cache sync starting in ' . ($apply ? 'apply' : 'dry-run') . " mode.\n";
echo 'DB: ' . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
$missing = scoutdns_missing_config();
if ($missing) { echo 'ScoutDNS API is not configured. Missing: ' . implode(', ', $missing) . "\n"; exit(0); }

$runId = vendor_sync_run_start('scoutdns', $apply ? 'cron_apply' : 'cron_dry_run', ['limit'=>$limit, 'client_id'=>$clientIdFilter ?: null]);
$result = ['status'=>'COMPLETE','clients_seen'=>0,'devices_seen'=>0,'devices_updated'=>0,'error_count'=>0,'message'=>'ScoutDNS getClients cache sync completed.','raw'=>['apply'=>$apply,'limit'=>$limit]];
try {
    $response = scoutdns_list_clients();
    $items = array_slice(scoutdns_response_items($response, ['clients','clientList','data']), 0, $limit);
    echo 'ScoutDNS clients returned: ' . count($items) . "\n";
    $linksSql = "SELECT vcl.*, c.legal_name, c.dba_name, c.syncro_customer_id FROM vendor_client_links vcl INNER JOIN clients c ON c.client_id = vcl.client_id WHERE vcl.vendor_code = 'scoutdns' AND vcl.link_status = 'ACTIVE'" . ($clientIdFilter > 0 ? ' AND vcl.client_id = ?' : '');
    $st = db()->prepare($linksSql); $st->execute($clientIdFilter > 0 ? [$clientIdFilter] : []); $links = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $linksByOrg = []; foreach ($links as $link) { $linksByOrg[(string)$link['vendor_org_id']] = $link; }
    $result['clients_seen'] = count($links);
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $device = scoutdns_sync_safe($item['clientName'] ?? $item['client_name'] ?? $item['name'] ?? $item['hostname'] ?? 'ScoutDNS client');
        $orgId = scoutdns_sync_vendor_org_id($item);
        $link = $orgId !== '' ? ($linksByOrg[$orgId] ?? null) : null;
        [$status, $label, $protected, $lastSeen] = scoutdns_sync_status($item);
        $summary = ['source_endpoint'=>'GET /getClients','vendor_org_id'=>$orgId ?: null,'has_username'=>scoutdns_sync_safe($item['username'] ?? $item['userName'] ?? '') !== '','reported_status'=>$status];
        echo json_encode(['vendor'=>'scoutdns','mapped_client_id'=>$link['client_id'] ?? null,'device_name'=>$device,'status'=>$status,'last_seen_at'=>$lastSeen,'synced_at'=>vendor_telemetry_now(),'apply'=>$apply], JSON_UNESCAPED_SLASHES) . "\n";
        $result['devices_seen']++;
        if ($apply && is_array($link)) {
            vendor_device_status_upsert((int)$link['client_id'], 'scoutdns', [
                'vendor_org_id'=>$orgId,
                'vendor_device_id'=>scoutdns_sync_device_id($item, $device),
                'syncro_customer_id'=>isset($link['syncro_customer_id']) ? (int)$link['syncro_customer_id'] : null,
                'device_name'=>$device,
                'username'=>scoutdns_sync_safe($item['username'] ?? $item['userName'] ?? $item['user'] ?? ''),
                'os_name'=>scoutdns_sync_safe($item['osName'] ?? $item['os_name'] ?? $item['os'] ?? ''),
                'status'=>$status,
                'status_label'=>$label,
                'status_detail'=>'ScoutDNS getClients reported this endpoint. No raw vendor payload stored for portal use.',
                'protection_enabled'=>$protected,
                'last_seen_at'=>$lastSeen,
                'last_success_at'=>$lastSeen,
                'raw_summary'=>$summary,
            ]);
            $result['devices_updated']++;
        }
    }
    if ($apply) vendor_integration_update_status('scoutdns', 'SYNCED');
    vendor_sync_run_finish($runId, $result);
    echo "ScoutDNS cache sync complete.\n" . json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    $message = scoutdns_mask_sensitive($e->getMessage());
    $result['status'] = 'ERROR'; $result['error_count']++; $result['message'] = $message; vendor_sync_run_finish($runId, $result); if ($apply) vendor_integration_update_status('scoutdns', 'ERROR', $message); fwrite(STDERR, 'ScoutDNS cache sync failed: ' . $message . "\n"); exit(1);
}
