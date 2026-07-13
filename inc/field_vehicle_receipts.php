<?php
declare(strict_types=1);

/**
 * AFEE service-vehicle receipt drafts.
 *
 * Drafts preserve receipt evidence before the accounting/event record is final.
 * OCR/AI parsing should write suggestions here later, not final ledger rows.
 */

require_once __DIR__ . '/field_vehicles.php';

function field_vehicle_receipt_categories(): array
{
    return [
        'FUEL' => 'Fuel',
        'MAINTENANCE' => 'Maintenance',
        'REPAIR' => 'Repair',
        'PARTS' => 'Parts',
        'TOOLS' => 'Tools',
        'SUPPLIES' => 'Supplies',
        'JOB_MATERIALS' => 'Job materials',
        'EQUIPMENT' => 'Equipment',
        'WARRANTY' => 'Warranty / document',
        'BUSINESS_EXPENSE' => 'Business expense',
        'OTHER' => 'Other',
    ];
}

function field_vehicle_receipt_vehicle_event_category_map(): array
{
    return [
        'FUEL' => [
            'event_type' => 'FUEL',
            'cost_treatment' => 'NORMAL',
            'description' => 'Fuel receipt',
        ],
        'MAINTENANCE' => [
            'event_type' => 'ROUTINE_MAINTENANCE',
            'cost_treatment' => 'NORMAL',
            'description' => 'Maintenance receipt',
        ],
        'REPAIR' => [
            'event_type' => 'REPAIR',
            'cost_treatment' => 'NORMAL',
            'description' => 'Repair receipt',
        ],
        'WARRANTY' => [
            'event_type' => 'OTHER',
            'cost_treatment' => 'NORMAL',
            'description' => 'Warranty document',
        ],
        'OTHER' => [
            'event_type' => 'OTHER',
            'cost_treatment' => 'NORMAL',
            'description' => 'Vehicle receipt',
        ],
    ];
}

function field_vehicle_receipts_ensure_schema(): void
{
    field_vehicles_ensure_schema();

    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_vehicle_receipt_drafts (
            receipt_draft_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,
            vehicle_event_id INT UNSIGNED NULL,

            receipt_status VARCHAR(40) NOT NULL DEFAULT 'CAPTURED',
            receipt_category VARCHAR(40) NOT NULL DEFAULT 'FUEL',
            receipt_date DATE NULL,

            vendor VARCHAR(180) NULL,
            amount DECIMAL(12,2) NULL,
            gallons DECIMAL(10,3) NULL,
            fuel_price_per_gallon DECIMAL(10,4) NULL,
            odometer DECIMAL(12,1) NULL,

            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            storage_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            checksum_sha256 VARCHAR(64) NULL,

            onedrive_folder_path VARCHAR(500) NULL,
            onedrive_item_id VARCHAR(190) NULL,
            onedrive_web_url VARCHAR(500) NULL,
            upload_status VARCHAR(40) NOT NULL DEFAULT 'LOCAL_ONLY',

            parse_status VARCHAR(40) NOT NULL DEFAULT 'NOT_PARSED',
            parse_confidence DECIMAL(5,4) NULL,
            raw_parse_json MEDIUMTEXT NULL,

            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,

            INDEX idx_vehicle_receipt_drafts_vehicle (vehicle_id),
            INDEX idx_vehicle_receipt_drafts_status (receipt_status),
            INDEX idx_vehicle_receipt_drafts_category (receipt_category),
            INDEX idx_vehicle_receipt_drafts_date (receipt_date),
            INDEX idx_vehicle_receipt_drafts_event (vehicle_event_id)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [
        'vehicle_event_id' => "INT UNSIGNED NULL",
        'receipt_status' => "VARCHAR(40) NOT NULL DEFAULT 'CAPTURED'",
        'receipt_category' => "VARCHAR(40) NOT NULL DEFAULT 'FUEL'",
        'receipt_date' => "DATE NULL",
        'vendor' => "VARCHAR(180) NULL",
        'amount' => "DECIMAL(12,2) NULL",
        'gallons' => "DECIMAL(10,3) NULL",
        'fuel_price_per_gallon' => "DECIMAL(10,4) NULL",
        'odometer' => "DECIMAL(12,1) NULL",
        'checksum_sha256' => "VARCHAR(64) NULL",
        'onedrive_folder_path' => "VARCHAR(500) NULL",
        'onedrive_item_id' => "VARCHAR(190) NULL",
        'onedrive_web_url' => "VARCHAR(500) NULL",
        'upload_status' => "VARCHAR(40) NOT NULL DEFAULT 'LOCAL_ONLY'",
        'parse_status' => "VARCHAR(40) NOT NULL DEFAULT 'NOT_PARSED'",
        'parse_confidence' => "DECIMAL(5,4) NULL",
        'raw_parse_json' => "MEDIUMTEXT NULL",
        'deleted_at' => "DATETIME NULL",
    ];

    foreach ($columns as $column => $definition) {
        if (
            function_exists('db_column_exists')
            && !db_column_exists('field_vehicle_receipt_drafts', $column)
        ) {
            try {
                $pdo->exec("
                    ALTER TABLE field_vehicle_receipt_drafts
                    ADD COLUMN {$column} {$definition}
                ");
            } catch (Throwable $e) {
                error_log(
                    'Unable to add field_vehicle_receipt_drafts.'
                    . $column
                    . ': '
                    . $e->getMessage()
                );
            }
        }
    }
}

function field_vehicle_receipt_account_home(): string
{
    $dir = __DIR__;

    if (preg_match('#^(/home/[^/]+)#', $dir, $m)) {
        return $m[1];
    }

    $home = getenv('HOME');

    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    return dirname(__DIR__);
}

function field_vehicle_receipt_storage_dir(): string
{
    return field_vehicle_receipt_account_home() . '/private/mmit-field-ops/vehicle-receipts';
}

function field_vehicle_receipt_vehicle_dir(int $vehicleId): string
{
    return field_vehicle_receipt_storage_dir() . '/vehicle-' . max(0, $vehicleId);
}

function field_vehicle_receipt_safe_filename(string $filename): string
{
    $filename = trim($filename);
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'receipt';
    $filename = trim($filename, '._-');

    return $filename !== '' ? substr($filename, 0, 180) : 'receipt';
}

function field_vehicle_receipt_clean_category(string $category): string
{
    $category = strtoupper(trim($category));
    $allowed = array_keys(field_vehicle_receipt_categories());

    return in_array($category, $allowed, true)
        ? $category
        : 'OTHER';
}

function field_vehicle_receipt_allowed_mime_types(): array
{
    return [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
        'image/tiff',
    ];
}

function field_vehicle_receipt_drafts(
    int $vehicleId,
    int $limit = 25
): array {
    field_vehicle_receipts_ensure_schema();

    $limit = max(1, min(100, $limit));

    $st = db()->prepare("
        SELECT *
        FROM field_vehicle_receipt_drafts
        WHERE vehicle_id = ?
          AND deleted_at IS NULL
        ORDER BY created_at DESC,
                 receipt_draft_id DESC
        LIMIT {$limit}
    ");
    $st->execute([$vehicleId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_vehicle_find_receipt_draft(int $receiptDraftId): ?array
{
    field_vehicle_receipts_ensure_schema();

    if ($receiptDraftId <= 0) {
        return null;
    }

    $st = db()->prepare("
        SELECT d.*, v.vehicle_name
        FROM field_vehicle_receipt_drafts d
        INNER JOIN field_vehicles v ON v.vehicle_id = d.vehicle_id
        WHERE d.receipt_draft_id = ?
          AND d.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$receiptDraftId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_receipt_drafts_by_event_ids(
    int $vehicleId,
    array $eventIds
): array {
    field_vehicle_receipts_ensure_schema();

    $eventIds = array_values(array_unique(array_filter(
        array_map('intval', $eventIds),
        static fn(int $eventId): bool => $eventId > 0
    )));

    if ($vehicleId <= 0 || !$eventIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
    $params = [
        $vehicleId,
        ...$eventIds,
    ];

    $st = db()->prepare("
        SELECT *
        FROM field_vehicle_receipt_drafts
        WHERE vehicle_id = ?
          AND deleted_at IS NULL
          AND vehicle_event_id IN ({$placeholders})
        ORDER BY receipt_draft_id DESC
    ");
    $st->execute($params);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byEvent = [];

    foreach ($rows as $row) {
        $eventId = (int)($row['vehicle_event_id'] ?? 0);

        if ($eventId <= 0) {
            continue;
        }

        $byEvent[$eventId] ??= [];
        $byEvent[$eventId][] = $row;
    }

    return $byEvent;
}

function field_vehicle_receipt_onedrive_root(): string
{
    return defined('ONEDRIVE_FIELD_OPS_ROOT')
        ? trim((string)ONEDRIVE_FIELD_OPS_ROOT)
        : 'MMIT Field Ops';
}

function field_vehicle_receipt_onedrive_folder_path(array $draft): string
{
    require_once __DIR__ . '/onedrive.php';

    $vehicleName = onedrive_sanitize_segment(
        (string)($draft['vehicle_name'] ?? 'Service Vehicle')
    );

    $receiptDate = trim((string)($draft['receipt_date'] ?? ''));
    $createdAt = trim((string)($draft['created_at'] ?? ''));

    $dateBasis = $receiptDate !== ''
        ? $receiptDate
        : ($createdAt !== '' ? $createdAt : date('Y-m-d'));

    try {
        $dt = new DateTimeImmutable($dateBasis);
    } catch (Throwable $e) {
        $dt = new DateTimeImmutable();
    }

    $category = field_vehicle_receipt_clean_category(
        (string)($draft['receipt_category'] ?? 'OTHER')
    );

    $categoryFolders = [
        'FUEL' => 'Fuel',
        'MAINTENANCE' => 'Maintenance',
        'REPAIR' => 'Repairs',
        'PARTS' => 'Parts',
        'TOOLS' => 'Tools',
        'SUPPLIES' => 'Supplies',
        'JOB_MATERIALS' => 'Job Materials',
        'EQUIPMENT' => 'Equipment',
        'WARRANTY' => 'Warranty',
        'BUSINESS_EXPENSE' => 'Business Expenses',
        'OTHER' => 'Other',
    ];

    return onedrive_sanitize_segment(field_vehicle_receipt_onedrive_root())
        . '/' . $dt->format('Y')
        . '/Service Vehicles'
        . '/' . $vehicleName
        . '/Receipts'
        . '/' . ($categoryFolders[$category] ?? 'Other')
        . '/' . $dt->format('Y-m');
}

function field_vehicle_receipt_onedrive_remote_name(array $draft): string
{
    require_once __DIR__ . '/onedrive.php';

    $date = trim((string)($draft['receipt_date'] ?? '')) ?: date('Y-m-d');
    $vendor = trim((string)($draft['vendor'] ?? 'Receipt'));
    $amount = $draft['amount'] !== null && $draft['amount'] !== ''
        ? number_format((float)$draft['amount'], 2, '.', '')
        : '';

    $original = field_vehicle_receipt_safe_filename(
        (string)($draft['original_filename'] ?? 'receipt')
    );

    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $parts = array_filter([
        $date,
        $vendor,
        $amount !== '' ? $amount : null,
        'draft-' . str_pad((string)((int)($draft['receipt_draft_id'] ?? 0)), 6, '0', STR_PAD_LEFT),
    ]);

    $base = onedrive_sanitize_segment(implode('_', $parts));
    $base = preg_replace('/[^A-Za-z0-9._ -]+/', '-', $base) ?? $base;
    $base = trim($base, ' ._-');

    return $base . ($ext !== '' ? '.' . $ext : '');
}

function field_vehicle_receipt_onedrive_unique_name(
    string $accessToken,
    string $folderPath,
    string $remoteName
): string {
    require_once __DIR__ . '/onedrive.php';

    $remoteName = onedrive_sanitize_segment($remoteName);
    $candidate = $remoteName;
    $ext = pathinfo($remoteName, PATHINFO_EXTENSION);
    $base = $ext !== ''
        ? substr($remoteName, 0, -(strlen($ext) + 1))
        : $remoteName;

    for ($i = 0; $i < 50; $i++) {
        if (!onedrive_item_exists($accessToken, $folderPath . '/' . $candidate)) {
            return $candidate;
        }

        $suffix = '-' . ($i + 1);
        $candidate = $ext !== ''
            ? ($base . $suffix . '.' . $ext)
            : ($base . $suffix);
    }

    return uniqid($base . '-', true) . ($ext !== '' ? '.' . $ext : '');
}

function field_vehicle_sync_receipt_draft_to_onedrive(int $receiptDraftId): array
{
    field_vehicle_receipts_ensure_schema();

    require_once __DIR__ . '/onedrive.php';

    $draft = field_vehicle_find_receipt_draft($receiptDraftId);

    if (!$draft) {
        return ['ok' => false, 'errors' => ['Receipt draft not found.']];
    }

    if (!empty($draft['onedrive_item_id']) && !empty($draft['onedrive_web_url'])) {
        return [
            'ok' => true,
            'receipt_draft_id' => $receiptDraftId,
            'message' => 'Receipt draft already synced to OneDrive.',
            'web_url' => (string)$draft['onedrive_web_url'],
        ];
    }

    $path = (string)($draft['storage_path'] ?? '');

    if ($path === '' || !is_file($path)) {
        return ['ok' => false, 'errors' => ['Local receipt file is missing.']];
    }

    if (!onedrive_is_configured()) {
        db()->prepare("
            UPDATE field_vehicle_receipt_drafts
            SET upload_status = 'LOCAL_ONLY'
            WHERE receipt_draft_id = ?
            LIMIT 1
        ")->execute([$receiptDraftId]);

        return ['ok' => false, 'errors' => ['OneDrive is not configured yet.']];
    }

    $token = onedrive_get_valid_access_token();

    if (empty($token['ok']) || empty($token['access_token'])) {
        db()->prepare("
            UPDATE field_vehicle_receipt_drafts
            SET upload_status = 'LOCAL_ONLY'
            WHERE receipt_draft_id = ?
            LIMIT 1
        ")->execute([$receiptDraftId]);

        return [
            'ok' => false,
            'errors' => [(string)($token['error'] ?? 'OneDrive is not connected.')]
        ];
    }

    $accessToken = (string)$token['access_token'];
    $folderPath = field_vehicle_receipt_onedrive_folder_path($draft);

    if (!onedrive_ensure_folder_path($accessToken, $folderPath)) {
        return [
            'ok' => false,
            'errors' => ['Unable to prepare the OneDrive service vehicle receipt folder.']
        ];
    }

    $remoteName = field_vehicle_receipt_onedrive_unique_name(
        $accessToken,
        $folderPath,
        field_vehicle_receipt_onedrive_remote_name($draft)
    );

    $bytes = @file_get_contents($path);

    if (!is_string($bytes) || $bytes === '') {
        return ['ok' => false, 'errors' => ['Unable to read local receipt for OneDrive sync.']];
    }

    $mime = trim((string)($draft['mime_type'] ?? '')) ?: 'application/octet-stream';
    $encodedPath = onedrive_encode_path($folderPath . '/' . $remoteName);

    $upload = onedrive_http_request(
        'PUT',
        'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encodedPath . ':/content',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: ' . $mime,
            'Accept: application/json',
        ],
        $bytes,
        true
    );

    if (empty($upload['ok']) || !is_array($upload['json'] ?? null)) {
        db()->prepare("
            UPDATE field_vehicle_receipt_drafts
            SET upload_status = 'LOCAL_ONLY'
            WHERE receipt_draft_id = ?
            LIMIT 1
        ")->execute([$receiptDraftId]);

        return [
            'ok' => false,
            'errors' => [(string)($upload['error'] ?? 'OneDrive upload failed.')]
        ];
    }

    $item = $upload['json'];
    $itemId = (string)($item['id'] ?? '');
    $webUrl = (string)($item['webUrl'] ?? '');

    db()->prepare("
        UPDATE field_vehicle_receipt_drafts
        SET onedrive_folder_path = ?,
            onedrive_item_id = ?,
            onedrive_web_url = ?,
            upload_status = 'ONEDRIVE_SYNCED'
        WHERE receipt_draft_id = ?
        LIMIT 1
    ")->execute([
        $folderPath,
        $itemId !== '' ? $itemId : null,
        $webUrl !== '' ? $webUrl : null,
        $receiptDraftId,
    ]);

    return [
        'ok' => true,
        'receipt_draft_id' => $receiptDraftId,
        'folder_path' => $folderPath,
        'remote_name' => $remoteName,
        'onedrive_item_id' => $itemId,
        'web_url' => $webUrl,
    ];
}

function field_vehicle_capture_receipt_draft(
    array $input,
    array $file,
    ?int $createdBy = null
): array {
    field_vehicle_receipts_ensure_schema();

    $vehicleId = (int)($input['vehicle_id'] ?? 0);
    $vehicle = field_vehicle_find($vehicleId);

    if (!$vehicle) {
        return ['ok' => false, 'errors' => ['Vehicle not found.']];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Choose a receipt photo or PDF to upload.']];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return ['ok' => false, 'errors' => ['Uploaded receipt is empty.']];
    }

    if ($size > 25 * 1024 * 1024) {
        return ['ok' => false, 'errors' => ['Receipt is too large. Max size is 25 MB.']];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'errors' => ['Upload temporary file is invalid.']];
    }

    $original = field_vehicle_receipt_safe_filename(
        (string)($file['name'] ?? 'receipt')
    );

    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'tif', 'tiff'];

    if ($extension !== '' && !in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'errors' => ['Upload a PDF or receipt image.']];
    }

    $mime = null;

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmp) ?: null;
            finfo_close($finfo);
        }
    }

    if ($mime !== null && !in_array($mime, field_vehicle_receipt_allowed_mime_types(), true)) {
        return ['ok' => false, 'errors' => ['Upload a PDF or receipt image.']];
    }

    $dir = field_vehicle_receipt_vehicle_dir($vehicleId);

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '-' . $original;
    $path = $dir . '/' . $stored;

    if (!@move_uploaded_file($tmp, $path)) {
        return ['ok' => false, 'errors' => ['Could not store uploaded receipt.']];
    }

    @chmod($path, 0640);

    $receiptDate = field_vehicle_nullable_date($input['receipt_date'] ?? null);
    $category = field_vehicle_receipt_clean_category(
        (string)($input['receipt_category'] ?? 'FUEL')
    );

    $amount = field_vehicle_nullable_decimal($input['amount'] ?? null, 2);
    $gallons = field_vehicle_nullable_decimal($input['gallons'] ?? null, 3);
    $fuelPrice = field_vehicle_nullable_decimal(
        $input['fuel_price_per_gallon'] ?? null,
        4
    );
    $odometer = field_vehicle_nullable_decimal($input['odometer'] ?? null, 1);

    if (
        $category === 'FUEL'
        && $fuelPrice === null
        && $amount !== null
        && $amount > 0
        && $gallons !== null
        && $gallons > 0
    ) {
        $fuelPrice = round($amount / $gallons, 4);
    }

    $checksum = hash_file('sha256', $path) ?: null;

    db()->prepare("
        INSERT INTO field_vehicle_receipt_drafts (
            vehicle_id,
            receipt_status,
            receipt_category,
            receipt_date,
            vendor,
            amount,
            gallons,
            fuel_price_per_gallon,
            odometer,
            original_filename,
            stored_filename,
            storage_path,
            mime_type,
            file_size_bytes,
            checksum_sha256,
            upload_status,
            notes,
            created_by
        ) VALUES (?, 'CAPTURED', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'LOCAL_ONLY', ?, ?)
    ")->execute([
        $vehicleId,
        $category,
        $receiptDate,
        trim((string)($input['vendor'] ?? '')) ?: null,
        $amount,
        $gallons,
        $fuelPrice,
        $odometer,
        $original,
        $stored,
        $path,
        $mime,
        filesize($path) ?: $size,
        $checksum,
        trim((string)($input['notes'] ?? '')) ?: null,
        $createdBy && $createdBy > 0 ? $createdBy : null,
    ]);

    $receiptDraftId = (int)db()->lastInsertId();
    $sync = field_vehicle_sync_receipt_draft_to_onedrive($receiptDraftId);

    return [
        'ok' => true,
        'receipt_draft_id' => $receiptDraftId,
        'vehicle_id' => $vehicleId,
        'sync' => $sync,
        'warnings' => empty($sync['ok'])
            ? (array)($sync['errors'] ?? ['Receipt saved locally but OneDrive sync failed.'])
            : [],
    ];
}

function field_vehicle_convert_receipt_draft_to_event(
    int $receiptDraftId,
    ?int $createdBy = null
): array {
    field_vehicle_receipts_ensure_schema();

    $draft = field_vehicle_find_receipt_draft($receiptDraftId);

    if (!$draft) {
        return [
            'ok' => false,
            'errors' => ['Receipt draft not found.'],
        ];
    }

    $vehicleId = (int)($draft['vehicle_id'] ?? 0);

    if ($vehicleId <= 0 || !field_vehicle_find($vehicleId)) {
        return [
            'ok' => false,
            'errors' => ['Vehicle not found for receipt draft.'],
        ];
    }

    if (!empty($draft['vehicle_event_id'])) {
        return [
            'ok' => true,
            'vehicle_id' => $vehicleId,
            'vehicle_event_id' => (int)$draft['vehicle_event_id'],
            'already_linked' => true,
        ];
    }

    $category = field_vehicle_receipt_clean_category(
        (string)($draft['receipt_category'] ?? 'OTHER')
    );

    $map = field_vehicle_receipt_vehicle_event_category_map();

    if (!isset($map[$category])) {
        return [
            'ok' => false,
            'errors' => [
                'This receipt category is receipt-only for now. Route it to expenses/equipment later.',
            ],
        ];
    }

    $eventDefaults = $map[$category];

    $eventDate = field_vehicle_nullable_date($draft['receipt_date'] ?? null);

    if ($eventDate === null) {
        $eventDate = field_vehicle_nullable_date(
            substr((string)($draft['created_at'] ?? ''), 0, 10)
        );
    }

    $eventDate = $eventDate ?: date('Y-m-d');

    $notes = trim((string)($draft['notes'] ?? ''));
    $receiptLines = [
        'Created from receipt draft #' . $receiptDraftId . '.',
    ];

    $eventNotes = trim(
        ($notes !== '' ? $notes . "\n\n" : '')
        . implode("\n", $receiptLines)
    );

    $result = field_vehicle_save_event(
        [
            'vehicle_id' => $vehicleId,
            'event_type' => (string)$eventDefaults['event_type'],
            'cost_treatment' => (string)$eventDefaults['cost_treatment'],
            'event_date' => $eventDate,
            'odometer' => $draft['odometer'] ?? null,
            'vendor' => $draft['vendor'] ?? null,
            'description' => (string)$eventDefaults['description'],
            'amount' => $draft['amount'] ?? 0,
            'gallons' => $draft['gallons'] ?? null,
            'fuel_price_per_gallon' => $draft['fuel_price_per_gallon'] ?? null,
            'notes' => $eventNotes,
        ],
        $createdBy
    );

    if (empty($result['ok'])) {
        return $result;
    }

    $vehicleEventId = (int)($result['vehicle_event_id'] ?? 0);

    db()->prepare("
        UPDATE field_vehicle_receipt_drafts
        SET vehicle_event_id = ?,
            receipt_status = 'LINKED',
            updated_at = NOW()
        WHERE receipt_draft_id = ?
          AND vehicle_id = ?
        LIMIT 1
    ")->execute([
        $vehicleEventId,
        $receiptDraftId,
        $vehicleId,
    ]);

    return [
        'ok' => true,
        'vehicle_id' => $vehicleId,
        'vehicle_event_id' => $vehicleEventId,
        'receipt_draft_id' => $receiptDraftId,
        'created' => true,
    ];
}
