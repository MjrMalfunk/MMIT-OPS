<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';

header('Content-Type: application/json; charset=UTF-8');

if (!accounting_bundle_catalog_ready()) {
    http_response_code(503);
    echo json_encode(['error' => 'Bundle catalog is not ready.']);
    exit;
}

$bundleId = (int)($_GET['bundle_id'] ?? 0);
if ($bundleId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid bundle id.']);
    exit;
}

$bundle = accounting_get_bundle($bundleId);
if (!$bundle) {
    http_response_code(404);
    echo json_encode(['error' => 'Bundle not found.']);
    exit;
}

$included = accounting_get_bundle_items($bundleId, 'INCLUDED');
$addons = accounting_get_bundle_items($bundleId, 'ADDON_OPTION');
$required = accounting_get_bundle_items($bundleId, 'REQUIRED');

$response = [
    'bundle' => [
        'bundle_id' => (int)$bundle['bundle_id'],
        'bundle_code' => (string)($bundle['bundle_code'] ?? ''),
        'bundle_name' => (string)($bundle['bundle_name'] ?? ''),
        'description' => (string)($bundle['description'] ?? ''),
        'pricing_model' => (string)($bundle['pricing_model'] ?? 'FIXED'),
        'default_unit_price' => (float)($bundle['default_unit_price'] ?? 0),
        'default_billing_cycle' => (string)($bundle['default_billing_cycle'] ?? 'MONTHLY'),
        'term_months' => (int)($bundle['term_months'] ?? 0),
    ],
    'required' => $required,
    'included' => $included,
    'addons' => $addons,
];

echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
