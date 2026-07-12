<?php
declare(strict_types=1);

$host = getenv('FIELD_VEHICLE_HOST') ?: 'ops-test.midwestmanagedit.com';

$_SERVER['HTTP_HOST'] = $host;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/field_vehicles.php';
require __DIR__ . '/../inc/field_vehicle_maintenance.php';
ob_end_clean();

function ok(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    echo "PASS: {$message}\n";
}

echo "Database: " . DB_NAME . PHP_EOL;

field_vehicle_maintenance_ensure_schema();

ok(db_table_exists('field_vehicle_components'), 'field_vehicle_components exists');
ok(db_table_exists('field_vehicle_maintenance_items'), 'field_vehicle_maintenance_items exists');
ok(db_column_exists('field_vehicle_components', 'component_type'), 'component type column exists');
ok(db_column_exists('field_vehicle_components', 'baseline_odometer'), 'component baseline odometer column exists');
ok(db_column_exists('field_vehicle_maintenance_items', 'clock_type'), 'maintenance clock type column exists');
ok(db_column_exists('field_vehicle_maintenance_items', 'next_due_odometer'), 'maintenance next due odometer column exists');

$vehicleResult = field_vehicle_save([
    'vehicle_name' => 'Smoke Maintenance Vehicle ' . date('YmdHis'),
    'model_year' => '2017',
    'make' => 'Hyundai',
    'model' => 'Tucson',
    'trim_name' => 'Limited',
    'current_odometer' => '79461',
    'fuel_mpg_estimate' => '25',
    'fuel_price_per_gallon_estimate' => '3.299',
    'expected_annual_business_miles' => '15000',
    'insurance_annual_cost' => '1400',
    'registration_annual_cost' => '102.35',
    'active' => '1',
]);

$vehicleId = (int)$vehicleResult['vehicle_id'];

$componentResult = field_vehicle_component_save([
    'vehicle_id' => (string)$vehicleId,
    'component_type' => 'ENGINE',
    'component_name' => 'Replacement engine',
    'status' => 'RESET',
    'baseline_date' => '2026-06-23',
    'baseline_odometer' => '78941',
    'notes' => 'Smoke-test replacement engine baseline.',
]);

ok(!empty($componentResult['component_id']), 'component baseline can be created');

$commaComponentResult = field_vehicle_component_save([
    'vehicle_id' => (string)$vehicleId,
    'component_type' => 'BATTERY',
    'component_name' => 'Comma parse battery',
    'status' => 'RESET',
    'baseline_date' => '2026-07-10',
    'baseline_odometer' => '79,461',
    'warranty_until_miles' => '100,000',
]);

$commaComponent = field_vehicle_component_find(
    (int)$commaComponentResult['component_id']
);

ok(
    (float)$commaComponent['baseline_odometer'] === 79461.0,
    'comma-formatted baseline odometer parses correctly'
);

ok(
    (float)$commaComponent['warranty_until_miles'] === 100000.0,
    'comma-formatted warranty miles parse correctly'
);

$maintenanceResult = field_vehicle_maintenance_item_save([
    'vehicle_id' => (string)$vehicleId,
    'component_id' => (string)$componentResult['component_id'],
    'item_name' => 'Oil and filter service',
    'subsystem' => 'Engine',
    'schedule_source' => 'MANUAL',
    'clock_type' => 'ENGINE',
    'interval_miles' => '5000',
    'interval_months' => '6',
    'baseline_odometer' => '79461',
    'baseline_date' => '2026-07-10',
    'estimated_service_cost' => '107.42',
    'active' => '1',
]);

ok(!empty($maintenanceResult['maintenance_item_id']), 'maintenance item can be created');
ok((float)$maintenanceResult['next_due_odometer'] === 84461.0, 'next due odometer is baseline + interval');
ok((string)$maintenanceResult['next_due_date'] === '2027-01-10', 'next due date is baseline + interval months');
ok((string)$maintenanceResult['status'] === 'CURRENT', 'maintenance status derives as current');

$summary = field_vehicle_maintenance_summary($vehicleId);

ok($summary['components_total'] === 2, 'summary counts components');
ok($summary['maintenance_total'] === 1, 'summary counts maintenance items');
ok(abs($summary['estimated_scheduled_reserve_cpm'] - 0.0215) < 0.0001, 'scheduled reserve CPM derives from cost / interval miles');

echo PHP_EOL;
echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;

echo PHP_EOL . 'Vehicle maintenance smoke check passed.' . PHP_EOL;
