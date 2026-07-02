<?php
declare(strict_types=1);

/**
 * MMIT Field Ops helpers.
 *
 * Tracks FieldNation-style work orders, buyers, inventory, and job economics.
 */

function field_ops_ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_buyers (
            buyer_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(60) NOT NULL DEFAULT 'FieldNation',
            buyer_name VARCHAR(160) NOT NULL,
            contact_name VARCHAR(160) NULL,
            contact_email VARCHAR(190) NULL,
            contact_phone VARCHAR(60) NULL,
            paperwork_burden VARCHAR(40) NOT NULL DEFAULT 'UNKNOWN',
            materials_policy VARCHAR(80) NOT NULL DEFAULT 'UNKNOWN',
            rating_internal TINYINT UNSIGNED NULL,
            is_preferred TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_field_buyers_platform_name (platform, buyer_name),
            INDEX idx_field_buyers_active (active),
            INDEX idx_field_buyers_preferred (is_preferred)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_inventory_items (
            item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(80) NULL,
            item_name VARCHAR(180) NOT NULL,
            category VARCHAR(80) NOT NULL DEFAULT 'General',
            location VARCHAR(80) NOT NULL DEFAULT 'Truck',
            unit VARCHAR(40) NOT NULL DEFAULT 'each',
            qty_on_hand DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reorder_qty DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            default_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            default_bill_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            taxable TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_field_inventory_active (active),
            INDEX idx_field_inventory_category (category),
            INDEX idx_field_inventory_reorder (active, qty_on_hand, reorder_point)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_work_orders (
            work_order_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(60) NOT NULL DEFAULT 'FieldNation',
            external_work_order_number VARCHAR(120) NULL,
            buyer_id INT UNSIGNED NULL,
            title VARCHAR(220) NOT NULL,
            work_type VARCHAR(80) NOT NULL DEFAULT 'Field service',
            site_name VARCHAR(180) NULL,
            site_address VARCHAR(255) NULL,
            city VARCHAR(120) NULL,
            state VARCHAR(40) NULL,
            scheduled_start_at DATETIME NULL,
            scheduled_end_at DATETIME NULL,
            checked_in_at DATETIME NULL,
            checked_out_at DATETIME NULL,
            actual_left_site_at DATETIME NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'REQUESTED',
            payment_status VARCHAR(40) NOT NULL DEFAULT 'UNPAID',
            gross_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            platform_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            bonus_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reimbursement_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            mileage DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            mileage_rate DECIMAL(12,4) NOT NULL DEFAULT 0.0000,
            drive_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            onsite_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            admin_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_field_work_orders_status (status),
            INDEX idx_field_work_orders_payment_status (payment_status),
            INDEX idx_field_work_orders_scheduled (scheduled_start_at),
            INDEX idx_field_work_orders_buyer (buyer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_work_order_materials (
            material_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT UNSIGNED NOT NULL,
            inventory_item_id INT UNSIGNED NULL,
            description VARCHAR(220) NOT NULL,
            quantity_used DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            bill_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            billable TINYINT(1) NOT NULL DEFAULT 0,
            reimbursable TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_field_materials_work_order (work_order_id),
            INDEX idx_field_materials_item (inventory_item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_work_order_expenses (
            expense_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT UNSIGNED NOT NULL,
            expense_date DATE NOT NULL,
            vendor VARCHAR(160) NULL,
            category VARCHAR(80) NOT NULL DEFAULT 'General',
            description VARCHAR(220) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            reimbursable TINYINT(1) NOT NULL DEFAULT 0,
            reimbursed TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_field_expenses_work_order (work_order_id),
            INDEX idx_field_expenses_date (expense_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'client_id' => "INT UNSIGNED NULL",
        'invoice_id' => "INT UNSIGNED NULL",
        'invoice_created_at' => "DATETIME NULL",

        'delete_reason' => "VARCHAR(255) NULL",
        'source_system' => "VARCHAR(80) NULL",
        'source_message_id' => "VARCHAR(190) NULL",
        'source_url' => "VARCHAR(500) NULL",
        'requested_at' => "DATETIME NULL",
        'assigned_at' => "DATETIME NULL"
    ] as $column => $definition) {
        if (function_exists('db_column_exists') && !db_column_exists('field_work_orders', $column)) {
            try {
                $pdo->exec("ALTER TABLE field_work_orders ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                error_log('Unable to add field_work_orders.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_work_order_attachments (
            attachment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT UNSIGNED NOT NULL,
            attachment_type VARCHAR(60) NOT NULL DEFAULT 'document',
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NOT NULL,
            storage_path VARCHAR(500) NOT NULL,
            mime_type VARCHAR(120) NULL,
            file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
            description VARCHAR(255) NULL,
            onedrive_item_id VARCHAR(190) NULL,
            onedrive_web_url VARCHAR(500) NULL,
            uploaded_by INT UNSIGNED NULL,
            uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            INDEX idx_field_attachments_work_order (work_order_id),
            INDEX idx_field_attachments_type (attachment_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    foreach ([
        'onedrive_item_id' => "VARCHAR(190) NULL",
        'onedrive_web_url' => "VARCHAR(500) NULL"
    ] as $column => $definition) {
        if (function_exists('db_column_exists') && db_table_exists('field_work_order_attachments') && !db_column_exists('field_work_order_attachments', $column)) {
            try {
                $pdo->exec("ALTER TABLE field_work_order_attachments ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                error_log('Unable to add field_work_order_attachments.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_work_order_time_entries (
            time_entry_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            work_order_id INT UNSIGNED NOT NULL,
            entry_type VARCHAR(40) NOT NULL DEFAULT 'onsite',
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            minutes INT UNSIGNED NOT NULL DEFAULT 0,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_field_time_work_order (work_order_id),
            INDEX idx_field_time_type (entry_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function field_ops_seed_defaults(): void
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        INSERT INTO field_buyers
          (platform, buyer_name, paperwork_burden, materials_policy, rating_internal, is_preferred, active, notes)
        VALUES
          ('FieldNation', 'FieldNation', 'LOW', 'VARIES_BY_WORK_ORDER', 4, 1, 1, 'Default FieldNation buyer bucket for manually entered work orders.')
        ON DUPLICATE KEY UPDATE buyer_name = buyer_name
    ");
    $st->execute();

    $direct = db()->prepare("
        INSERT INTO field_buyers
          (platform, buyer_name, paperwork_burden, materials_policy, rating_internal, is_preferred, active, notes)
        VALUES
          ('Direct', 'Direct / One-Off Work', 'LOW', 'MMIT_STANDARD', 5, 1, 1, 'Default bucket for direct break-fix, one-off, referral, and non-FieldNation jobs.')
        ON DUPLICATE KEY UPDATE buyer_name = buyer_name
    ");
    $direct->execute();
}

function field_ops_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function field_ops_money($value): float
{
    $value = preg_replace('/[^0-9.\-]/', '', (string)$value) ?? '';

    return round((float)$value, 2);
}

function field_ops_money_input_value($value): string
{
    return '$' . number_format((float)$value, 2, '.', '');
}

function field_ops_datetime_input_value($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return '';
    }
}

function field_ops_datetime_display($value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Not scheduled';
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function field_ops_decimal($value, int $scale = 2): float
{
    return round((float)str_replace(',', '', (string)$value), $scale);
}

function field_ops_int($value): int
{
    return max(0, (int)$value);
}

function field_ops_datetime_or_null($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        return $value . ':00';
    }

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        return $value;
    }

    return null;
}

function field_ops_date_or_today($value): string
{
    $value = trim((string)$value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $value;
    }

    return date('Y-m-d');
}

function field_ops_sources(): array
{
    return [
        'FieldNation',
        'Direct',
        'Referral',
        'Existing Client',
        'QR / Website',
        'Other',
    ];
}

function field_ops_work_statuses(): array
{
    return [
        'REQUESTED',
        'ASSIGNED',
        'SCHEDULED',
        'CHECKED_IN',
        'IN_PROGRESS',
        'RELEASED_BY_LEAD',
        'CHECKED_OUT',
        'SUBMITTED',
        'APPROVED',
        'PAID',
        'CANCELLED',
        'DECLINED',
    ];
}

function field_ops_payment_statuses(): array
{
    return ['UNPAID', 'PENDING', 'PAID', 'REIMBURSED', 'DISPUTED', 'VOID'];
}

function field_ops_clean_status(string $value, array $allowed, string $fallback): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9_]+/', '_', $value) ?? '';

    return in_array($value, $allowed, true) ? $value : $fallback;
}

function field_ops_buyers(bool $activeOnly = true): array
{
    field_ops_ensure_schema();

    $sql = "SELECT * FROM field_buyers";
    if ($activeOnly) {
        $sql .= " WHERE active = 1";
    }
    $sql .= " ORDER BY is_preferred DESC, buyer_name ASC";

    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_inventory_items(bool $activeOnly = true): array
{
    field_ops_ensure_schema();

    $sql = "SELECT * FROM field_inventory_items";
    if ($activeOnly) {
        $sql .= " WHERE active = 1";
    }
    $sql .= " ORDER BY category ASC, item_name ASC";

    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_work_orders(int $limit = 100): array
{
    field_ops_ensure_schema();

    $limit = max(1, min(500, $limit));

    $sql = "
        SELECT wo.*,
               b.buyer_name,
               COALESCE(mat.material_cost, 0) AS material_cost,
               COALESCE(exp.expense_cost, 0) AS expense_cost,
               (
                 wo.gross_pay + wo.bonus_pay + wo.reimbursement_amount
                 - wo.platform_fee
                 - COALESCE(mat.material_cost, 0)
                 - COALESCE(exp.expense_cost, 0)
               ) AS estimated_net
        FROM field_work_orders wo
        LEFT JOIN field_buyers b ON b.buyer_id = wo.buyer_id
        LEFT JOIN (
            SELECT work_order_id, SUM(quantity_used * unit_cost) AS material_cost
            FROM field_work_order_materials
            GROUP BY work_order_id
        ) mat ON mat.work_order_id = wo.work_order_id
        LEFT JOIN (
            SELECT work_order_id, SUM(amount) AS expense_cost
            FROM field_work_order_expenses
            GROUP BY work_order_id
        ) exp ON exp.work_order_id = wo.work_order_id
        WHERE wo.deleted_at IS NULL
        ORDER BY COALESCE(wo.scheduled_start_at, wo.created_at) DESC, wo.work_order_id DESC
        LIMIT {$limit}
    ";

    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_save_buyer(array $input): array
{
    field_ops_ensure_schema();

    $buyerName = trim((string)($input['buyer_name'] ?? ''));
    $platform = trim((string)($input['platform'] ?? 'FieldNation')) ?: 'FieldNation';

    if ($buyerName === '') {
        return ['ok' => false, 'errors' => ['Buyer name is required.']];
    }

    $st = db()->prepare("
        INSERT INTO field_buyers
          (platform, buyer_name, contact_name, contact_email, contact_phone, paperwork_burden, materials_policy, rating_internal, is_preferred, active, notes)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ON DUPLICATE KEY UPDATE
          contact_name = VALUES(contact_name),
          contact_email = VALUES(contact_email),
          contact_phone = VALUES(contact_phone),
          paperwork_burden = VALUES(paperwork_burden),
          materials_policy = VALUES(materials_policy),
          rating_internal = VALUES(rating_internal),
          is_preferred = VALUES(is_preferred),
          notes = VALUES(notes),
          updated_at = NOW()
    ");

    $st->execute([
        $platform,
        $buyerName,
        trim((string)($input['contact_name'] ?? '')) ?: null,
        trim((string)($input['contact_email'] ?? '')) ?: null,
        trim((string)($input['contact_phone'] ?? '')) ?: null,
        strtoupper(trim((string)($input['paperwork_burden'] ?? 'UNKNOWN'))) ?: 'UNKNOWN',
        strtoupper(trim((string)($input['materials_policy'] ?? 'UNKNOWN'))) ?: 'UNKNOWN',
        field_ops_int($input['rating_internal'] ?? 0) ?: null,
        !empty($input['is_preferred']) ? 1 : 0,
        trim((string)($input['notes'] ?? '')) ?: null,
    ]);

    return ['ok' => true];
}

function field_ops_save_inventory_item(array $input): array
{
    field_ops_ensure_schema();

    $name = trim((string)($input['item_name'] ?? ''));

    if ($name === '') {
        return ['ok' => false, 'errors' => ['Inventory item name is required.']];
    }

    $st = db()->prepare("
        INSERT INTO field_inventory_items
          (sku, item_name, category, location, unit, qty_on_hand, reorder_point, reorder_qty, default_unit_cost, default_bill_price, taxable, active, notes)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
    ");

    $st->execute([
        trim((string)($input['sku'] ?? '')) ?: null,
        $name,
        trim((string)($input['category'] ?? 'General')) ?: 'General',
        trim((string)($input['location'] ?? 'Truck')) ?: 'Truck',
        trim((string)($input['unit'] ?? 'each')) ?: 'each',
        field_ops_decimal($input['qty_on_hand'] ?? 0),
        field_ops_decimal($input['reorder_point'] ?? 0),
        field_ops_decimal($input['reorder_qty'] ?? 0),
        field_ops_money($input['default_unit_cost'] ?? 0),
        field_ops_money($input['default_bill_price'] ?? 0),
        !empty($input['taxable']) ? 1 : 0,
        trim((string)($input['notes'] ?? '')) ?: null,
    ]);

    return ['ok' => true, 'item_id' => (int)db()->lastInsertId()];
}

function field_ops_save_work_order(array $input, int $userId = 0): array
{
    field_ops_ensure_schema();

    $title = trim((string)($input['title'] ?? ''));

    if ($title === '') {
        return ['ok' => false, 'errors' => ['Work order title is required.']];
    }

    $status = field_ops_clean_status((string)($input['status'] ?? 'REQUESTED'), field_ops_work_statuses(), 'REQUESTED');
    $paymentStatus = field_ops_clean_status((string)($input['payment_status'] ?? 'UNPAID'), field_ops_payment_statuses(), 'UNPAID');

    $st = db()->prepare("
        INSERT INTO field_work_orders
          (platform, external_work_order_number, buyer_id, title, work_type, site_name, site_address, city, state,
           scheduled_start_at, scheduled_end_at, checked_in_at, checked_out_at, actual_left_site_at,
           status, payment_status, gross_pay, platform_fee, bonus_pay, reimbursement_amount,
           mileage, mileage_rate, drive_minutes, onsite_minutes, admin_minutes, notes, created_by)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $st->execute([
        trim((string)($input['platform'] ?? 'FieldNation')) ?: 'FieldNation',
        trim((string)($input['external_work_order_number'] ?? '')) ?: null,
        !empty($input['buyer_id']) ? (int)$input['buyer_id'] : null,
        $title,
        trim((string)($input['work_type'] ?? 'Field service')) ?: 'Field service',
        trim((string)($input['site_name'] ?? '')) ?: null,
        trim((string)($input['site_address'] ?? '')) ?: null,
        trim((string)($input['city'] ?? '')) ?: null,
        trim((string)($input['state'] ?? '')) ?: null,
        field_ops_datetime_or_null($input['scheduled_start_at'] ?? ''),
        field_ops_datetime_or_null($input['scheduled_end_at'] ?? ''),
        field_ops_datetime_or_null($input['checked_in_at'] ?? ''),
        field_ops_datetime_or_null($input['checked_out_at'] ?? ''),
        field_ops_datetime_or_null($input['actual_left_site_at'] ?? ''),
        $status,
        $paymentStatus,
        field_ops_money($input['gross_pay'] ?? 0),
        field_ops_money($input['platform_fee'] ?? 0),
        field_ops_money($input['bonus_pay'] ?? 0),
        field_ops_money($input['reimbursement_amount'] ?? 0),
        field_ops_decimal($input['mileage'] ?? 0),
        field_ops_decimal($input['mileage_rate'] ?? 0, 4),
        field_ops_int($input['drive_minutes'] ?? 0),
        field_ops_int($input['onsite_minutes'] ?? 0),
        field_ops_int($input['admin_minutes'] ?? 0),
        trim((string)($input['notes'] ?? '')) ?: null,
        $userId > 0 ? $userId : null,
    ]);

    return ['ok' => true, 'work_order_id' => (int)db()->lastInsertId()];
}

function field_ops_discard_work_order(int $workOrderId, string $reason = ''): array
{
    field_ops_ensure_schema();

    if ($workOrderId <= 0) {
        return ['ok' => false, 'errors' => ['Invalid work order.']];
    }

    $reason = trim($reason);
    $reason = $reason !== '' ? substr($reason, 0, 255) : 'Discarded from Field Ops dashboard.';

    $st = db()->prepare("
        UPDATE field_work_orders
        SET deleted_at = NOW(),
            delete_reason = ?,
            status = CASE
                WHEN status IN ('REQUESTED', 'ASSIGNED', 'SCHEDULED') THEN 'DECLINED'
                ELSE status
            END,
            updated_at = NOW()
        WHERE work_order_id = ?
        LIMIT 1
    ");

    $st->execute([$reason, $workOrderId]);

    return ['ok' => true];
}


function field_ops_find_work_order(int $workOrderId): ?array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT wo.*,
               b.buyer_name,
               b.platform AS buyer_platform
        FROM field_work_orders wo
        LEFT JOIN field_buyers b ON b.buyer_id = wo.buyer_id
        WHERE wo.work_order_id = ?
          AND wo.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$workOrderId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_ops_work_order_materials(int $workOrderId): array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT m.*, i.item_name, i.category, i.location, i.unit
        FROM field_work_order_materials m
        LEFT JOIN field_inventory_items i ON i.item_id = m.inventory_item_id
        WHERE m.work_order_id = ?
        ORDER BY m.material_id DESC
    ");
    $st->execute([$workOrderId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_work_order_expenses(int $workOrderId): array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT *
        FROM field_work_order_expenses
        WHERE work_order_id = ?
        ORDER BY expense_date DESC, expense_id DESC
    ");
    $st->execute([$workOrderId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_work_order_time_entries(int $workOrderId): array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT *
        FROM field_work_order_time_entries
        WHERE work_order_id = ?
        ORDER BY created_at DESC, time_entry_id DESC
    ");
    $st->execute([$workOrderId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_work_order_totals(int $workOrderId): array
{
    $wo = field_ops_find_work_order($workOrderId);

    if (!$wo) {
        return [];
    }

    $st = db()->prepare("
        SELECT COALESCE(SUM(quantity_used * unit_cost), 0) AS material_cost,
               COALESCE(SUM(CASE WHEN billable = 1 THEN quantity_used * bill_price ELSE 0 END), 0) AS material_billable
        FROM field_work_order_materials
        WHERE work_order_id = ?
    ");
    $st->execute([$workOrderId]);
    $materials = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = db()->prepare("
        SELECT COALESCE(SUM(amount), 0) AS expense_cost,
               COALESCE(SUM(CASE WHEN reimbursable = 1 THEN amount ELSE 0 END), 0) AS reimbursable_expenses
        FROM field_work_order_expenses
        WHERE work_order_id = ?
    ");
    $st->execute([$workOrderId]);
    $expenses = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = db()->prepare("
        SELECT COALESCE(SUM(minutes), 0) AS detail_minutes
        FROM field_work_order_time_entries
        WHERE work_order_id = ?
    ");
    $st->execute([$workOrderId]);
    $detailMinutes = (int)($st->fetchColumn() ?: 0);

    $baseMinutes = (int)$wo['drive_minutes'] + (int)$wo['onsite_minutes'] + (int)$wo['admin_minutes'];
    $totalMinutes = $detailMinutes > 0 ? $detailMinutes : $baseMinutes;

    $gross = (float)$wo['gross_pay'] + (float)$wo['bonus_pay'] + (float)$wo['reimbursement_amount'];
    $fees = (float)$wo['platform_fee'];
    $mileageCost = (float)$wo['mileage'] * (float)$wo['mileage_rate'];
    $materialCost = (float)($materials['material_cost'] ?? 0);
    $expenseCost = (float)($expenses['expense_cost'] ?? 0);
    $net = $gross - $fees - $mileageCost - $materialCost - $expenseCost;
    $hours = $totalMinutes / 60;

    return [
        'gross' => $gross,
        'fees' => $fees,
        'mileage_cost' => $mileageCost,
        'material_cost' => $materialCost,
        'material_billable' => (float)($materials['material_billable'] ?? 0),
        'expense_cost' => $expenseCost,
        'reimbursable_expenses' => (float)($expenses['reimbursable_expenses'] ?? 0),
        'estimated_net' => $net,
        'total_minutes' => $totalMinutes,
        'total_hours' => $hours,
        'effective_hourly' => $hours > 0 ? ($net / $hours) : 0,
    ];
}

function field_ops_add_material_pull(array $input): array
{
    field_ops_ensure_schema();

    $workOrderId = (int)($input['work_order_id'] ?? 0);
    $itemId = (int)($input['inventory_item_id'] ?? 0);
    $qty = field_ops_decimal($input['quantity_used'] ?? 0);

    if ($workOrderId <= 0 || !field_ops_find_work_order($workOrderId)) {
        return ['ok' => false, 'errors' => ['Valid work order is required.']];
    }

    if ($qty <= 0) {
        return ['ok' => false, 'errors' => ['Quantity used must be greater than zero.']];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $item = null;

        if ($itemId > 0) {
            $st = $pdo->prepare("SELECT * FROM field_inventory_items WHERE item_id = ? LIMIT 1 FOR UPDATE");
            $st->execute([$itemId]);
            $item = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $description = trim((string)($input['description'] ?? ''));

        if ($description === '' && $item) {
            $description = (string)$item['item_name'];
        }

        if ($description === '') {
            throw new RuntimeException('Material description is required.');
        }

        $unitCost = isset($input['unit_cost']) && (string)$input['unit_cost'] !== ''
            ? field_ops_money($input['unit_cost'])
            : (float)($item['default_unit_cost'] ?? 0);

        $billPrice = isset($input['bill_price']) && (string)$input['bill_price'] !== ''
            ? field_ops_money($input['bill_price'])
            : (float)($item['default_bill_price'] ?? 0);

        $st = $pdo->prepare("
            INSERT INTO field_work_order_materials
              (work_order_id, inventory_item_id, description, quantity_used, unit_cost, bill_price, billable, reimbursable, notes)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $st->execute([
            $workOrderId,
            $itemId > 0 ? $itemId : null,
            $description,
            $qty,
            $unitCost,
            $billPrice,
            !empty($input['billable']) ? 1 : 0,
            !empty($input['reimbursable']) ? 1 : 0,
            trim((string)($input['notes'] ?? '')) ?: null,
        ]);

        if ($itemId > 0) {
            $upd = $pdo->prepare("
                UPDATE field_inventory_items
                SET qty_on_hand = qty_on_hand - ?,
                    updated_at = NOW()
                WHERE item_id = ?
                LIMIT 1
            ");
            $upd->execute([$qty, $itemId]);
        }

        $pdo->commit();

        return ['ok' => true];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'errors' => ['Material pull failed: ' . $e->getMessage()]];
    }
}

function field_ops_add_expense(array $input): array
{
    field_ops_ensure_schema();

    $workOrderId = (int)($input['work_order_id'] ?? 0);

    if ($workOrderId <= 0 || !field_ops_find_work_order($workOrderId)) {
        return ['ok' => false, 'errors' => ['Valid work order is required.']];
    }

    $description = trim((string)($input['description'] ?? ''));

    if ($description === '') {
        return ['ok' => false, 'errors' => ['Expense description is required.']];
    }

    db()->prepare("
        INSERT INTO field_work_order_expenses
          (work_order_id, expense_date, vendor, category, description, amount, reimbursable, reimbursed, notes)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $workOrderId,
        field_ops_date_or_today($input['expense_date'] ?? ''),
        trim((string)($input['vendor'] ?? '')) ?: null,
        trim((string)($input['category'] ?? 'General')) ?: 'General',
        $description,
        field_ops_money($input['amount'] ?? 0),
        !empty($input['reimbursable']) ? 1 : 0,
        !empty($input['reimbursed']) ? 1 : 0,
        trim((string)($input['notes'] ?? '')) ?: null,
    ]);

    return ['ok' => true];
}

function field_ops_add_time_entry(array $input): array
{
    field_ops_ensure_schema();

    $workOrderId = (int)($input['work_order_id'] ?? 0);

    if ($workOrderId <= 0 || !field_ops_find_work_order($workOrderId)) {
        return ['ok' => false, 'errors' => ['Valid work order is required.']];
    }

    $minutes = field_ops_int($input['minutes'] ?? 0);

    if ($minutes <= 0) {
        return ['ok' => false, 'errors' => ['Minutes must be greater than zero.']];
    }

    $type = strtolower(trim((string)($input['entry_type'] ?? 'onsite')));
    $type = preg_replace('/[^a-z0-9_]+/', '_', $type) ?: 'onsite';

    db()->prepare("
        INSERT INTO field_work_order_time_entries
          (work_order_id, entry_type, started_at, ended_at, minutes, notes)
        VALUES
          (?, ?, ?, ?, ?, ?)
    ")->execute([
        $workOrderId,
        $type,
        field_ops_datetime_or_null($input['started_at'] ?? ''),
        field_ops_datetime_or_null($input['ended_at'] ?? ''),
        $minutes,
        trim((string)($input['notes'] ?? '')) ?: null,
    ]);

    return ['ok' => true];
}

function field_ops_update_work_order_state(array $input): array
{
    field_ops_ensure_schema();

    $workOrderId = (int)($input['work_order_id'] ?? 0);

    if ($workOrderId <= 0 || !field_ops_find_work_order($workOrderId)) {
        return ['ok' => false, 'errors' => ['Valid work order is required.']];
    }

    $status = field_ops_clean_status((string)($input['status'] ?? 'REQUESTED'), field_ops_work_statuses(), 'REQUESTED');
    $paymentStatus = field_ops_clean_status((string)($input['payment_status'] ?? 'UNPAID'), field_ops_payment_statuses(), 'UNPAID');

    db()->prepare("
        UPDATE field_work_orders
        SET status = ?,
            payment_status = ?,
            gross_pay = ?,
            platform_fee = ?,
            bonus_pay = ?,
            reimbursement_amount = ?,
            mileage = ?,
            mileage_rate = ?,
            drive_minutes = ?,
            onsite_minutes = ?,
            admin_minutes = ?,
            updated_at = NOW()
        WHERE work_order_id = ?
        LIMIT 1
    ")->execute([
        $status,
        $paymentStatus,
        field_ops_money($input['gross_pay'] ?? 0),
        field_ops_money($input['platform_fee'] ?? 0),
        field_ops_money($input['bonus_pay'] ?? 0),
        field_ops_money($input['reimbursement_amount'] ?? 0),
        field_ops_decimal($input['mileage'] ?? 0),
        field_ops_decimal($input['mileage_rate'] ?? 0, 4),
        field_ops_int($input['drive_minutes'] ?? 0),
        field_ops_int($input['onsite_minutes'] ?? 0),
        field_ops_int($input['admin_minutes'] ?? 0),
        $workOrderId,
    ]);

    return ['ok' => true];
}



function field_ops_clients_for_invoice(): array
{
    field_ops_ensure_schema();

    if (!db_table_exists('clients')) {
        return [];
    }

    $sql = "
        SELECT client_id, client_code, legal_name, dba_name, email, status
        FROM clients
        ORDER BY
          CASE WHEN status IN ('ACTIVE', 'LIVE', 'ONBOARDING') THEN 0 ELSE 1 END,
          COALESCE(NULLIF(dba_name, ''), legal_name) ASC
    ";

    return db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_default_revenue_account_id(): int
{
    if (!db_table_exists('gl_account')) {
        return 0;
    }

    $sql = "
        SELECT account_id
        FROM gl_account
        WHERE is_active = 1
          AND (
            account_type IN ('REVENUE', 'INCOME')
            OR detail_type LIKE '%revenue%'
            OR detail_type LIKE '%income%'
            OR account_name LIKE '%service%'
            OR account_name LIKE '%income%'
            OR account_name LIKE '%revenue%'
          )
        ORDER BY
          CASE
            WHEN account_type = 'REVENUE' THEN 0
            WHEN account_type = 'INCOME' THEN 1
            ELSE 2
          END,
          account_code ASC,
          account_id ASC
        LIMIT 1
    ";

    return (int)(db()->query($sql)->fetchColumn() ?: 0);
}

function field_ops_existing_invoice_for_work_order(int $workOrderId): ?array
{
    field_ops_ensure_schema();

    require_once __DIR__ . '/accounting.php';

    $wo = field_ops_find_work_order($workOrderId);

    if (!$wo) {
        return null;
    }

    $invoiceId = (int)($wo['invoice_id'] ?? 0);

    if ($invoiceId > 0 && function_exists('accounting_get_invoice')) {
        $invoice = accounting_get_invoice($invoiceId);

        if ($invoice) {
            return $invoice;
        }
    }

    if (function_exists('accounting_list_source_invoices')) {
        $matches = accounting_list_source_invoices('FIELD_OPS', (string)$workOrderId);

        if (!empty($matches[0]) && is_array($matches[0])) {
            if (db_column_exists('field_work_orders', 'invoice_id')) {
                db()->prepare("
                    UPDATE field_work_orders
                    SET invoice_id = ?,
                        invoice_created_at = COALESCE(invoice_created_at, NOW()),
                        updated_at = NOW()
                    WHERE work_order_id = ?
                    LIMIT 1
                ")->execute([(int)$matches[0]['invoice_id'], $workOrderId]);
            }

            return $matches[0];
        }
    }

    return null;
}

function field_ops_can_invoice_work_order(array $wo): bool
{
    $source = strtolower(trim((string)($wo['platform'] ?? '')));

    return $source !== 'fieldnation';
}

function field_ops_build_invoice_lines(int $workOrderId, array $input, int $revenueAccountId): array
{
    $wo = field_ops_find_work_order($workOrderId);

    if (!$wo) {
        return ['ok' => false, 'errors' => ['Work order not found.']];
    }

    $lineDescriptions = [];
    $lineQuantities = [];
    $linePrices = [];
    $lineRevenueAccounts = [];
    $lineItemIds = [];

    $addLine = static function (string $description, float $quantity, float $unitPrice) use (&$lineDescriptions, &$lineQuantities, &$linePrices, &$lineRevenueAccounts, &$lineItemIds, $revenueAccountId): void {
        $description = trim($description);

        if ($description === '' || $quantity <= 0 || $unitPrice < 0) {
            return;
        }

        $lineItemIds[] = 0;
        $lineDescriptions[] = $description;
        $lineQuantities[] = number_format($quantity, 2, '.', '');
        $linePrices[] = number_format($unitPrice, 2, '.', '');
        $lineRevenueAccounts[] = $revenueAccountId;
    };

    $laborAmount = field_ops_money($input['labor_amount'] ?? 0);
    $laborDescription = trim((string)($input['labor_description'] ?? ''));

    if ($laborDescription === '') {
        $laborDescription = 'Field service labor - ' . (string)$wo['title'];
    }

    if ($laborAmount > 0) {
        $addLine($laborDescription, 1.00, $laborAmount);
    }

    if (!empty($input['include_billable_materials'])) {
        foreach (field_ops_work_order_materials($workOrderId) as $material) {
            if (empty($material['billable'])) {
                continue;
            }

            $qty = (float)($material['quantity_used'] ?? 0);
            $billPrice = (float)($material['bill_price'] ?? 0);
            $description = 'Materials - ' . (string)($material['description'] ?? 'Field materials');

            $addLine($description, $qty, $billPrice);
        }
    }

    if (!empty($input['include_reimbursable_expenses'])) {
        foreach (field_ops_work_order_expenses($workOrderId) as $expense) {
            if (empty($expense['reimbursable'])) {
                continue;
            }

            $amount = (float)($expense['amount'] ?? 0);
            $description = 'Reimbursable expense - ' . (string)($expense['description'] ?? 'Field expense');

            $addLine($description, 1.00, $amount);
        }
    }

    if ($lineDescriptions === []) {
        return ['ok' => false, 'errors' => ['No invoiceable W/O lines found. Add labor amount, billable materials, or reimbursable expenses first.']];
    }

    return [
        'ok' => true,
        'line_item_id' => $lineItemIds,
        'line_description' => $lineDescriptions,
        'line_quantity' => $lineQuantities,
        'line_unit_price' => $linePrices,
        'line_revenue_account_id' => $lineRevenueAccounts,
    ];
}

function field_ops_create_draft_invoice_from_work_order(array $input, int $userId = 0): array
{
    field_ops_ensure_schema();

    require_once __DIR__ . '/accounting.php';

    $workOrderId = (int)($input['work_order_id'] ?? 0);
    $wo = field_ops_find_work_order($workOrderId);

    if (!$wo) {
        return ['ok' => false, 'errors' => ['Work order not found.']];
    }

    if (!field_ops_can_invoice_work_order($wo)) {
        return ['ok' => false, 'errors' => ['FieldNation work orders are payout-tracked only and should not create OPS customer invoices.']];
    }

    $existing = field_ops_existing_invoice_for_work_order($workOrderId);

    if ($existing) {
        return [
            'ok' => true,
            'invoice_id' => (int)$existing['invoice_id'],
            'message' => 'Draft invoice already exists for this work order.',
        ];
    }

    $clientId = (int)($input['client_id'] ?? 0);

    if ($clientId <= 0) {
        return ['ok' => false, 'errors' => ['Choose the client to invoice.']];
    }

    $revenueAccountId = (int)($input['revenue_account_id'] ?? 0);

    if ($revenueAccountId <= 0) {
        $revenueAccountId = field_ops_default_revenue_account_id();
    }

    if ($revenueAccountId <= 0) {
        return ['ok' => false, 'errors' => ['No revenue account could be resolved. Set up a revenue account before creating W/O invoices.']];
    }

    $invoiceDate = field_ops_date_or_today($input['invoice_date'] ?? '');
    $dueDate = field_ops_date_or_today($input['due_date'] ?? date('Y-m-d', strtotime($invoiceDate . ' +15 days')));

    $lines = field_ops_build_invoice_lines($workOrderId, $input, $revenueAccountId);

    if (empty($lines['ok'])) {
        return ['ok' => false, 'errors' => (array)($lines['errors'] ?? ['Unable to build invoice lines.'])];
    }

    $memo = trim((string)($input['memo'] ?? ''));

    if ($memo === '') {
        $memo = 'Created from Field Ops W/O #' . $workOrderId . ': ' . (string)$wo['title'];
    }

    $invoiceData = [
        'client_id' => $clientId,
        'contract_id' => 0,
        'invoice_date' => $invoiceDate,
        'due_date' => $dueDate,
        'status' => 'DRAFT',
        'memo' => $memo,
        'source_system' => 'FIELD_OPS',
        'source_record_id' => (string)$workOrderId,
        'line_item_id' => $lines['line_item_id'],
        'line_description' => $lines['line_description'],
        'line_quantity' => $lines['line_quantity'],
        'line_unit_price' => $lines['line_unit_price'],
        'line_revenue_account_id' => $lines['line_revenue_account_id'],
    ];

    $result = accounting_create_invoice($invoiceData, $userId);

    if (empty($result['ok'])) {
        return ['ok' => false, 'errors' => (array)($result['errors'] ?? ['Invoice could not be created.'])];
    }

    $invoiceId = (int)($result['invoice_id'] ?? $result['id'] ?? 0);

    if ($invoiceId > 0 && db_column_exists('field_work_orders', 'invoice_id')) {
        db()->prepare("
            UPDATE field_work_orders
            SET client_id = ?,
                invoice_id = ?,
                invoice_created_at = NOW(),
                updated_at = NOW()
            WHERE work_order_id = ?
            LIMIT 1
        ")->execute([$clientId, $invoiceId, $workOrderId]);
    }

    return [
        'ok' => true,
        'invoice_id' => $invoiceId,
        'message' => 'Draft invoice created from Field Ops work order.',
    ];
}


function field_ops_account_home(): string
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

function field_ops_storage_dir(): string
{
    return field_ops_account_home() . '/private/mmit-field-ops';
}

function field_ops_attachment_dir(int $workOrderId): string
{
    return field_ops_storage_dir() . '/attachments/work-order-' . $workOrderId;
}

function field_ops_attachment_types(): array
{
    return [
        'receipt' => 'Receipt',
        'photo' => 'Photo',
        'tester_export' => 'Cable tester export',
        'signed_work_order' => 'Signed work order',
        'customer_signoff' => 'Customer / lead sign-off',
        'document' => 'Document',
        'other' => 'Other',
    ];
}

function field_ops_clean_attachment_type(string $type): string
{
    $type = strtolower(trim($type));
    $allowed = array_keys(field_ops_attachment_types());

    return in_array($type, $allowed, true) ? $type : 'document';
}

function field_ops_safe_filename(string $filename): string
{
    $filename = trim($filename);
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?? 'attachment';
    $filename = trim($filename, '._-');

    return $filename !== '' ? substr($filename, 0, 180) : 'attachment';
}

function field_ops_work_order_attachments(int $workOrderId): array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT *
        FROM field_work_order_attachments
        WHERE work_order_id = ?
          AND deleted_at IS NULL
        ORDER BY uploaded_at DESC, attachment_id DESC
    ");
    $st->execute([$workOrderId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_ops_find_attachment(int $attachmentId): ?array
{
    field_ops_ensure_schema();

    $st = db()->prepare("
        SELECT a.*, wo.title AS work_order_title
        FROM field_work_order_attachments a
        INNER JOIN field_work_orders wo ON wo.work_order_id = a.work_order_id
        WHERE a.attachment_id = ?
          AND a.deleted_at IS NULL
          AND wo.deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$attachmentId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_ops_upload_work_order_attachment(array $input, array $file, int $userId = 0): array
{
    field_ops_ensure_schema();

    $workOrderId = (int)($input['work_order_id'] ?? 0);

    if ($workOrderId <= 0 || !field_ops_find_work_order($workOrderId)) {
        return ['ok' => false, 'errors' => ['Valid work order is required.']];
    }

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'errors' => ['Upload failed or no file was selected.']];
    }

    $size = (int)($file['size'] ?? 0);

    if ($size <= 0) {
        return ['ok' => false, 'errors' => ['Uploaded file is empty.']];
    }

    if ($size > 25 * 1024 * 1024) {
        return ['ok' => false, 'errors' => ['Attachment is too large. Max size is 25 MB.']];
    }

    $tmp = (string)($file['tmp_name'] ?? '');

    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'errors' => ['Upload temporary file is invalid.']];
    }

    $original = field_ops_safe_filename((string)($file['name'] ?? 'attachment'));
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));

    $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt', 'csv', 'log', 'doc', 'docx', 'xls', 'xlsx'];

    if ($extension !== '' && !in_array($extension, $allowedExtensions, true)) {
        return ['ok' => false, 'errors' => ['File type is not allowed for W/O attachments.']];
    }

    $dir = field_ops_attachment_dir($workOrderId);

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    $stored = date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '-' . $original;
    $path = $dir . '/' . $stored;

    if (!@move_uploaded_file($tmp, $path)) {
        return ['ok' => false, 'errors' => ['Could not store uploaded attachment.']];
    }

    @chmod($path, 0640);

    $mime = null;

    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path) ?: null;
            finfo_close($finfo);
        }
    }

    db()->prepare("
        INSERT INTO field_work_order_attachments
          (work_order_id, attachment_type, original_filename, stored_filename, storage_path, mime_type, file_size_bytes, description, uploaded_by)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $workOrderId,
        field_ops_clean_attachment_type((string)($input['attachment_type'] ?? 'document')),
        $original,
        $stored,
        $path,
        $mime,
        filesize($path) ?: $size,
        trim((string)($input['description'] ?? '')) ?: null,
        $userId > 0 ? $userId : null,
    ]);

    return ['ok' => true, 'attachment_id' => (int)db()->lastInsertId()];
}

function field_ops_delete_attachment(int $attachmentId): array
{
    field_ops_ensure_schema();

    if ($attachmentId <= 0 || !field_ops_find_attachment($attachmentId)) {
        return ['ok' => false, 'errors' => ['Attachment not found.']];
    }

    db()->prepare("
        UPDATE field_work_order_attachments
        SET deleted_at = NOW()
        WHERE attachment_id = ?
        LIMIT 1
    ")->execute([$attachmentId]);

    return ['ok' => true];
}



function field_ops_onedrive_root(): string
{
    return defined('ONEDRIVE_FIELD_OPS_ROOT')
        ? trim((string)ONEDRIVE_FIELD_OPS_ROOT)
        : 'MMIT Field Ops';
}

function field_ops_onedrive_attachment_folder(array $attachment): string
{
    require_once __DIR__ . '/onedrive.php';

    $workOrderId = (int)($attachment['work_order_id'] ?? 0);
    $title = onedrive_sanitize_segment((string)($attachment['work_order_title'] ?? ('Work Order ' . $workOrderId)));
    $type = field_ops_clean_attachment_type((string)($attachment['attachment_type'] ?? 'document'));

    $typeFolderMap = [
        'receipt' => 'receipts',
        'photo' => 'photos',
        'tester_export' => 'tester-exports',
        'signed_work_order' => 'signed-work-orders',
        'customer_signoff' => 'signoff',
        'document' => 'documents',
        'other' => 'other',
    ];

    $uploadedAt = trim((string)($attachment['uploaded_at'] ?? ''));
    $year = date('Y');

    if ($uploadedAt !== '') {
        try {
            $year = (new DateTimeImmutable($uploadedAt))->format('Y');
        } catch (Throwable $e) {
            $year = date('Y');
        }
    }

    $woFolder = onedrive_sanitize_segment(sprintf('WO-%06d - %s', $workOrderId, $title));
    $typeFolder = $typeFolderMap[$type] ?? 'documents';

    return onedrive_sanitize_segment(field_ops_onedrive_root())
        . '/' . $year
        . '/Work Orders'
        . '/' . $woFolder
        . '/' . $typeFolder;
}

function field_ops_onedrive_remote_name(array $attachment): string
{
    require_once __DIR__ . '/onedrive.php';

    $name = field_ops_safe_filename((string)($attachment['original_filename'] ?? 'attachment'));
    $type = field_ops_clean_attachment_type((string)($attachment['attachment_type'] ?? 'document'));
    $id = (int)($attachment['attachment_id'] ?? 0);

    $prefix = $type . '-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);

    return onedrive_sanitize_segment($prefix . '-' . $name);
}

function field_ops_onedrive_unique_name(string $accessToken, string $folderPath, string $remoteName): string
{
    require_once __DIR__ . '/onedrive.php';

    $remoteName = onedrive_sanitize_segment($remoteName);
    $candidate = $remoteName;
    $ext = pathinfo($remoteName, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($remoteName, 0, -(strlen($ext) + 1)) : $remoteName;

    for ($i = 0; $i < 50; $i++) {
        $testPath = $folderPath . '/' . $candidate;

        if (!onedrive_item_exists($accessToken, $testPath)) {
            return $candidate;
        }

        $suffix = '-' . ($i + 1);
        $candidate = $ext !== '' ? ($base . $suffix . '.' . $ext) : ($base . $suffix);
    }

    return uniqid($base . '-', true) . ($ext !== '' ? '.' . $ext : '');
}

function field_ops_sync_attachment_to_onedrive(int $attachmentId): array
{
    field_ops_ensure_schema();

    require_once __DIR__ . '/onedrive.php';

    $attachment = field_ops_find_attachment($attachmentId);

    if (!$attachment) {
        return ['ok' => false, 'errors' => ['Attachment not found.']];
    }

    if (!empty($attachment['onedrive_item_id']) && !empty($attachment['onedrive_web_url'])) {
        return [
            'ok' => true,
            'attachment_id' => $attachmentId,
            'message' => 'Attachment already synced to OneDrive.',
            'web_url' => (string)$attachment['onedrive_web_url'],
        ];
    }

    $path = (string)($attachment['storage_path'] ?? '');

    if ($path === '' || !is_file($path)) {
        return ['ok' => false, 'errors' => ['Local attachment file is missing.']];
    }

    if (!onedrive_is_configured()) {
        return ['ok' => false, 'errors' => ['OneDrive is not configured yet.']];
    }

    $token = onedrive_get_valid_access_token();

    if (empty($token['ok']) || empty($token['access_token'])) {
        return ['ok' => false, 'errors' => [(string)($token['error'] ?? 'OneDrive is not connected.')]];
    }

    $accessToken = (string)$token['access_token'];
    $folderPath = field_ops_onedrive_attachment_folder($attachment);

    if (!onedrive_ensure_folder_path($accessToken, $folderPath)) {
        return ['ok' => false, 'errors' => ['Unable to prepare the OneDrive Field Ops folder.']];
    }

    $remoteName = field_ops_onedrive_unique_name($accessToken, $folderPath, field_ops_onedrive_remote_name($attachment));
    $encodedPath = onedrive_encode_path($folderPath . '/' . $remoteName);
    $bytes = @file_get_contents($path);

    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'errors' => ['Unable to read local attachment for OneDrive sync.']];
    }

    $mime = trim((string)($attachment['mime_type'] ?? '')) ?: 'application/octet-stream';

    $upload = onedrive_http_request(
        'PUT',
        'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encodedPath . ':/content',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: ' . $mime,
        ],
        $bytes,
        true
    );

    if (empty($upload['ok']) || !is_array($upload['json'] ?? null)) {
        return ['ok' => false, 'errors' => [(string)($upload['error'] ?? 'OneDrive upload failed.')]];
    }

    $item = $upload['json'];
    $itemId = (string)($item['id'] ?? '');
    $webUrl = (string)($item['webUrl'] ?? '');

    db()->prepare("
        UPDATE field_work_order_attachments
        SET onedrive_item_id = ?,
            onedrive_web_url = ?
        WHERE attachment_id = ?
        LIMIT 1
    ")->execute([
        $itemId !== '' ? $itemId : null,
        $webUrl !== '' ? $webUrl : null,
        $attachmentId,
    ]);

    return [
        'ok' => true,
        'attachment_id' => $attachmentId,
        'folder_path' => $folderPath,
        'remote_name' => $remoteName,
        'onedrive_item_id' => $itemId,
        'web_url' => $webUrl,
    ];
}

function field_ops_sync_work_order_attachments_to_onedrive(int $workOrderId): array
{
    field_ops_ensure_schema();

    $attachments = field_ops_work_order_attachments($workOrderId);
    $synced = 0;
    $skipped = 0;
    $errors = [];

    foreach ($attachments as $attachment) {
        if (!empty($attachment['onedrive_item_id']) && !empty($attachment['onedrive_web_url'])) {
            $skipped++;
            continue;
        }

        $result = field_ops_sync_attachment_to_onedrive((int)$attachment['attachment_id']);

        if (!empty($result['ok'])) {
            $synced++;
        } else {
            $errors[] = (string)($attachment['original_filename'] ?? 'Attachment') . ': ' . implode(' ', (array)($result['errors'] ?? ['Sync failed.']));
        }
    }

    return [
        'ok' => $errors === [],
        'synced' => $synced,
        'skipped' => $skipped,
        'errors' => $errors,
    ];
}


function field_ops_summary(): array
{
    field_ops_ensure_schema();

    $wo = db()->query("
        SELECT
          COUNT(*) AS total_work_orders,
          SUM(CASE WHEN status IN ('REQUESTED', 'ASSIGNED', 'SCHEDULED', 'CHECKED_IN', 'IN_PROGRESS') THEN 1 ELSE 0 END) AS active_work_orders,
          SUM(CASE WHEN payment_status <> 'PAID' THEN 1 ELSE 0 END) AS unpaid_work_orders,
          COALESCE(SUM(gross_pay + bonus_pay + reimbursement_amount), 0) AS gross_total,
          COALESCE(SUM(platform_fee), 0) AS platform_fee_total,
          COALESCE(SUM(mileage * mileage_rate), 0) AS mileage_cost_total,
          COALESCE(SUM(drive_minutes + onsite_minutes + admin_minutes), 0) AS total_minutes
        FROM field_work_orders
        WHERE deleted_at IS NULL
    ")->fetch(PDO::FETCH_ASSOC) ?: [];

    $materialCost = (float)(db()->query("
        SELECT COALESCE(SUM(quantity_used * unit_cost), 0)
        FROM field_work_order_materials
    ")->fetchColumn() ?: 0);

    $expenseCost = (float)(db()->query("
        SELECT COALESCE(SUM(amount), 0)
        FROM field_work_order_expenses
    ")->fetchColumn() ?: 0);

    $inventoryLow = (int)(db()->query("
        SELECT COUNT(*)
        FROM field_inventory_items
        WHERE active = 1 AND reorder_point > 0 AND qty_on_hand <= reorder_point
    ")->fetchColumn() ?: 0);

    $gross = (float)($wo['gross_total'] ?? 0);
    $fees = (float)($wo['platform_fee_total'] ?? 0);
    $mileage = (float)($wo['mileage_cost_total'] ?? 0);
    $net = $gross - $fees - $materialCost - $expenseCost - $mileage;
    $hours = ((int)($wo['total_minutes'] ?? 0)) / 60;

    return [
        'total_work_orders' => (int)($wo['total_work_orders'] ?? 0),
        'active_work_orders' => (int)($wo['active_work_orders'] ?? 0),
        'unpaid_work_orders' => (int)($wo['unpaid_work_orders'] ?? 0),
        'gross_total' => $gross,
        'platform_fee_total' => $fees,
        'material_cost_total' => $materialCost,
        'expense_cost_total' => $expenseCost,
        'mileage_cost_total' => $mileage,
        'estimated_net_total' => $net,
        'total_hours' => $hours,
        'effective_hourly' => $hours > 0 ? ($net / $hours) : 0,
        'low_stock_count' => $inventoryLow,
    ];
}
