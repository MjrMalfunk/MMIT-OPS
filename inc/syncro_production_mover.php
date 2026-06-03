<?php
declare(strict_types=1);

require_once __DIR__ . '/syncro.php';

const MMIT_SYNCRO_FOLDER_COMPANY_ROOT = 5027844;
const MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS = 5027864;
const MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS = 5027865;
const MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS = 5027867;
const MMIT_SYNCRO_FOLDER_DEPLOY_SERVERS = 5027868;

const MMIT_SYNCRO_FIELD_ONBOARDING_STATUS = 'MMIT Onboarding Status';
const MMIT_SYNCRO_FIELD_READY_TO_MOVE = 'MMIT Ready To Move';
const MMIT_SYNCRO_FIELD_PRODUCTION_TARGET = 'MMIT Production Folder Target';
const MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT = 'MMIT Auto Move Result';
const MMIT_SYNCRO_FIELD_COMPLETED_AT = 'MMIT Onboarding Completed At';

function syncro_production_folder_allowlist(): array
{
    return [
        'Company root/top' => MMIT_SYNCRO_FOLDER_COMPANY_ROOT,
        'Production/Workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'Production/Servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'Deploy/Workstations' => MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS,
        'Deploy/Servers' => MMIT_SYNCRO_FOLDER_DEPLOY_SERVERS,
    ];
}

function syncro_production_move_target_map(): array
{
    return [
        'production/workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'production workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'workstations' => MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS,
        'production/servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'production servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
        'servers' => MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS,
    ];
}

function syncro_production_move_normalize_text(mixed $value): string
{
    $text = trim((string)$value);
    $text = preg_replace('~\\s*/\\s*~', '/', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function syncro_production_move_normalize_key(mixed $value): string
{
    return mb_strtolower(syncro_production_move_normalize_text($value), 'UTF-8');
}

function syncro_production_move_target_folder_id(mixed $target): ?int
{
    $key = syncro_production_move_normalize_key($target);
    if ($key === '') {
        return null;
    }
    $map = syncro_production_move_target_map();
    return $map[$key] ?? null;
}

function syncro_production_move_mask_secrets(string $message): string
{
    $message = preg_replace('/(api[_-]?key=)[^\s&]+/i', '$1[redacted]', $message) ?? $message;
    $message = preg_replace('/(authorization\s*[:=]\s*)(bearer\s+)?[^\s,;]+/i', '$1[redacted]', $message) ?? $message;
    if (defined('SYNCRO_API_KEY') && trim((string)SYNCRO_API_KEY) !== '') {
        $message = str_replace((string)SYNCRO_API_KEY, '[redacted]', $message);
    }
    return trim($message);
}

function syncro_production_move_response_errors(array $response, string $fallback): string
{
    $errors = array_filter(array_map(static fn($v): string => trim((string)$v), (array)($response['errors'] ?? [])));
    $message = $errors ? implode(' ', $errors) : $fallback;
    if (!empty($response['status'])) {
        $message .= ' (HTTP ' . (int)$response['status'] . ')';
    }
    return syncro_production_move_mask_secrets($message);
}

function syncro_production_move_now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function syncro_production_move_extract_asset(array $response): array
{
    $data = $response['data'] ?? $response;
    if (isset($data['asset']) && is_array($data['asset'])) {
        return $data['asset'];
    }
    if (isset($data['customer_asset']) && is_array($data['customer_asset'])) {
        return $data['customer_asset'];
    }
    if (isset($data['data']) && is_array($data['data'])) {
        return syncro_production_move_extract_asset(['data' => $data['data']]);
    }
    return is_array($data) ? $data : [];
}

function syncro_production_move_asset_list(array $response): array
{
    $data = $response['data'] ?? $response;
    foreach (['assets', 'customer_assets', 'records', 'items', 'results', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            if (array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], static fn($row): bool => is_array($row)));
            }
            if (isset($data[$key]['assets']) || isset($data[$key]['customer_assets'])) {
                return syncro_production_move_asset_list(['data' => $data[$key]]);
            }
        }
    }
    if (array_is_list($data)) {
        return array_values(array_filter($data, static fn($row): bool => is_array($row)));
    }
    return [];
}

function syncro_production_move_extract_custom_fields(array $asset): array
{
    $fields = [];
    $sources = [];
    foreach (['custom_fields', 'properties', 'asset_properties', 'fields'] as $key) {
        if (isset($asset[$key]) && is_array($asset[$key])) {
            $sources[] = $asset[$key];
        }
    }

    foreach ($sources as $source) {
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                if (is_array($value) && array_key_exists('value', $value)) {
                    $fields[$key] = $value['value'];
                } else {
                    $fields[$key] = $value;
                }
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $name = (string)($value['name'] ?? $value['field_name'] ?? $value['label'] ?? '');
            if ($name === '') {
                continue;
            }
            $fields[$name] = $value['value'] ?? $value['field_value'] ?? $value['content'] ?? '';
        }
    }
    return $fields;
}

function syncro_production_move_custom_field(array $asset, string $name): string
{
    $target = syncro_normalize_match_text($name);
    foreach (syncro_production_move_extract_custom_fields($asset) as $key => $value) {
        if (syncro_normalize_match_text((string)$key) === $target) {
            return syncro_production_move_normalize_text($value);
        }
    }
    return '';
}

function syncro_production_move_asset_folder_id(array $asset): ?int
{
    foreach (['policy_folder_id', 'policyFolderId', 'policy_folder', 'folder_id'] as $key) {
        if (isset($asset[$key]) && is_numeric($asset[$key])) {
            return (int)$asset[$key];
        }
    }
    foreach (['policy_folder', 'folder'] as $key) {
        if (isset($asset[$key]) && is_array($asset[$key]) && isset($asset[$key]['id']) && is_numeric($asset[$key]['id'])) {
            return (int)$asset[$key]['id'];
        }
    }
    return null;
}

function syncro_production_move_asset_name(array $asset): string
{
    foreach (['name', 'asset_name', 'hostname', 'computer_name', 'display_name'] as $key) {
        $value = trim((string)($asset[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return 'Asset #' . (string)($asset['id'] ?? 'unknown');
}

function syncro_production_move_validate_asset(array $asset): array
{
    $status = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_ONBOARDING_STATUS);
    $ready = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_READY_TO_MOVE);
    $target = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_PRODUCTION_TARGET);
    $targetFolderId = syncro_production_move_target_folder_id($target);
    $errors = [];

    if (mb_strtoupper($status, 'UTF-8') !== 'READY') {
        $errors[] = MMIT_SYNCRO_FIELD_ONBOARDING_STATUS . ' must be READY.';
    }
    if (mb_strtolower($ready, 'UTF-8') !== 'yes') {
        $errors[] = MMIT_SYNCRO_FIELD_READY_TO_MOVE . ' must be Yes.';
    }
    if ($target === '') {
        $errors[] = MMIT_SYNCRO_FIELD_PRODUCTION_TARGET . ' is missing.';
    } elseif ($targetFolderId === null) {
        $errors[] = MMIT_SYNCRO_FIELD_PRODUCTION_TARGET . ' must be Production/Workstations or Production/Servers.';
    }

    return [
        'ok' => !$errors,
        'errors' => $errors,
        'status' => $status,
        'ready_to_move' => $ready,
        'target' => $target,
        'target_folder_id' => $targetFolderId,
        'current_folder_id' => syncro_production_move_asset_folder_id($asset),
    ];
}

function syncro_production_move_fetch_asset(int $customerId, int $assetId): array
{
    if ($customerId <= 0 || $assetId <= 0) {
        return ['ok' => false, 'errors' => ['Customer ID and asset ID are required.']];
    }

    $response = syncro_api_request('GET', 'customer_assets/' . $assetId, ['customer_id' => $customerId]);
    if (!empty($response['ok'])) {
        $asset = syncro_production_move_extract_asset($response);
        if ($asset) {
            return ['ok' => true, 'asset' => $asset, 'response' => $response];
        }
    }

    return [
        'ok' => false,
        'errors' => [syncro_production_move_response_errors($response, 'Unable to fetch Syncro asset.')],
        'response' => $response,
    ];
}

function syncro_production_move_find_ready_assets(int $customerId): array
{
    if ($customerId <= 0) {
        return ['ok' => false, 'errors' => ['Customer ID is required to find ready assets.'], 'assets' => []];
    }
    $response = syncro_api_request('GET', 'customer_assets', ['customer_id' => $customerId]);
    if (empty($response['ok'])) {
        return ['ok' => false, 'errors' => [syncro_production_move_response_errors($response, 'Unable to list Syncro assets.')], 'assets' => []];
    }

    $ready = [];
    foreach (syncro_production_move_asset_list($response) as $asset) {
        $validation = syncro_production_move_validate_asset($asset);
        if (!empty($validation['ok'])) {
            $ready[] = ['asset' => $asset, 'validation' => $validation];
        }
    }
    return ['ok' => true, 'assets' => $ready];
}

function syncro_production_move_policy_assignment_payload(int $assetId, int $targetFolderId): array
{
    return [
        'changes' => [
            'add_folder' => [],
            'update_folder' => [],
            'remove_folder' => [],
            'update_asset' => [
                [
                    'id' => $assetId,
                    'change' => [
                        'policy_folder_id' => $targetFolderId,
                    ],
                ],
            ],
        ],
    ];
}

function syncro_production_move_update_asset_fields(int $assetId, array $fields): array
{
    if ($assetId <= 0) {
        return ['ok' => false, 'errors' => ['Asset ID is required to write Syncro fields.']];
    }
    $fields = array_filter($fields, static fn($value): bool => $value !== null);
    if (!$fields) {
        return ['ok' => true, 'skipped' => true];
    }

    $payload = ['properties' => $fields];
    return syncro_api_request('PUT', 'customer_assets/' . $assetId, [], $payload);
}

function syncro_production_move_write_result(int $assetId, string $message, bool $completed): array
{
    $fields = [MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT => syncro_production_move_mask_secrets($message)];
    if ($completed) {
        $fields[MMIT_SYNCRO_FIELD_COMPLETED_AT] = syncro_production_move_now_utc();
    }
    return syncro_production_move_update_asset_fields($assetId, $fields);
}

function syncro_production_move_update_ticket(?int $ticketId, string $message, bool $closeTicket): array
{
    if ($ticketId === null || $ticketId <= 0) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No ready ticket ID supplied.'];
    }

    $comment = syncro_api_request('POST', 'tickets/' . $ticketId . '/comments', [], [
        'comment' => [
            'body' => syncro_production_move_mask_secrets($message),
            'hidden' => false,
            'do_not_email' => true,
        ],
    ]);
    if ($closeTicket && !empty($comment['ok'])) {
        $close = syncro_api_request('PUT', 'tickets/' . $ticketId, [], ['ticket' => ['status' => 'Resolved']]);
        if (empty($close['ok'])) {
            return ['ok' => false, 'errors' => [syncro_production_move_response_errors($close, 'Move succeeded, but ticket close failed.')], 'comment' => $comment, 'close' => $close];
        }
        return ['ok' => true, 'comment' => $comment, 'close' => $close];
    }
    return !empty($comment['ok']) ? ['ok' => true, 'comment' => $comment] : ['ok' => false, 'errors' => [syncro_production_move_response_errors($comment, 'Move result ticket comment failed.')], 'comment' => $comment];
}

function syncro_production_move_asset(int $customerId, int $assetId, ?int $readyTicketId = null, bool $dryRun = true, bool $closeTicket = false, ?array $assetOverride = null): array
{
    $asset = $assetOverride;
    $fetch = null;
    if ($asset === null) {
        $fetch = syncro_production_move_fetch_asset($customerId, $assetId);
        if (empty($fetch['ok'])) {
            return ['ok' => false, 'dry_run' => $dryRun, 'errors' => $fetch['errors'] ?? ['Unable to fetch asset.'], 'fetch' => $fetch];
        }
        $asset = (array)$fetch['asset'];
    }

    $assetName = syncro_production_move_asset_name($asset);
    $validation = syncro_production_move_validate_asset($asset);
    if (empty($validation['ok'])) {
        $message = 'Move failed validation for ' . $assetName . ': ' . implode(' ', $validation['errors']);
        if (!$dryRun) {
            syncro_production_move_write_result($assetId, $message, false);
        }
        return ['ok' => false, 'dry_run' => $dryRun, 'asset' => $asset, 'validation' => $validation, 'errors' => $validation['errors'], 'message' => $message];
    }

    $targetFolderId = (int)$validation['target_folder_id'];
    $currentFolderId = $validation['current_folder_id'];
    if ($currentFolderId !== null && (int)$currentFolderId === $targetFolderId) {
        $message = 'Already in target folder.';
        if (!$dryRun) {
            $write = syncro_production_move_write_result($assetId, $message, true);
            $ticket = syncro_production_move_update_ticket($readyTicketId, $message, $closeTicket);
            $warnings = [];
            if (empty($write['ok'])) {
                $warnings[] = syncro_production_move_response_errors($write, 'Already-in-target result write-back failed.');
            }
            if (empty($ticket['ok'])) {
                $warnings[] = implode(' ', (array)($ticket['errors'] ?? ['Already-in-target ticket update failed.']));
            }
            return ['ok' => true, 'dry_run' => false, 'noop' => true, 'message' => $message, 'validation' => $validation, 'write' => $write, 'ticket' => $ticket, 'warnings' => $warnings];
        }
        return ['ok' => true, 'dry_run' => true, 'noop' => true, 'message' => $message, 'validation' => $validation];
    }

    $payload = syncro_production_move_policy_assignment_payload($assetId, $targetFolderId);
    $message = 'Ready to move ' . $assetName . ' to ' . $validation['target'] . ' (#' . $targetFolderId . ').';
    if ($dryRun) {
        return ['ok' => true, 'dry_run' => true, 'message' => $message, 'validation' => $validation, 'payload' => $payload, 'asset' => $asset];
    }

    $move = syncro_api_request('PATCH', 'customers/' . $customerId . '/policy_assignments', [], $payload);
    if (empty($move['ok'])) {
        $failure = 'Move failed for ' . $assetName . ': ' . syncro_production_move_response_errors($move, 'Syncro policy assignment move failed.');
        $write = syncro_production_move_write_result($assetId, $failure, false);
        return ['ok' => false, 'dry_run' => false, 'message' => $failure, 'validation' => $validation, 'payload' => $payload, 'move' => $move, 'write' => $write, 'errors' => [$failure]];
    }

    $success = 'Moved to target folder ' . $validation['target'] . ' (#' . $targetFolderId . ').';
    $write = syncro_production_move_write_result($assetId, $success, true);
    $ticket = syncro_production_move_update_ticket($readyTicketId, $success, $closeTicket);
    $warnings = [];
    if (empty($write['ok'])) {
        $warnings[] = syncro_production_move_response_errors($write, 'Move succeeded, but result write-back failed.');
    }
    if (empty($ticket['ok'])) {
        $warnings[] = implode(' ', (array)($ticket['errors'] ?? ['Move succeeded, but ticket update failed.']));
    }
    return ['ok' => true, 'dry_run' => false, 'message' => $success, 'validation' => $validation, 'payload' => $payload, 'move' => $move, 'write' => $write, 'ticket' => $ticket, 'warnings' => $warnings];
}
