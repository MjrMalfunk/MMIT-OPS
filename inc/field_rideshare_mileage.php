<?php
declare(strict_types=1);

/** Validate readings without silently turning a blank or malformed value into zero. */
function field_rideshare_reading(mixed $value, string $label): float
{
    if (!is_scalar($value) || trim((string)$value) === '' || !is_numeric($value)) {
        throw new InvalidArgumentException("Enter a numeric {$label}.");
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > 999999999) {
        throw new InvalidArgumentException("Invalid {$label}.");
    }
    return round($number, 1);
}

/** Legacy records keep their meaning. A whole-outing split partitions, never adds miles. */
function field_rideshare_mileage(array $input): array
{
    $start = field_rideshare_reading($input['odometer_start'] ?? null, 'starting odometer');
    $end = field_rideshare_reading($input['odometer_end'] ?? null, 'ending odometer');
    if ($end < $start) {
        throw new InvalidArgumentException('Ending odometer cannot be below starting odometer.');
    }
    $scope = (string)($input['odometer_scope'] ?? 'WORK_INTERVAL');
    if (!in_array($scope, ['WORK_INTERVAL', 'ENTIRE_OUTING'], true)) {
        throw new InvalidArgumentException('Select what the ending odometer covers.');
    }
    $span = round($end - $start, 2);
    $offlineReading = null;
    $known = true;
    $source = 'LEGACY';
    $offline = 0.0;
    $rawOffline = $input['deadhead_miles'] ?? '';
    if ($scope === 'WORK_INTERVAL') {
        if ($rawOffline !== '') {
            if (!is_numeric($rawOffline) || !is_finite((float)$rawOffline) || (float)$rawOffline < 0) {
                throw new InvalidArgumentException('Offline miles must be a nonnegative number.');
            }
            $offline = round((float)$rawOffline, 2);
        }
        $before = $span;
        $total = round($span + $offline, 2);
    } else {
        if (isset($input['offline_odometer']) && trim((string)$input['offline_odometer']) !== '') {
            $offlineReading = field_rideshare_reading($input['offline_odometer'], 'odometer at going offline');
            if ($offlineReading < $start || $offlineReading > $end) {
                throw new InvalidArgumentException('Offline odometer must be between the starting and ending readings.');
            }
            $offline = round($end - $offlineReading, 2);
            $source = 'ODOMETER';
        } elseif ($rawOffline !== '' && $rawOffline !== null) {
            if (!is_numeric($rawOffline) || !is_finite((float)$rawOffline) || (float)$rawOffline < 0) {
                throw new InvalidArgumentException('Offline miles must be a nonnegative number.');
            }
            $offline = round((float)$rawOffline, 2);
            if ($offline > $span) {
                throw new InvalidArgumentException('Included offline miles cannot exceed the full outing total.');
            }
            $source = (string)($input['mileage_split_source'] ?? 'MANUAL_ESTIMATE');
            if (!in_array($source, ['GPS_ESTIMATE', 'MANUAL_ESTIMATE'], true)) {
                $source = 'MANUAL_ESTIMATE';
            }
        } else {
            $known = false;
            $source = 'UNALLOCATED';
        }
        // Existing totals consistently sum these columns. Unallocated miles remain
        // in business_miles for storage; their online/offline split is displayed as unknown.
        $before = round($span - $offline, 2);
        $total = $span;
    }
    return [
        'odometer_scope' => $scope,
        'offline_odometer' => $offlineReading,
        'mileage_split_source' => $source,
        'mileage_split_known' => $known,
        'business_miles' => $before,
        'deadhead_miles' => $offline,
        'total_business_miles' => $total,
    ];
}

/** Read only completed rideshare packets. Never infer odometers or earnings from GPS. */
function field_rideshare_tracker_preview(string $raw): array
{
    if (strlen($raw) > 4 * 1024 * 1024) {
        throw new InvalidArgumentException('Tracker file is larger than 4 MB.');
    }
    try {
        $packet = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new InvalidArgumentException('Select a valid tracker JSON file.', 0, $error);
    }
    if (!is_array($packet) || ($packet['schema'] ?? '') !== 'mmit.work-tracker.v2'
        || !is_array($packet['shift'] ?? null)) {
        throw new InvalidArgumentException('This is not a supported tracker packet.');
    }
    $shift = $packet['shift'];
    $id = (string)($shift['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $id)
        || !in_array($shift['platform'] ?? '', ['LYFT', 'UBER'], true)) {
        throw new InvalidArgumentException('Select a completed Lyft or Uber outing.');
    }
    $times = [];
    foreach (['startedAtEpochMs', 'wentOfflineAtEpochMs', 'homeArrivedAtEpochMs', 'completedAtEpochMs'] as $key) {
        $value = $shift[$key] ?? null;
        if (!is_numeric($value) || (float)$value < 946684800000 || (float)$value > 4102444800000) {
            throw new InvalidArgumentException('The outing is missing a valid start, offline, arrival, or completion time.');
        }
        $times[$key] = (int)$value;
    }
    $arrival = $times['homeArrivedAtEpochMs'];
    if (isset($shift['correctedHomeArrivedAtEpochMs'])) {
        if (!is_numeric($shift['correctedHomeArrivedAtEpochMs'])) {
            throw new InvalidArgumentException('Invalid corrected arrival time.');
        }
        $arrival = (int)$shift['correctedHomeArrivedAtEpochMs'];
    }
    $start = $times['startedAtEpochMs'];
    $offline = $times['wentOfflineAtEpochMs'];
    if ($offline < $start || $arrival < $offline || $arrival > $times['homeArrivedAtEpochMs']
        || $times['completedAtEpochMs'] < $times['homeArrivedAtEpochMs']) {
        throw new InvalidArgumentException('Tracker times are out of order.');
    }
    // Use the same event boundaries as the tracker; retain seconds in form values.
    $events = $packet['events'] ?? [];
    if (!is_array($events)) {
        throw new InvalidArgumentException('Invalid tracker events.');
    }
    foreach ($events as $event) {
        if (!is_array($event) || !is_numeric($event['occurredAtEpochMs'] ?? null)) {
            throw new InvalidArgumentException('Invalid tracker event.');
        }
    }
    usort($events, static fn($a, $b) => (int)$a['occurredAtEpochMs'] <=> (int)$b['occurredAtEpochMs']);
    $breakStart = null;
    $breaks = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            throw new InvalidArgumentException('Invalid tracker event.');
        }
        $time = max($start, min($offline, (int)($event['occurredAtEpochMs'] ?? 0)));
        if (($event['type'] ?? '') === 'BREAK_STARTED' && $breakStart === null) {
            $breakStart = $time;
        } elseif (($event['type'] ?? '') === 'BREAK_ENDED' && $breakStart !== null) {
            $breaks[] = [$breakStart, $time];
            $breakStart = null;
        }
    }
    if ($breakStart !== null) {
        $breaks[] = [$breakStart, $offline];
    }
    $format = static fn(int $ms): string => (new DateTimeImmutable('@' . intdiv($ms, 1000)))
        ->setTimezone(new DateTimeZone('America/Indiana/Indianapolis'))->format('Y-m-d\TH:i:s');
    $form = [
        'platform' => $shift['platform'],
        'shift_date' => substr($format($start), 0, 10),
        'started_at' => $format($start),
        'ended_at' => $format($offline),
        'odometer_start' => $shift['startOdometer'] ?? null,
        'odometer_end' => $shift['endOdometer'] ?? null,
        'odometer_scope' => 'ENTIRE_OUTING',
        'offline_odometer' => $shift['offlineOdometer'] ?? '',
        // A blank split stays unknown. GPS is shown as a suggestion, not accepted automatically.
        'deadhead_miles' => '',
        'mileage_split_source' => 'MANUAL_ESTIMATE',
        'deadhead_minutes' => (int)round(($arrival - $offline) / 60000),
        'break_started_at' => array_map(static fn($b) => $format($b[0]), $breaks),
        'break_ended_at' => array_map(static fn($b) => $format($b[1]), $breaks),
    ];
    $mileage = field_rideshare_mileage($form);
    $warnings = ['Times use Fort Wayne local time and retain seconds; displayed minutes are rounded. Confirm Lyft/Uber earnings and booked figures separately.'];
    if ($arrival !== $times['homeArrivedAtEpochMs']) {
        $warnings[] = 'Arrival uses your driver correction; the original tap remains in the saved packet.';
    }
    if ($breaks) {
        $warnings[] = 'Review any personal miles during breaks. The odometer total includes every mile in this outing.';
    }
    $gps = $packet['mileageSummary']['offlineGps'] ?? null;
    if (is_array($gps) && ($gps['source'] ?? '') === 'GPS_ESTIMATE' && is_numeric($gps['miles'] ?? null)) {
        $distance = (float)$gps['miles'];
        if (is_finite($distance) && $distance >= 0) {
            $warnings[] = sprintf('Tracker return GPS estimate: %.2f mi. Gaps over 60 seconds: %d; rejected links: %d. This is not an odometer reading.',
                $distance, (int)($gps['gapsOver60Seconds'] ?? 0), (int)($gps['rejectedLinks'] ?? 0));
        }
    }
    if (!$mileage['mileage_split_known']) {
        $warnings[] = 'Offline odometer was not recorded. Total miles are preserved; the online/offline split is unknown unless you enter an estimate.';
    }
    return ['form' => $form, 'tracker_shift_id' => strtolower($id), 'sha256' => hash('sha256', $raw),
        'warnings' => $warnings, 'raw' => $raw];
}

/**
 * Pure calculator used by both persistence and smoke tests.
 *
 * Platform fees are informational. Base ride earnings represent the
 * driver's earned amount and therefore are not reduced by platform fees.
 */
function field_rideshare_calculate(array $input): array
{
    $mileage = field_rideshare_mileage($input);
    $businessMiles = $mileage['business_miles'];
    $deadheadMiles = $mileage['deadhead_miles'];
    $totalBusinessMiles = $mileage['total_business_miles'];

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
        ...$mileage,
        'business_miles' => $businessMiles,
        'deadhead_miles' => $deadheadMiles,
        'total_business_miles' => $totalBusinessMiles,
        'deadhead_minutes' => $deadheadMinutes,
        'total_work_minutes' => $totalWorkMinutes,
        'vehicle_cpm_snapshot' => $vehicleCpm,
        'online_vehicle_cost' => $mileage['mileage_split_known'] ? $onlineVehicleCost : null,
        'deadhead_vehicle_cost' => $mileage['mileage_split_known'] ? $deadheadVehicleCost : null,
        'vehicle_cost' => $vehicleCost,
        'recognized_revenue' => $recognizedRevenue,
        'true_operating_profit' => $profit,

        'gross_per_business_mile' => $mileage['mileage_split_known'] && $businessMiles > 0
            ? round($recognizedRevenue / $businessMiles, 2)
            : null,

        'profit_per_business_mile' => $mileage['mileage_split_known'] && $businessMiles > 0
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

