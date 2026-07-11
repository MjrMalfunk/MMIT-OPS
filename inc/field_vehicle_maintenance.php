<?php
declare(strict_types=1);

/**
 * Field Ops vehicle maintenance planning.
 *
 * This module extends the service vehicle ledger with component baselines
 * and scheduled maintenance items. It intentionally stays separate from
 * inc/field_vehicles.php so AFEE v1 remains clean and stable.
 */

function field_vehicle_component_types(): array
{
    return [
        'ENGINE' => 'Engine / powertrain baseline',
        'BATTERY' => 'Battery',
        'STARTER' => 'Starter',
        'SPARK_PLUGS' => 'Spark plugs',
        'INJECTORS' => 'Fuel injectors',
        'TRANSMISSION' => 'Transmission / driveline',
        'COOLING_SYSTEM' => 'Cooling system',
        'HVAC_AC' => 'HVAC / A/C',
        'BRAKES' => 'Brakes',
        'TIRES' => 'Tires',
        'SUSPENSION' => 'Suspension / steering',
        'WHEEL_BEARINGS' => 'Wheel bearings / hubs',
        'BELTS_HOSES' => 'Belts / hoses',
        'OTHER' => 'Other component',
    ];
}

function field_vehicle_component_statuses(): array
{
    return [
        'UNKNOWN' => 'Unknown',
        'BASELINE_NEEDED' => 'Baseline inspection needed',
        'RESET' => 'Reset / replaced',
        'SERVICED' => 'Serviced',
        'INSPECTED' => 'Inspected',
        'WATCH' => 'Watch',
        'FAILED' => 'Failed',
        'RETIRED' => 'Retired',
    ];
}

function field_vehicle_maintenance_clock_types(): array
{
    return [
        'VEHICLE' => 'Vehicle odometer / chassis clock',
        'ENGINE' => 'Replacement engine clock',
        'COMPONENT' => 'Component baseline clock',
        'CALENDAR' => 'Calendar-only interval',
    ];
}

function field_vehicle_maintenance_sources(): array
{
    return [
        'MANUFACTURER' => 'Manufacturer schedule',
        'WARRANTY' => 'Warranty requirement',
        'INSPECTION' => 'Inspection baseline',
        'MANUAL' => 'Manual entry',
        'SHOP_RECOMMENDED' => 'Shop recommended',
    ];
}

function field_vehicle_maintenance_statuses(): array
{
    return [
        'CURRENT' => 'Current',
        'DUE_SOON' => 'Due soon',
        'DUE' => 'Due',
        'OVERDUE' => 'Overdue',
        'BASELINE_NEEDED' => 'Baseline needed',
        'DISABLED' => 'Disabled',
    ];
}

function field_vehicle_maintenance_ensure_schema(): void
{
    field_vehicles_ensure_schema();

    db()->exec("
        CREATE TABLE IF NOT EXISTS field_vehicle_components (
            component_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_id INT UNSIGNED NOT NULL,
            component_type VARCHAR(64) NOT NULL,
            component_name VARCHAR(160) NOT NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'UNKNOWN',
            baseline_date DATE NULL,
            baseline_odometer DECIMAL(12,2) NULL,
            baseline_vehicle_event_id INT UNSIGNED NULL,
            warranty_until_date DATE NULL,
            warranty_until_miles DECIMAL(12,2) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            PRIMARY KEY (component_id),
            KEY idx_vehicle_components_vehicle (vehicle_id),
            KEY idx_vehicle_components_type (component_type),
            KEY idx_vehicle_components_status (status),
            KEY idx_vehicle_components_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    db()->exec("
        CREATE TABLE IF NOT EXISTS field_vehicle_maintenance_items (
            maintenance_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_id INT UNSIGNED NOT NULL,
            component_id INT UNSIGNED NULL,
            item_name VARCHAR(190) NOT NULL,
            subsystem VARCHAR(120) NULL,
            schedule_source VARCHAR(64) NOT NULL DEFAULT 'MANUAL',
            clock_type VARCHAR(64) NOT NULL DEFAULT 'VEHICLE',
            interval_miles DECIMAL(12,2) NULL,
            interval_months INT UNSIGNED NULL,
            baseline_odometer DECIMAL(12,2) NULL,
            baseline_date DATE NULL,
            last_vehicle_event_id INT UNSIGNED NULL,
            estimated_service_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            next_due_odometer DECIMAL(12,2) NULL,
            next_due_date DATE NULL,
            status VARCHAR(64) NOT NULL DEFAULT 'BASELINE_NEEDED',
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,
            PRIMARY KEY (maintenance_item_id),
            KEY idx_vehicle_maintenance_vehicle (vehicle_id),
            KEY idx_vehicle_maintenance_component (component_id),
            KEY idx_vehicle_maintenance_status (status),
            KEY idx_vehicle_maintenance_due_miles (next_due_odometer),
            KEY idx_vehicle_maintenance_due_date (next_due_date),
            KEY idx_vehicle_maintenance_deleted (deleted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function field_vehicle_components(int $vehicleId): array
{
    field_vehicle_maintenance_ensure_schema();

    if ($vehicleId <= 0) {
        return [];
    }

    $stmt = db()->prepare("
        SELECT *
        FROM field_vehicle_components
        WHERE vehicle_id = ?
          AND deleted_at IS NULL
        ORDER BY
          FIELD(status, 'BASELINE_NEEDED', 'UNKNOWN', 'WATCH', 'FAILED', 'RESET', 'SERVICED', 'INSPECTED', 'RETIRED'),
          component_type ASC,
          component_name ASC
    ");

    $stmt->execute([$vehicleId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_vehicle_component_find(int $componentId): ?array
{
    field_vehicle_maintenance_ensure_schema();

    if ($componentId <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM field_vehicle_components
        WHERE component_id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute([$componentId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_component_save(array $input): array
{
    field_vehicle_maintenance_ensure_schema();

    $componentId = (int)($input['component_id'] ?? 0);
    $vehicleId = (int)($input['vehicle_id'] ?? 0);

    if ($vehicleId <= 0) {
        throw new InvalidArgumentException('Vehicle is required.');
    }

    $componentTypes = field_vehicle_component_types();
    $statuses = field_vehicle_component_statuses();

    $componentType = strtoupper(trim((string)($input['component_type'] ?? 'OTHER')));
    if (!isset($componentTypes[$componentType])) {
        $componentType = 'OTHER';
    }

    $componentName = trim((string)($input['component_name'] ?? ''));
    if ($componentName === '') {
        $componentName = $componentTypes[$componentType] ?? 'Component';
    }

    $status = strtoupper(trim((string)($input['status'] ?? 'UNKNOWN')));
    if (!isset($statuses[$status])) {
        $status = 'UNKNOWN';
    }

    $baselineDate = trim((string)($input['baseline_date'] ?? ''));
    $baselineDate = $baselineDate !== '' ? $baselineDate : null;

    $baselineOdometer = trim((string)($input['baseline_odometer'] ?? ''));
    $baselineOdometer = $baselineOdometer !== '' ? (float)$baselineOdometer : null;

    $baselineEventId = (int)($input['baseline_vehicle_event_id'] ?? 0);
    $baselineEventId = $baselineEventId > 0 ? $baselineEventId : null;

    $warrantyUntilDate = trim((string)($input['warranty_until_date'] ?? ''));
    $warrantyUntilDate = $warrantyUntilDate !== '' ? $warrantyUntilDate : null;

    $warrantyUntilMiles = trim((string)($input['warranty_until_miles'] ?? ''));
    $warrantyUntilMiles = $warrantyUntilMiles !== '' ? (float)$warrantyUntilMiles : null;

    $notes = trim((string)($input['notes'] ?? ''));

    if ($componentId > 0) {
        $existing = field_vehicle_component_find($componentId);

        if (!$existing || (int)$existing['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('Component baseline not found for this vehicle.');
        }

        $stmt = db()->prepare("
            UPDATE field_vehicle_components
            SET component_type = ?,
                component_name = ?,
                status = ?,
                baseline_date = ?,
                baseline_odometer = ?,
                baseline_vehicle_event_id = ?,
                warranty_until_date = ?,
                warranty_until_miles = ?,
                notes = ?
            WHERE component_id = ?
              AND vehicle_id = ?
        ");

        $stmt->execute([
            $componentType,
            $componentName,
            $status,
            $baselineDate,
            $baselineOdometer,
            $baselineEventId,
            $warrantyUntilDate,
            $warrantyUntilMiles,
            $notes,
            $componentId,
            $vehicleId,
        ]);

        return [
            'component_id' => $componentId,
            'updated' => true,
        ];
    }

    $stmt = db()->prepare("
        INSERT INTO field_vehicle_components (
            vehicle_id,
            component_type,
            component_name,
            status,
            baseline_date,
            baseline_odometer,
            baseline_vehicle_event_id,
            warranty_until_date,
            warranty_until_miles,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $vehicleId,
        $componentType,
        $componentName,
        $status,
        $baselineDate,
        $baselineOdometer,
        $baselineEventId,
        $warrantyUntilDate,
        $warrantyUntilMiles,
        $notes,
    ]);

    return [
        'component_id' => (int)db()->lastInsertId(),
        'created' => true,
    ];
}

function field_vehicle_maintenance_items(
    int $vehicleId,
    bool $includeDisabled = false
): array {
    field_vehicle_maintenance_ensure_schema();

    if ($vehicleId <= 0) {
        return [];
    }

    $sql = "
        SELECT mi.*,
               vc.component_type,
               vc.component_name
        FROM field_vehicle_maintenance_items mi
        LEFT JOIN field_vehicle_components vc
          ON vc.component_id = mi.component_id
         AND vc.deleted_at IS NULL
        WHERE mi.vehicle_id = ?
          AND mi.deleted_at IS NULL
    ";

    $params = [$vehicleId];

    if (!$includeDisabled) {
        $sql .= " AND mi.active = 1";
    }

    $sql .= "
        ORDER BY
          FIELD(mi.status, 'OVERDUE', 'DUE', 'DUE_SOON', 'BASELINE_NEEDED', 'CURRENT', 'DISABLED'),
          mi.next_due_date IS NULL ASC,
          mi.next_due_date ASC,
          mi.next_due_odometer IS NULL ASC,
          mi.next_due_odometer ASC,
          mi.item_name ASC
    ";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_vehicle_maintenance_item_find(int $maintenanceItemId): ?array
{
    field_vehicle_maintenance_ensure_schema();

    if ($maintenanceItemId <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM field_vehicle_maintenance_items
        WHERE maintenance_item_id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");

    $stmt->execute([$maintenanceItemId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_maintenance_next_due(
    ?float $baselineOdometer,
    ?string $baselineDate,
    ?float $intervalMiles,
    ?int $intervalMonths
): array {
    $nextDueOdometer = null;
    $nextDueDate = null;

    if ($baselineOdometer !== null && $intervalMiles !== null && $intervalMiles > 0) {
        $nextDueOdometer = $baselineOdometer + $intervalMiles;
    }

    if ($baselineDate && $intervalMonths !== null && $intervalMonths > 0) {
        try {
            $date = new DateTimeImmutable($baselineDate);
            $nextDueDate = $date
                ->modify('+' . $intervalMonths . ' months')
                ->format('Y-m-d');
        } catch (Throwable $e) {
            $nextDueDate = null;
        }
    }

    return [
        'next_due_odometer' => $nextDueOdometer,
        'next_due_date' => $nextDueDate,
    ];
}

function field_vehicle_maintenance_status_from_due(
    ?float $currentOdometer,
    ?float $nextDueOdometer,
    ?string $nextDueDate,
    string $fallback = 'BASELINE_NEEDED'
): string {
    $dueSoon = false;

    if ($nextDueOdometer !== null && $currentOdometer !== null) {
        $milesRemaining = $nextDueOdometer - $currentOdometer;

        if ($milesRemaining <= 0) {
            return 'DUE';
        }

        if ($milesRemaining <= 500) {
            $dueSoon = true;
        }
    }

    if ($nextDueDate) {
        try {
            $today = new DateTimeImmutable('today');
            $dueDate = new DateTimeImmutable($nextDueDate);
            $daysRemaining = (int)$today->diff($dueDate)->format('%r%a');

            if ($daysRemaining < 0) {
                return 'OVERDUE';
            }

            if ($daysRemaining <= 30) {
                $dueSoon = true;
            }
        } catch (Throwable $e) {
            // Keep mileage-derived state if date parsing fails.
        }
    }

    if ($dueSoon) {
        return 'DUE_SOON';
    }

    if ($nextDueOdometer !== null || $nextDueDate !== null) {
        return 'CURRENT';
    }

    return $fallback;
}

function field_vehicle_maintenance_item_save(array $input): array
{
    field_vehicle_maintenance_ensure_schema();

    $maintenanceItemId = (int)($input['maintenance_item_id'] ?? 0);
    $vehicleId = (int)($input['vehicle_id'] ?? 0);

    if ($vehicleId <= 0) {
        throw new InvalidArgumentException('Vehicle is required.');
    }

    $vehicle = field_vehicle_find($vehicleId);
    if (!$vehicle) {
        throw new InvalidArgumentException('Vehicle not found.');
    }

    $sources = field_vehicle_maintenance_sources();
    $clockTypes = field_vehicle_maintenance_clock_types();
    $statuses = field_vehicle_maintenance_statuses();

    $componentId = (int)($input['component_id'] ?? 0);
    $componentId = $componentId > 0 ? $componentId : null;

    if ($componentId !== null) {
        $component = field_vehicle_component_find($componentId);

        if (!$component || (int)$component['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('Component baseline not found for this vehicle.');
        }
    }

    $itemName = trim((string)($input['item_name'] ?? ''));
    if ($itemName === '') {
        throw new InvalidArgumentException('Maintenance item name is required.');
    }

    $subsystem = trim((string)($input['subsystem'] ?? ''));
    $subsystem = $subsystem !== '' ? $subsystem : null;

    $scheduleSource = strtoupper(trim((string)($input['schedule_source'] ?? 'MANUAL')));
    if (!isset($sources[$scheduleSource])) {
        $scheduleSource = 'MANUAL';
    }

    $clockType = strtoupper(trim((string)($input['clock_type'] ?? 'VEHICLE')));
    if (!isset($clockTypes[$clockType])) {
        $clockType = 'VEHICLE';
    }

    $intervalMiles = trim((string)($input['interval_miles'] ?? ''));
    $intervalMiles = $intervalMiles !== '' ? (float)$intervalMiles : null;

    $intervalMonths = trim((string)($input['interval_months'] ?? ''));
    $intervalMonths = $intervalMonths !== '' ? (int)$intervalMonths : null;

    $baselineOdometer = trim((string)($input['baseline_odometer'] ?? ''));
    $baselineOdometer = $baselineOdometer !== '' ? (float)$baselineOdometer : null;

    $baselineDate = trim((string)($input['baseline_date'] ?? ''));
    $baselineDate = $baselineDate !== '' ? $baselineDate : null;

    $lastVehicleEventId = (int)($input['last_vehicle_event_id'] ?? 0);
    $lastVehicleEventId = $lastVehicleEventId > 0 ? $lastVehicleEventId : null;

    $estimatedServiceCost = (float)($input['estimated_service_cost'] ?? 0);

    $due = field_vehicle_maintenance_next_due(
        $baselineOdometer,
        $baselineDate,
        $intervalMiles,
        $intervalMonths
    );

    $requestedStatus = strtoupper(trim((string)($input['status'] ?? '')));
    if ($requestedStatus !== '' && isset($statuses[$requestedStatus])) {
        $status = $requestedStatus;
    } else {
        $status = field_vehicle_maintenance_status_from_due(
            isset($vehicle['current_odometer']) ? (float)$vehicle['current_odometer'] : null,
            $due['next_due_odometer'],
            $due['next_due_date']
        );
    }

    $active = !empty($input['active']) ? 1 : 0;
    $notes = trim((string)($input['notes'] ?? ''));

    if ($maintenanceItemId > 0) {
        $existing = field_vehicle_maintenance_item_find($maintenanceItemId);

        if (!$existing || (int)$existing['vehicle_id'] !== $vehicleId) {
            throw new InvalidArgumentException('Maintenance item not found for this vehicle.');
        }

        $stmt = db()->prepare("
            UPDATE field_vehicle_maintenance_items
            SET component_id = ?,
                item_name = ?,
                subsystem = ?,
                schedule_source = ?,
                clock_type = ?,
                interval_miles = ?,
                interval_months = ?,
                baseline_odometer = ?,
                baseline_date = ?,
                last_vehicle_event_id = ?,
                estimated_service_cost = ?,
                next_due_odometer = ?,
                next_due_date = ?,
                status = ?,
                active = ?,
                notes = ?
            WHERE maintenance_item_id = ?
              AND vehicle_id = ?
        ");

        $stmt->execute([
            $componentId,
            $itemName,
            $subsystem,
            $scheduleSource,
            $clockType,
            $intervalMiles,
            $intervalMonths,
            $baselineOdometer,
            $baselineDate,
            $lastVehicleEventId,
            $estimatedServiceCost,
            $due['next_due_odometer'],
            $due['next_due_date'],
            $status,
            $active,
            $notes,
            $maintenanceItemId,
            $vehicleId,
        ]);

        return [
            'maintenance_item_id' => $maintenanceItemId,
            'updated' => true,
            'status' => $status,
            'next_due_odometer' => $due['next_due_odometer'],
            'next_due_date' => $due['next_due_date'],
        ];
    }

    $stmt = db()->prepare("
        INSERT INTO field_vehicle_maintenance_items (
            vehicle_id,
            component_id,
            item_name,
            subsystem,
            schedule_source,
            clock_type,
            interval_miles,
            interval_months,
            baseline_odometer,
            baseline_date,
            last_vehicle_event_id,
            estimated_service_cost,
            next_due_odometer,
            next_due_date,
            status,
            active,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $vehicleId,
        $componentId,
        $itemName,
        $subsystem,
        $scheduleSource,
        $clockType,
        $intervalMiles,
        $intervalMonths,
        $baselineOdometer,
        $baselineDate,
        $lastVehicleEventId,
        $estimatedServiceCost,
        $due['next_due_odometer'],
        $due['next_due_date'],
        $status,
        $active,
        $notes,
    ]);

    return [
        'maintenance_item_id' => (int)db()->lastInsertId(),
        'created' => true,
        'status' => $status,
        'next_due_odometer' => $due['next_due_odometer'],
        'next_due_date' => $due['next_due_date'],
    ];
}

function field_vehicle_maintenance_summary(int $vehicleId): array
{
    $items = field_vehicle_maintenance_items($vehicleId, true);
    $components = field_vehicle_components($vehicleId);

    $summary = [
        'components_total' => count($components),
        'components_baseline_needed' => 0,
        'components_unknown' => 0,
        'maintenance_total' => count($items),
        'maintenance_overdue' => 0,
        'maintenance_due' => 0,
        'maintenance_due_soon' => 0,
        'maintenance_baseline_needed' => 0,
        'estimated_scheduled_reserve_cpm' => 0.0,
    ];

    foreach ($components as $component) {
        if ((string)$component['status'] === 'BASELINE_NEEDED') {
            $summary['components_baseline_needed']++;
        }

        if ((string)$component['status'] === 'UNKNOWN') {
            $summary['components_unknown']++;
        }
    }

    foreach ($items as $item) {
        $status = (string)$item['status'];

        if ($status === 'OVERDUE') {
            $summary['maintenance_overdue']++;
        } elseif ($status === 'DUE') {
            $summary['maintenance_due']++;
        } elseif ($status === 'DUE_SOON') {
            $summary['maintenance_due_soon']++;
        } elseif ($status === 'BASELINE_NEEDED') {
            $summary['maintenance_baseline_needed']++;
        }

        $cost = (float)($item['estimated_service_cost'] ?? 0);
        $intervalMiles = (float)($item['interval_miles'] ?? 0);

        if ($cost > 0 && $intervalMiles > 0) {
            $summary['estimated_scheduled_reserve_cpm'] += $cost / $intervalMiles;
        }
    }

    $summary['estimated_scheduled_reserve_cpm'] = round(
        $summary['estimated_scheduled_reserve_cpm'],
        4
    );

    return $summary;
}
