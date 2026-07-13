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
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'route_target'), 'receipt draft route target column exists');
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'route_status'), 'receipt draft route status column exists');
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'routed_at'), 'receipt draft routed timestamp column exists');
smoke_ok(db_column_exists('field_vehicle_receipt_drafts', 'route_notes'), 'receipt draft route notes column exists');

$categories = field_vehicle_receipt_categories();
smoke_ok(isset($categories['TOOLS']), 'receipt categories include tools');
smoke_ok(isset($categories['SUPPLIES']), 'receipt categories include supplies');
smoke_ok(isset($categories['JOB_MATERIALS']), 'receipt categories include job materials');
smoke_ok(isset($categories['BUSINESS_EXPENSE']), 'receipt categories include business expense');

$eventMap = field_vehicle_receipt_vehicle_event_category_map();
smoke_ok(isset($eventMap['FUEL']), 'fuel receipt category can convert to vehicle event');
smoke_ok(!isset($eventMap['TOOLS']), 'tools receipt category remains receipt-only');

$routeTargets = field_vehicle_receipt_route_targets();
smoke_ok(isset($routeTargets['TOOL_ASSET']), 'route targets include tool asset');
smoke_ok(isset($routeTargets['EQUIPMENT_ASSET']), 'route targets include equipment asset');
smoke_ok(isset($routeTargets['BUSINESS_EXPENSE']), 'route targets include business expense');
smoke_ok(
    field_vehicle_receipt_default_route_target('TOOLS') === 'TOOL_ASSET',
    'tools default to tool asset routing'
);

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

$pdo = db();

$pdo->prepare("
    INSERT INTO field_vehicle_receipt_drafts (
        vehicle_id,
        receipt_status,
        receipt_category,
        receipt_date,
        vendor,
        amount,
        gallons,
        fuel_price_per_gallon,
        odometer,
        original_filename,
        stored_filename,
        storage_path,
        mime_type,
        file_size_bytes,
        upload_status,
        notes
    ) VALUES (?, 'CAPTURED', 'FUEL', ?, 'Costco', 33.50, 11.360, 2.9490, 12400.0, 'fuel.jpg', 'fuel.jpg', ?, 'image/jpeg', 100, 'LOCAL_ONLY', 'Smoke fuel draft')
")->execute([
    $vehicleId,
    date('Y-m-d'),
    __FILE__,
]);

$fuelDraftId = (int)$pdo->lastInsertId();
$convert = field_vehicle_convert_receipt_draft_to_event($fuelDraftId, null);
smoke_ok(!empty($convert['ok']), 'fuel receipt draft converts to vehicle event');

$event = field_vehicle_find_event((int)$convert['vehicle_event_id']);
smoke_ok(is_array($event), 'converted vehicle event can be read');
smoke_ok((string)$event['event_type'] === 'FUEL', 'converted event keeps fuel type');
smoke_ok((float)$event['amount'] === 33.50, 'converted event copies receipt amount');
smoke_ok((float)$event['gallons'] === 11.36, 'converted event copies gallons');

$linkedByEvent = field_vehicle_receipt_drafts_by_event_ids(
    $vehicleId,
    [(int)$convert['vehicle_event_id']]
);
smoke_ok(
    isset($linkedByEvent[(int)$convert['vehicle_event_id']]),
    'linked receipt helper indexes drafts by event'
);

$linked = field_vehicle_find_receipt_draft($fuelDraftId);
smoke_ok((string)$linked['receipt_status'] === 'LINKED', 'converted draft is marked linked');
smoke_ok((string)$linked['route_target'] === 'VEHICLE_EVENT', 'converted draft route target is vehicle event');
smoke_ok((string)$linked['route_status'] === 'LINKED', 'converted draft route status is linked');
smoke_ok((int)$linked['vehicle_event_id'] === (int)$convert['vehicle_event_id'], 'converted draft stores linked event id');

$pdo->prepare("
    INSERT INTO field_vehicle_receipt_drafts (
        vehicle_id,
        receipt_status,
        receipt_category,
        receipt_date,
        vendor,
        amount,
        original_filename,
        stored_filename,
        storage_path,
        mime_type,
        file_size_bytes,
        upload_status,
        notes
    ) VALUES (?, 'CAPTURED', 'TOOLS', ?, 'Home Depot', 19.99, 'tool.pdf', 'tool.pdf', ?, 'application/pdf', 100, 'LOCAL_ONLY', 'Smoke tool draft')
")->execute([
    $vehicleId,
    date('Y-m-d'),
    __FILE__,
]);

$toolDraftId = (int)$pdo->lastInsertId();
$toolConvert = field_vehicle_convert_receipt_draft_to_event($toolDraftId, null);
smoke_ok(empty($toolConvert['ok']), 'tool receipt draft does not convert to vehicle event yet');

$toolRoute = field_vehicle_route_receipt_draft(
    $toolDraftId,
    'TOOL_ASSET',
    'Smoke tool receipt reviewed',
    null
);
smoke_ok(!empty($toolRoute['ok']), 'tool receipt draft can be routed');

$routedToolDraft = field_vehicle_find_receipt_draft($toolDraftId);
smoke_ok((string)$routedToolDraft['receipt_status'] === 'REVIEWED', 'routed tool draft is marked reviewed');
smoke_ok((string)$routedToolDraft['route_target'] === 'TOOL_ASSET', 'routed tool draft stores route target');
smoke_ok((string)$routedToolDraft['route_status'] === 'REVIEWED', 'routed tool draft stores reviewed status');
smoke_ok(str_contains((string)$routedToolDraft['route_notes'], 'Smoke tool'), 'routed tool draft stores route notes');

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
