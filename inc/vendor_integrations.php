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

function vendor_telemetry_bytes_label(int|string|null $bytes): string
{
    $value = max(0, (float) ($bytes ?? 0));
    if ($value <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $power = min((int) floor(log($value, 1024)), count($units) - 1);
    $scaled = $value / (1024 ** $power);
    $precision = $scaled >= 100 || $power === 0 ? 0 : ($scaled >= 10 ? 1 : 2);

    return number_format($scaled, $precision) . ' ' . $units[$power];
}

function vendor_telemetry_list_integrations(): array
{
    $stmt = db()->query("
        SELECT
            vi.*,
            COALESCE(link_counts.link_count, 0) AS linked_clients,
            COALESCE(device_counts.device_count, 0) AS cached_devices,
            latest_run.started_at AS latest_run_started_at,
            latest_run.finished_at AS latest_run_finished_at,
            latest_run.status AS latest_run_status,
            latest_run.message AS latest_run_message
        FROM vendor_integrations vi
        LEFT JOIN (
            SELECT vendor_code, COUNT(*) AS link_count
            FROM vendor_client_links
            GROUP BY vendor_code
        ) link_counts ON link_counts.vendor_code = vi.vendor_code
        LEFT JOIN (
            SELECT vendor_code, COUNT(*) AS device_count
            FROM vendor_device_status
            GROUP BY vendor_code
        ) device_counts ON device_counts.vendor_code = vi.vendor_code
        LEFT JOIN vendor_sync_runs latest_run
            ON latest_run.run_id = (
                SELECT vsr.run_id
                FROM vendor_sync_runs vsr
                WHERE vsr.vendor_code = vi.vendor_code
                ORDER BY vsr.started_at DESC, vsr.run_id DESC
                LIMIT 1
            )
        ORDER BY vi.display_name, vi.vendor_code
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function vendor_telemetry_list_client_links(int $limit = 100): array
{
    $limit = max(1, min(500, $limit));

    $stmt = db()->prepare("
        SELECT
            vcl.*,
            c.legal_name,
            c.dba_name,
            c.client_code,
            c.status AS client_status,
            COALESCE(device_counts.device_count, 0) AS cached_devices,
            device_counts.latest_success_at,
            device_counts.latest_synced_at,
            device_counts.storage_used_bytes
        FROM vendor_client_links vcl
        INNER JOIN clients c ON c.client_id = vcl.client_id
        LEFT JOIN (
            SELECT
                client_id,
                vendor_code,
                COUNT(*) AS device_count,
                MAX(last_success_at) AS latest_success_at,
                MAX(synced_at) AS latest_synced_at,
                SUM(COALESCE(storage_used_bytes, 0)) AS storage_used_bytes
            FROM vendor_device_status
            GROUP BY client_id, vendor_code
        ) device_counts
            ON device_counts.client_id = vcl.client_id
            AND device_counts.vendor_code = vcl.vendor_code
        ORDER BY vcl.vendor_code, c.legal_name, vcl.vendor_org_name
        LIMIT {$limit}
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function vendor_telemetry_list_device_statuses(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));

    $stmt = db()->prepare("
        SELECT
            vds.*,
            c.legal_name,
            c.dba_name,
            c.client_code,
            vcl.vendor_org_name,
            vcl.link_status,
            vi.display_name AS vendor_display_name
        FROM vendor_device_status vds
        INNER JOIN clients c ON c.client_id = vds.client_id
        LEFT JOIN vendor_client_links vcl
            ON vcl.client_id = vds.client_id
            AND vcl.vendor_code = vds.vendor_code
        LEFT JOIN vendor_integrations vi
            ON vi.vendor_code = vds.vendor_code
        ORDER BY vds.synced_at DESC, vds.vendor_code, c.legal_name, vds.device_name
        LIMIT {$limit}
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function vendor_telemetry_list_sync_runs(int $limit = 50): array
{
    $limit = max(1, min(250, $limit));

    $stmt = db()->prepare("
        SELECT
            vsr.*,
            vi.display_name AS vendor_display_name
        FROM vendor_sync_runs vsr
        LEFT JOIN vendor_integrations vi
            ON vi.vendor_code = vsr.vendor_code
        ORDER BY vsr.started_at DESC, vsr.run_id DESC
        LIMIT {$limit}
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function vendor_telemetry_dashboard_snapshot(): array
{
    $integrations = vendor_telemetry_list_integrations();
    $clientLinks = vendor_telemetry_list_client_links();
    $deviceStatuses = vendor_telemetry_list_device_statuses();
    $syncRuns = vendor_telemetry_list_sync_runs();

    $healthyDevices = 0;
    $attentionDevices = 0;
    $storageBytes = 0;

    foreach ($deviceStatuses as $row) {
        $status = strtoupper(trim((string) ($row['status'] ?? $row['status_label'] ?? '')));
        if (in_array($status, ['ACTIVE', 'COMPLETED', 'COMPLETE', 'SUCCESS', 'OK', 'HEALTHY', 'PROTECTED', 'REPORTED', 'SYNCED'], true)) {
            $healthyDevices++;
        } else {
            $attentionDevices++;
        }

        $storageBytes += max(0, (int) ($row['storage_used_bytes'] ?? 0));
    }

    return [
        'integrations' => $integrations,
        'client_links' => $clientLinks,
        'device_statuses' => $deviceStatuses,
        'sync_runs' => $syncRuns,
        'summary' => [
            'integrations' => count($integrations),
            'enabled_integrations' => count(array_filter($integrations, static fn(array $row): bool => (int) ($row['enabled'] ?? 0) === 1)),
            'client_links' => count($clientLinks),
            'cached_devices' => count($deviceStatuses),
            'healthy_devices' => $healthyDevices,
            'attention_devices' => $attentionDevices,
            'storage_used_bytes' => $storageBytes,
            'storage_used_label' => vendor_telemetry_bytes_label($storageBytes),
        ],
    ];
}

