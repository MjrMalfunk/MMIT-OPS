<?php
declare(strict_types=1);

$targetHost = trim((string)getenv('FIELD_VEHICLE_HOST'));
$expectedDatabase = trim(
    (string)getenv('FIELD_VEHICLE_DATABASE')
);

if ($targetHost === '' || $expectedDatabase === '') {
    throw new RuntimeException(
        'FIELD_VEHICLE_HOST and FIELD_VEHICLE_DATABASE are required'
    );
}

$_SERVER['HTTP_HOST'] = $targetHost;

$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_vehicles.php';
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

$assert(
    db_table_exists('field_vehicles'),
    'field_vehicles exists'
);

$assert(
    db_table_exists('field_vehicle_events'),
    'field_vehicle_events exists'
);

$assert(
    db_table_exists('field_vehicle_event_attachments'),
    'field_vehicle_event_attachments exists'
);

$assert(
    db_column_exists(
        'field_vehicles',
        'repair_reserve_per_mile'
    ),
    'vehicle repair reserve column exists'
);

$assert(
    db_column_exists(
        'field_vehicle_events',
        'cost_treatment'
    ),
    'event cost treatment column exists'
);

$model = field_vehicle_cost_model_from_row([
    'fuel_mpg_estimate' => 20.00,
    'fuel_price_per_gallon_estimate' => 3.200,

    'maintenance_reserve_per_mile' => 0.0500,
    'tire_reserve_per_mile' => 0.0200,
    'repair_reserve_per_mile' => 0.0600,

    'acquisition_cost' => 20000.00,
    'expected_residual_value' => 5000.00,
    'expected_service_miles' => 150000.0,
    'depreciation_per_mile_override' => null,

    'insurance_annual_cost' => 1800.00,
    'registration_annual_cost' => 250.00,
    'other_fixed_annual_cost' => 200.00,
    'expected_annual_business_miles' => 20000.0,
]);

$assert(
    abs((float)$model['fuel_cpm'] - 0.1600) < 0.00001,
    'fuel CPM calculates from fuel price / MPG'
);

$assert(
    abs((float)$model['depreciation_cpm'] - 0.1000)
        < 0.00001,
    'depreciation CPM derives from basis / service miles'
);

$assert(
    abs((float)$model['fixed_cpm'] - 0.1125)
        < 0.00001,
    'fixed CPM derives from annual fixed cost / business miles'
);

$assert(
    abs((float)$model['all_in_cpm'] - 0.5025)
        < 0.00001,
    'all-in internal CPM totals every cost component'
);

echo PHP_EOL;
echo json_encode(
    $model,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;

if ($failures > 0) {
    echo PHP_EOL;
    echo 'Vehicle cost model smoke check FAILED: ',
        $failures,
        ' failure(s).',
        PHP_EOL;

    exit(1);
}

echo PHP_EOL;
echo 'Vehicle cost model smoke check passed.',
    PHP_EOL;
