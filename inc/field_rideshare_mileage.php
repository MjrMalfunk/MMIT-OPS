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

/** Read completed gig packets. GPS never supplies an odometer reading or payout. */
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
        || !in_array($shift['platform'] ?? '', ['LYFT', 'UBER', 'AMAZON_FLEX'], true)) {
        throw new InvalidArgumentException('Select a completed Lyft, Uber, or Amazon Flex outing.');
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
        'online_minutes' => max(0, (int)round(($offline - $start) / 60000) - array_sum(array_map(static fn($b) => (int)round(($b[1] - $b[0]) / 60000), $breaks))),
        'break_started_at' => array_map(static fn($b) => $format($b[0]), $breaks),
        'break_ended_at' => array_map(static fn($b) => $format($b[1]), $breaks),
    ];
    $mileage = field_rideshare_mileage($form);
    $warnings = ['Times use Fort Wayne local time and retain seconds; displayed minutes are rounded. Confirm Lyft/Uber earnings before saving.'];
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
    $rideshare = null;
    if ($shift['platform'] !== 'AMAZON_FLEX') {
        $rideshare = field_rideshare_tracker_booked_fields($packet, $start, $offline, $breaks);
        $form = [...$form, ...$rideshare['form']];
        $warnings = [...$warnings, ...$rideshare['warnings']];
    }
    $flex = null;
    if ($shift['platform'] === 'AMAZON_FLEX') {
        $flex = field_rideshare_flex_tracker_fields($packet, $times, $format);
        $form = [...$form, ...$flex['form']];
        $warnings[0] = 'Times use Fort Wayne local time. Work time starts at departure, not the scheduled block start. Package counts and costs are not recorded by the tracker.';
        $warnings = [...$warnings, ...$flex['warnings']];
    }
    return ['form' => $form, 'tracker_shift_id' => strtolower($id), 'sha256' => hash('sha256', $raw),
        'warnings' => $warnings, 'raw' => $raw, 'flex' => $flex, 'rideshare' => $rideshare];
}

/**
 * Estimate booked Lyft/Uber time and distance from per-ride tracker evidence.
 * Pickup time begins when tracking for that accepted ride starts and ends at
 * drop-off or cancellation. GPS distance includes pickup and passenger phases,
 * but excludes available/repositioning phases and recorded breaks.
 */
function field_rideshare_tracker_booked_fields(array $packet, int $shiftStart, int $offline, array $breaks): array
{
    $rides = $packet['rides'] ?? null;
    if (!is_array($rides)) {
        return ['form' => [], 'warnings' => ['This tracker export has no per-ride detail; enter booked figures manually.']];
    }

    $events = $packet['events'] ?? [];
    if (!is_array($events)) {
        return ['form' => [], 'warnings' => ['Tracker ride events are unavailable; enter booked figures manually.']];
    }

    $endEvents = [];
    foreach ($events as $event) {
        if (!is_array($event) || !is_string($event['rideId'] ?? null)
            || !is_numeric($event['occurredAtEpochMs'] ?? null)) {
            continue;
        }
        $type = $event['type'] ?? '';
        if (in_array($type, ['PASSENGER_DROPPED_OFF', 'RIDE_CANCELLED'], true)) {
            $endEvents[strtolower($event['rideId'])][$type][] = (int)$event['occurredAtEpochMs'];
        }
    }

    $intervals = [];
    $unresolved = false;
    $seen = [];
    foreach ($rides as $ride) {
        if (!is_array($ride)) {
            throw new InvalidArgumentException('Invalid tracker ride record.');
        }
        $rideId = $ride['id'] ?? null;
        if (!is_string($rideId) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $rideId)
            || isset($seen[strtolower($rideId)])) {
            throw new InvalidArgumentException('Invalid or duplicate tracker ride ID.');
        }
        $seen[strtolower($rideId)] = true;
        $state = strtoupper((string)($ride['state'] ?? ''));
        if (!in_array($state, ['COMPLETED', 'CANCELLED'], true)) {
            if (in_array($state, ['EN_ROUTE_PICKUP', 'PASSENGER'], true)) $unresolved = true;
            continue;
        }

        $rideStart = $ride['trackingStartedAtEpochMs'] ?? null;
        if (!is_numeric($rideStart)) {
            $unresolved = true;
            continue;
        }
        $rideStart = (int)$rideStart;
        if ($state === 'COMPLETED') {
            $rideEnd = $ride['dropoffAtEpochMs'] ?? null;
            $matching = $endEvents[strtolower($rideId)]['PASSENGER_DROPPED_OFF'] ?? [];
            if (!is_numeric($rideEnd) || count($matching) !== 1 || (int)$rideEnd !== $matching[0]) {
                $unresolved = true;
                continue;
            }
            $rideEnd = (int)$rideEnd;
        } else {
            $matching = $endEvents[strtolower($rideId)]['RIDE_CANCELLED'] ?? [];
            if (count($matching) !== 1) {
                $unresolved = true;
                continue;
            }
            $rideEnd = $matching[0];
        }

        if ($rideStart < $shiftStart || $rideEnd <= $rideStart || $rideEnd > $offline) {
            throw new InvalidArgumentException('Tracker ride times fall outside the online shift window.');
        }
        $intervals[] = ['id' => $rideId, 'start' => $rideStart, 'end' => $rideEnd];
    }

    $warnings = [];
    if ($unresolved) {
        $warnings[] = 'At least one accepted ride is missing a usable finish time. Booked totals are left blank to avoid understating the shift.';
        return ['form' => [], 'warnings' => $warnings];
    }
    if ($intervals === []) {
        if ($rides !== []) {
            $warnings[] = 'No completed or canceled booked ride intervals were recorded; verify the tracker ride list before saving.';
            return ['form' => [], 'warnings' => $warnings];
        }
        return ['form' => ['booked_minutes' => '0', 'booked_miles' => '0.00'], 'warnings' => []];
    }

    usort($intervals, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
    $merged = [];
    foreach ($intervals as $interval) {
        $last = count($merged) - 1;
        if ($last >= 0 && $interval['start'] <= $merged[$last]['end']) {
            $merged[$last]['end'] = max($merged[$last]['end'], $interval['end']);
        } else {
            $merged[] = ['start' => $interval['start'], 'end' => $interval['end']];
        }
    }

    $bookedMs = 0;
    foreach ($merged as $interval) $bookedMs += $interval['end'] - $interval['start'];
    foreach ($breaks as [$breakStart, $breakEnd]) {
        if ($breakEnd <= $breakStart) continue;
        foreach ($merged as $interval) {
            $bookedMs -= max(0, min($interval['end'], $breakEnd) - max($interval['start'], $breakStart));
        }
    }
    $onlineMinutes = max(0, (int)round(($offline - $shiftStart) / 60000)
        - array_sum(array_map(static fn($b) => (int)round(($b[1] - $b[0]) / 60000), $breaks)));
    $bookedMinutes = min($onlineMinutes, max(0, (int)round($bookedMs / 60000)));

    $locations = $packet['locations'] ?? [];
    if (!is_array($locations)) $locations = [];
    $pointsByRide = [];
    foreach ($locations as $point) {
        if (!is_array($point) || !is_string($point['rideId'] ?? null)
            || !in_array($point['phase'] ?? '', ['EN_ROUTE_PICKUP', 'PASSENGER'], true)
            || !is_numeric($point['occurredAtEpochMs'] ?? null)) continue;
        $rideId = $point['rideId'];
        if (!isset($seen[strtolower($rideId)])) continue;
        $pointsByRide[strtolower($rideId)][] = [
            'time' => (int)$point['occurredAtEpochMs'],
            'latitude' => $point['latitude'] ?? null,
            'longitude' => $point['longitude'] ?? null,
            'accuracy' => $point['accuracyMeters'] ?? null,
        ];
    }

    $bookedMiles = 0.0;
    $milesComplete = true;
    foreach ($intervals as $interval) {
        $ridePoints = $pointsByRide[strtolower($interval['id'])] ?? [];
        $segments = [[$interval['start'], $interval['end']]];
        foreach ($breaks as [$breakStart, $breakEnd]) {
            $next = [];
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                if ($breakEnd <= $segmentStart || $breakStart >= $segmentEnd) {
                    $next[] = [$segmentStart, $segmentEnd];
                    continue;
                }
                if ($breakStart > $segmentStart) $next[] = [$segmentStart, min($breakStart, $segmentEnd)];
                if ($breakEnd < $segmentEnd) $next[] = [max($breakEnd, $segmentStart), $segmentEnd];
            }
            $segments = $next;
        }
        $rideMiles = 0.0;
        $rideLinks = 0;
        foreach ($segments as [$segmentStart, $segmentEnd]) {
            if ($segmentEnd <= $segmentStart) continue;
            $estimate = field_rideshare_tracker_gps_estimate($ridePoints, $segmentStart, $segmentEnd);
            $rideMiles += $estimate['miles'] ?? 0.0;
            $rideLinks += $estimate['links'];
        }
        if ($rideLinks === 0) $milesComplete = false;
        $bookedMiles += $rideMiles;
    }

    $form = ['booked_minutes' => (string)$bookedMinutes];
    if ($milesComplete) {
        $form['booked_miles'] = number_format($bookedMiles, 2, '.', '');
        $warnings[] = 'Booked miles are estimated from tracker GPS during pickup and passenger phases. Review the estimate before saving.';
    } else {
        $form['booked_miles'] = '';
        $warnings[] = 'Booked miles are left blank because at least one ride lacks enough valid pickup/passenger GPS points.';
    }
    $warnings[] = 'Booked minutes cover accepted pickup through drop-off or cancellation, with idle time excluded and recorded breaks removed.';
    return ['form' => $form, 'warnings' => $warnings];
}

/** Match the Android tracker GPS estimator and retain only its sampled interval. */
function field_rideshare_tracker_gps_estimate(array $points, int $from, int $to): array
{
    usort($points, static fn(array $a, array $b): int => $a['time'] <=> $b['time']);
    $unique = [];
    foreach ($points as $point) {
        if ($unique !== [] && $unique[count($unique) - 1]['time'] === $point['time']) continue;
        $unique[] = $point;
    }
    $miles = 0.0;
    $links = 0;
    $count = count($unique);
    for ($index = 0; $index + 1 < $count; $index++) {
        $a = $unique[$index];
        $b = $unique[$index + 1];
        $begin = max($from, $a['time']);
        $finish = min($to, $b['time']);
        $elapsed = $b['time'] - $a['time'];
        if ($finish <= $begin || $elapsed <= 0) continue;
        $valid = static function (array $point): bool {
            return is_numeric($point['latitude'] ?? null) && is_numeric($point['longitude'] ?? null)
                && is_numeric($point['accuracy'] ?? null) && is_finite((float)$point['latitude'])
                && is_finite((float)$point['longitude']) && is_finite((float)$point['accuracy'])
                && (float)$point['latitude'] >= -90 && (float)$point['latitude'] <= 90
                && (float)$point['longitude'] >= -180 && (float)$point['longitude'] <= 180
                && (float)$point['accuracy'] >= 0 && (float)$point['accuracy'] <= 50;
        };
        if (!$valid($a) || !$valid($b)) continue;
        $radians = M_PI / 180;
        $latA = (float)$a['latitude'] * $radians;
        $latB = (float)$b['latitude'] * $radians;
        $deltaLat = ((float)$b['latitude'] - (float)$a['latitude']) * $radians;
        $deltaLon = ((float)$b['longitude'] - (float)$a['longitude']) * $radians;
        $h = sin($deltaLat / 2) ** 2 + cos($latA) * cos($latB) * sin($deltaLon / 2) ** 2;
        $meters = 6371008.8 * 2 * atan2(sqrt(max(0.0, min(1.0, $h))), sqrt(max(0.0, 1.0 - $h)));
        if ($meters / ($elapsed / 1000) > 70) continue;
        $miles += ($meters / 1609.344) * (($finish - $begin) / $elapsed);
        $links++;
    }
    return ['miles' => $links > 0 ? $miles : null, 'links' => $links];
}

/** Validate Flex facts independently of user-editable earnings and schedule fields. */
function field_rideshare_flex_tracker_fields(array $packet, array $times, callable $format): array
{
    $block = $packet['flexBlock'] ?? null;
    if (!is_array($block) || !in_array($block['version'] ?? null, [1, 2], true)) {
        throw new InvalidArgumentException('The Flex export is missing supported block details.');
    }
    $epochs = [];
    foreach (['scheduledStartEpochMs', 'scheduledEndEpochMs', 'actualWorkFinishedAtEpochMs'] as $key) {
        $value = $block[$key] ?? null;
        if (!is_int($value) || $value < 946684800000 || $value > 4102444800000) {
            throw new InvalidArgumentException('Invalid Flex schedule or actual finish.');
        }
        $epochs[$key] = $value;
    }
    $duration = $epochs['scheduledEndEpochMs'] - $epochs['scheduledStartEpochMs'];
    if ($duration < 900000 || $duration > 86400000 || $duration % 900000 !== 0
        || $epochs['actualWorkFinishedAtEpochMs'] !== $times['wentOfflineAtEpochMs']) {
        throw new InvalidArgumentException('Flex block duration or actual finish does not agree with the outing.');
    }
    $money = static function ($value): int {
        if (!is_int($value) || $value < 0 || $value > 100000000) {
            throw new InvalidArgumentException('Invalid Flex pay amount.');
        }
        return $value;
    };
    $offered = $money($block['offeredPayCents'] ?? null);
    $confirmed = isset($block['confirmedPayCents']) ? $money($block['confirmedPayCents']) : null;
    $types = array_column($packet['events'] ?? [], 'type');
    if (in_array('FLEX_PACKAGE_RETURN_STARTED', $types, true) && !in_array('FLEX_PACKAGE_RETURN_COMPLETED', $types, true)) {
        throw new InvalidArgumentException('Required station returns must be completed before importing a Flex outing.');
    }
    $outcome = $block['outcome'] ?? ($block['version'] === 1
        ? (in_array('FLEX_RELEASED_NO_ROUTE', $types, true) ? 'RELEASED_NO_ROUTE' : 'COMPLETED') : '');
    $finishEvent = match ($outcome) {
        'COMPLETED' => in_array('FLEX_PACKAGE_RETURN_COMPLETED', $types, true) ? 'FLEX_PACKAGE_RETURN_COMPLETED' : 'FLEX_DELIVERIES_FINISHED',
        'RELEASED_NO_ROUTE' => 'FLEX_RELEASED_NO_ROUTE',
        'CANCELLED' => 'FLEX_BLOCK_CANCELLED',
        default => throw new InvalidArgumentException('Unsupported Flex outcome.'),
    };
    $matching = array_values(array_filter($packet['events'] ?? [], static fn($e) => ($e['type'] ?? '') === $finishEvent));
    if (count($matching) !== 1 || ($matching[0]['occurredAtEpochMs'] ?? null) !== $times['wentOfflineAtEpochMs']) {
        throw new InvalidArgumentException('The Flex finish event does not match its outcome and offline boundary.');
    }
    $effective = $confirmed ?? ($outcome === 'CANCELLED' ? 0 : $offered);
    if (isset($block['effectivePayCents']) && $money($block['effectivePayCents']) !== $effective) {
        throw new InvalidArgumentException('Flex effective pay does not agree with the offer, outcome, and confirmed payout.');
    }
    $station = $block['station'] ?? '';
    if (!is_string($station) || strlen($station) > 100 || preg_match('/[\x00-\x1f\x7f]/', $station)) {
        throw new InvalidArgumentException('Invalid Flex station.');
    }
    $warnings = [
        'The full recorded return is included in miles and time. Personal detours require a reviewed correction; importing does not automatically remove them.',
        $confirmed !== null ? 'Earnings are your confirmed Flex payout.' : ($outcome === 'CANCELLED'
            ? 'Canceled / pulled block: earnings default to $0. An appeal is not a confirmed payout.'
            : 'Earnings are the offered block pay, not a confirmed payout. Check Flex earnings before posting to Accounting.'),
    ];
    $summary = sprintf('Tracker export: Flex %s; scheduled %s to %s; offered $%.2f; %s.',
        str_replace('_', ' ', strtolower($outcome)), str_replace('T', ' ', $format($epochs['scheduledStartEpochMs'])),
        str_replace('T', ' ', $format($epochs['scheduledEndEpochMs'])), $offered / 100,
        $confirmed !== null ? sprintf('confirmed pay $%.2f', $confirmed / 100) : 'payout not confirmed in export');
    foreach ([$block['cancellationReason'] ?? null, $block['cancellationNote'] ?? null, $packet['shift']['closingNote'] ?? null] as $note) {
        if ($note === null) continue;
        if (!is_string($note) || strlen($note) > 10000 || strpos($note, "\0") !== false) {
            throw new InvalidArgumentException('Invalid tracker closeout or cancellation note.');
        }
        if (trim($note) !== '') $summary .= "\n" . trim($note);
    }
    return ['form' => [
        'block_hours' => $duration / 3600000,
        'flex_scheduled_start_at' => $format($epochs['scheduledStartEpochMs']),
        'flex_station' => trim($station),
        'base_ride_earnings' => number_format($effective / 100, 2, '.', ''),
    ], 'warnings' => $warnings, 'summary_note' => $summary, 'outcome' => $outcome, 'pay_confirmed' => $confirmed !== null,
        'offered_pay_cents' => $offered, 'scheduled_start' => $format($epochs['scheduledStartEpochMs']),
        'scheduled_finish' => $format($epochs['scheduledEndEpochMs'])];
}

/** Fill known facts without overwriting manually confirmed earnings on an existing shift. */
function field_rideshare_tracker_prefill(array $preview, array $current, bool $editing): array
{
    $imported = $preview['form'];
    $flex = $preview['flex'];
    if ($editing && $flex && !$flex['pay_confirmed']) unset($imported['base_ride_earnings']);
    if ($editing && is_array($preview['rideshare'] ?? null)) {
        foreach (['booked_minutes', 'booked_miles'] as $field) {
            $saved = trim((string)($current[$field] ?? ''));
            if ($saved !== '' && is_numeric($saved) && (float)$saved > 0) unset($imported[$field]);
        }
    }
    if (!$editing && $flex) $imported['payout_destination'] = 'PERSONAL_BANK';
    $filled = array_replace($current, $imported);
    if ($flex && !str_contains((string)($filled['notes'] ?? ''), $flex['summary_note'])) {
        $filled['notes'] = trim((string)($filled['notes'] ?? '') . "\n" . $flex['summary_note']);
    }
    return $filled;
}

/** Compare recorded facts with reviewed fields without treating date display formats as edits. */
function field_rideshare_tracker_changes(array $original, array $current): array
{
    $changes = [];
    foreach (['platform', 'odometer_start', 'odometer_end', 'odometer_scope', 'offline_odometer', 'started_at', 'ended_at', 'deadhead_minutes', 'booked_minutes', 'booked_miles'] as $field) {
        $a = trim((string)($original[$field] ?? ''));
        $b = trim((string)($current[$field] ?? ''));
        if (in_array($field, ['started_at', 'ended_at'], true)) {
            $a = str_replace('T', ' ', $a); $b = str_replace('T', ' ', $b);
            if (strlen($a) === 16) $a .= ':00';
            if (strlen($b) === 16) $b .= ':00';
        }
        if ($a === $b || (is_numeric($a) && is_numeric($b) && (float)$a === (float)$b)) continue;
        $changes[$field] = ['tracker' => $original[$field] ?? '', 'corrected' => $current[$field] ?? ''];
    }
    return $changes;
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

