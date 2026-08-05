<?php
declare(strict_types=1);

$targetHost = trim((string)getenv('FIELD_RIDESHARE_HOST'));
$expectedDatabase = trim(
    (string)getenv('FIELD_RIDESHARE_DATABASE')
);

if ($targetHost === '' || $expectedDatabase === '') {
    throw new RuntimeException(
        'FIELD_RIDESHARE_HOST and FIELD_RIDESHARE_DATABASE are required'
    );
}

$_SERVER['HTTP_HOST'] = $targetHost;

$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_rideshare.php';
ob_end_clean();

$failures = 0;

$assert = static function (
    bool $condition,
    string $message
) use (&$failures): void {
    if ($condition) {
        echo 'PASS: ', $message, PHP_EOL;
        return;
    }

    $failures++;
    echo 'FAIL: ', $message, PHP_EOL;
};

$actualDatabase = (string)db()
    ->query('SELECT DATABASE()')
    ->fetchColumn();

if (!hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException(
        "Database guard failed: expected {$expectedDatabase}, connected {$actualDatabase}"
    );
}

echo 'Database: ', $actualDatabase, PHP_EOL;

field_vehicles_ensure_schema();
field_rideshare_ensure_schema();

$assert(
    db_table_exists('field_rideshare_shifts'),
    'field_rideshare_shifts exists'
);

$assert(
    db_column_exists(
        'field_rideshare_shifts',
        'vehicle_cpm_snapshot'
    ),
    'immutable vehicle CPM snapshot column exists'
);

$assert(
    db_column_exists(
        'field_rideshare_shifts',
        'true_operating_profit'
    ),
    'true operating profit column exists'
);

$assert(
    db_column_exists(
        'field_rideshare_shifts',
        'deadhead_miles'
    ),
    'off-app deadhead mileage column exists'
);

$assert(
    db_column_exists(
        'field_rideshare_shifts',
        'deadhead_minutes'
    ),
    'off-app deadhead time column exists'
);

$result = field_rideshare_calculate([
    'odometer_start' => 10000.0,
    'odometer_end' => 10100.0,
    'vehicle_cpm_snapshot' => 0.5000,

    'online_minutes' => 300,

    'base_ride_earnings' => 100.00,
    'tips' => 20.00,
    'bonuses' => 15.00,
    'adjustments' => -5.00,
    'toll_reimbursements' => 10.00,

    // Informational only; must not be subtracted twice.
    'platform_fees' => 25.00,

    'direct_trip_costs' => 5.00,
]);

$assert(
    abs((float)$result['business_miles'] - 100.00) < 0.001,
    'business miles derive from odometer difference'
);

$assert(
    abs((float)$result['recognized_revenue'] - 140.00) < 0.001,
    'revenue totals driver earnings, tips, bonuses, adjustments, and tolls'
);

$assert(
    abs((float)$result['vehicle_cost'] - 50.00) < 0.001,
    'vehicle cost uses snapshotted all-in CPM'
);

$assert(
    abs((float)$result['true_operating_profit'] - 85.00) < 0.001,
    'profit subtracts vehicle and direct costs without double-counting fees'
);

$assert(
    abs((float)$result['gross_per_online_hour'] - 28.00) < 0.001,
    'gross hourly rate uses online time'
);

$assert(
    abs((float)$result['profit_per_online_hour'] - 17.00) < 0.001,
    'true-profit hourly rate uses online time'
);

$outing = field_rideshare_calculate([
    'odometer_start' => 80060.1,
    'odometer_end' => 80222.4,
    'deadhead_miles' => 113.2,
    'online_minutes' => 300,
    'deadhead_minutes' => 120,
    'vehicle_cpm_snapshot' => 0.2200,
    'base_ride_earnings' => 100.00,
]);

$assert(
    abs((float)$outing['business_miles'] - 162.30) < 0.001,
    'odometer mileage preserves one-tenth-mile precision'
);

$assert(
    abs((float)$outing['total_business_miles'] - 275.50) < 0.001,
    'total outing mileage includes off-app deadhead miles'
);

$assert(
    abs((float)$outing['vehicle_cost'] - 60.61) < 0.001,
    'vehicle cost includes online and off-app business mileage'
);

$assert(
    abs((float)$outing['profit_per_total_hour'] - 5.63) < 0.001,
    'door-to-door hourly profit includes deadhead time'
);

$rejected = false;

try {
    field_rideshare_calculate([
        'odometer_start' => 10100,
        'odometer_end' => 10000,
    ]);
} catch (InvalidArgumentException) {
    $rejected = true;
}

$assert(
    $rejected,
    'reversed odometer values are rejected'
);

echo PHP_EOL;
echo json_encode(
    $result,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;

if ($failures > 0) {
    echo PHP_EOL,
        'Rideshare smoke check FAILED: ',
        $failures,
        ' failure(s).',
        PHP_EOL;

    exit(1);
}

echo PHP_EOL, 'Rideshare smoke check passed.', PHP_EOL;