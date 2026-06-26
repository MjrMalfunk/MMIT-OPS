<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function vendor_telemetry_normalize_vendor(string $vendorCode): string
{
    $vendor = strtolower(trim($vendorCode));
    $vendor = preg_replace('/[^a-z0-9_:-]+/', '', $vendor) ?: '';
    return $vendor;
}

function vendor_telemetry_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function vendor_telemetry_truncate(?string $value, int $max = 255): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return mb_substr($value, 0, $max, 'UTF-8');
}

function vendor_telemetry_json(mixed $value): ?string
{
    if ($value === null || $value === []) {
        return null;
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
}

function vendor_telemetry_normalize_device_key(string $deviceName): string
{
    $key = strtolower(trim($deviceName));
    $key = preg_replace('/\..*$/', '', $key) ?: $key;
    $key = preg_replace('/[^a-z0-9]+/', '-', $key) ?: '';
    $key = trim($key, '-');

    return $key !== '' ? $key : sha1($deviceName);
}

function vendor_integration_get(string $vendorCode): ?array
{
    $vendor = vendor_telemetry_normalize_vendor($vendorCode);
    if ($vendor === '') {
        return null;
    }

    $st = db()->prepare('SELECT * FROM vendor_integrations WHERE vendor_code = ? LIMIT 1');
    $st->execute([$vendor]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function vendor_integration_is_enabled(string $vendorCode): bool
{
    $row = vendor_integration_get($vendorCode);
    return $row !== null && (int)($row['enabled'] ?? 0) === 1;
}

function vendor_integration_update_status(string $vendorCode, string $status, ?string $error = null): void
{
    $vendor = vendor_telemetry_normalize_vendor($vendorCode);
    if ($vendor === '') {
        throw new InvalidArgumentException('Vendor code is required.');
    }

    $st = db()->prepare(
        'UPDATE vendor_integrations
         SET status = ?, last_error = ?, last_sync_at = CASE WHEN ? IS NULL THEN last_sync_at ELSE ? END
         WHERE vendor_code = ?'
    );

    $syncAt = $error === null ? vendor_telemetry_now() : null;
    $st->execute([
        vendor_telemetry_truncate($status, 40),
        vendor_telemetry_truncate($error),
        $syncAt,
        $syncAt,
        $vendor,
    ]);
}

function vendor_sync_run_start(string $vendorCode, string $runType = 'manual', ?array $raw = null): int
{
    $vendor = vendor_telemetry_normalize_vendor($vendorCode);
    if ($vendor === '') {
        throw new InvalidArgumentException('Vendor code is required.');
    }

    $st = db()->prepare(
        'INSERT INTO vendor_sync_runs (vendor_code, run_type, status, raw_json)
         VALUES (?, ?, ?, ?)'
    );

    $st->execute([
        $vendor,
        vendor_telemetry_truncate($runType, 60) ?? 'manual',
        'RUNNING',
        vendor_telemetry_json($raw),
    ]);

    return (int)db()->lastInsertId();
}

function vendor_sync_run_finish(int $runId, array $result): void
{
    if ($runId <= 0) {
        throw new InvalidArgumentException('Run ID is required.');
    }

    $st = db()->prepare(
        'UPDATE vendor_sync_runs
         SET finished_at = ?,
             status = ?,
             clients_seen = ?,
             devices_seen = ?,
             devices_updated = ?,
             error_count = ?,
             message = ?,
             raw_json = ?
         WHERE run_id = ?'
    );

    $st->execute([
        vendor_telemetry_now(),
        vendor_telemetry_truncate((string)($result['status'] ?? 'COMPLETE'), 40) ?? 'COMPLETE',
        max(0, (int)($result['clients_seen'] ?? 0)),
        max(0, (int)($result['devices_seen'] ?? 0)),
        max(0, (int)($result['devices_updated'] ?? 0)),
        max(0, (int)($result['error_count'] ?? 0)),
        vendor_telemetry_truncate((string)($result['message'] ?? '')),
        vendor_telemetry_json($result['raw'] ?? null),
        $runId,
    ]);
}

function vendor_client_link_upsert(int $clientId, string $vendorCode, string $vendorOrgId, array $data = []): array
{
    $vendor = vendor_telemetry_normalize_vendor($vendorCode);
    $vendorOrgId = trim($vendorOrgId);

    if ($clientId <= 0 || $vendor === '' || $vendorOrgId === '') {
        throw new InvalidArgumentException('Client ID, vendor code, and vendor org ID are required.');
    }

    $st = db()->prepare(
        'INSERT INTO vendor_client_links
            (client_id, vendor_code, vendor_org_id, vendor_org_name, link_status, matched_by, notes, last_sync_at, last_error)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            vendor_org_id = VALUES(vendor_org_id),
            vendor_org_name = VALUES(vendor_org_name),
            link_status = VALUES(link_status),
            matched_by = VALUES(matched_by),
            notes = VALUES(notes),
            last_sync_at = VALUES(last_sync_at),
            last_error = VALUES(last_error),
            updated_at = CURRENT_TIMESTAMP'
    );

    $st->execute([
        $clientId,
        $vendor,
        vendor_telemetry_truncate($vendorOrgId, 120),
        vendor_telemetry_truncate((string)($data['vendor_org_name'] ?? ''), 200),
        vendor_telemetry_truncate((string)($data['link_status'] ?? 'ACTIVE'), 40) ?? 'ACTIVE',
        vendor_telemetry_truncate((string)($data['matched_by'] ?? 'manual'), 80),
        vendor_telemetry_truncate((string)($data['notes'] ?? '')),
        (string)($data['last_sync_at'] ?? vendor_telemetry_now()),
        vendor_telemetry_truncate((string)($data['last_error'] ?? '')),
    ]);

    $select = db()->prepare('SELECT * FROM vendor_client_links WHERE client_id = ? AND vendor_code = ? LIMIT 1');
    $select->execute([$clientId, $vendor]);

    $row = $select->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function vendor_device_status_upsert(int $clientId, string $vendorCode, array $data): array
{
    $vendor = vendor_telemetry_normalize_vendor($vendorCode);
    $deviceName = trim((string)($data['device_name'] ?? ''));

    if ($clientId <= 0 || $vendor === '' || $deviceName === '') {
        throw new InvalidArgumentException('Client ID, vendor code, and device name are required.');
    }

    $normalizedKey = trim((string)($data['normalized_device_key'] ?? ''));
    if ($normalizedKey === '') {
        $normalizedKey = vendor_telemetry_normalize_device_key($deviceName);
    }

    $vendorDeviceId = trim((string)($data['vendor_device_id'] ?? ''));
    if ($vendorDeviceId === '') {
        $vendorDeviceId = 'local:' . $clientId . ':' . $normalizedKey;
    }

    $st = db()->prepare(
        'INSERT INTO vendor_device_status
            (client_id, vendor_code, vendor_org_id, vendor_device_id, syncro_asset_id, device_name,
             normalized_device_key, device_role, status, status_label, status_detail, last_seen_at,
             last_success_at, storage_used_bytes, storage_quota_bytes, raw_json, synced_at)
         VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            client_id = VALUES(client_id),
            vendor_org_id = VALUES(vendor_org_id),
            syncro_asset_id = VALUES(syncro_asset_id),
            device_name = VALUES(device_name),
            normalized_device_key = VALUES(normalized_device_key),
            device_role = VALUES(device_role),
            status = VALUES(status),
            status_label = VALUES(status_label),
            status_detail = VALUES(status_detail),
            last_seen_at = VALUES(last_seen_at),
            last_success_at = VALUES(last_success_at),
            storage_used_bytes = VALUES(storage_used_bytes),
            storage_quota_bytes = VALUES(storage_quota_bytes),
            raw_json = VALUES(raw_json),
            synced_at = VALUES(synced_at),
            updated_at = CURRENT_TIMESTAMP'
    );

    $st->execute([
        $clientId,
        $vendor,
        vendor_telemetry_truncate((string)($data['vendor_org_id'] ?? ''), 120),
        vendor_telemetry_truncate($vendorDeviceId, 160),
        isset($data['syncro_asset_id']) && (int)$data['syncro_asset_id'] > 0 ? (int)$data['syncro_asset_id'] : null,
        vendor_telemetry_truncate($deviceName, 200) ?? $deviceName,
        vendor_telemetry_truncate($normalizedKey, 220) ?? $normalizedKey,
        vendor_telemetry_truncate((string)($data['device_role'] ?? ''), 40),
        vendor_telemetry_truncate((string)($data['status'] ?? 'UNKNOWN'), 40) ?? 'UNKNOWN',
        vendor_telemetry_truncate((string)($data['status_label'] ?? ''), 120),
        vendor_telemetry_truncate((string)($data['status_detail'] ?? '')),
        $data['last_seen_at'] ?? null,
        $data['last_success_at'] ?? null,
        isset($data['storage_used_bytes']) ? max(0, (int)$data['storage_used_bytes']) : null,
        isset($data['storage_quota_bytes']) ? max(0, (int)$data['storage_quota_bytes']) : null,
        vendor_telemetry_json($data['raw'] ?? null),
        $data['synced_at'] ?? vendor_telemetry_now(),
    ]);

    $select = db()->prepare('SELECT * FROM vendor_device_status WHERE vendor_code = ? AND vendor_device_id = ? LIMIT 1');
    $select->execute([$vendor, $vendorDeviceId]);

    $row = $select->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function vendor_device_status_for_client(int $clientId, ?string $vendorCode = null): array
{
    if ($clientId <= 0) {
        return [];
    }

    if ($vendorCode !== null && vendor_telemetry_normalize_vendor($vendorCode) !== '') {
        $st = db()->prepare(
            'SELECT * FROM vendor_device_status
             WHERE client_id = ? AND vendor_code = ?
             ORDER BY device_name, vendor_code'
        );
        $st->execute([$clientId, vendor_telemetry_normalize_vendor($vendorCode)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    $st = db()->prepare(
        'SELECT * FROM vendor_device_status
         WHERE client_id = ?
         ORDER BY device_name, vendor_code'
    );
    $st->execute([$clientId]);

    return $st->fetchAll(PDO::FETCH_ASSOC);
}
