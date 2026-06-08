<?php
declare(strict_types=1);

define('APP_ENV', 'production');
define('BASE_URL', 'https://ops.midwestmanagedit.com');
define('SYNCRO_SUBDOMAIN', 'example');
define('SYNCRO_API_KEY', 'smoke-test-key-not-secret');
define('SYNCRO_BASE_URL', 'https://127.0.0.1/never-called/');
define('MMIT_SYNCRO_PRODUCTION_MOVER_DISABLE_METADATA_FETCH', true);

$GLOBALS['smoke_syncro_staging_mode'] = false;
function ops_is_staging_env(): bool { return !empty($GLOBALS['smoke_syncro_staging_mode']); }

require_once __DIR__ . '/../inc/syncro_onboarding_completion.php';

function smoke_assert(bool $condition, string $label, array &$failed): void
{
    if (!$condition) $failed[] = $label;
}

$failed = [];
$client = ['client_id' => 101, 'syncro_customer_id' => 35912652, 'legal_name' => 'Acme Co', 'services' => []];
$contract = ['contract_id' => 77, 'status' => 'ONBOARDING', 'sla_level' => 'Manage IT', 'billing_profile_ready' => true];
$baseAsset = [
    'id' => 12561086,
    'name' => 'ACME-WS-01',
    'policy_folder_id' => MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS,
    'properties' => [
        MMIT_SYNCRO_FIELD_ASSET_ROLE => 'Workstation',
        MMIT_SYNCRO_FIELD_BACKUP_REQUIRED => 'No',
        MMIT_SYNCRO_FIELD_DNS_REQUIRED => 'No',
        MMIT_SYNCRO_FIELD_PRODUCTION_TARGET => 'Production/Workstations',
        MMIT_SYNCRO_FIELD_ONBOARDING_RESULT => "Defender: FAIL\nScoutDNS: FAIL\nHuntress: FAIL\nCoveAgent: FAIL\nCoveBackupComplete: FAIL",
    ],
];

$tickets = [];
$assets = [$baseAsset['id'] => $baseAsset];
$requests = [];
$GLOBALS['syncro_onboarding_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload) use (&$tickets, &$assets, &$requests): array {
    $requests[] = compact('method', 'path', 'query', 'payload');
    if ($method === 'PUT' && str_starts_with($path, 'customer_assets/')) {
        $assetId = (int)substr($path, strlen('customer_assets/'));
        foreach (($payload['properties'] ?? []) as $key => $value) {
            $assets[$assetId]['properties'][$key] = $value;
        }
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $assets[$assetId] ?? []]];
    }
    if ($method === 'GET' && $path === 'tickets') {
        return ['ok' => true, 'status' => 200, 'data' => ['tickets' => array_values($tickets)]];
    }
    if ($method === 'POST' && $path === 'tickets') {
        $ticket = $payload['ticket'] ?? [];
        $ticket['id'] = 5000 + count($tickets);
        $tickets[] = $ticket;
        return ['ok' => true, 'status' => 201, 'data' => ['ticket' => $ticket]];
    }
    return ['ok' => false, 'status' => 500, 'errors' => ['unexpected onboarding request ' . $method . ' ' . $path]];
};

$notReady = syncro_onboarding_evaluate_asset_completion(35912652, $baseAsset, $client, $contract, true);
smoke_assert(($notReady['ready'] ?? true) === false, 'asset not ready does not become ready', $failed);
smoke_assert(count($tickets) === 0, 'asset not ready does not create ticket', $failed);
smoke_assert(($assets[$baseAsset['id']]['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] ?? '') === 'NOT_READY', 'asset not ready writes NOT_READY', $failed);

$readyAsset = $baseAsset;
$readyAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_RESULT] = "Defender: PASS\nScoutDNS: FAIL\nHuntress: FAIL\nCoveAgent: FAIL\nCoveBackupComplete: FAIL";
$assets[$readyAsset['id']] = $readyAsset;
$ready = syncro_onboarding_evaluate_asset_completion(35912652, $readyAsset, $client, $contract, true);
smoke_assert(($ready['ready'] ?? false) === true, 'ready asset passes validation', $failed);
smoke_assert(count($tickets) === 1, 'ready asset creates one move ticket', $failed);
smoke_assert(($tickets[0]['subject'] ?? '') === 'MMIT Auto Move Ready - ACME-WS-01', 'ready ticket subject format', $failed);
smoke_assert(str_contains((string)($tickets[0]['body'] ?? ''), 'OPS client id: 101'), 'ticket body includes OPS client id', $failed);
smoke_assert(str_contains(json_encode($tickets[0]) ?: '', MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE), 'ticket has recognizable auto move type/tag', $failed);

$duplicate = syncro_onboarding_evaluate_asset_completion(35912652, $readyAsset, $client, $contract, true);
smoke_assert(($duplicate['ticket']['duplicate'] ?? false) === true, 'duplicate ready check identifies existing ticket', $failed);
smoke_assert(count($tickets) === 1, 'duplicate ready checks do not create duplicate tickets', $failed);
unset($GLOBALS['syncro_onboarding_api_request_handler']);

$moverAsset = $readyAsset;
$moverAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] = 'READY';
$moverAsset['properties'][MMIT_SYNCRO_FIELD_READY_TO_MOVE] = 'No';
$refused = syncro_onboarding_move_ready_asset(35912652, 12561086, 5000, $moverAsset);
smoke_assert(($refused['ok'] ?? true) === false, 'mover refuses asset without ready flag', $failed);

$movedAsset = $readyAsset;
$movedAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] = 'READY';
$movedAsset['properties'][MMIT_SYNCRO_FIELD_READY_TO_MOVE] = 'Yes';
$moveRequests = [];
$GLOBALS['syncro_production_move_api_request_handler'] = static function (string $method, string $path, array $query, ?array $payload) use (&$moveRequests, &$movedAsset): array {
    $moveRequests[] = compact('method', 'path', 'query', 'payload');
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['policy_folder_id'])) {
        $movedAsset['policy_folder_id'] = (int)$payload['policy_folder_id'];
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'GET' && $path === 'customer_assets/12561086') {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'PUT' && $path === 'customer_assets/12561086' && isset($payload['properties'])) {
        foreach ($payload['properties'] as $key => $value) $movedAsset['properties'][$key] = $value;
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => $movedAsset]];
    }
    if ($method === 'POST' && $path === 'tickets/5000/comments') {
        return ['ok' => true, 'status' => 201, 'data' => ['comment' => ['id' => 90]]];
    }
    if ($method === 'PUT' && $path === 'tickets/5000') {
        return ['ok' => true, 'status' => 200, 'data' => ['ticket' => ['id' => 5000, 'status' => 'Resolved']]];
    }
    return ['ok' => false, 'status' => 500, 'errors' => ['unexpected mover request ' . $method . ' ' . $path]];
};
$move = syncro_onboarding_move_ready_asset(35912652, 12561086, 5000, $movedAsset);
smoke_assert(($move['ok'] ?? false) === true, 'mover moves ready asset', $failed);
smoke_assert(($movedAsset['policy_folder_id'] ?? null) === MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS, 'mover uses production folder target', $failed);
smoke_assert(($movedAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] ?? '') === 'COMPLETED', 'mover marks asset completed', $failed);
$closedTicket = false;
foreach ($moveRequests as $request) {
    if (($request['method'] ?? '') === 'PUT' && ($request['path'] ?? '') === 'tickets/5000' && (($request['payload']['ticket']['status'] ?? '') === 'Resolved')) $closedTicket = true;
}
smoke_assert($closedTicket, 'mover closes ticket', $failed);
unset($GLOBALS['syncro_production_move_api_request_handler']);

$completedAsset = $movedAsset;
$completedAsset['policy_folder_id'] = MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS;
$completedAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] = 'COMPLETED';
$incompleteAsset = $completedAsset;
$incompleteAsset['id'] = 12561087;
$incompleteAsset['name'] = 'ACME-WS-02';
$incompleteAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] = 'READY';
$contractWithExpected = $contract + ['expected_assets' => [['id' => 12561086], ['id' => 12561087]]];
$notActive = syncro_onboarding_activate_contract_if_complete($contractWithExpected, [$completedAsset, $incompleteAsset], [], 0);
smoke_assert(($notActive['activated'] ?? true) === false, 'contract remains onboarding while any asset incomplete', $failed);

$events = [];
$alerts = [];
$GLOBALS['syncro_onboarding_contract_event_handler'] = static function (int $contractId, string $message) use (&$events): void { $events[] = [$contractId, $message]; };
$GLOBALS['syncro_onboarding_alert_handler'] = static function (string $subject, string $body, array $context) use (&$alerts): void { $alerts[] = [$subject, $body, $context]; };
$incompleteAsset['properties'][MMIT_SYNCRO_FIELD_ONBOARDING_STATUS] = 'COMPLETED';
$incompleteAsset['policy_folder_id'] = MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS;
$active = syncro_onboarding_activate_contract_if_complete($contractWithExpected, [$completedAsset, $incompleteAsset], [], 0);
smoke_assert(($active['activated'] ?? false) === true, 'contract becomes active when all expected assets complete', $failed);
smoke_assert(($events[0][1] ?? '') === 'Contract activated automatically after onboarding completion.', 'contract activation event/note emitted', $failed);
smoke_assert(($alerts[0][0] ?? '') === 'MMIT contract became Active', 'Keith activation alert emitted', $failed);

if ($failed) {
    fwrite(STDERR, 'Syncro onboarding completion smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro onboarding completion smoke check passed.' . PHP_EOL;
