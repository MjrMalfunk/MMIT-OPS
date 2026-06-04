<?php
declare(strict_types=1);

// Smoke checks for the MMIT Syncro production mover helpers. This intentionally
// avoids bootstrap/config secrets and must not perform network requests.
define('APP_ENV', 'staging');
define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');

require_once __DIR__ . '/../inc/syncro_production_mover.php';

function smoke_assert(bool $condition, string $label, array &$failed): void
{
    if (!$condition) {
        $failed[] = $label;
    }
}

$failed = [];

smoke_assert(syncro_production_move_target_folder_id('Production/Workstations') === 5027864, 'workstation target maps', $failed);
smoke_assert(syncro_production_move_target_folder_id('Production / Servers') === 5027865, 'server target maps with spacing', $failed);
smoke_assert(syncro_production_move_target_folder_id('Deploy/Workstations') === null, 'deploy target is not accepted for production move', $failed);

$readyAsset = [
    'id' => 12561086,
    'name' => 'MANAGE-WS-02',
    'policy_folder_id' => 5027867,
    'properties' => [
        'MMIT Onboarding Status' => 'READY',
        'MMIT Ready To Move' => 'Yes',
        'MMIT Production Folder Target' => 'Production/Workstations',
    ],
];
$validation = syncro_production_move_validate_asset($readyAsset);
smoke_assert(($validation['ok'] ?? null) === true, 'ready asset validates', $failed);
smoke_assert(($validation['target_folder_id'] ?? null) === 5027864, 'ready asset target folder id', $failed);

$syncroObjectAsset = [
    'id' => 12561086,
    'name' => 'MANAGE-WS-02',
    'policy_folder_id' => 5027867,
    'custom_fields' => [
        [
            'id' => 135355,
            'field_definition_id' => 135355,
            'name' => 'MMIT Onboarding Status',
            'value' => 135355,
            'display_value' => 'READY',
        ],
        [
            'id' => 135359,
            'field_definition_id' => 135359,
            'name' => 'MMIT Ready To Move',
            'value' => 135359,
            'display_value' => 'Yes',
        ],
        [
            'id' => 135360,
            'field_definition_id' => 135360,
            'name' => 'MMIT Production Folder Target',
            'value' => 'Production/Workstations',
        ],
    ],
];
$syncroObjectFields = syncro_production_move_extract_custom_fields($syncroObjectAsset);
smoke_assert(($syncroObjectFields['MMIT Onboarding Status'] ?? null) === 'READY', 'object status value is display value not id', $failed);
smoke_assert(($syncroObjectFields['MMIT Ready To Move'] ?? null) === 'Yes', 'object ready value is display value not id', $failed);
smoke_assert(($syncroObjectFields['MMIT Production Folder Target'] ?? null) === 'Production/Workstations', 'object target value is actual target', $failed);
$syncroObjectValidation = syncro_production_move_validate_asset($syncroObjectAsset);
smoke_assert(($syncroObjectValidation['ok'] ?? null) === true, 'Syncro object-shaped fields validate', $failed);
smoke_assert(($syncroObjectValidation['status'] ?? null) === 'READY', 'validation status is READY not id', $failed);
smoke_assert(($syncroObjectValidation['ready_to_move'] ?? null) === 'Yes', 'validation ready is Yes not id', $failed);

$keyedObjectAsset = $readyAsset;
$keyedObjectAsset['properties']['MMIT Onboarding Status'] = ['id' => 135355, 'value' => 135355, 'value_text' => 'READY'];
$keyedObjectAsset['properties']['MMIT Ready To Move'] = ['id' => 135359, 'value' => 135359, 'text' => 'Yes'];
$keyedObjectValidation = syncro_production_move_validate_asset($keyedObjectAsset);
smoke_assert(($keyedObjectValidation['status'] ?? null) === 'READY', 'keyed object status is READY not id', $failed);
smoke_assert(($keyedObjectValidation['ready_to_move'] ?? null) === 'Yes', 'keyed object ready is Yes not id', $failed);

$badShapeAsset = $readyAsset;
$badShapeAsset['properties']['MMIT Onboarding Status'] = ['api_key' => 'smoke-test-key-not-secret', 'value' => 'NOT_READY'];
$badShapeValidation = syncro_production_move_validate_asset($badShapeAsset);
$badShapeDebug = json_encode($badShapeValidation['custom_field_debug'] ?? []);
smoke_assert(($badShapeValidation['ok'] ?? null) === false, 'bad shape fails validation', $failed);
smoke_assert($badShapeDebug !== false && str_contains($badShapeDebug, '[redacted]') && !str_contains($badShapeDebug, 'smoke-test-key-not-secret'), 'custom field debug masks secrets', $failed);

$payload = syncro_production_move_policy_assignment_payload(12561086, 5027864);
smoke_assert(($payload['changes']['update_asset'][0]['id'] ?? null) === 12561086, 'payload asset id', $failed);
smoke_assert(($payload['changes']['update_asset'][0]['change']['policy_folder_id'] ?? null) === 5027864, 'payload folder id', $failed);
smoke_assert(($payload['changes']['add_folder'] ?? null) === [], 'payload add_folder empty', $failed);

$dryRun = syncro_production_move_asset(35912652, 12561086, 4211, true, false, $readyAsset);
smoke_assert(($dryRun['ok'] ?? null) === true, 'dry-run succeeds without network', $failed);
smoke_assert(($dryRun['dry_run'] ?? null) === true, 'dry-run marked', $failed);
smoke_assert(($dryRun['payload']['changes']['update_asset'][0]['change']['policy_folder_id'] ?? null) === 5027864, 'dry-run payload target', $failed);

$alreadyAsset = $readyAsset;
$alreadyAsset['policy_folder_id'] = 5027864;
$noop = syncro_production_move_asset(35912652, 12561086, 4211, true, false, $alreadyAsset);
smoke_assert(($noop['ok'] ?? null) === true, 'already-in-target succeeds', $failed);
smoke_assert(($noop['noop'] ?? null) === true, 'already-in-target is noop', $failed);
smoke_assert(($noop['message'] ?? null) === 'Already in target folder.', 'already-in-target message', $failed);

$invalidAsset = $readyAsset;
$invalidAsset['properties']['MMIT Production Folder Target'] = 'Deploy/Workstations';
$invalid = syncro_production_move_validate_asset($invalidAsset);
smoke_assert(($invalid['ok'] ?? null) === false, 'invalid target fails validation', $failed);

$masked = syncro_production_move_mask_secrets('failure api_key=smoke-test-key-not-secret Authorization: Bearer abc123');
smoke_assert(!str_contains($masked, 'smoke-test-key-not-secret') && !str_contains($masked, 'abc123'), 'secret masking', $failed);

if ($failed) {
    fwrite(STDERR, 'Syncro production mover smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro production mover smoke check passed.' . PHP_EOL;
