<?php
declare(strict_types=1);
require dirname(__DIR__) . '/inc/field_rideshare_mileage.php';
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $count++;
};
$near = static fn(float $a, float $b): bool => abs($a - $b) < 0.00001;
$bad = static function (callable $action) use ($check): void {
    try { $action(); } catch (InvalidArgumentException $e) { $check(true, 'Rejected invalid input'); return; }
    throw new RuntimeException('Invalid input was accepted.');
};
$input = ['odometer_start' => 85556, 'odometer_end' => 85640,
    'vehicle_cpm_snapshot' => .236, 'base_ride_earnings' => 75.75, 'tips' => 4.59,
    'online_minutes' => 209, 'deadhead_minutes' => 20, 'platform_fees' => 28];
$legacy = field_rideshare_calculate($input + ['deadhead_miles' => 5.3]);
$check($near($legacy['total_business_miles'], 89.3), 'Legacy scope remains additive');
$check($legacy['odometer_scope'] === 'WORK_INTERVAL', 'Legacy default scope');
$check($legacy['mileage_split_source'] === 'LEGACY', 'Legacy provenance');
$full = $input + ['odometer_scope' => 'ENTIRE_OUTING', 'offline_odometer' => 85634.7, 'deadhead_miles' => 50];
$exact = field_rideshare_calculate($full);
foreach (['business_miles' => 78.7, 'deadhead_miles' => 5.3, 'total_business_miles' => 84,
    'recognized_revenue' => 80.34, 'vehicle_cost' => 19.82, 'true_operating_profit' => 60.52,
    'total_work_minutes' => 229] as $key => $expected) {
    $check($near((float)$exact[$key], $expected), 'Exact ' . $key);
}
$check($exact['mileage_split_source'] === 'ODOMETER', 'Boundary odometer takes precedence over stale entered miles');
$check($exact['mileage_split_known'], 'Exact split known');
$unknown = field_rideshare_calculate($input + ['odometer_scope' => 'ENTIRE_OUTING']);
$check($near($unknown['total_business_miles'], 84), 'Unknown split preserves exact total');
$check(!$unknown['mileage_split_known'], 'Absent boundary is unknown, not zero');
$check($unknown['mileage_split_source'] === 'UNALLOCATED', 'Unknown split provenance');
$check($unknown['gross_per_business_mile'] === null, 'Unknown split does not produce online mileage rate');
$check($unknown['online_vehicle_cost'] === null && $unknown['deadhead_vehicle_cost'] === null, 'Unknown segment costs remain unknown');
$estimate = field_rideshare_calculate($input + ['odometer_scope' => 'ENTIRE_OUTING', 'deadhead_miles' => 5.29, 'mileage_split_source' => 'GPS_ESTIMATE']);
$check($near($estimate['total_business_miles'], 84) && $near($estimate['vehicle_cost'], 19.82), 'GPS estimate does not add to actual odometer total');
$check($estimate['mileage_split_source'] === 'GPS_ESTIMATE', 'GPS is identified as an estimate');
$zero = field_rideshare_mileage(['odometer_start' => 1, 'odometer_end' => 1, 'odometer_scope' => 'ENTIRE_OUTING', 'offline_odometer' => 1]);
$check($zero['mileage_split_known'] && $zero['total_business_miles'] === 0.0, 'A recorded stationary outing is zero');
foreach ([['odometer_start' => ''], ['odometer_end' => null], ['odometer_end' => 85550],
    ['odometer_start' => 'nonsense'], ['odometer_start' => -2], ['odometer_scope' => 'bad'],
    ['odometer_scope' => 'ENTIRE_OUTING', 'offline_odometer' => 85555],
    ['odometer_scope' => 'ENTIRE_OUTING', 'offline_odometer' => 85641],
    ['odometer_scope' => 'ENTIRE_OUTING', 'deadhead_miles' => 85],
    ['odometer_scope' => 'ENTIRE_OUTING', 'deadhead_miles' => -1],
    ['odometer_scope' => 'ENTIRE_OUTING', 'deadhead_miles' => 'nonsense']] as $override) {
    $bad(static fn() => field_rideshare_mileage(array_replace($input, $override)));
}
$packet = [
    'schema' => 'mmit.work-tracker.v2',
    'shift' => ['id' => 'f25da247-cef0-48ec-b2fb-6d92b05930ae', 'platform' => 'LYFT',
        'startedAtEpochMs' => 1790795591296, 'wentOfflineAtEpochMs' => 1790808106130,
        'homeArrivedAtEpochMs' => 1790809328322, 'completedAtEpochMs' => 1790809328322,
        'startOdometer' => 85556, 'endOdometer' => 85640],
    'events' => [],
];
$raw = json_encode($packet, JSON_THROW_ON_ERROR);
$preview = field_rideshare_tracker_preview($raw);
$check($preview['raw'] === $raw && hash_equals(hash('sha256', $raw), $preview['sha256']), 'Raw packet and digest are retained exactly');
$check($preview['form']['odometer_scope'] === 'ENTIRE_OUTING', 'Old tracker packets also cover the full outing');
$check($preview['form']['started_at'] === '2026-09-30T15:13:11', 'Eastern start includes seconds');
$check($preview['form']['ended_at'] === '2026-09-30T18:41:46', 'Went-offline boundary includes seconds');
$check($preview['form']['deadhead_minutes'] === 20, 'Return interval follows arrival tap');
$check($preview['form']['deadhead_miles'] === '', 'Old packet does not fabricate offline miles');
$check(!isset($preview['form']['base_ride_earnings']) && !isset($preview['form']['booked_miles']), 'Platform earnings and booked mileage are not guessed');
$packet['shift']['offlineOdometer'] = 85634.7;
$packet['shift']['correctedHomeArrivedAtEpochMs'] = 1790809028322;
$packet['mileageSummary'] = ['offlineGps' => ['source' => 'GPS_ESTIMATE', 'miles' => 5.29, 'gapsOver60Seconds' => 1, 'rejectedLinks' => 0]];
$preview = field_rideshare_tracker_preview(json_encode($packet, JSON_THROW_ON_ERROR));
$check($near((float)$preview['form']['offline_odometer'], 85634.7), 'New boundary reading is imported');
$check($preview['form']['deadhead_minutes'] === 15, 'Driver correction adjusts return time');
$check($packet['shift']['homeArrivedAtEpochMs'] === 1790809328322, 'Original arrival remains intact');
$check(str_contains(implode(' ', $preview['warnings']), 'GPS estimate'), 'GPS diagnostics are visible');
$packet['events'] = [['type' => 'BREAK_ENDED', 'occurredAtEpochMs' => 1790795791296],
    ['type' => 'BREAK_STARTED', 'occurredAtEpochMs' => 1790795691296]];
$preview = field_rideshare_tracker_preview(json_encode($packet, JSON_THROW_ON_ERROR));
$check(count($preview['form']['break_started_at']) === 1, 'Unordered break events normalize once');
$check(str_contains(implode(' ', $preview['warnings']), 'personal miles'), 'Break travel receives a review note');
foreach ([['platform' => 'FIELD_NATION'], ['id' => 'bad'], ['endOdometer' => null],
    ['homeArrivedAtEpochMs' => null], ['correctedHomeArrivedAtEpochMs' => 1790807000000],
    ['offlineOdometer' => 85641]] as $override) {
    $changed = $packet;
    $changed['shift'] = array_replace($packet['shift'], $override);
    $bad(static fn() => field_rideshare_tracker_preview(json_encode($changed, JSON_THROW_ON_ERROR)));
}
$bad(static fn() => field_rideshare_tracker_preview('{'));
$bad(static fn() => field_rideshare_tracker_preview(str_repeat('x', 4 * 1024 * 1024 + 1)));
$changed = $packet; $changed['events'] = ['bad'];
$bad(static fn() => field_rideshare_tracker_preview(json_encode($changed, JSON_THROW_ON_ERROR)));
// Synthetic Flex outing with the real export's boundary durations; no rider or GPS data.
$flex = [
    'schema' => 'mmit.work-tracker.v2',
    'shift' => ['id' => '11111111-1111-4111-8111-111111111111', 'platform' => 'AMAZON_FLEX',
        'startedAtEpochMs' => 1791912032698, 'wentOfflineAtEpochMs' => 1791925760877,
        'homeArrivedAtEpochMs' => 1791933352821, 'completedAtEpochMs' => 1791933352821,
        'startOdometer' => 10000, 'offlineOdometer' => 10095, 'endOdometer' => 10187],
    'rides' => [], 'events' => [['type' => 'FLEX_DELIVERIES_FINISHED', 'occurredAtEpochMs' => 1791925760877]],
    'flexBlock' => ['version' => 2, 'station' => 'FWA6', 'scheduledStartEpochMs' => 1791912600000,
        'scheduledEndEpochMs' => 1791927000000, 'actualWorkFinishedAtEpochMs' => 1791925760877,
        'offeredPayCents' => 9000, 'effectivePayCents' => 9000, 'confirmedPayCents' => null, 'outcome' => 'COMPLETED'],
];
$parseFlex = static fn($p) => field_rideshare_tracker_preview(json_encode($p, JSON_THROW_ON_ERROR));
$preview = $parseFlex($flex); $f = $preview['form'];
$equivalent = array_replace($f, ['started_at' => '2026-10-13 13:20:32', 'odometer_start' => '10000.0']);
$check(field_rideshare_tracker_changes($f, $equivalent) === [], 'Stored SQL dates and numeric form formatting do not create false corrections');
$corrected = array_replace($f, ['deadhead_minutes' => '47', 'ended_at' => '2026-10-13T17:00:00']);
$changes = field_rideshare_tracker_changes($f, $corrected);
$check(count($changes) === 2 && $changes['deadhead_minutes']['tracker'] === 127, 'Return and work-time edits retain original values for audit');
$existing = ['base_ride_earnings' => '110.00', 'vehicle_id' => 17, 'tips' => '3.00', 'notes' => 'Already reviewed', 'payout_destination' => 'PNC'];
$merged = field_rideshare_tracker_prefill($preview, $existing, true);
$check($merged['base_ride_earnings'] === '110.00' && $merged['tips'] === '3.00', 'Existing manually confirmed earnings are not replaced by an offer');
$check($merged['vehicle_id'] === 17 && $merged['payout_destination'] === 'PNC', 'Import preserves vehicle and saved payout destination');
$check(str_starts_with($merged['notes'], 'Already reviewed') && str_contains($merged['notes'], 'payout not confirmed'), 'Original notes and pay provenance remain visible');
$check(field_rideshare_tracker_prefill($preview, $merged, true)['notes'] === $merged['notes'], 'Re-preview does not duplicate imported notes');
$new = field_rideshare_tracker_prefill($preview, [], false);
$check($new['base_ride_earnings'] === '90.00' && $new['payout_destination'] === 'PERSONAL_BANK', 'New Flex form receives offered pay and editable bank default');
$confirmed = $flex; $confirmed['flexBlock']['confirmedPayCents'] = 11000; $confirmed['flexBlock']['effectivePayCents'] = 11000;
$check(field_rideshare_tracker_prefill($parseFlex($confirmed), $existing, true)['base_ride_earnings'] === '110.00', 'Confirmed export payout can prefill earnings');
$check($f['started_at'] === '2026-10-13T13:20:32', 'Departure is not replaced by scheduled block start');
$check($f['flex_scheduled_start_at'] === '2026-10-13T13:30:00', 'Scheduled block start stays distinct');
$check($f['ended_at'] === '2026-10-13T17:09:20' && $f['online_minutes'] === 229, 'Actual Flex finish and work time');
$check($f['deadhead_minutes'] === 127, 'Recorded return time includes all post-work time');
$check($f['block_hours'] == 4 && $f['flex_station'] === 'FWA6', 'Block hours and station prefill');
$check($f['base_ride_earnings'] === '90.00' && !$preview['flex']['pay_confirmed'], 'Offer is labelled as unconfirmed pay');
$check(!isset($f['packages_delivered'], $f['platform_fees'], $f['booked_minutes']), 'Unknown delivery counts and costs remain absent');
$calc = field_rideshare_mileage($f);
$check($calc['business_miles'] === 95.0 && $calc['deadhead_miles'] === 92.0 && $calc['total_business_miles'] === 187.0, 'Full actual mileage imports without hiding personal detours');
$manual = array_replace($f, ['odometer_scope' => 'WORK_INTERVAL', 'odometer_end' => 10095, 'deadhead_miles' => 47, 'deadhead_minutes' => 47]);
$calc = field_rideshare_calculate($manual);
$check($calc['total_business_miles'] === 142.0 && $calc['total_work_minutes'] === 276, 'Reviewed direct-home estimate gives 142 miles and 276 minutes');
$v1 = $flex; $v1['flexBlock']['version'] = 1; unset($v1['flexBlock']['outcome']);
$check($parseFlex($v1)['flex']['outcome'] === 'COMPLETED', 'Older Flex export remains supported');
$released = $flex; $released['events'][0]['type'] = 'FLEX_RELEASED_NO_ROUTE'; $released['flexBlock']['outcome'] = 'RELEASED_NO_ROUTE';
$check($parseFlex($released)['form']['base_ride_earnings'] === '90.00', 'No-route release keeps expected offered pay');
$cancelled = $flex; $cancelled['events'][0]['type'] = 'FLEX_BLOCK_CANCELLED';
$cancelled['flexBlock']['outcome'] = 'CANCELLED'; $cancelled['flexBlock']['effectivePayCents'] = 0;
$check($parseFlex($cancelled)['form']['base_ride_earnings'] === '0.00', 'Pulled shift remains unpaid');
$cancelled['flexBlock']['confirmedPayCents'] = 2500; $cancelled['flexBlock']['effectivePayCents'] = 2500;
$check($parseFlex($cancelled)['form']['base_ride_earnings'] === '25.00', 'Confirmed appeal payout imports');
$returned = $flex; $returned['events'] = [['type' => 'FLEX_PACKAGE_RETURN_STARTED', 'occurredAtEpochMs' => 1791925000000],
    ['type' => 'FLEX_PACKAGE_RETURN_COMPLETED', 'occurredAtEpochMs' => 1791925760877]];
$check($parseFlex($returned)['form']['ended_at'] === '2026-10-13T17:09:20', 'Required return completion controls actual finish');
foreach ([['offeredPayCents' => -1], ['confirmedPayCents' => '9000'], ['effectivePayCents' => 9001],
    ['actualWorkFinishedAtEpochMs' => 1791925760000], ['outcome' => 'PENDING'], ['scheduledEndEpochMs' => 1791912600000],
    ['station' => "bad\0station"], ['version' => 99]] as $override) {
    $changed = $flex; $changed['flexBlock'] = array_replace($flex['flexBlock'], $override);
    $bad(static fn() => $parseFlex($changed));
}
$returned['events'] = [$returned['events'][0]]; $bad(static fn() => $parseFlex($returned));
$changed = $flex; unset($changed['flexBlock']); $bad(static fn() => $parseFlex($changed));
$changed = $flex; $changed['events'] = []; $bad(static fn() => $parseFlex($changed));
$changed = $flex; unset($changed['flexBlock']['outcome']); $bad(static fn() => $parseFlex($changed));
echo "PASS: {$count} mileage, earnings, tracker, correction, and invalid-input checks; TZ=" . date_default_timezone_get() . PHP_EOL;
