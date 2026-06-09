<?php
declare(strict_types=1);

// Smoke checks for the MMIT Syncro production mover helpers. This intentionally
// avoids bootstrap/config secrets and external network requests.
define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('MMIT_SYNCRO_PRODUCTION_MOVER_DISABLE_METADATA_FETCH', true);

$GLOBALS['smoke_syncro_staging_mode'] = false;

function ops_is_staging_env(): bool
{
    return !empty($GLOBALS['smoke_syncro_staging_mode']);
}

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


$definitionShape = [
    'asset_type_fields' => [
        ['id' => 901, 'name' => 'MMIT Onboarding Status', 'options' => [['id' => 135355, 'name' => 'READY']]],
        ['id' => 902, 'name' => 'MMIT Ready To Move', 'option_definition' => ['values' => [['key' => 135359, 'display_name' => 'Yes']]]],
    ],
];
$definitionMap = syncro_production_move_collect_option_definitions($definitionShape);
$definitionStatus = syncro_production_move_resolve_custom_field_value('MMIT Onboarding Status', 135355, $definitionMap);
$definitionReady = syncro_production_move_resolve_custom_field_value('MMIT Ready To Move', 135359, $definitionMap);
smoke_assert(($definitionStatus['value'] ?? null) === 'READY' && ($definitionStatus['source'] ?? null) === 'option_definition', 'metadata option definition resolves status label', $failed);
smoke_assert(($definitionReady['value'] ?? null) === 'Yes' && ($definitionReady['source'] ?? null) === 'option_definition', 'metadata option_definition resolves ready label', $failed);

$metadataDiagnostic = syncro_production_move_settings_metadata_diagnostic($definitionShape);
$diagnosticFields = [];
foreach (($metadataDiagnostic['fields'] ?? []) as $diagnosticField) {
    $diagnosticFields[(string)($diagnosticField['target_field'] ?? '')] = $diagnosticField;
}
smoke_assert(($metadataDiagnostic['contains_custom_field_definitions'] ?? null) === true, 'metadata diagnostic detects custom field definitions', $failed);
smoke_assert(($metadataDiagnostic['asset_custom_fields_present'] ?? null) === true, 'metadata diagnostic detects asset custom fields', $failed);
smoke_assert(($diagnosticFields['MMIT Onboarding Status']['found_in_settings'] ?? null) === true, 'metadata diagnostic finds onboarding status', $failed);
smoke_assert(($diagnosticFields['MMIT Onboarding Status']['resolver_has_option_ids'] ?? null) === true, 'metadata diagnostic reports resolver option ids', $failed);
smoke_assert(($diagnosticFields['MMIT Ready To Move']['metadata_entries'][0]['option_list_present'] ?? null) === true, 'metadata diagnostic reports option list path', $failed);
smoke_assert(($diagnosticFields['MMIT Production Folder Target']['found_in_settings'] ?? null) === false, 'metadata diagnostic reports missing production target metadata', $failed);

$liveNumericAsset = $readyAsset;
$liveNumericAsset['properties']['MMIT Onboarding Status'] = 135355;
$liveNumericAsset['properties']['MMIT Ready To Move'] = 135359;
$liveNumericAsset['properties']['MMIT Production Folder Target'] = 'Production/Workstations';
$liveNumericValidation = syncro_production_move_validate_asset($liveNumericAsset);
$liveNumericDebug = json_encode($liveNumericValidation['custom_field_debug'] ?? []);
smoke_assert(($liveNumericValidation['ok'] ?? null) === true, 'live numeric option ID shape validates through configured option map', $failed);
smoke_assert(($liveNumericValidation['status'] ?? null) === 'READY', 'live numeric status option ID resolves to READY', $failed);
smoke_assert(($liveNumericValidation['ready_to_move'] ?? null) === 'Yes', 'live numeric ready option ID resolves to Yes', $failed);
smoke_assert(($liveNumericValidation['target'] ?? null) === 'Production/Workstations', 'live target string remains unchanged', $failed);
smoke_assert($liveNumericDebug !== false && str_contains($liveNumericDebug, 'configured_option_map'), 'live numeric debug shows configured option map source', $failed);

$unresolvedNumericAsset = $readyAsset;
$unresolvedNumericAsset['properties']['MMIT Onboarding Status'] = 999999;
$unresolvedNumericValidation = syncro_production_move_validate_asset($unresolvedNumericAsset);
$unresolvedNumericDebug = json_encode($unresolvedNumericValidation['custom_field_debug'] ?? []);
$unresolvedNumericMove = syncro_production_move_asset(35912652, 12561086, 4211, true, false, $unresolvedNumericAsset);
smoke_assert(($unresolvedNumericValidation['ok'] ?? null) === false, 'unresolved numeric option ID fails validation safely', $failed);
smoke_assert($unresolvedNumericDebug !== false && str_contains($unresolvedNumericDebug, 'unresolved_numeric'), 'unresolved numeric debug source is shown', $failed);
smoke_assert(($unresolvedNumericMove['ok'] ?? null) === false && !isset($unresolvedNumericMove['payload']), 'unresolved numeric dry run does not attempt move payload', $failed);

$payload = syncro_production_move_policy_assignment_payload(12561086, 5027864);
smoke_assert(($payload['changes']['update_asset'][0]['id'] ?? null) === 12561086, 'payload asset id', $failed);
smoke_assert(($payload['changes']['update_asset'][0]['change']['policy_folder_id'] ?? null) === 5027864, 'payload folder id', $failed);
smoke_assert(($payload['changes']['add_folder'] ?? null) === [], 'payload add_folder empty', $failed);

$dryRunRequests = [];
$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload) use (&$dryRunRequests): array {
    $dryRunRequests[] = compact('method', 'path', 'query', 'payload');
    return ['ok' => false, 'status' => 500, 'errors' => ['dry run should not write']];
};
$dryRun = syncro_production_move_asset(35912652, 12561086, 4211, true, false, $readyAsset);
smoke_assert(($dryRun['ok'] ?? null) === true, 'dry-run succeeds without network', $failed);
smoke_assert(($dryRun['dry_run'] ?? null) === true, 'dry-run marked', $failed);
smoke_assert(($dryRun['payload']['changes']['update_asset'][0]['change']['policy_folder_id'] ?? null) === 5027864, 'dry-run payload target', $failed);
smoke_assert(($dryRun['asset_update_payload']['policy_folder_id'] ?? null) === 5027864, 'dry-run shows asset update payload target', $failed);
smoke_assert($dryRunRequests === [], 'dry-run produces no Syncro write', $failed);
smoke_assert(str_contains((string)($dryRun['ticket_note_preview'] ?? ''), 'completion note would be added'), 'dry-run logs ticket note preview', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$successRequests = [];
$movedAsset = $readyAsset;
$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload) use (&$successRequests, &$movedAsset): array {
    $successRequests[] = compact('method', 'path', 'query', 'payload');
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['policy_folder_id'])) {
        $movedAsset['policy_folder_id'] = (int)$payload['policy_folder_id'];
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'GET' && $path === 'customer_assets/12561086') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['properties'])) {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'POST' && $path === 'tickets/4211/comments') {
        return ['ok' => true, 'status' => 201, 'data' => ['comment' => ['id' => 88]]];
    }
    return ['ok' => false, 'status' => 500, 'errors' => ['unexpected request ' . $method . ' ' . $path]];
};
$successfulPut = syncro_production_move_asset(35912652, 12561086, 4211, false, true, $readyAsset);
smoke_assert(($successfulPut['ok'] ?? null) === true, 'successful PUT move succeeds', $failed);
smoke_assert(($successfulPut['move_diagnostic']['method'] ?? null) === 'PUT', 'successful move diagnostic method', $failed);
smoke_assert(($successfulPut['move_diagnostic']['path'] ?? null) === '/api/v1/customer_assets/12561086', 'successful move diagnostic path', $failed);
smoke_assert(($successfulPut['move_diagnostic']['http_status'] ?? null) === 200, 'successful move diagnostic status', $failed);
smoke_assert(($successfulPut['move_diagnostic']['payload']['policy_folder_id'] ?? null) === 5027864, 'successful move diagnostic payload', $failed);
smoke_assert(($successfulPut['verified_policy_folder_id'] ?? null) === 5027864, 'successful PUT re-fetch verifies policy folder', $failed);
smoke_assert(($successfulPut['ticket_id'] ?? null) === 4211, 'successful move records ticket id for note', $failed);
smoke_assert(str_contains((string)($successfulPut['ticket_note_body'] ?? ''), 'Asset name: MANAGE-WS-02') && str_contains((string)($successfulPut['ticket_note_body'] ?? ''), 'Source policy_folder_id: 5027867') && str_contains((string)($successfulPut['ticket_note_body'] ?? ''), 'Manual technician verification is still required'), 'successful move note body includes lifecycle details', $failed);
$successWriteIndex = null;
$successVerifyIndex = null;
$successTicketCloseAttempted = false;
$successTicketNoteIndex = null;
foreach ($successRequests as $index => $request) {
    if (($request['method'] ?? '') === 'GET' && ($request['path'] ?? '') === 'customer_assets/12561086') {
        $successVerifyIndex = $successVerifyIndex ?? $index;
    }
    if (($request['method'] ?? '') === 'PUT' && isset($request['payload']['properties'][MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT])) {
        $successWriteIndex = $successWriteIndex ?? $index;
    }
    if (($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === 'tickets/4211/comments') {
        $successTicketNoteIndex = $successTicketNoteIndex ?? $index;
    }
    if (($request['method'] ?? '') === 'PUT' && ($request['path'] ?? '') === 'tickets/4211') {
        $successTicketCloseAttempted = true;
    }
}
smoke_assert($successVerifyIndex !== null && $successWriteIndex !== null && $successVerifyIndex < $successWriteIndex, 'success writes result only after verification fetch', $failed);
smoke_assert($successVerifyIndex !== null && $successTicketNoteIndex !== null && $successVerifyIndex < $successTicketNoteIndex, 'success adds ticket note only after verification fetch', $failed);
smoke_assert(!$successTicketCloseAttempted, 'successful move does not resolve or close ticket even when requested', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$missingTicketRequests = [];
$missingTicketMovedAsset = $readyAsset;
$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload) use (&$missingTicketRequests, &$missingTicketMovedAsset): array {
    $missingTicketRequests[] = compact('method', 'path', 'query', 'payload');
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['policy_folder_id'])) {
        $missingTicketMovedAsset['policy_folder_id'] = (int)$payload['policy_folder_id'];
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $missingTicketMovedAsset]];
    }
    if ($method === 'GET' && $path === 'customer_assets/12561086') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $missingTicketMovedAsset]];
    }
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['properties'])) {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $missingTicketMovedAsset]];
    }
    if ($method === 'GET' && $path === 'tickets') {
        return ['ok' => true, 'status' => 200, 'data' => ['tickets' => []]];
    }
    return ['ok' => false, 'status' => 500, 'errors' => ['unexpected missing-ticket request ' . $method . ' ' . $path]];
};
$missingTicketMove = syncro_production_move_asset(35912652, 12561086, null, false, false, $readyAsset);
smoke_assert(($missingTicketMove['ok'] ?? null) === true, 'successful move continues when related ticket cannot be found', $failed);
smoke_assert(str_contains(implode(' ', array_map('strval', (array)($missingTicketMove['warnings'] ?? []))), 'ticket was not found'), 'missing ticket warning is logged', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload): array {
    return ['ok' => false, 'status' => 422, 'errors' => ['policy_folder_id is invalid'], 'raw_body' => '{"errors":["policy_folder_id is invalid"]}'];
};
$invalidFolderPut = syncro_production_move_asset(35912652, 12561086, 4211, false, false, $readyAsset);
smoke_assert(($invalidFolderPut['ok'] ?? null) === false, '422 invalid folder remains failure', $failed);
smoke_assert(($invalidFolderPut['move_diagnostic']['http_status'] ?? null) === 422, '422 diagnostic status captured', $failed);
smoke_assert(str_contains((string)($invalidFolderPut['message'] ?? ''), 'policy_folder_id is invalid'), '422 response excerpt displayed', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload): array {
    return ['ok' => false, 'status' => 404, 'errors' => ['Not Found'], 'raw_body' => '{"error":"Not Found"}'];
};
$notFoundPut = syncro_production_move_asset(35912652, 12561086, 4211, false, false, $readyAsset);
smoke_assert(($notFoundPut['ok'] ?? null) === false, '404 remains hard failure', $failed);
smoke_assert(($notFoundPut['move_diagnostic']['http_status'] ?? null) === 404, '404 diagnostic status captured', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$GLOBALS['smoke_syncro_staging_mode'] = true;
$stagingBlocked = syncro_production_move_asset(35912652, 12561086, 4211, false, false, $readyAsset);
smoke_assert(($stagingBlocked['ok'] ?? null) === true, 'staging write disabled guarded result is ok for UI', $failed);
smoke_assert(($stagingBlocked['staging_guarded'] ?? null) === true, 'staging write disabled result is marked guarded', $failed);
smoke_assert(($stagingBlocked['production_move_succeeded'] ?? true) === false, 'staging write disabled is not production success', $failed);
smoke_assert(isset($stagingBlocked['payload']) && !isset($stagingBlocked['write']) && str_contains((string)($stagingBlocked['message'] ?? ''), 'Would move MANAGE-WS-02 to Production/Workstations (#5027864).'), 'staging guarded message and payload', $failed);

$GLOBALS['smoke_syncro_staging_mode'] = false;
$realWriteFailure = syncro_production_move_asset(35912652, 12561086, 4211, false, false, $readyAsset);
smoke_assert(($realWriteFailure['ok'] ?? null) === false, 'real write failure remains failure', $failed);
smoke_assert(($realWriteFailure['staging_guarded'] ?? false) === false, 'real write failure is not staging guarded', $failed);
smoke_assert(str_starts_with((string)($realWriteFailure['message'] ?? ''), 'Move failed for MANAGE-WS-02:'), 'real write failure message remains hard failure', $failed);

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
