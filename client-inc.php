<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';

if (file_exists(__DIR__ . '/portal-auth.php')) {
    require_once __DIR__ . '/portal-auth.php';
} elseif (file_exists(__DIR__ . '/inc/portal_access.php')) {
    require_once __DIR__ . '/inc/portal_access.php';
}

function client_portal_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_portal_current_client_id(): int
{
    if (function_exists('portal_current_client_id')) {
        return max(0, (int)portal_current_client_id());
    }

    $invite = function_exists('portal_access_current_invite') ? portal_access_current_invite() : null;
    if (is_array($invite) && (int)($invite['client_id'] ?? 0) > 0) {
        return (int)$invite['client_id'];
    }

    if (function_exists('current_user') && current_user()) {
        return max(0, (int)($_GET['client_id'] ?? 0));
    }

    return 0;
}

function client_portal_require_client(): array
{
    $clientId = client_portal_current_client_id();
    if ($clientId <= 0) {
        http_response_code(403);
        echo 'Client portal access is required.';
        exit;
    }

    $st = db()->prepare('SELECT client_id, legal_name, dba_name FROM clients WHERE client_id = ? LIMIT 1');
    $st->execute([$clientId]);
    $client = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($client)) {
        http_response_code(404);
        echo 'Client not found.';
        exit;
    }

    return $client;
}

function client_portal_normalized_device_key(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/\..*$/', '', $name) ?? $name;
    return preg_replace('/[^a-z0-9]+/', '', $name) ?? '';
}

function client_portal_vendor_rows(int $clientId, ?string $vendorCode = null): array
{
    $where = 'client_id = ?';
    $args = [$clientId];
    if ($vendorCode !== null && trim($vendorCode) !== '') {
        $where .= ' AND vendor_code = ?';
        $args[] = strtolower(trim($vendorCode));
    }

    $sql = "SELECT client_id, vendor_code, syncro_asset_id, device_name, username, os_name,
                   status, status_label, status_detail, protection_enabled,
                   last_seen_at, last_success_at, synced_at, storage_used_bytes, storage_quota_bytes
            FROM vendor_device_status
            WHERE {$where}
            ORDER BY COALESCE(NULLIF(device_name, ''), username, vendor_code) ASC, vendor_code ASC";
    $st = db()->prepare($sql);
    $st->execute($args);

    $safe = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $safe[] = [
            'client_id' => (int)($row['client_id'] ?? 0),
            'vendor_code' => (string)($row['vendor_code'] ?? ''),
            'syncro_asset_id' => (int)($row['syncro_asset_id'] ?? 0),
            'device_name' => (string)($row['device_name'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'os_name' => (string)($row['os_name'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'status_label' => (string)($row['status_label'] ?? ''),
            'status_detail' => (string)($row['status_detail'] ?? ''),
            'protection_enabled' => isset($row['protection_enabled']) ? (int)$row['protection_enabled'] : null,
            'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
            'last_success_at' => (string)($row['last_success_at'] ?? ''),
            'synced_at' => (string)($row['synced_at'] ?? ''),
            'storage_used_bytes' => $row['storage_used_bytes'] ?? null,
            'storage_quota_bytes' => $row['storage_quota_bytes'] ?? null,
        ];
    }

    return $safe;
}

function client_portal_public_status(?array $row): string
{
    if (!$row) {
        return 'Not included';
    }

    $vendor = strtolower((string)($row['vendor_code'] ?? ''));
    $status = strtoupper((string)($row['status'] ?? ''));
    $label = strtoupper((string)($row['status_label'] ?? ''));

    if (($vendor === 'scoutdns' && $label === 'MISSING') || in_array($status, ['MISSING', 'OFFLINE', 'STALE'], true)) {
        return 'Not reporting';
    }

    if (in_array($status, ['ACTIVE', 'REPORTED', 'OK', 'SUCCESS'], true) || in_array($label, ['ACTIVE', 'ONLINE', 'PROTECTED', 'REPORTED BY COVE'], true)) {
        return 'Protected';
    }

    if (in_array($status, ['WARNING', 'DEGRADED', 'ERROR', 'FAILED'], true)) {
        return 'Needs attention';
    }

    return 'Unknown';
}

function client_portal_grouped_assets(int $clientId): array
{
    $assets = [];
    $hostIndex = [];

    foreach (client_portal_vendor_rows($clientId) as $row) {
        $assetId = (int)$row['syncro_asset_id'];
        $name = trim((string)$row['device_name']);
        $hostKey = client_portal_normalized_device_key($name);

        if ($assetId > 0) {
            $key = 'asset:' . $assetId;
        } elseif ($hostKey !== '' && isset($hostIndex[$hostKey])) {
            $key = $hostIndex[$hostKey];
        } elseif ($hostKey !== '') {
            $key = 'host:' . $hostKey;
            $hostIndex[$hostKey] = $key;
        } else {
            $key = 'row:' . count($assets);
        }

        $assets[$key] ??= ['key' => $key, 'syncro_asset_id' => $assetId, 'device_name' => $name ?: 'Unassigned device', 'os_name' => '', 'username' => '', 'vendors' => []];
        if ($assetId > 0) {
            $assets[$key]['syncro_asset_id'] = $assetId;
        }
        foreach (['os_name', 'username'] as $field) {
            if ($assets[$key][$field] === '' && $row[$field] !== '') {
                $assets[$key][$field] = $row[$field];
            }
        }
        $assets[$key]['vendors'][(string)$row['vendor_code']] = $row;
    }

    uasort($assets, static fn($a, $b) => strcasecmp((string)$a['device_name'], (string)$b['device_name']));
    return array_values($assets);
}

function client_portal_asset_by_key(int $clientId, string $key): ?array
{
    foreach (client_portal_grouped_assets($clientId) as $asset) {
        if (hash_equals((string)$asset['key'], $key)) {
            return $asset;
        }
    }

    return null;
}
