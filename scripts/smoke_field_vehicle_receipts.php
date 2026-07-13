<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = getenv('FIELD_VEHICLE_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/field_vehicles.php';
require __DIR__ . '/../inc/field_vehicle_receipts.php';
ob_end_clean();

function smoke_ok(bool $condition, string $message): void
{
    if (!$condition) {
        echo "FAIL: {$message}\n";
        exit(1);
    }

    echo "PASS: {$message}\n";
}

echo "Database: " . (defined('DB_NAME') ? DB_NAME : 'unknown') . "\n";

field_vehicles_ensure_schema();
field_vehicle_receipts_ensure_schema();

smoke_ok(db_table_exists('field_vehicle_receipt_drafts'), 'field_vehicle_receipt_drafts exists');
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'onedrive_web_url'), 'receipt draft OneDrive URL column exists');
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'parse_status'), 'receipt draft parse status column exists');

$categories = field_vehicle_receipt_categories();
smoke_ok(isset($categories['TOOLS']), 'receipt categories include tools');
smoke_ok(isset($categories['SUPPLIES']), 'receipt categories include supplies');
smoke_ok(isset($categories['JOB_MATERIALS']), 'receipt categories include job materials');
smoke_ok(isset($categories['BUSINESS_EXPENSE']), 'receipt categories include business expense');

$result = field_vehicle_save([
    'vehicle_name' => 'Smoke Receipt Vehicle ' . date('YmdHis'),
    'current_odometer' => '12345',
    'fuel_mpg_estimate' => '20',
    'fuel_price_per_gallon_estimate' => '3.200',
    'expected_annual_business_miles' => '10000',
    'active' => '1',
]);

$vehicleId = (int)($result['vehicle_id'] ?? 0);
smoke_ok($vehicleId > 0, 'smoke vehicle can be created');

$vehicle = field_vehicle_find($vehicleId);
smoke_ok(is_array($vehicle), 'smoke vehicle can be read');

$draft = [
    'receipt_draft_id' => 123,
    'vehicle_name' => $vehicle['vehicle_name'],
    'receipt_category' => 'FUEL',
    'receipt_date' => date('Y-m-d'),
    'vendor' => 'Costco',
    'amount' => '33.50',
    'original_filename' => 'receipt.jpg',
    'created_at' => date('Y-m-d H:i:s'),
];

$folder = field_vehicle_receipt_onedrive_folder_path($draft);
smoke_ok(str_contains($folder, 'MMIT Field Ops'), 'receipt folder uses Field Ops root');
smoke_ok(str_contains($folder, 'Service Vehicles'), 'receipt folder uses Service Vehicles branch');
smoke_ok(str_contains($folder, 'Receipts/Fuel'), 'receipt folder uses Fuel category');

$name = field_vehicle_receipt_onedrive_remote_name($draft);
smoke_ok(str_contains($name, 'Costco'), 'receipt remote name includes vendor');
smoke_ok(str_ends_with($name, '.jpg'), 'receipt remote name preserves extension');

$toolDraft = $draft;
$toolDraft['receipt_category'] = 'TOOLS';
$toolFolder = field_vehicle_receipt_onedrive_folder_path($toolDraft);
smoke_ok(str_contains($toolFolder, 'Receipts/Tools'), 'tools receipts route to Tools folder');

$jobMaterialDraft = $draft;
$jobMaterialDraft['receipt_category'] = 'JOB_MATERIALS';
$jobMaterialFolder = field_vehicle_receipt_onedrive_folder_path($jobMaterialDraft);
smoke_ok(str_contains($jobMaterialFolder, 'Receipts/Job Materials'), 'job material receipts route to Job Materials folder');

$businessDraft = $draft;
$businessDraft['receipt_category'] = 'BUSINESS_EXPENSE';
$businessFolder = field_vehicle_receipt_onedrive_folder_path($businessDraft);
smoke_ok(str_contains($businessFolder, 'Receipts/Business Expenses'), 'business receipts route to Business Expenses folder');

db()->prepare("
    DELETE FROM field_vehicle_receipt_drafts
    WHERE vehicle_id = ?
")->execute([$vehicleId]);

db()->prepare("
    DELETE FROM field_vehicle_events
    WHERE vehicle_id = ?
")->execute([$vehicleId]);

db()->prepare("
    DELETE FROM field_vehicles
    WHERE vehicle_id = ?
")->execute([$vehicleId]);

echo "\nVehicle receipt draft smoke check passed.\n";
