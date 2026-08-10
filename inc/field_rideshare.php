<?php
declare(strict_types=1);

require_once __DIR__ . '/field_vehicles.php';

/**
 * Lyft/rideshare profitability tracking.
 *
 * Accounting journals are intentionally excluded until the real Lyft
 * earnings statement and Lyft Direct transaction formats are validated.
 */
function field_rideshare_ensure_schema(): void
{
    static $schemaReady = false;

    if ($schemaReady) {
        return;
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS field_rideshare_shifts (
            shift_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vehicle_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(40) NOT NULL DEFAULT 'LYFT',
            shift_date DATE NOT NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,

            odometer_start DECIMAL(12,1) NOT NULL,
            odometer_end DECIMAL(12,1) NOT NULL,
            business_miles DECIMAL(12,2) NOT NULL,
            deadhead_miles DECIMAL(12,2) NOT NULL DEFAULT 0,
            deadhead_minutes INT UNSIGNED NOT NULL DEFAULT 0,

            online_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            booked_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            passenger_minutes INT UNSIGNED NOT NULL DEFAULT 0,
            booked_miles DECIMAL(12,2) NOT NULL DEFAULT 0,
            passenger_miles DECIMAL(12,2) NOT NULL DEFAULT 0,

            base_ride_earnings DECIMAL(12,2) NOT NULL DEFAULT 0,
            tips DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonuses DECIMAL(12,2) NOT NULL DEFAULT 0,
            adjustments DECIMAL(12,2) NOT NULL DEFAULT 0,
            toll_reimbursements DECIMAL(12,2) NOT NULL DEFAULT 0,

            platform_fees DECIMAL(12,2) NOT NULL DEFAULT 0,
            direct_trip_costs DECIMAL(12,2) NOT NULL DEFAULT 0,

            vehicle_cpm_snapshot DECIMAL(12,4) NOT NULL,
            vehicle_cost DECIMAL(12,2) NOT NULL,
            recognized_revenue DECIMAL(12,2) NOT NULL,
            true_operating_profit DECIMAL(12,2) NOT NULL,

            payout_destination VARCHAR(40)
                NOT NULL DEFAULT 'LYFT_DIRECT',
            notes TEXT NULL,

            created_by_user_id BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (shift_id),
            KEY idx_rideshare_vehicle_date (vehicle_id, shift_date),
            KEY idx_rideshare_platform_date (platform, shift_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );

    foreach ([
        'deadhead_miles' =>
            'DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER business_miles',
        'deadhead_minutes' =>
            'INT UNSIGNED NOT NULL DEFAULT 0 AFTER deadhead_miles',
    ] as $column => $definition) {
        if (!db_column_exists('field_rideshare_shifts', $column)) {
            db()->exec(
                "ALTER TABLE field_rideshare_shifts "
                . "ADD COLUMN {$column} {$definition}"
            );
        }
    }

    db()->exec(
        "CREATE TABLE IF NOT EXISTS field_rideshare_breaks (
            break_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shift_id BIGINT UNSIGNED NOT NULL,
            break_position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            started_at DATETIME NOT NULL,
            ended_at DATETIME NOT NULL,
            break_minutes INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (break_id),
            KEY idx_rideshare_break_shift (
                shift_id,
                break_position
            )
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci"
    );

    $schemaReady = true;
}

/**
 * Pure calculator used by both persistence and smoke tests.
 *
 * Platform fees are informational. Base ride earnings represent the
 * driver's earned amount and therefore are not reduced by platform fees.
 */
function field_rideshare_calculate(array $input): array
{
    $odometerStart = round(
        max(0.0, (float)($input['odometer_start'] ?? 0)),
        1
    );

    $odometerEnd = round(
        max(0.0, (float)($input['odometer_end'] ?? 0)),
        1
    );

    if ($odometerEnd < $odometerStart) {
        throw new InvalidArgumentException(
            'Ending odometer cannot be below starting odometer.'
        );
    }

    $businessMiles = round($odometerEnd - $odometerStart, 2);
    $deadheadMiles = round(
        max(0.0, (float)($input['deadhead_miles'] ?? 0)),
        2
    );
    $totalBusinessMiles = round(
        $businessMiles + $deadheadMiles,
        2
    );

    $vehicleCpm = round(
        max(0.0, (float)($input['vehicle_cpm_snapshot'] ?? 0)),
        4
    );

    $base = round(
        (float)($input['base_ride_earnings'] ?? 0),
        2
    );

    $tips = round((float)($input['tips'] ?? 0), 2);
    $bonuses = round((float)($input['bonuses'] ?? 0), 2);
    $adjustments = round((float)($input['adjustments'] ?? 0), 2);

    $tolls = round(
        (float)($input['toll_reimbursements'] ?? 0),
        2
    );

    $directCosts = round(
        max(0.0, (float)($input['direct_trip_costs'] ?? 0)),
        2
    );

    $recognizedRevenue = round(
        $base + $tips + $bonuses + $adjustments + $tolls,
        2
    );

    $onlineVehicleCost = round($businessMiles * $vehicleCpm, 2);
    $deadheadVehicleCost = round($deadheadMiles * $vehicleCpm, 2);
    $vehicleCost = round($totalBusinessMiles * $vehicleCpm, 2);

    $profit = round(
        $recognizedRevenue - $directCosts - $vehicleCost,
        2
    );

    $onlineMinutes = max(
        0,
        (int)($input['online_minutes'] ?? 0)
    );

    $onlineHours = $onlineMinutes / 60;
    $deadheadMinutes = max(
        0,
        (int)($input['deadhead_minutes'] ?? 0)
    );
    $totalWorkMinutes = $onlineMinutes + $deadheadMinutes;
    $totalWorkHours = $totalWorkMinutes / 60;

    return [
        'business_miles' => $businessMiles,
        'deadhead_miles' => $deadheadMiles,
        'total_business_miles' => $totalBusinessMiles,
        'deadhead_minutes' => $deadheadMinutes,
        'total_work_minutes' => $totalWorkMinutes,
        'vehicle_cpm_snapshot' => $vehicleCpm,
        'online_vehicle_cost' => $onlineVehicleCost,
        'deadhead_vehicle_cost' => $deadheadVehicleCost,
        'vehicle_cost' => $vehicleCost,
        'recognized_revenue' => $recognizedRevenue,
        'true_operating_profit' => $profit,

        'gross_per_business_mile' => $businessMiles > 0
            ? round($recognizedRevenue / $businessMiles, 2)
            : null,

        'profit_per_business_mile' => $businessMiles > 0
            ? round($profit / $businessMiles, 2)
            : null,

        'gross_per_total_business_mile' => $totalBusinessMiles > 0
            ? round($recognizedRevenue / $totalBusinessMiles, 2)
            : null,

        'profit_per_total_business_mile' => $totalBusinessMiles > 0
            ? round($profit / $totalBusinessMiles, 2)
            : null,

        'gross_per_online_hour' => $onlineHours > 0
            ? round($recognizedRevenue / $onlineHours, 2)
            : null,

        'profit_per_online_hour' => $onlineHours > 0
            ? round($profit / $onlineHours, 2)
            : null,

        'gross_per_total_hour' => $totalWorkHours > 0
            ? round($recognizedRevenue / $totalWorkHours, 2)
            : null,

        'profit_per_total_hour' => $totalWorkHours > 0
            ? round($profit / $totalWorkHours, 2)
            : null,
    ];
}

function field_rideshare_find_shift(int $shiftId): ?array
{
    field_rideshare_ensure_schema();

    if ($shiftId <= 0) {
        return null;
    }

    $st = db()->prepare("
        SELECT s.*, v.vehicle_name
        FROM field_rideshare_shifts s
        INNER JOIN field_vehicles v
            ON v.vehicle_id = s.vehicle_id
        WHERE s.shift_id = ?
        LIMIT 1
    ");
    $st->execute([$shiftId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}


function field_rideshare_normalize_datetime(
    mixed $value,
    string $label
): ?string {
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $formats = [
        'Y-m-d H:i',
        'Y-m-d H:i:s',
        'Y-m-d\TH:i',
    ];

    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);

        if (
            $date instanceof DateTimeImmutable
            && $date->format($format) === $value
        ) {
            return $date->format('Y-m-d H:i:s');
        }
    }

    throw new InvalidArgumentException(
        "{$label} must use YYYY-MM-DD HH:MM."
    );
}


/**
 * Normalize and validate submitted break intervals.
 *
 * @return array<int, array{
 *     started_at:string,
 *     ended_at:string,
 *     break_minutes:int
 * }>
 */
function field_rideshare_normalize_breaks(
    array $input,
    ?string $shiftStartedAt,
    ?string $shiftEndedAt
): array {
    $starts = $input['break_started_at'] ?? [];
    $ends = $input['break_ended_at'] ?? [];

    if (!is_array($starts) || !is_array($ends)) {
        throw new InvalidArgumentException(
            'Break intervals are malformed.'
        );
    }

    $rowCount = max(count($starts), count($ends));
    $breaks = [];

    for ($index = 0; $index < $rowCount; $index++) {
        $rawStart = trim((string)($starts[$index] ?? ''));
        $rawEnd = trim((string)($ends[$index] ?? ''));

        if ($rawStart === '' && $rawEnd === '') {
            continue;
        }

        $rowNumber = $index + 1;

        if ($rawStart === '' || $rawEnd === '') {
            throw new InvalidArgumentException(
                "Break {$rowNumber} requires both start and end."
            );
        }

        if ($shiftStartedAt === null || $shiftEndedAt === null) {
            throw new InvalidArgumentException(
                'Enter the shift start and end before adding breaks.'
            );
        }

        $startedAt = field_rideshare_normalize_datetime(
            $rawStart,
            "Break {$rowNumber} start"
        );

        $endedAt = field_rideshare_normalize_datetime(
            $rawEnd,
            "Break {$rowNumber} end"
        );

        if (
            $startedAt === null
            || $endedAt === null
            || strtotime($endedAt) <= strtotime($startedAt)
        ) {
            throw new InvalidArgumentException(
                "Break {$rowNumber} end must be after its start."
            );
        }

        if (
            strtotime($startedAt) < strtotime($shiftStartedAt)
            || strtotime($endedAt) > strtotime($shiftEndedAt)
        ) {
            throw new InvalidArgumentException(
                "Break {$rowNumber} must fall within the shift."
            );
        }

        $minutes = (int)round(
            (strtotime($endedAt) - strtotime($startedAt)) / 60
        );

        if ($minutes < 1) {
            throw new InvalidArgumentException(
                "Break {$rowNumber} must be at least one minute."
            );
        }

        $breaks[] = [
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'break_minutes' => $minutes,
        ];
    }

    usort(
        $breaks,
        static fn(array $left, array $right): int =>
            strcmp($left['started_at'], $right['started_at'])
    );

    $previousEnd = null;

    foreach ($breaks as $break) {
        if (
            $previousEnd !== null
            && strtotime($break['started_at']) < strtotime($previousEnd)
        ) {
            throw new InvalidArgumentException(
                'Break intervals cannot overlap.'
            );
        }

        $previousEnd = $break['ended_at'];
    }

    return $breaks;
}

function field_rideshare_breaks(int $shiftId): array
{
    field_rideshare_ensure_schema();

    if ($shiftId <= 0) {
        return [];
    }

    $statement = db()->prepare(
        'SELECT
            break_id,
            shift_id,
            break_position,
            started_at,
            ended_at,
            break_minutes,
            created_at
         FROM field_rideshare_breaks
         WHERE shift_id = ?
         ORDER BY break_position ASC, break_id ASC'
    );
    $statement->execute([$shiftId]);

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function field_rideshare_total_break_minutes(array $breaks): int
{
    $total = 0;

    foreach ($breaks as $break) {
        $total += max(0, (int)($break['break_minutes'] ?? 0));
    }

    return $total;
}

function field_rideshare_replace_breaks(
    int $shiftId,
    array $breaks
): void {
    field_rideshare_ensure_schema();

    if ($shiftId <= 0) {
        throw new InvalidArgumentException(
            'A saved shift is required before storing breaks.'
        );
    }

    $delete = db()->prepare(
        'DELETE FROM field_rideshare_breaks WHERE shift_id = ?'
    );
    $delete->execute([$shiftId]);

    if ($breaks === []) {
        return;
    }

    $insert = db()->prepare(
        'INSERT INTO field_rideshare_breaks (
            shift_id,
            break_position,
            started_at,
            ended_at,
            break_minutes
         ) VALUES (?, ?, ?, ?, ?)'
    );

    foreach ($breaks as $position => $break) {
        $insert->execute([
            $shiftId,
            $position,
            $break['started_at'],
            $break['ended_at'],
            $break['break_minutes'],
        ]);
    }
}

function field_rideshare_save_shift(
    array $input,
    int $userId = 0
): array {
    field_rideshare_ensure_schema();

    $shiftId = max(0, (int)($input['shift_id'] ?? 0));
    $vehicleId = max(0, (int)($input['vehicle_id'] ?? 0));

    $vehicle = field_vehicle_find($vehicleId);

    if (!$vehicle || empty($vehicle['active'])) {
        throw new InvalidArgumentException(
            'Select an active service vehicle.'
        );
    }

    $shiftDate = trim((string)($input['shift_date'] ?? ''));

    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d',
        $shiftDate
    );

    if (
        !$date instanceof DateTimeImmutable
        || $date->format('Y-m-d') !== $shiftDate
    ) {
        throw new InvalidArgumentException(
            'Shift date must use YYYY-MM-DD.'
        );
    }

    $startedAt = field_rideshare_normalize_datetime(
        $input['started_at'] ?? null,
        'Shift start'
    );

    $endedAt = field_rideshare_normalize_datetime(
        $input['ended_at'] ?? null,
        'Shift end'
    );

    if (($startedAt === null) !== ($endedAt === null)) {
        throw new InvalidArgumentException(
            'Enter both shift start and shift end.'
        );
    }

    if (
        $startedAt !== null
        && $endedAt !== null
        && strtotime($endedAt) < strtotime($startedAt)
    ) {
        throw new InvalidArgumentException(
            'Shift end cannot be before shift start.'
        );
    }

    $breaks = field_rideshare_normalize_breaks(
        $input,
        $startedAt,
        $endedAt
    );

    $onlineMinutes = max(
        0,
        (int)($input['online_minutes'] ?? 0)
    );

    if ($startedAt !== null && $endedAt !== null) {
        $shiftMinutes = max(
            0,
            (int)round(
                (strtotime($endedAt) - strtotime($startedAt)) / 60
            )
        );

        $onlineMinutes = max(
            0,
            $shiftMinutes
            - field_rideshare_total_break_minutes($breaks)
        );
    }

    $bookedMinutes = max(
        0,
        (int)($input['booked_minutes'] ?? 0)
    );

    $passengerMinutes = max(
        0,
        (int)($input['passenger_minutes'] ?? 0)
    );

    if (
        $onlineMinutes > 0
        && $bookedMinutes > $onlineMinutes
    ) {
        throw new InvalidArgumentException(
            'Booked minutes cannot exceed online minutes.'
        );
    }

    if (
        $bookedMinutes > 0
        && $passengerMinutes > $bookedMinutes
    ) {
        throw new InvalidArgumentException(
            'Passenger minutes cannot exceed booked minutes.'
        );
    }

    $costModel = field_vehicle_cost_model($vehicleId);

    if (
        empty($costModel['ok'])
        || !isset($costModel['all_in_cpm'])
    ) {
        throw new RuntimeException(
            'The selected vehicle does not have a valid cost model.'
        );
    }

    $calculation = field_rideshare_calculate([
        ...$input,
        'online_minutes' => $onlineMinutes,
        'vehicle_cpm_snapshot' =>
            (float)$costModel['all_in_cpm'],
    ]);

    $platform = strtoupper(
        trim((string)($input['platform'] ?? 'LYFT'))
    );

    if ($platform === '') {
        $platform = 'LYFT';
    }

    $payoutDestination = strtoupper(
        trim(
            (string)(
                $input['payout_destination']
                ?? 'LYFT_DIRECT'
            )
        )
    );

    if (
        !in_array(
            $payoutDestination,
            ['LYFT_DIRECT', 'PNC', 'OTHER'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid payout destination.'
        );
    }

    $values = [
        'vehicle_id' => $vehicleId,
        'platform' => substr($platform, 0, 40),
        'shift_date' => $shiftDate,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,

        'odometer_start' => round(
            max(0.0, (float)($input['odometer_start'] ?? 0)),
            1
        ),
        'odometer_end' => round(
            max(0.0, (float)($input['odometer_end'] ?? 0)),
            1
        ),
        'business_miles' =>
            $calculation['business_miles'],
        'deadhead_miles' =>
            $calculation['deadhead_miles'],
        'deadhead_minutes' =>
            $calculation['deadhead_minutes'],

        'online_minutes' => $onlineMinutes,
        'booked_minutes' => $bookedMinutes,
        'passenger_minutes' => $passengerMinutes,
        'booked_miles' => round(
            max(0.0, (float)($input['booked_miles'] ?? 0)),
            2
        ),
        'passenger_miles' => round(
            max(0.0, (float)($input['passenger_miles'] ?? 0)),
            2
        ),

        'base_ride_earnings' => round(
            (float)($input['base_ride_earnings'] ?? 0),
            2
        ),
        'tips' => round((float)($input['tips'] ?? 0), 2),
        'bonuses' => round(
            (float)($input['bonuses'] ?? 0),
            2
        ),
        'adjustments' => round(
            (float)($input['adjustments'] ?? 0),
            2
        ),
        'toll_reimbursements' => round(
            (float)($input['toll_reimbursements'] ?? 0),
            2
        ),

        'platform_fees' => round(
            max(0.0, (float)($input['platform_fees'] ?? 0)),
            2
        ),
        'direct_trip_costs' => round(
            max(
                0.0,
                (float)($input['direct_trip_costs'] ?? 0)
            ),
            2
        ),

        'vehicle_cpm_snapshot' =>
            $calculation['vehicle_cpm_snapshot'],
        'vehicle_cost' => $calculation['vehicle_cost'],
        'recognized_revenue' =>
            $calculation['recognized_revenue'],
        'true_operating_profit' =>
            $calculation['true_operating_profit'],

        'payout_destination' => $payoutDestination,
        'notes' => trim((string)($input['notes'] ?? '')),
        'created_by_user_id' => $userId > 0
            ? $userId
            : null,
    ];

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        if ($shiftId > 0) {
            $lockStatement = $pdo->prepare(
                'SELECT shift_id, accounting_journal_id
                 FROM field_rideshare_shifts
                 WHERE shift_id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $lockStatement->execute([$shiftId]);

            $lockedShift = $lockStatement->fetch(PDO::FETCH_ASSOC);

            if (!$lockedShift) {
                throw new InvalidArgumentException(
                    'Rideshare shift not found.'
                );
            }

            if (!empty($lockedShift['accounting_journal_id'])) {
                throw new RuntimeException(
                    'This shift is already posted to Accounting. '
                    . 'Use an Accounting adjustment instead of editing it.'
                );
            }

            $sql = "
                UPDATE field_rideshare_shifts
                SET vehicle_id = :vehicle_id,
                    platform = :platform,
                    shift_date = :shift_date,
                    started_at = :started_at,
                    ended_at = :ended_at,
                    odometer_start = :odometer_start,
                    odometer_end = :odometer_end,
                    business_miles = :business_miles,
                    deadhead_miles = :deadhead_miles,
                    deadhead_minutes = :deadhead_minutes,
                    online_minutes = :online_minutes,
                    booked_minutes = :booked_minutes,
                    passenger_minutes = :passenger_minutes,
                    booked_miles = :booked_miles,
                    passenger_miles = :passenger_miles,
                    base_ride_earnings = :base_ride_earnings,
                    tips = :tips,
                    bonuses = :bonuses,
                    adjustments = :adjustments,
                    toll_reimbursements =
                        :toll_reimbursements,
                    platform_fees = :platform_fees,
                    direct_trip_costs = :direct_trip_costs,
                    vehicle_cpm_snapshot =
                        :vehicle_cpm_snapshot,
                    vehicle_cost = :vehicle_cost,
                    recognized_revenue =
                        :recognized_revenue,
                    true_operating_profit =
                        :true_operating_profit,
                    payout_destination =
                        :payout_destination,
                    notes = :notes
                WHERE shift_id = :shift_id
            ";

            $updateValues = $values;
            unset($updateValues['created_by_user_id']);

            $statement = $pdo->prepare($sql);
            $statement->execute([
                ...$updateValues,
                'shift_id' => $shiftId,
            ]);
        } else {
            $columns = array_keys($values);

            $sql = sprintf(
                "INSERT INTO field_rideshare_shifts (%s)
                 VALUES (%s)",
                implode(', ', $columns),
                implode(
                    ', ',
                    array_map(
                        static fn(string $column): string =>
                            ':' . $column,
                        $columns
                    )
                )
            );

            $statement = $pdo->prepare($sql);
            $statement->execute($values);
            $shiftId = (int)$pdo->lastInsertId();
        }

        field_rideshare_replace_breaks(
            $shiftId,
            $breaks
        );

        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    $saved = field_rideshare_find_shift($shiftId);

    if (!$saved) {
        throw new RuntimeException(
            'The saved rideshare shift could not be reloaded.'
        );
    }

    return [
        'shift' => $saved,
        'metrics' => $calculation,
    ];
}


function field_rideshare_shifts(
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    field_rideshare_ensure_schema();

    $where = [];
    $params = [];

    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 's.shift_date >= ?';
        $params[] = $dateFrom;
    }

    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 's.shift_date <= ?';
        $params[] = $dateTo;
    }

    $whereSql = $where
        ? 'WHERE ' . implode(' AND ', $where)
        : '';

    $st = db()->prepare("
        SELECT s.*, v.vehicle_name
        FROM field_rideshare_shifts s
        INNER JOIN field_vehicles v
            ON v.vehicle_id = s.vehicle_id
        {$whereSql}
        ORDER BY s.shift_date DESC,
                 s.started_at DESC,
                 s.shift_id DESC
    ");
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}


function field_rideshare_summary(
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    $shifts = field_rideshare_shifts($dateFrom, $dateTo);

    $summary = [
        'shift_count' => count($shifts),
        'business_miles' => 0.0,
        'deadhead_miles' => 0.0,
        'total_business_miles' => 0.0,
        'online_minutes' => 0,
        'deadhead_minutes' => 0,
        'total_work_minutes' => 0,
        'booked_minutes' => 0,
        'passenger_minutes' => 0,
        'recognized_revenue' => 0.0,
        'vehicle_cost' => 0.0,
        'direct_trip_costs' => 0.0,
        'true_operating_profit' => 0.0,
    ];

    foreach ($shifts as $shift) {
        $summary['business_miles'] +=
            (float)$shift['business_miles'];

        $summary['deadhead_miles'] +=
            (float)($shift['deadhead_miles'] ?? 0);

        $summary['total_business_miles'] +=
            (float)$shift['business_miles']
            + (float)($shift['deadhead_miles'] ?? 0);

        $summary['online_minutes'] +=
            (int)$shift['online_minutes'];

        $summary['deadhead_minutes'] +=
            (int)($shift['deadhead_minutes'] ?? 0);

        $summary['total_work_minutes'] +=
            (int)$shift['online_minutes']
            + (int)($shift['deadhead_minutes'] ?? 0);

        $summary['booked_minutes'] +=
            (int)$shift['booked_minutes'];

        $summary['passenger_minutes'] +=
            (int)$shift['passenger_minutes'];

        $summary['recognized_revenue'] +=
            (float)$shift['recognized_revenue'];

        $summary['vehicle_cost'] +=
            (float)$shift['vehicle_cost'];

        $summary['direct_trip_costs'] +=
            (float)$shift['direct_trip_costs'];

        $summary['true_operating_profit'] +=
            (float)$shift['true_operating_profit'];
    }

    $onlineHours = $summary['online_minutes'] / 60;
    $totalWorkHours = $summary['total_work_minutes'] / 60;

    $summary['gross_per_online_hour'] = $onlineHours > 0
        ? round(
            $summary['recognized_revenue'] / $onlineHours,
            2
        )
        : null;

    $summary['profit_per_online_hour'] = $onlineHours > 0
        ? round(
            $summary['true_operating_profit'] / $onlineHours,
            2
        )
        : null;

    $summary['gross_per_total_hour'] = $totalWorkHours > 0
        ? round(
            $summary['recognized_revenue'] / $totalWorkHours,
            2
        )
        : null;

    $summary['profit_per_total_hour'] = $totalWorkHours > 0
        ? round(
            $summary['true_operating_profit'] / $totalWorkHours,
            2
        )
        : null;

    $summary['gross_per_business_mile'] =
        $summary['business_miles'] > 0
            ? round(
                $summary['recognized_revenue']
                / $summary['business_miles'],
                2
            )
            : null;

    $summary['profit_per_business_mile'] =
        $summary['business_miles'] > 0
            ? round(
                $summary['true_operating_profit']
                / $summary['business_miles'],
                2
            )
            : null;

    $summary['gross_per_total_business_mile'] =
        $summary['total_business_miles'] > 0
            ? round(
                $summary['recognized_revenue']
                / $summary['total_business_miles'],
                2
            )
            : null;

    $summary['profit_per_total_business_mile'] =
        $summary['total_business_miles'] > 0
            ? round(
                $summary['true_operating_profit']
                / $summary['total_business_miles'],
                2
            )
            : null;

    foreach (
        [
            'business_miles',
            'deadhead_miles',
            'total_business_miles',
            'recognized_revenue',
            'vehicle_cost',
            'direct_trip_costs',
            'true_operating_profit',
        ] as $key
    ) {
        $summary[$key] = round((float)$summary[$key], 2);
    }

    return $summary;
}
/**
 * Post a saved rideshare shift to Accounting.
 *
 * Debit:  1020 Lyft Direct
 * Credit: 4110 Rideshare Income
 *
 * @return array{
 *     ok:bool,
 *     journal_id?:int,
 *     already_posted?:bool,
 *     errors?:array
 * }
 */
function field_rideshare_post_shift_to_accounting(
    int $shiftId,
    int $userId = 0
): array {
    require_once __DIR__ . '/accounting_source_posting.php';

    if ($shiftId <= 0) {
        return [
            'ok' => false,
            'errors' => ['Valid rideshare shift is required.'],
        ];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();

    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare(
            'SELECT *
             FROM field_rideshare_shifts
             WHERE shift_id = ?
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([$shiftId]);

        $shift = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            throw new RuntimeException('Rideshare shift not found.');
        }

        $existingJournalId =
            (int)($shift['accounting_journal_id'] ?? 0);

        if ($existingJournalId > 0) {
            if ($ownsTransaction) {
                $pdo->commit();
            }

            return [
                'ok' => true,
                'journal_id' => $existingJournalId,
                'already_posted' => true,
            ];
        }

        $recognizedRevenue = round(
            (float)$shift['recognized_revenue'],
            2
        );

        if ($recognizedRevenue <= 0) {
            throw new RuntimeException(
                'Recognized rideshare revenue must be greater than zero.'
            );
        }

        $accountStatement = $pdo->prepare(
            'SELECT account_id
             FROM gl_account
             WHERE account_code = ?
               AND is_active = 1
             LIMIT 1'
        );

        $accountStatement->execute(['1020']);
        $lyftDirectAccountId = (int)$accountStatement->fetchColumn();

        $accountStatement->execute(['4110']);
        $incomeAccountId = (int)$accountStatement->fetchColumn();

        if ($lyftDirectAccountId <= 0 || $incomeAccountId <= 0) {
            throw new RuntimeException(
                'Required rideshare Accounting accounts are missing.'
            );
        }

        $platform = trim((string)($shift['platform'] ?? 'Lyft'));
        $amount = number_format(
            $recognizedRevenue,
            2,
            '.',
            ''
        );

        $journalId = accounting_post_source_journal(
            $pdo,
            [
                'journal_date' => (string)$shift['shift_date'],
                'source_type' => 'RIDESHARE_SHIFT',
                'source_id' => $shiftId,
                'reference_number' => strtoupper($platform)
                    . '-SHIFT-'
                    . $shiftId,
                'memo' => $platform
                    . ' shift revenue for '
                    . (string)$shift['shift_date']
                    . '.',
                'posted_by' => $userId > 0 ? $userId : null,
            ],
            [
                [
                    'account_id' => $lyftDirectAccountId,
                    'debit_amount' => $amount,
                    'credit_amount' => '0.00',
                    'line_memo' => 'Rideshare earnings deposited.',
                ],
                [
                    'account_id' => $incomeAccountId,
                    'debit_amount' => '0.00',
                    'credit_amount' => $amount,
                    'line_memo' => 'Rideshare income.',
                ],
            ]
        );

        $link = $pdo->prepare(
            'UPDATE field_rideshare_shifts
             SET accounting_journal_id = ?,
                 updated_at = NOW()
             WHERE shift_id = ?
               AND accounting_journal_id IS NULL'
        );
        $link->execute([$journalId, $shiftId]);

        if ($link->rowCount() !== 1) {
            throw new RuntimeException(
                'Rideshare journal link was not saved.'
            );
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'ok' => true,
            'journal_id' => $journalId,
            'already_posted' => false,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'errors' => [$exception->getMessage()],
        ];
    }
}