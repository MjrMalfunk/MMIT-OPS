<?php
declare(strict_types=1);

/**
 * MMIT Field Ops service-vehicle ledger and internal cost model.
 *
 * Historical events record what actually happened to the vehicle.
 * Profile assumptions drive forward-looking cost-per-mile projections.
 *
 * Tax mileage calculations intentionally do not belong in this model.
 */

function field_vehicles_ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_vehicles (
            vehicle_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_name VARCHAR(160) NOT NULL,
            model_year SMALLINT UNSIGNED NULL,
            make VARCHAR(100) NULL,
            model VARCHAR(140) NULL,
            trim_name VARCHAR(140) NULL,
            vin VARCHAR(64) NULL,
            plate VARCHAR(40) NULL,

            in_service_date DATE NULL,
            out_of_service_date DATE NULL,
            current_odometer DECIMAL(12,1) NULL,

            acquisition_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expected_residual_value DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expected_service_miles DECIMAL(12,1) NOT NULL DEFAULT 0.0,

            fuel_mpg_estimate DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            fuel_price_per_gallon_estimate DECIMAL(8,3) NOT NULL DEFAULT 0.000,

            maintenance_reserve_per_mile DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            tire_reserve_per_mile DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            repair_reserve_per_mile DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            depreciation_per_mile_override DECIMAL(10,4) NULL,

            insurance_annual_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            registration_annual_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            other_fixed_annual_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            expected_annual_business_miles DECIMAL(12,1) NOT NULL DEFAULT 0.0,

            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT NULL,

            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_field_vehicles_active (active),
            INDEX idx_field_vehicles_primary (is_primary)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_vehicle_events (
            vehicle_event_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT UNSIGNED NOT NULL,

            event_type VARCHAR(40) NOT NULL,
            cost_treatment VARCHAR(40) NOT NULL DEFAULT 'NORMAL',
            event_date DATE NOT NULL,
            odometer DECIMAL(12,1) NULL,

            vendor VARCHAR(180) NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,

            gallons DECIMAL(10,3) NULL,
            fuel_price_per_gallon DECIMAL(10,4) NULL,

            amortize_over_miles DECIMAL(12,1) NULL,
            notes TEXT NULL,

            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL,

            INDEX idx_field_vehicle_events_vehicle (vehicle_id),
            INDEX idx_field_vehicle_events_date (event_date),
            INDEX idx_field_vehicle_events_type (event_type),
            INDEX idx_field_vehicle_events_treatment (cost_treatment)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS field_vehicle_event_attachments (
            attachment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vehicle_event_id INT UNSIGNED NOT NULL,

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

            INDEX idx_field_vehicle_attachment_event (vehicle_event_id)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * Additive convergence.
     *
     * Keep this from day one so an older environment does not repeat the
     * field_work_orders.deleted_at drift that bit production.
     */
    $vehicleColumns = [
        'out_of_service_date' => "DATE NULL",
        'current_odometer' => "DECIMAL(12,1) NULL",
        'depreciation_per_mile_override' => "DECIMAL(10,4) NULL",
        'other_fixed_annual_cost' => "DECIMAL(12,2) NOT NULL DEFAULT 0.00",
    ];

    foreach ($vehicleColumns as $column => $definition) {
        if (
            function_exists('db_column_exists')
            && !db_column_exists('field_vehicles', $column)
        ) {
            try {
                $pdo->exec(
                    "ALTER TABLE field_vehicles
                     ADD COLUMN {$column} {$definition}"
                );
            } catch (Throwable $e) {
                error_log(
                    'Unable to add field_vehicles.'
                    . $column
                    . ': '
                    . $e->getMessage()
                );
            }
        }
    }

    $eventColumns = [
        'cost_treatment' => "VARCHAR(40) NOT NULL DEFAULT 'NORMAL'",
        'fuel_price_per_gallon' => "DECIMAL(10,4) NULL",
        'amortize_over_miles' => "DECIMAL(12,1) NULL",
        'deleted_at' => "DATETIME NULL",
    ];

    foreach ($eventColumns as $column => $definition) {
        if (
            function_exists('db_column_exists')
            && !db_column_exists('field_vehicle_events', $column)
        ) {
            try {
                $pdo->exec(
                    "ALTER TABLE field_vehicle_events
                     ADD COLUMN {$column} {$definition}"
                );
            } catch (Throwable $e) {
                error_log(
                    'Unable to add field_vehicle_events.'
                    . $column
                    . ': '
                    . $e->getMessage()
                );
            }
        }
    }
}

function field_vehicle_event_types(): array
{
    return [
        'FUEL' => 'Fuel',
        'OIL_CHANGE' => 'Oil change',
        'ROUTINE_MAINTENANCE' => 'Routine maintenance',
        'TIRE' => 'Tire',
        'BRAKE' => 'Brake',
        'REPAIR' => 'Repair',
        'BREAKDOWN' => 'Breakdown',
        'ENGINE' => 'Engine',
        'TRANSMISSION' => 'Transmission',
        'INSURANCE' => 'Insurance',
        'REGISTRATION' => 'Registration',
        'CAR_WASH' => 'Car wash',
        'TOLL' => 'Toll',
        'PARKING' => 'Parking',
        'OTHER' => 'Other',
    ];
}

function field_vehicle_cost_treatments(): array
{
    return [
        'NORMAL' => 'Normal operating history',
        'FIXED' => 'Fixed / annual cost',
        'EXTRAORDINARY' => 'Extraordinary one-off',
        'AMORTIZED' => 'Amortize across future miles',
        'DIRECT_TRIP' => 'Direct trip cost',
    ];
}

function field_vehicle_decimal(mixed $value, int $scale = 2): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    $clean = str_replace([',', '$'], '', trim((string)$value));

    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }

    return round((float)$clean, $scale);
}

function field_vehicle_nullable_decimal(
    mixed $value,
    int $scale = 2
): ?float {
    if ($value === null || trim((string)$value) === '') {
        return null;
    }

    return field_vehicle_decimal($value, $scale);
}

function field_vehicle_nullable_date(mixed $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($value))->format('Y-m-d');
    } catch (Throwable $e) {
        return null;
    }
}

function field_vehicle_observed_fuel_price(
    int $vehicleId,
    int $lookbackDays = 90
): ?float {
    field_vehicles_ensure_schema();

    if ($vehicleId <= 0) {
        return null;
    }

    $lookbackDays = max(30, min(730, $lookbackDays));

    $st = db()->prepare("
        SELECT
            SUM(amount) AS fuel_amount,
            SUM(gallons) AS fuel_gallons
        FROM field_vehicle_events
        WHERE vehicle_id = ?
          AND event_type = 'FUEL'
          AND deleted_at IS NULL
          AND event_date >= DATE_SUB(CURDATE(), INTERVAL {$lookbackDays} DAY)
          AND gallons IS NOT NULL
          AND gallons > 0
          AND amount > 0
    ");
    $st->execute([$vehicleId]);

    $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $amount = (float)($row['fuel_amount'] ?? 0);
    $gallons = (float)($row['fuel_gallons'] ?? 0);

    if ($amount <= 0 || $gallons <= 0) {
        return null;
    }

    return round($amount / $gallons, 4);
}

function field_vehicle_cost_model_from_row(
    array $vehicle,
    ?float $observedFuelPrice = null
): array {
    $mpg = max(
        0.0,
        (float)($vehicle['fuel_mpg_estimate'] ?? 0)
    );

    $profileFuelPrice = max(
        0.0,
        (float)($vehicle['fuel_price_per_gallon_estimate'] ?? 0)
    );

    $fuelPrice = $observedFuelPrice !== null && $observedFuelPrice > 0
        ? $observedFuelPrice
        : $profileFuelPrice;

    $fuelPriceSource = $observedFuelPrice !== null
        && $observedFuelPrice > 0
            ? 'OBSERVED_90_DAY'
            : 'PROFILE';

    $fuelCpm = $mpg > 0
        ? $fuelPrice / $mpg
        : 0.0;

    $maintenanceCpm = max(
        0.0,
        (float)($vehicle['maintenance_reserve_per_mile'] ?? 0)
    );

    $tireCpm = max(
        0.0,
        (float)($vehicle['tire_reserve_per_mile'] ?? 0)
    );

    $repairCpm = max(
        0.0,
        (float)($vehicle['repair_reserve_per_mile'] ?? 0)
    );

    $depreciationOverride =
        $vehicle['depreciation_per_mile_override'] ?? null;

    if (
        $depreciationOverride !== null
        && $depreciationOverride !== ''
    ) {
        $depreciationCpm = max(
            0.0,
            (float)$depreciationOverride
        );

        $depreciationSource = 'OVERRIDE';
    } else {
        $basis = max(
            0.0,
            (float)($vehicle['acquisition_cost'] ?? 0)
            - (float)($vehicle['expected_residual_value'] ?? 0)
        );

        $serviceMiles = max(
            0.0,
            (float)($vehicle['expected_service_miles'] ?? 0)
        );

        $depreciationCpm = $serviceMiles > 0
            ? $basis / $serviceMiles
            : 0.0;

        $depreciationSource = 'PROFILE_BASIS';
    }

    $annualFixedCost =
        max(0.0, (float)($vehicle['insurance_annual_cost'] ?? 0))
        + max(
            0.0,
            (float)($vehicle['registration_annual_cost'] ?? 0)
        )
        + max(
            0.0,
            (float)($vehicle['other_fixed_annual_cost'] ?? 0)
        );

    $annualBusinessMiles = max(
        0.0,
        (float)($vehicle['expected_annual_business_miles'] ?? 0)
    );

    $fixedCpm = $annualBusinessMiles > 0
        ? $annualFixedCost / $annualBusinessMiles
        : 0.0;

    $allInCpm =
        $fuelCpm
        + $maintenanceCpm
        + $tireCpm
        + $repairCpm
        + $depreciationCpm
        + $fixedCpm;

    return [
        'fuel_price_per_gallon' => round($fuelPrice, 4),
        'fuel_price_source' => $fuelPriceSource,
        'mpg' => round($mpg, 2),

        'fuel_cpm' => round($fuelCpm, 4),
        'maintenance_cpm' => round($maintenanceCpm, 4),
        'tire_cpm' => round($tireCpm, 4),
        'repair_cpm' => round($repairCpm, 4),
        'depreciation_cpm' => round($depreciationCpm, 4),
        'depreciation_source' => $depreciationSource,
        'fixed_cpm' => round($fixedCpm, 4),

        'annual_fixed_cost' => round($annualFixedCost, 2),
        'expected_annual_business_miles' => round(
            $annualBusinessMiles,
            1
        ),

        'all_in_cpm' => round($allInCpm, 4),
        'model_version' => 1,
    ];
}

function field_vehicles(bool $includeInactive = false): array
{
    field_vehicles_ensure_schema();

    $where = $includeInactive
        ? ''
        : 'WHERE active = 1';

    return db()->query("
        SELECT *
        FROM field_vehicles
        {$where}
        ORDER BY is_primary DESC,
                 active DESC,
                 vehicle_name ASC,
                 vehicle_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_vehicle_find(int $vehicleId): ?array
{
    field_vehicles_ensure_schema();

    if ($vehicleId <= 0) {
        return null;
    }

    $st = db()->prepare("
        SELECT *
        FROM field_vehicles
        WHERE vehicle_id = ?
        LIMIT 1
    ");
    $st->execute([$vehicleId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_primary(): ?array
{
    field_vehicles_ensure_schema();

    $row = db()->query("
        SELECT *
        FROM field_vehicles
        WHERE active = 1
        ORDER BY is_primary DESC,
                 vehicle_id ASC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_cost_model(int $vehicleId): array
{
    $vehicle = field_vehicle_find($vehicleId);

    if (!$vehicle) {
        return [
            'ok' => false,
            'errors' => ['Vehicle not found.'],
        ];
    }

    $observedFuelPrice = field_vehicle_observed_fuel_price(
        $vehicleId,
        90
    );

    return [
        'ok' => true,
        'vehicle_id' => $vehicleId,
        'vehicle_name' => (string)$vehicle['vehicle_name'],
        ...field_vehicle_cost_model_from_row(
            $vehicle,
            $observedFuelPrice
        ),
    ];
}

function field_vehicle_save(array $input): array
{
    field_vehicles_ensure_schema();

    $vehicleId = (int)($input['vehicle_id'] ?? 0);
    $vehicleName = trim((string)($input['vehicle_name'] ?? ''));

    if ($vehicleName === '') {
        return [
            'ok' => false,
            'errors' => ['Vehicle name is required.'],
        ];
    }

    $isPrimary = !empty($input['is_primary']) ? 1 : 0;
    $active = array_key_exists('active', $input)
        ? (!empty($input['active']) ? 1 : 0)
        : 1;

    $values = [
        $vehicleName,
        field_vehicle_nullable_decimal($input['model_year'] ?? null, 0),
        trim((string)($input['make'] ?? '')) ?: null,
        trim((string)($input['model'] ?? '')) ?: null,
        trim((string)($input['trim_name'] ?? '')) ?: null,
        trim((string)($input['vin'] ?? '')) ?: null,
        trim((string)($input['plate'] ?? '')) ?: null,

        field_vehicle_nullable_date($input['in_service_date'] ?? null),
        field_vehicle_nullable_date(
            $input['out_of_service_date'] ?? null
        ),
        field_vehicle_nullable_decimal(
            $input['current_odometer'] ?? null,
            1
        ),

        field_vehicle_decimal($input['acquisition_cost'] ?? 0),
        field_vehicle_decimal(
            $input['expected_residual_value'] ?? 0
        ),
        field_vehicle_decimal(
            $input['expected_service_miles'] ?? 0,
            1
        ),

        field_vehicle_decimal(
            $input['fuel_mpg_estimate'] ?? 0,
            2
        ),
        field_vehicle_decimal(
            $input['fuel_price_per_gallon_estimate'] ?? 0,
            3
        ),

        field_vehicle_decimal(
            $input['maintenance_reserve_per_mile'] ?? 0,
            4
        ),
        field_vehicle_decimal(
            $input['tire_reserve_per_mile'] ?? 0,
            4
        ),
        field_vehicle_decimal(
            $input['repair_reserve_per_mile'] ?? 0,
            4
        ),
        field_vehicle_nullable_decimal(
            $input['depreciation_per_mile_override'] ?? null,
            4
        ),

        field_vehicle_decimal(
            $input['insurance_annual_cost'] ?? 0
        ),
        field_vehicle_decimal(
            $input['registration_annual_cost'] ?? 0
        ),
        field_vehicle_decimal(
            $input['other_fixed_annual_cost'] ?? 0
        ),
        field_vehicle_decimal(
            $input['expected_annual_business_miles'] ?? 0,
            1
        ),

        $isPrimary,
        $active,
        trim((string)($input['notes'] ?? '')) ?: null,
    ];

    $pdo = db();

    if ($isPrimary === 1) {
        $pdo->exec("
            UPDATE field_vehicles
            SET is_primary = 0
        ");
    }

    if ($vehicleId > 0) {
        $st = $pdo->prepare("
            UPDATE field_vehicles
            SET vehicle_name = ?,
                model_year = ?,
                make = ?,
                model = ?,
                trim_name = ?,
                vin = ?,
                plate = ?,
                in_service_date = ?,
                out_of_service_date = ?,
                current_odometer = ?,
                acquisition_cost = ?,
                expected_residual_value = ?,
                expected_service_miles = ?,
                fuel_mpg_estimate = ?,
                fuel_price_per_gallon_estimate = ?,
                maintenance_reserve_per_mile = ?,
                tire_reserve_per_mile = ?,
                repair_reserve_per_mile = ?,
                depreciation_per_mile_override = ?,
                insurance_annual_cost = ?,
                registration_annual_cost = ?,
                other_fixed_annual_cost = ?,
                expected_annual_business_miles = ?,
                is_primary = ?,
                active = ?,
                notes = ?,
                updated_at = NOW()
            WHERE vehicle_id = ?
            LIMIT 1
        ");

        $st->execute([
            ...$values,
            $vehicleId,
        ]);

        return [
            'ok' => true,
            'vehicle_id' => $vehicleId,
        ];
    }

    $st = $pdo->prepare("
        INSERT INTO field_vehicles
          (
            vehicle_name,
            model_year,
            make,
            model,
            trim_name,
            vin,
            plate,
            in_service_date,
            out_of_service_date,
            current_odometer,
            acquisition_cost,
            expected_residual_value,
            expected_service_miles,
            fuel_mpg_estimate,
            fuel_price_per_gallon_estimate,
            maintenance_reserve_per_mile,
            tire_reserve_per_mile,
            repair_reserve_per_mile,
            depreciation_per_mile_override,
            insurance_annual_cost,
            registration_annual_cost,
            other_fixed_annual_cost,
            expected_annual_business_miles,
            is_primary,
            active,
            notes
          )
        VALUES
          (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?
          )
    ");

    $st->execute($values);

    return [
        'ok' => true,
        'vehicle_id' => (int)$pdo->lastInsertId(),
    ];
}

function field_vehicle_events(
    int $vehicleId,
    int $limit = 250
): array {
    field_vehicles_ensure_schema();

    $limit = max(1, min(1000, $limit));

    $st = db()->prepare("
        SELECT *
        FROM field_vehicle_events
        WHERE vehicle_id = ?
          AND deleted_at IS NULL
        ORDER BY event_date DESC,
                 vehicle_event_id DESC
        LIMIT {$limit}
    ");
    $st->execute([$vehicleId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_vehicle_find_event(
    int $vehicleEventId
): ?array {
    field_vehicles_ensure_schema();

    if ($vehicleEventId <= 0) {
        return null;
    }

    $st = db()->prepare("
        SELECT *
        FROM field_vehicle_events
        WHERE vehicle_event_id = ?
          AND deleted_at IS NULL
        LIMIT 1
    ");
    $st->execute([$vehicleEventId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function field_vehicle_save_event(
    array $input,
    ?int $createdBy = null
): array {
    field_vehicles_ensure_schema();

    $vehicleEventId = (int)($input['vehicle_event_id'] ?? 0);
    $vehicleId = (int)($input['vehicle_id'] ?? 0);
    $vehicle = field_vehicle_find($vehicleId);

    if (!$vehicle) {
        return [
            'ok' => false,
            'errors' => ['Vehicle not found.'],
        ];
    }

    $eventTypes = field_vehicle_event_types();
    $eventType = strtoupper(
        trim((string)($input['event_type'] ?? 'OTHER'))
    );

    if (!array_key_exists($eventType, $eventTypes)) {
        return [
            'ok' => false,
            'errors' => ['Invalid vehicle event type.'],
        ];
    }

    $treatments = field_vehicle_cost_treatments();
    $costTreatment = strtoupper(
        trim((string)($input['cost_treatment'] ?? 'NORMAL'))
    );

    if (!array_key_exists($costTreatment, $treatments)) {
        return [
            'ok' => false,
            'errors' => ['Invalid cost treatment.'],
        ];
    }

    $eventDate = field_vehicle_nullable_date(
        $input['event_date'] ?? null
    );

    if ($eventDate === null) {
        return [
            'ok' => false,
            'errors' => ['Event date is required.'],
        ];
    }

    $description = trim(
        (string)($input['description'] ?? '')
    );

    if ($description === '') {
        return [
            'ok' => false,
            'errors' => ['Description is required.'],
        ];
    }

    $odometer = field_vehicle_nullable_decimal(
        $input['odometer'] ?? null,
        1
    );

    $amount = field_vehicle_decimal(
        $input['amount'] ?? 0,
        2
    );

    $gallons = field_vehicle_nullable_decimal(
        $input['gallons'] ?? null,
        3
    );

    $fuelPrice = field_vehicle_nullable_decimal(
        $input['fuel_price_per_gallon'] ?? null,
        4
    );

    if (
        $eventType === 'FUEL'
        && $fuelPrice === null
        && $amount > 0
        && $gallons !== null
        && $gallons > 0
    ) {
        $fuelPrice = round($amount / $gallons, 4);
    }

    $vendor = trim(
        (string)($input['vendor'] ?? '')
    ) ?: null;

    $amortizeOverMiles = field_vehicle_nullable_decimal(
        $input['amortize_over_miles'] ?? null,
        1
    );

    $notes = trim(
        (string)($input['notes'] ?? '')
    ) ?: null;

    $pdo = db();
    $created = $vehicleEventId <= 0;

    if ($vehicleEventId > 0) {
        $existingEvent = field_vehicle_find_event($vehicleEventId);

        if (
            !$existingEvent
            || (int)$existingEvent['vehicle_id'] !== $vehicleId
        ) {
            return [
                'ok' => false,
                'errors' => ['Vehicle event not found.'],
            ];
        }

        $st = $pdo->prepare("
            UPDATE field_vehicle_events
            SET event_type = ?,
                cost_treatment = ?,
                event_date = ?,
                odometer = ?,
                vendor = ?,
                description = ?,
                amount = ?,
                gallons = ?,
                fuel_price_per_gallon = ?,
                amortize_over_miles = ?,
                notes = ?,
                updated_at = NOW()
            WHERE vehicle_event_id = ?
              AND vehicle_id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $st->execute([
            $eventType,
            $costTreatment,
            $eventDate,
            $odometer,
            $vendor,
            $description,
            $amount,
            $gallons,
            $fuelPrice,
            $amortizeOverMiles,
            $notes,
            $vehicleEventId,
            $vehicleId,
        ]);
    } else {
        $st = $pdo->prepare("
            INSERT INTO field_vehicle_events
              (
                vehicle_id,
                event_type,
                cost_treatment,
                event_date,
                odometer,
                vendor,
                description,
                amount,
                gallons,
                fuel_price_per_gallon,
                amortize_over_miles,
                notes,
                created_by
              )
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $st->execute([
            $vehicleId,
            $eventType,
            $costTreatment,
            $eventDate,
            $odometer,
            $vendor,
            $description,
            $amount,
            $gallons,
            $fuelPrice,
            $amortizeOverMiles,
            $notes,
            $createdBy,
        ]);

        $vehicleEventId = (int)$pdo->lastInsertId();
    }

    if (
        $odometer !== null
        && (
            $vehicle['current_odometer'] === null
            || $odometer > (float)$vehicle['current_odometer']
        )
    ) {
        $update = $pdo->prepare("
            UPDATE field_vehicles
            SET current_odometer = ?,
                updated_at = NOW()
            WHERE vehicle_id = ?
            LIMIT 1
        ");
        $update->execute([
            $odometer,
            $vehicleId,
        ]);
    }

    return [
        'ok' => true,
        'vehicle_event_id' => $vehicleEventId,
        'vehicle_id' => $vehicleId,
        'created' => $created,
        'updated' => !$created,
    ];
}

function field_vehicle_spend_summary(
    int $vehicleId,
    ?int $year = null
): array {
    field_vehicles_ensure_schema();

    $year = $year ?? (int)date('Y');

    $st = db()->prepare("
        SELECT
            event_type,
            cost_treatment,
            COUNT(*) AS event_count,
            COALESCE(SUM(amount), 0) AS total_amount
        FROM field_vehicle_events
        WHERE vehicle_id = ?
          AND deleted_at IS NULL
          AND YEAR(event_date) = ?
        GROUP BY event_type,
                 cost_treatment
        ORDER BY total_amount DESC,
                 event_type ASC
    ");
    $st->execute([
        $vehicleId,
        $year,
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $total = 0.0;

    foreach ($rows as $row) {
        $total += (float)($row['total_amount'] ?? 0);
    }

    return [
        'vehicle_id' => $vehicleId,
        'year' => $year,
        'total_spend' => round($total, 2),
        'rows' => $rows,
    ];
}
