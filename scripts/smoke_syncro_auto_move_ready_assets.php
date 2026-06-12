<?php
declare(strict_types=1);

// Smoke checks for OPS Syncro READY auto mover helpers. This avoids bootstrap,
// config secrets, and external network calls by defining the small Syncro helper
// surface the cron script needs for these focused assertions.

$GLOBALS['smoke_syncro_auto_move_requests'] = [];
$GLOBALS['smoke_syncro_auto_move_folder_move'] = [];

function syncro_api_request(string $method, string $path, array $query = [], ?array $payload = null): array
{
    $GLOBALS['smoke_syncro_auto_move_requests'][] = [
        'method' => $method,
        'path' => $path,
        'query' => $query,
        'payload' => $payload,
    ];

    if ($method === 'GET' && $path === 'tickets') {
        $assetId = (int)($query['asset_id'] ?? 0);
        $assetName = $assetId === 202 ? 'MANAGE-SRV-01' : 'MANAGE-WS-01';
        return ['ok' => true, 'data' => ['tickets' => [[
            'id' => $assetId === 202 ? 112489922 : 112489921,
            'number' => $assetId === 202 ? 7002 : 7001,
            'status' => 'New',
            'subject' => 'MMIT Auto Move Ready - ' . $assetName,
            'body' => 'mmit_auto_move_ready asset id: ' . $assetId,
        ]]]];
    }

    if ($method === 'PUT' && str_starts_with($path, 'customer_assets/')) {
        return ['ok' => true, 'status' => 200, 'data' => ['customer_asset' => ['id' => (int)substr($path, 16)]]];
    }

    if ($method === 'POST' && $path === 'tickets/112489921/comment') {
        return ['ok' => false, 'status' => 404, 'errors' => ['error: Not Found']];
    }

    if ($method === 'POST' && $path === 'tickets/112489922/comment') {
        return ['ok' => true, 'status' => 201, 'data' => ['comment' => ['id' => 92]]];
    }

    return ['ok' => false, 'status' => 500, 'errors' => ['unexpected request ' . $method . ' ' . $path]];
}

function syncro_update_customer_asset_policy_folder(int $assetId, int $targetFolderId): array
{
    $GLOBALS['smoke_syncro_auto_move_folder_move'][] = ['asset_id' => $assetId, 'target_folder_id' => $targetFolderId];
    return ['ok' => true, 'status' => 200];
}

function syncro_extract_asset_custom_fields(array $asset): array
{
    return (array)($asset['properties'] ?? []);
}

function syncro_asset_id(array $asset): int
{
    return (int)($asset['id'] ?? 0);
}

function syncro_asset_name(array $asset): string
{
    return (string)($asset['name'] ?? '');
}

function syncro_asset_policy_folder_id(array $asset): int
{
    return (int)($asset['policy_folder_id'] ?? 0);
}

function syncro_asset_os_text(array $asset): string
{
    return (string)($asset['os'] ?? 'Windows 11 Pro');
}

function syncro_classify_asset_os(string $os): array
{
    return ['platform' => 'windows', 'role' => stripos($os, 'server') !== false ? 'server' : 'workstation'];
}

require_once __DIR__ . '/syncro_auto_move_ready_assets_cron.php';

function smoke_assert(bool $condition, string $label, array &$failed): void
{
    if (!$condition) {
        $failed[] = $label;
    }
}

$failed = [];
$workstationAsset = [
    'id' => 101,
    'name' => 'MANAGE-WS-01',
    'policy_folder_id' => 501,
    'properties' => ['MMIT Ready Move Ticket ID' => ''],
];
$workstationCandidate = [
    'asset_id' => 101,
    'asset_name' => 'MANAGE-WS-01',
    'current_folder_id' => 501,
    'target_folder_id' => 601,
];
$workstation = syncro_auto_move_move_workstation(11, 22, $workstationAsset, $workstationCandidate);
smoke_assert(($workstation['ok'] ?? null) === true, 'workstation move succeeds', $failed);
smoke_assert(($workstation['ticket_found'] ?? null) === true, 'workstation ticket found', $failed);
smoke_assert(($workstation['ticket_id'] ?? null) === 112489921, 'workstation internal ticket id extracted', $failed);
smoke_assert(($workstation['ticket_number'] ?? null) === 7001, 'workstation internal ticket id extracted', $failed);

$readyTicketWrites = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static function (array $request): bool {
    return ($request['method'] ?? '') === 'PUT'
        && ($request['path'] ?? '') === 'customer_assets/101'
        && (($request['payload']['properties']['MMIT Ready Move Ticket ID'] ?? null) === '7001');
}));
smoke_assert(count($readyTicketWrites) === 1, 'workstation blank ready move ticket id is backfilled', $failed);

$completionWrites = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static function (array $request): bool {
    return ($request['method'] ?? '') === 'PUT'
        && ($request['path'] ?? '') === 'customer_assets/101'
        && isset($request['payload']['properties']['MMIT Auto Move Result'])
        && isset($request['payload']['properties']['MMIT Onboarding Completed At']);
}));
smoke_assert(count($completionWrites) === 1, 'workstation completion fields are stamped', $failed);

$workstationComments = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static fn(array $request): bool => ($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === 'tickets/112489921/comment'));
smoke_assert(count($workstationComments) === 1, 'workstation move comments use internal ticket id', $failed);
smoke_assert(($workstation['ticket_comment']['ok'] ?? true) === false, 'workstation move remains successful when ticket comment fails', $failed);

$serverAsset = [
    'id' => 202,
    'name' => 'MANAGE-SRV-01',
    'policy_folder_id' => 502,
    'properties' => ['MMIT Ready Move Ticket ID' => 'System.Collections.Hashtable'],
];
$serverCandidate = [
    'asset_id' => 202,
    'asset_name' => 'MANAGE-SRV-01',
    'current_folder_id' => 502,
    'target_folder_id' => 602,
];
$server = syncro_auto_move_handle_server_ready_disabled(11, 22, $serverAsset, $serverCandidate);
smoke_assert(($server['ok'] ?? null) === true, 'server-disabled handling succeeds', $failed);
smoke_assert(($server['ticket_found'] ?? null) === true, 'server-disabled ticket found', $failed);
smoke_assert(($server['ticket_id'] ?? null) === 112489922, 'server-disabled internal ticket id extracted', $failed);
smoke_assert(($server['ticket_number'] ?? null) === 7002, 'server-disabled internal ticket id extracted', $failed);

$serverResultWrites = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static function (array $request): bool {
    return ($request['method'] ?? '') === 'PUT'
        && ($request['path'] ?? '') === 'customer_assets/202'
        && (($request['payload']['properties']['MMIT Auto Move Result'] ?? null) === 'Server READY but automatic server move is disabled.');
}));
smoke_assert(count($serverResultWrites) === 1, 'server-disabled auto move result is stamped', $failed);

$serverTicketWrites = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static function (array $request): bool {
    return ($request['method'] ?? '') === 'PUT'
        && ($request['path'] ?? '') === 'customer_assets/202'
        && (($request['payload']['properties']['MMIT Ready Move Ticket ID'] ?? null) === '7002');
}));
smoke_assert(count($serverTicketWrites) === 1, 'server-disabled non-numeric ready move ticket id is backfilled', $failed);

$serverComments = array_values(array_filter($GLOBALS['smoke_syncro_auto_move_requests'], static fn(array $request): bool => ($request['method'] ?? '') === 'POST' && ($request['path'] ?? '') === 'tickets/112489922/comment'));
smoke_assert(count($serverComments) === 1, 'server-disabled comments use internal ticket id', $failed);
smoke_assert(($server['ticket_comment']['ticket_comment_identifier_type'] ?? '') === 'ticket_id', 'ticket comment helper records internal ticket id identifier type', $failed);
smoke_assert(count($GLOBALS['smoke_syncro_auto_move_folder_move']) === 1, 'server-disabled does not move server folder', $failed);

if ($failed) {
    fwrite(STDERR, 'Syncro auto mover smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'Syncro auto mover smoke check passed.' . PHP_EOL;
