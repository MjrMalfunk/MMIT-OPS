<?php
declare(strict_types=1);

/** Live FN tracker import. Including this file has no database side effects. */
const FIELD_TRACKER_MAX_BYTES = 4194304;

function field_tracker_json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function field_tracker_rows(PDO $pdo, string $sql, array $args = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($args);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function field_tracker_live(PDO $pdo): void
{
    if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'mjrmstlj_mittops') {
        throw new RuntimeException('Tracker import is available only in the Live OPS database.');
    }
}

function field_tracker_ready(PDO $pdo): void
{
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    field_tracker_live($pdo);
    $tables = ['field_work_orders', 'field_work_order_time_entries', 'field_work_order_tracking_corrections',
        'field_tracker_imports', 'field_tracker_import_events'];
    $rows = field_tracker_rows($pdo, 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?,?,?,?,?)', $tables);
    if (count($rows) !== count($tables)) throw new RuntimeException('Run the staging tracker migration before opening this page.');
    foreach ($rows as $row) {
        if (strtoupper((string)$row['ENGINE']) !== 'INNODB') {
            throw new RuntimeException('Tracker requires InnoDB tables; nothing was changed.');
        }
    }
    foreach (['field_tracker_imports' => ['outing_id', 'work_order_id'], 'field_tracker_import_events' => ['event_id']] as $table => $columns) {
        $unique = [];
        foreach (field_tracker_rows($pdo, "SHOW INDEX FROM `{$table}`") as $index) {
            if ((int)$index['Non_unique'] === 0) $unique[$index['Key_name']][] = $index['Column_name'];
        }
        foreach ($columns as $column) {
            if (!in_array([$column], $unique, true)) throw new RuntimeException('Required tracker uniqueness constraints are missing.');
        }
    }
}

function field_tracker_uuid($value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
        throw new InvalidArgumentException('Invalid outing or event UUID.');
    }
    return strtolower($value);
}

function field_tracker_epoch($value): int
{
    if (!is_int($value) || $value < 1577836800000 || $value > 4102444800000) {
        throw new InvalidArgumentException('Invalid epoch-millisecond timestamp (expected 2020–2100).');
    }
    return $value;
}

function field_tracker_number($value, string $label, float $min, float $max): float
{
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value < $min || $value > $max) {
        throw new InvalidArgumentException("Invalid {$label}.");
    }
    return (float)$value;
}

/** Optional free-form closeout note, retained in the immutable app export. */
function field_tracker_closing_note($value): ?string
{
    if ($value === null) return null;
    if (!is_string($value)) throw new InvalidArgumentException('Closing note must be text.');
    $value = trim($value);
    if ($value === '') return null;
    if (strlen($value) > 10000 || strpos($value, "\0") !== false) {
        throw new InvalidArgumentException('Closing note is too long or contains invalid text.');
    }
    return $value;
}

function field_tracker_datetime(int $epoch, DateTimeZone $zone): string
{
    return (new DateTimeImmutable('@' . intdiv($epoch, 1000)))->setTimezone($zone)->format('Y-m-d H:i:s');
}

/** Exact v0.2 lifecycle. Equal-time events retain their exported order. */
function field_tracker_parse(string $raw, string $timezone): array
{
    if ($raw === '' || strlen($raw) > FIELD_TRACKER_MAX_BYTES) throw new InvalidArgumentException('Choose one JSON export no larger than 4 MiB.');
    $root = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($root) || ($root['schema'] ?? null) !== 'mmit.work-tracker.v2' || !is_array($root['shift'] ?? null)) {
        throw new InvalidArgumentException('Expected an MMIT Work Tracker v0.2 export.');
    }
    $zone = new DateTimeZone($timezone);
    $shift = $root['shift'];
    if (($shift['platform'] ?? null) !== 'FIELD_NATION') throw new InvalidArgumentException('This pilot imports FieldNation outings only.');
    if (!is_bool($shift['roundTripExpected'] ?? null) || ($root['rides'] ?? null) !== []) {
        throw new InvalidArgumentException('Invalid FN round-trip flag or unexpected passenger rides.');
    }
    $id = field_tracker_uuid($shift['id'] ?? null);
    $number = $shift['workOrderNumber'] ?? null;
    if (!is_string($number) || trim($number) === '' || strlen($number) > 120 || preg_match('/[\x00-\x1f\x7f]/', $number)) {
        throw new InvalidArgumentException('Invalid work-order number.');
    }
    $number = trim($number);
    $round = $shift['roundTripExpected'];
    $closingNote = field_tracker_closing_note($shift['closingNote'] ?? null);
    $start = field_tracker_epoch($shift['startedAtEpochMs'] ?? null);
    $end = field_tracker_epoch($shift['completedAtEpochMs'] ?? null);
    $exported = field_tracker_epoch($root['exportedAtEpochMs'] ?? null);
    if ($end < $start || $end - $start > 172800000 || $exported < $end) {
        throw new InvalidArgumentException('Outing must be completed, chronologically valid, and no longer than 48 hours.');
    }
    $odoStart = field_tracker_number($shift['startOdometer'] ?? null, 'starting odometer', 0, 9999999);
    $odoEnd = field_tracker_number($shift['endOdometer'] ?? null, 'ending odometer', $odoStart, 9999999);
    if ($odoEnd - $odoStart > 3000) throw new InvalidArgumentException('Outing exceeds the 3,000-mile pilot limit.');
    $sequence = ['SHIFT_STARTED', 'FN_TRIP_STARTED', 'FN_ARRIVED_SITE', 'FN_CHECKED_IN', 'FN_WORK_COMPLETED', 'FN_CHECKED_OUT'];
    $sequence = array_merge($sequence, $round ? ['FN_RETURN_STARTED', 'FN_RETURN_COMPLETED'] : ['OUTING_COMPLETED']);
    $events = $root['events'] ?? null;
    if (!is_array($events) || array_keys($events) !== range(0, count($sequence) - 1)) {
        throw new InvalidArgumentException('Incomplete or unsupported FN event sequence. Export a completed outing.');
    }
    $ids = []; $times = []; $previous = $start;
    foreach ($sequence as $i => $type) {
        $event = $events[$i];
        if (!is_array($event) || ($event['type'] ?? null) !== $type || !empty($event['rideId'])) {
            throw new InvalidArgumentException("Expected {$type} at event " . ($i + 1) . '.');
        }
        $eventId = field_tracker_uuid($event['id'] ?? null);
        if (isset($ids[$eventId])) throw new InvalidArgumentException('Duplicate event UUID in this export.');
        $ids[$eventId] = true;
        $time = field_tracker_epoch($event['occurredAtEpochMs'] ?? null);
        if ($time < $previous || $time < $start || $time > $end) throw new InvalidArgumentException('Events are out of order or outside the outing. Do not sort or edit the export.');
        $times[$type] = $time; $previous = $time;
    }
    $payload = $events[0]['payload'] ?? null;
    if (!is_array($payload) || ($payload['workOrderNumber'] ?? null) !== $number || ($payload['roundTripExpected'] ?? null) !== $round
        || $times['SHIFT_STARTED'] !== $start || $times['FN_TRIP_STARTED'] !== $start || $previous !== $end
        || field_tracker_epoch($shift['wentOfflineAtEpochMs'] ?? null) !== $times['FN_CHECKED_OUT']
        || field_tracker_epoch($shift['homeArrivedAtEpochMs'] ?? null) !== $end
        || ($round && $times['FN_RETURN_STARTED'] !== $times['FN_CHECKED_OUT'])) {
        throw new InvalidArgumentException('Shift metadata does not agree with the event timeline.');
    }
    $warnings = [];
    if ($end - $start < 300000) $warnings[] = 'Outing is under five minutes; this may be a stationary test.';
    if ($odoEnd === $odoStart) $warnings[] = 'Odometer mileage is zero. Confirm this was intentional.';
    if ($end > (int)(microtime(true) * 1000) + 300000) $warnings[] = 'Outing is in the future. Check the phone clock.';
    if (!$round) $warnings[] = 'One-way outing: post-checkout time is excluded. Confirm the ending odometer belongs to this job, not travel to the next job.';
    $segments = [];
    $segment = static function (string $label, string $type, int $a, int $b) use (&$segments, $zone): void {
        $segments[] = ['label' => $label, 'entry_type' => $type, 'started_at' => field_tracker_datetime($a, $zone),
            'ended_at' => field_tracker_datetime($b, $zone), 'duration_ms' => $b - $a, 'minutes' => (int)ceil(($b - $a) / 60000)];
    };
    $segment('Outbound drive', 'drive', $start, $times['FN_ARRIVED_SITE']);
    $segment('Arrival / waiting before check-in', 'admin', $times['FN_ARRIVED_SITE'], $times['FN_CHECKED_IN']);
    $segment('Onsite (check-in through checkout)', 'onsite', $times['FN_CHECKED_IN'], $times['FN_CHECKED_OUT']);
    if ($round) $segment('Return drive', 'drive', $times['FN_RETURN_STARTED'], $end);
    $totals = ['drive' => 0, 'onsite' => 0, 'admin' => 0];
    foreach ($segments as $item) $totals[$item['entry_type']] += $item['minutes'];
    $locations = $root['locations'] ?? null;
    if (!is_array($locations) || count($locations) > 25000 || ($locations !== [] && array_keys($locations) !== range(0, count($locations) - 1))) {
        throw new InvalidArgumentException('Invalid GPS array (maximum 25,000 points).');
    }
    // Comparison only: never bridge phase changes, >60s gaps, poor fixes, or >100mph jumps.
    $gpsMiles = 0.0; $links = 0; $skipped = 0; $last = null; $lastTime = $start;
    foreach ($locations as $point) {
        if (!is_array($point)) throw new InvalidArgumentException('Invalid GPS point.');
        $t = field_tracker_epoch($point['occurredAtEpochMs'] ?? null);
        $lat = field_tracker_number($point['latitude'] ?? null, 'GPS latitude', -90, 90);
        $lon = field_tracker_number($point['longitude'] ?? null, 'GPS longitude', -180, 180);
        $accuracy = field_tracker_number($point['accuracyMeters'] ?? null, 'GPS accuracy', 0, 100000);
        $phase = $point['phase'] ?? null;
        if (!in_array($phase, ['EN_ROUTE_SITE','ON_SITE','WORKING','WRAP_UP','RETURNING_HOME'], true) || $t < $lastTime || $t > $end) {
            throw new InvalidArgumentException('GPS times or phases are invalid.');
        }
        $lastTime = $t;
        $driving = ($phase === 'EN_ROUTE_SITE' && $t <= $times['FN_ARRIVED_SITE'])
            || ($round && $phase === 'RETURNING_HOME' && $t >= $times['FN_CHECKED_OUT']);
        if (!$driving || $accuracy > 50) { $last = null; $skipped++; continue; }
        if ($last !== null && $last['phase'] === $phase) {
            $seconds = ($t - $last['time']) / 1000;
            $a = sin(deg2rad($lat - $last['lat']) / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad($last['lat'])) * sin(deg2rad($lon - $last['lon']) / 2) ** 2;
            $miles = 3958.7613 * 2 * asin(sqrt(min(1.0, max(0.0, $a))));
            if ($seconds > 0 && $seconds <= 60 && $miles / $seconds * 3600 <= 100) { $gpsMiles += $miles; $links++; }
            else $skipped++;
        }
        $last = ['time' => $t, 'lat' => $lat, 'lon' => $lon, 'phase' => $phase];
    }
    $mileage = round($odoEnd - $odoStart, 2);
    if ($links === 0) $warnings[] = 'Not enough usable driving GPS points to compare mileage.';
    if ($skipped > 0) $warnings[] = 'GPS comparison excludes stationary phases, poor fixes, gaps, or jumps; it is not a complete route measurement.';
    if ($links > 0 && abs($gpsMiles - $mileage) > max(2, $mileage * 0.2)) $warnings[] = 'GPS segments and odometer miles differ by more than 20% (or two miles). Review the readings and route.';
    return ['id' => $id, 'number' => $number, 'round_trip' => $round, 'timezone' => $timezone,
        'start_odometer' => $odoStart, 'end_odometer' => $odoEnd, 'mileage' => $mileage,
        'closing_note' => $closingNote,
        'events' => $events, 'event_ids' => array_keys($ids), 'times' => $times, 'segments' => $segments,
        'totals' => $totals, 'warnings' => $warnings, 'gps_miles' => $links > 0 ? round($gpsMiles, 2) : null, 'gps_points' => count($locations),
        'work_minutes_exact' => ($times['FN_WORK_COMPLETED'] - $times['FN_CHECKED_IN']) / 60000,
        'wrap_minutes_exact' => ($times['FN_CHECKED_OUT'] - $times['FN_WORK_COMPLETED']) / 60000,
        'post_checkout_minutes' => !$round ? ($end - $times['FN_CHECKED_OUT']) / 60000 : 0,
        'changes' => ['checked_in_at' => field_tracker_datetime($times['FN_CHECKED_IN'], $zone),
            'checked_out_at' => field_tracker_datetime($times['FN_CHECKED_OUT'], $zone),
            'actual_left_site_at' => field_tracker_datetime($times['FN_CHECKED_OUT'], $zone),
            'mileage' => $mileage, 'drive_minutes' => $totals['drive'], 'onsite_minutes' => $totals['onsite']]];
}

/** Read-only preview; no upload or work-order rows are created here. */
function field_tracker_preview(PDO $pdo, array $parsed, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $matches = field_tracker_rows($pdo, "SELECT * FROM field_work_orders WHERE platform = 'FieldNation'
        AND BINARY external_work_order_number = ? AND deleted_at IS NULL" . $suffix, [$parsed['number']]);
    $errors = [];
    if (count($matches) !== 1) $errors[] = count($matches) === 0
        ? 'No active FieldNation work order matches this exact number. No job will be created or guessed.'
        : 'More than one active FieldNation work order has this number. Resolve the duplicate in OPS first.';
    $wo = count($matches) === 1 ? $matches[0] : null;
    $entries = []; $changes = $parsed['changes']; $warnings = $parsed['warnings'];
    if ($wo !== null) {
        $entries = field_tracker_rows($pdo, 'SELECT * FROM field_work_order_time_entries WHERE work_order_id = ?
            AND deleted_at IS NULL ORDER BY time_entry_id' . $suffix, [$wo['work_order_id']]);
        if ($entries !== []) $errors[] = 'This work order already has detailed time entries. Automatic merging is disabled in this pilot; existing entries are untouched.';
        if (in_array(strtoupper((string)$wo['status']), ['CANCELLED', 'DECLINED'], true)) $errors[] = 'Cancelled or declined work orders cannot receive tracker data.';
        $changes['admin_minutes'] = (int)$wo['admin_minutes'] + $parsed['totals']['admin'];
        if ((int)$wo['admin_minutes'] > 0) $warnings[] = 'Existing admin time is carried forward. Verify it does not already include the arrival waiting time shown here.';
        if (!empty($wo['accounting_journal_id'])) $warnings[] = 'This job is posted. Tracking changes require your reason and are audited; its journal remains unchanged.';
        if ((float)$wo['mileage'] > 0 || (int)$wo['drive_minutes'] > 0 || (int)$wo['onsite_minutes'] > 0
            || !empty($wo['checked_in_at']) || !empty($wo['checked_out_at']) || !empty($wo['actual_left_site_at'])) {
            $warnings[] = 'Existing mileage, drive/onsite summaries, or timestamps will be replaced by the values shown. Existing admin minutes are carried forward.';
        }
        if (!empty($wo['scheduled_start_at']) && substr($wo['scheduled_start_at'], 0, 10) !== substr($changes['checked_in_at'], 0, 10)) {
            $warnings[] = 'The tracked check-in date differs from the scheduled job date.';
        }
    }
    if (field_tracker_rows($pdo, 'SELECT import_id FROM field_tracker_imports WHERE outing_id = ? OR work_order_id = ?', [$parsed['id'], $wo['work_order_id'] ?? 0]) !== []) {
        $errors[] = 'This outing or work order already has an applied tracker import. Re-exporting will not add time or mileage.';
    }
    $ids = $parsed['event_ids'];
    if (field_tracker_rows($pdo, 'SELECT event_id FROM field_tracker_import_events WHERE event_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) !== []) {
        $errors[] = 'One or more event IDs already belong to an applied import.';
    }
    return ['work_order' => $wo, 'entries' => $entries, 'changes' => $changes, 'errors' => $errors,
        'warnings' => $warnings, 'fingerprint' => hash('sha256', field_tracker_json([$wo, $entries]))];
}

/** Ticket must come from the authenticated server session, never the browser body. */
function field_tracker_apply(PDO $pdo, array $ticket, int $userId, string $reason): int
{
    field_tracker_ready($pdo);
    if ($userId <= 0 || ($ticket['user_id'] ?? null) !== $userId || ($ticket['expires'] ?? 0) < time()) {
        throw new InvalidArgumentException('Preview expired or belongs to another session. Upload again.');
    }
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 1000 || strpos($reason, "\0") !== false) throw new InvalidArgumentException('Enter a review / correction reason (maximum 1,000 bytes).');
    $parsed = field_tracker_parse($ticket['raw'], $ticket['timezone']);
    // No DDL in this path. Savepoints allow an outer staging-test rollback.
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction(); else $pdo->exec('SAVEPOINT field_tracker_apply');
    try {
        $preview = field_tracker_preview($pdo, $parsed, true);
        if ($preview['errors'] !== []) throw new InvalidArgumentException(implode(' ', $preview['errors']));
        if (!hash_equals($ticket['fingerprint'], $preview['fingerprint'])) throw new InvalidArgumentException('Work order changed since preview. Upload again to review current values.');
        $wo = $preview['work_order']; $changes = $preview['changes']; $old = array_intersect_key($wo, $changes);
        $pdo->prepare('INSERT INTO field_tracker_imports
            (outing_id, work_order_id, content_sha256, raw_json, timezone_name, old_values, new_values, review_reason, applied_by, applied_at)
            VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([$parsed['id'], $wo['work_order_id'], hash('sha256', $ticket['raw']),
                $ticket['raw'], $ticket['timezone'], field_tracker_json($old), field_tracker_json($changes), $reason, $userId, date('Y-m-d H:i:s')]);
        $importId = (int)$pdo->lastInsertId();
        $eventInsert = $pdo->prepare('INSERT INTO field_tracker_import_events (event_id, import_id) VALUES (?,?)');
        foreach ($parsed['event_ids'] as $id) $eventInsert->execute([$id, $importId]);
        $pdo->prepare('UPDATE field_work_orders SET checked_in_at = ?, checked_out_at = ?, actual_left_site_at = ?,
            mileage = ?, drive_minutes = ?, onsite_minutes = ?, admin_minutes = ?, updated_at = NOW()
            WHERE work_order_id = ? AND deleted_at IS NULL')->execute([$changes['checked_in_at'], $changes['checked_out_at'],
                $changes['actual_left_site_at'], $changes['mileage'], $changes['drive_minutes'], $changes['onsite_minutes'],
                $changes['admin_minutes'], $wo['work_order_id']]);
        $insert = $pdo->prepare('INSERT INTO field_work_order_time_entries
            (work_order_id, entry_type, started_at, ended_at, minutes, notes, updated_by) VALUES (?,?,?,?,?,?,?)');
        foreach ($parsed['segments'] as $segment) {
            if ($segment['minutes'] === 0) continue;
            $insert->execute([$wo['work_order_id'], $segment['entry_type'], $segment['started_at'], $segment['ended_at'],
                $segment['minutes'], 'Tracker #' . $importId . ': ' . $segment['label'], $userId]);
        }
        if ((int)$wo['admin_minutes'] > 0) $insert->execute([$wo['work_order_id'], 'admin', null, null, (int)$wo['admin_minutes'],
            'Tracker #' . $importId . ': existing OPS admin summary carried forward (not measured by app)', $userId]);
        $pdo->prepare('INSERT INTO field_work_order_tracking_corrections
            (work_order_id, old_values, new_values, reason, corrected_by) VALUES (?,?,?,?,?)')->execute([
                $wo['work_order_id'], field_tracker_json($old), field_tracker_json($changes), 'Tracker #' . $importId . ': ' . $reason, $userId]);
        if ($ownsTransaction) $pdo->commit(); else $pdo->exec('RELEASE SAVEPOINT field_tracker_apply');
        return $importId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            if ($ownsTransaction) $pdo->rollBack();
            else { $pdo->exec('ROLLBACK TO SAVEPOINT field_tracker_apply'); $pdo->exec('RELEASE SAVEPOINT field_tracker_apply'); }
        }
        throw $e;
    }
}
