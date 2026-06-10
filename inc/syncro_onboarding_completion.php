<?php
declare(strict_types=1);

require_once __DIR__ . '/syncro_production_mover.php';

const MMIT_SYNCRO_FIELD_ASSET_ROLE = 'MMIT Asset Role';
const MMIT_SYNCRO_FIELD_BACKUP_REQUIRED = 'MMIT Backup Required';
const MMIT_SYNCRO_FIELD_DNS_REQUIRED = 'MMIT DNS Filtering Required';
const MMIT_SYNCRO_FIELD_ONBOARDING_RESULT = 'MMIT Onboarding Result';
const MMIT_SYNCRO_FIELD_CONTRACT_ID = 'MMIT Contract ID';
const MMIT_SYNCRO_FIELD_READY_MOVE_TICKET_ID = 'MMIT Ready Move Ticket ID';
const MMIT_SYNCRO_AUTO_MOVE_TICKET_SUBJECT_PREFIX = 'MMIT Auto Move Ready - ';
const MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE = 'MMIT_AUTO_MOVE_READY';

function syncro_onboarding_api_request(string $method, string $path, array $query = [], ?array $payload = null): array
{
    $handler = $GLOBALS['syncro_onboarding_api_request_handler'] ?? null;
    if (is_callable($handler)) {
        return (array)$handler(strtoupper($method), ltrim($path, '/'), $query, $payload);
    }
    return syncro_production_move_api_request($method, $path, $query, $payload);
}

function syncro_onboarding_now_utc(): string
{
    return gmdate('Y-m-d\TH:i:s\Z');
}

function syncro_onboarding_truthy(mixed $value): bool
{
    $text = mb_strtolower(syncro_production_move_normalize_text($value), 'UTF-8');
    return in_array($text, ['1', 'true', 'yes', 'y', 'pass', 'passed', 'ok', 'success'], true);
}

function syncro_onboarding_asset_in_deploy(array $asset): bool
{
    $folderId = syncro_production_move_asset_folder_id($asset);
    return in_array($folderId, [MMIT_SYNCRO_FOLDER_DEPLOY_WORKSTATIONS, MMIT_SYNCRO_FOLDER_DEPLOY_SERVERS], true);
}

function syncro_onboarding_asset_in_production(array $asset): bool
{
    $folderId = syncro_production_move_asset_folder_id($asset);
    return in_array($folderId, [MMIT_SYNCRO_FOLDER_PRODUCTION_WORKSTATIONS, MMIT_SYNCRO_FOLDER_PRODUCTION_SERVERS], true);
}

function syncro_onboarding_asset_role(array $asset): string
{
    $role = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_ASSET_ROLE);
    $key = syncro_production_move_normalize_key($role);
    if (in_array($key, ['server', 'servers'], true)) {
        return 'Server';
    }
    return 'Workstation';
}

function syncro_onboarding_contract_tier(array $contract, array $client): ?string
{
    foreach ([$contract, $client] as $row) {
        foreach (['syncro_policy_tier', 'policy_tier', 'service_tier', 'package_tier', 'service_package', 'package_code', 'package', 'msp_package', 'ops_package', 'sla_level', 'contract_name'] as $field) {
            if (array_key_exists($field, $row)) {
                $tier = syncro_normalize_policy_tier((string)$row[$field]);
                if ($tier !== null) {
                    return $tier;
                }
            }
        }
    }
    if ($client) {
        $resolved = syncro_resolve_client_policy_tier($client);
        if (!empty($resolved['ok'])) {
            return (string)$resolved['tier'];
        }
    }
    return null;
}

function syncro_onboarding_check_state(array $asset, string $checkName): string
{
    $fieldCandidates = [
        'MMIT ' . $checkName . ' Check',
        'MMIT ' . $checkName . ' Status',
        $checkName,
    ];
    foreach ($fieldCandidates as $field) {
        $value = syncro_production_move_custom_field($asset, $field);
        if ($value !== '') {
            return syncro_onboarding_truthy($value) ? 'PASS' : 'FAIL';
        }
    }
    $result = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_ONBOARDING_RESULT);
    if ($result !== '' && preg_match('/(^|\R)\s*' . preg_quote($checkName, '/') . '\s*:\s*(PASS|PASSED|OK|SUCCESS|FAIL|FAILED)/i', $result, $m)) {
        return syncro_onboarding_truthy($m[2]) ? 'PASS' : 'FAIL';
    }
    return 'FAIL';
}

function syncro_onboarding_required_checks(array $asset, array $client, array $contract = []): array
{
    $tier = syncro_onboarding_contract_tier($contract, $client);
    if ($tier === null) {
        return ['ok' => false, 'errors' => ['Unable to resolve OPS contract/package tier.'], 'checks' => []];
    }
    $role = syncro_onboarding_asset_role($asset);
    $opsClient = $client;
    $opsClient['services'] = (array)($contract['services'] ?? $client['services'] ?? []);
    $requirements = syncro_client_service_addon_requirements($opsClient, $tier, $role);
    $tier = syncro_normalize_policy_tier($tier) ?? 'manage';
    $server = syncro_production_move_normalize_key($role) === 'server';
    $backupRequired = !empty($requirements['backup_required']) || syncro_onboarding_truthy(syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_BACKUP_REQUIRED));
    $dnsRequired = !empty($requirements['dns_required']) || syncro_onboarding_truthy(syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_DNS_REQUIRED));

    $checks = [
        'Defender' => true,
        'ScoutDNS' => $dnsRequired || in_array($tier, ['protect', 'govern'], true),
        'Huntress' => in_array($tier, ['protect', 'govern'], true),
        'CoveAgent' => $server ? $backupRequired : ($backupRequired || in_array($tier, ['protect', 'govern'], true)),
        'CoveBackupComplete' => $server ? $backupRequired : ($backupRequired || in_array($tier, ['protect', 'govern'], true)),
    ];
    return ['ok' => true, 'tier' => $tier, 'asset_role' => $role, 'requirements' => $requirements, 'checks' => $checks];
}

function syncro_onboarding_validate_asset_ready(array $asset, array $client, array $contract = []): array
{
    $errors = [];
    if (!syncro_onboarding_asset_in_deploy($asset)) {
        $errors[] = 'Asset is not in a Deploy folder.';
    }
    $target = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_PRODUCTION_TARGET);
    $targetFolderId = syncro_production_move_target_folder_id($target);
    if ($targetFolderId === null) {
        $errors[] = MMIT_SYNCRO_FIELD_PRODUCTION_TARGET . ' must point at a known Production folder.';
    }
    $route = syncro_onboarding_required_checks($asset, $client, $contract);
    if (empty($route['ok'])) {
        $errors = array_merge($errors, (array)($route['errors'] ?? []));
    }
    $checkStates = [];
    foreach ((array)($route['checks'] ?? []) as $checkName => $required) {
        if (!$required) {
            $checkStates[$checkName] = 'SKIPPED';
            continue;
        }
        $state = syncro_onboarding_check_state($asset, (string)$checkName);
        $checkStates[$checkName] = $state;
        if ($state !== 'PASS') {
            $errors[] = (string)$checkName;
        }
    }
    return [
        'ok' => $errors === [],
        'errors' => array_values(array_unique($errors)),
        'check_states' => $checkStates,
        'route' => $route,
        'target_folder_id' => $targetFolderId,
        'target' => $target,
        'current_folder_id' => syncro_production_move_asset_folder_id($asset),
    ];
}

function syncro_onboarding_write_readiness_fields(int $assetId, array $validation): array
{
    $now = syncro_onboarding_now_utc();
    if (!empty($validation['ok'])) {
        $fields = [
            MMIT_SYNCRO_FIELD_ONBOARDING_STATUS => 'READY',
            MMIT_SYNCRO_FIELD_READY_TO_MOVE => 'Yes',
            MMIT_SYNCRO_FIELD_AUTO_MOVE_RESULT => 'Ready for production move at ' . $now,
            MMIT_SYNCRO_FIELD_ONBOARDING_RESULT => 'Ready for production move. Checks: ' . json_encode($validation['check_states'], JSON_UNESCAPED_SLASHES),
        ];
    } else {
        $fields = [
            MMIT_SYNCRO_FIELD_ONBOARDING_STATUS => 'NOT_READY',
            MMIT_SYNCRO_FIELD_READY_TO_MOVE => 'No',
            MMIT_SYNCRO_FIELD_ONBOARDING_RESULT => 'Missing onboarding checks: ' . implode(', ', (array)$validation['errors']) . '. Checks: ' . json_encode($validation['check_states'], JSON_UNESCAPED_SLASHES),
        ];
    }
    return syncro_onboarding_api_request('PUT', 'customer_assets/' . $assetId, [], ['properties' => $fields]);
}

function syncro_onboarding_open_statuses(): array
{
    return ['new', 'open', 'in progress', 'pending', 'waiting'];
}


function syncro_extract_ticket_id(mixed $response): int
{
    if ($response === null) {
        return 0;
    }
    if (is_int($response)) {
        return $response > 0 ? $response : 0;
    }
    if (is_string($response)) {
        $text = trim($response);
        return preg_match('/^\d+$/', $text) === 1 ? (int)$text : 0;
    }
    if (is_object($response)) {
        $response = get_object_vars($response);
    }
    if (!is_array($response)) {
        return 0;
    }

    foreach (['id', 'number', 'ticket_id', 'ticket_number'] as $key) {
        if (array_key_exists($key, $response)) {
            $ticketId = syncro_extract_ticket_id($response[$key]);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }

    foreach (['ticket', 'data', 'record', 'item', 'result'] as $key) {
        if (isset($response[$key])) {
            $ticketId = syncro_extract_ticket_id($response[$key]);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }

    if (array_is_list($response)) {
        foreach ($response as $item) {
            $ticketId = syncro_extract_ticket_id($item);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }

    return 0;
}

function syncro_onboarding_write_ready_move_ticket_id(int $assetId, int $ticketId): array
{
    if ($assetId <= 0 || $ticketId <= 0) {
        return ['ok' => true, 'skipped' => true, 'message' => 'No numeric ready move ticket ID available.'];
    }
    return syncro_onboarding_api_request('PUT', 'customer_assets/' . $assetId, [], [
        'properties' => [MMIT_SYNCRO_FIELD_READY_MOVE_TICKET_ID => (string)$ticketId],
    ]);
}

function syncro_onboarding_extract_ticket_list(array $response): array
{
    $data = $response['data'] ?? $response;
    foreach (['tickets', 'records', 'items', 'results', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return array_is_list($data[$key]) ? array_values(array_filter($data[$key], 'is_array')) : syncro_onboarding_extract_ticket_list(['data' => $data[$key]]);
        }
    }
    return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
}

function syncro_onboarding_ticket_is_open_auto_move(array $ticket, int $assetId, string $assetName = ''): bool
{
    $status = syncro_production_move_normalize_key($ticket['status'] ?? '');
    $subject = syncro_production_move_normalize_text($ticket['subject'] ?? $ticket['title'] ?? '');
    $body = (string)($ticket['body'] ?? $ticket['description'] ?? $ticket['notes'] ?? '');
    $tagText = mb_strtolower(json_encode($ticket, JSON_UNESCAPED_SLASHES) ?: '', 'UTF-8');
    if (!in_array($status, syncro_onboarding_open_statuses(), true)) {
        return false;
    }
    if (!str_starts_with($subject, MMIT_SYNCRO_AUTO_MOVE_TICKET_SUBJECT_PREFIX) && !str_contains($tagText, mb_strtolower(MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE, 'UTF-8'))) {
        return false;
    }
    if ($assetId > 0 && (str_contains($body, 'Syncro asset id: ' . $assetId) || str_contains($tagText, '"asset_id":' . $assetId) || str_contains($tagText, '"syncro_asset_id":' . $assetId))) {
        return true;
    }
    return $assetName !== '' && $subject === MMIT_SYNCRO_AUTO_MOVE_TICKET_SUBJECT_PREFIX . $assetName;
}

function syncro_onboarding_ticket_status_rank(array $ticket): int
{
    $status = syncro_production_move_normalize_key($ticket['status'] ?? '');
    return in_array($status, ['new', 'open', 'in progress', 'pending', 'waiting'], true) ? 0 : 1;
}

function syncro_onboarding_find_open_move_ticket(int $customerId, int $assetId, string $assetName): ?array
{
    $response = syncro_onboarding_api_request('GET', 'tickets', ['customer_id' => $customerId, 'asset_id' => $assetId, 'status' => 'open']);
    if (!empty($response['ok'])) {
        foreach (syncro_onboarding_extract_ticket_list($response) as $ticket) {
            if (syncro_onboarding_ticket_is_open_auto_move($ticket, $assetId, $assetName)) {
                return $ticket;
            }
        }
    }

    $fallback = syncro_onboarding_api_request('GET', 'tickets', ['customer_id' => $customerId]);
    if (empty($fallback['ok'])) {
        return null;
    }
    $matches = [];
    foreach (syncro_onboarding_extract_ticket_list($fallback) as $ticket) {
        if (syncro_onboarding_ticket_is_open_auto_move($ticket, $assetId, $assetName)) {
            $matches[] = $ticket;
        }
    }
    if ($matches === []) {
        return null;
    }
    usort($matches, static function (array $left, array $right): int {
        $rank = syncro_onboarding_ticket_status_rank($left) <=> syncro_onboarding_ticket_status_rank($right);
        if ($rank !== 0) {
            return $rank;
        }
        return syncro_extract_ticket_id($right) <=> syncro_extract_ticket_id($left);
    });
    return $matches[0];
}

function syncro_onboarding_move_ticket_body(array $asset, array $client, array $contract, array $validation): string
{
    $assetId = (int)($asset['id'] ?? 0);
    $assetName = syncro_production_move_asset_name($asset);
    $contractId = (int)($contract['contract_id'] ?? syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_CONTRACT_ID) ?: 0);
    $target = (int)($validation['target_folder_id'] ?? 0);
    $source = (int)($validation['current_folder_id'] ?? 0);
    $policyKey = ((string)(($validation['route']['tier'] ?? 'unknown'))) . '.' . (syncro_onboarding_asset_role($asset) === 'Server' ? 'production.servers' : 'production.workstations');
    return implode("\n", [
        'MMIT Auto Move Ready ticket type: ' . MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE,
        'OPS client id: ' . (int)($client['client_id'] ?? 0),
        'Syncro customer id: ' . (int)($client['syncro_customer_id'] ?? $client['customer_id'] ?? 0),
        'Syncro asset id: ' . $assetId,
        'Asset name: ' . $assetName,
        'Asset role: ' . syncro_onboarding_asset_role($asset),
        'Source Deploy folder: ' . $source,
        'Target Production folder: ' . $target,
        'Expected policy key/id: ' . $policyKey . ' / ' . $target,
        'Validation summary: ' . json_encode($validation['check_states'] ?? [], JSON_UNESCAPED_SLASHES),
        'Contract id: ' . ($contractId > 0 ? (string)$contractId : 'not available'),
    ]);
}

function syncro_onboarding_create_move_ticket(int $customerId, array $asset, array $client, array $contract, array $validation): array
{
    $assetId = (int)($asset['id'] ?? 0);
    $assetName = syncro_production_move_asset_name($asset);
    $existing = syncro_onboarding_find_open_move_ticket($customerId, $assetId, $assetName);
    if ($existing) {
        $ticketId = syncro_extract_ticket_id($existing);
        return ['ok' => true, 'duplicate' => true, 'ticket' => $existing, 'ticket_id' => $ticketId, 'ticket_id_write' => syncro_onboarding_write_ready_move_ticket_id($assetId, $ticketId)];
    }
    $subject = MMIT_SYNCRO_AUTO_MOVE_TICKET_SUBJECT_PREFIX . $assetName;
    $body = syncro_onboarding_move_ticket_body($asset, $client, $contract, $validation);
    $payload = ['ticket' => [
        'customer_id' => $customerId,
        'subject' => $subject,
        'body' => $body,
        'status' => 'New',
        'issue_type' => 'Onboarding',
        'problem_type' => MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE,
        'tag_list' => [MMIT_SYNCRO_AUTO_MOVE_TICKET_TYPE, 'mmit-auto-remediation'],
    ]];
    $response = syncro_onboarding_api_request('POST', 'tickets', [], $payload);
    if (empty($response['ok'])) {
        return ['ok' => false, 'errors' => [syncro_production_move_response_errors($response, 'Move ticket creation failed.')], 'response' => $response];
    }
    $data = $response['data']['ticket'] ?? $response['data'] ?? [];
    $ticketId = syncro_extract_ticket_id($response);
    $ticketIdWrite = syncro_onboarding_write_ready_move_ticket_id($assetId, $ticketId);
    return ['ok' => true, 'ticket' => is_array($data) ? $data : [], 'ticket_id' => $ticketId, 'ticket_id_write' => $ticketIdWrite, 'response' => $response];
}

function syncro_onboarding_evaluate_asset_completion(int $customerId, array $asset, array $client, array $contract = [], bool $write = true): array
{
    $assetId = (int)($asset['id'] ?? 0);
    $validation = syncro_onboarding_validate_asset_ready($asset, $client, $contract);
    if (!syncro_onboarding_asset_in_deploy($asset)) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'not_deploy', 'validation' => $validation];
    }
    $writeResult = $write ? syncro_onboarding_write_readiness_fields($assetId, $validation) : ['ok' => true, 'skipped' => true];
    if (empty($validation['ok'])) {
        return ['ok' => false, 'ready' => false, 'validation' => $validation, 'write' => $writeResult, 'message' => 'Asset is not ready: ' . implode(', ', (array)$validation['errors'])];
    }
    $ticket = $write ? syncro_onboarding_create_move_ticket($customerId, $asset, $client, $contract, $validation) : ['ok' => true, 'skipped' => true];
    return ['ok' => !empty($ticket['ok']), 'ready' => true, 'validation' => $validation, 'write' => $writeResult, 'ticket' => $ticket];
}

function syncro_onboarding_alert_keith(string $subject, string $body, array $context = []): void
{
    $handler = $GLOBALS['syncro_onboarding_alert_handler'] ?? null;
    if (is_callable($handler)) {
        $handler($subject, $body, $context);
        return;
    }
    error_log('[mmit-onboarding] ' . $subject . ' ' . json_encode($context, JSON_UNESCAPED_SLASHES));
}

function syncro_onboarding_move_ready_asset(int $customerId, int $assetId, ?int $ticketId = null, ?array $assetOverride = null): array
{
    $asset = $assetOverride;
    if ($asset === null) {
        $fetch = syncro_production_move_fetch_asset($customerId, $assetId);
        if (empty($fetch['ok'])) {
            syncro_onboarding_alert_keith('MMIT auto move manual review', 'Unable to fetch ready asset.', ['asset_id' => $assetId, 'errors' => $fetch['errors'] ?? []]);
            return ['ok' => false, 'errors' => $fetch['errors'] ?? ['Unable to fetch asset.']];
        }
        $asset = (array)$fetch['asset'];
    }
    $assetName = syncro_production_move_asset_name($asset);
    $ticket = $ticketId && $ticketId > 0 ? ['id' => $ticketId] : syncro_onboarding_find_open_move_ticket($customerId, $assetId, $assetName);
    if (!$ticket || (int)($ticket['id'] ?? $ticket['number'] ?? 0) <= 0) {
        $message = 'Mover refused asset without an open MMIT Auto Move Ready ticket.';
        syncro_onboarding_alert_keith('MMIT auto move manual review', $message, ['asset_id' => $assetId]);
        return ['ok' => false, 'message' => $message, 'errors' => [$message]];
    }
    $ticketId = (int)($ticket['id'] ?? $ticket['number']);
    $result = syncro_production_move_asset($customerId, $assetId, $ticketId, false, true, $asset);
    if (!empty($result['ok'])) {
        syncro_onboarding_alert_keith('MMIT asset moved to Production', 'Asset moved to Production.', ['asset_id' => $assetId, 'ticket_id' => $ticketId, 'asset_name' => $assetName]);
        return $result;
    }
    syncro_production_move_write_result($assetId, 'Production move failed at ' . syncro_onboarding_now_utc() . ': ' . syncro_production_move_mask_secrets((string)($result['message'] ?? implode(' ', (array)($result['errors'] ?? [])))), false);
    syncro_onboarding_alert_keith('MMIT auto move failure', 'Asset production move failed/manual review required.', ['asset_id' => $assetId, 'ticket_id' => $ticketId, 'result' => $result]);
    return $result;
}

function syncro_onboarding_contract_billing_ready(array $contract): bool
{
    if (array_key_exists('billing_profile_ready', $contract)) {
        return (bool)$contract['billing_profile_ready'];
    }
    if (array_key_exists('invoice_autogen_enabled', $contract)) {
        return (bool)$contract['invoice_autogen_enabled'];
    }
    return true;
}

function syncro_onboarding_contract_expected_assets(array $contract, array $assets): array
{
    if (!empty($contract['expected_assets']) && is_array($contract['expected_assets'])) {
        $expected = [];
        foreach ($contract['expected_assets'] as $expectedAsset) {
            if (is_array($expectedAsset)) {
                $expected[] = $expectedAsset;
            }
        }
        return $expected;
    }
    return $assets;
}

function syncro_onboarding_contract_completion_status(array $contract, array $assets, array $openTickets = []): array
{
    $expected = syncro_onboarding_contract_expected_assets($contract, $assets);
    $incomplete = [];
    $notCompleted = [];
    $notInProduction = [];
    foreach ($expected as $expectedAsset) {
        $assetId = (int)($expectedAsset['id'] ?? $expectedAsset['asset_id'] ?? 0);
        $asset = $expectedAsset;
        foreach ($assets as $candidate) {
            if ((int)($candidate['id'] ?? 0) === $assetId) {
                $asset = $candidate;
                break;
            }
        }
        $assetLabel = $assetId > 0 ? (string)$assetId : syncro_production_move_asset_name($asset);
        $status = syncro_production_move_custom_field($asset, MMIT_SYNCRO_FIELD_ONBOARDING_STATUS);
        $isCompleted = mb_strtoupper($status, 'UTF-8') === 'COMPLETED';
        $isInProduction = syncro_onboarding_asset_in_production($asset);
        if (!$isCompleted) {
            $notCompleted[] = $assetLabel;
        }
        if (!$isInProduction) {
            $notInProduction[] = $assetLabel;
        }
        if (!$isCompleted || !$isInProduction) {
            $incomplete[] = $assetLabel;
        }
    }
    $contractStatus = strtoupper((string)($contract['status'] ?? ''));
    $eligibleStatus = in_array($contractStatus, ['SIGNED_PENDING_ONBOARDING', 'ONBOARDING'], true);
    $billingReadinessAvailable = array_key_exists('billing_profile_ready', $contract) || array_key_exists('invoice_autogen_enabled', $contract);
    $billingProfileReady = syncro_onboarding_contract_billing_ready($contract);
    $readyForManualActivation = $eligibleStatus && $incomplete === [] && $openTickets === [] && $billingProfileReady;
    return [
        'ok' => $readyForManualActivation,
        'ready_for_manual_activation' => $readyForManualActivation,
        'eligible_status' => $eligibleStatus,
        'incomplete_assets' => $incomplete,
        'not_completed_assets' => $notCompleted,
        'not_in_production_assets' => $notInProduction,
        'all_expected_assets_completed' => $notCompleted === [],
        'all_expected_assets_in_production' => $notInProduction === [],
        'open_move_tickets' => $openTickets,
        'no_open_auto_move_tickets' => $openTickets === [],
        'billing_profile_ready' => $billingProfileReady,
        'billing_readiness_available' => $billingReadinessAvailable,
        'billing_readiness' => [
            'available' => $billingReadinessAvailable,
            'ready' => $billingProfileReady,
        ],
    ];
}

function syncro_onboarding_activate_contract_if_complete(array $contract, array $assets, array $openTickets = [], int $userId = 0): array
{
    $status = syncro_onboarding_contract_completion_status($contract, $assets, $openTickets);
    if (empty($status['ready_for_manual_activation'])) {
        return ['ok' => true, 'activated' => false, 'ready_for_manual_activation' => false, 'status' => $status];
    }
    $contractId = (int)($contract['contract_id'] ?? 0);
    $message = 'All onboarding checks complete. Contract is ready for manual go-live.';
    $eventHandler = $GLOBALS['syncro_onboarding_contract_event_handler'] ?? null;
    if (is_callable($eventHandler)) {
        $eventHandler($contractId, $message, $contract, $status);
    } else {
        error_log('[mmit-onboarding] ' . $message . ' contract_id=' . $contractId);
    }
    syncro_onboarding_alert_keith('MMIT contract ready for manual go-live', $message, ['contract_id' => $contractId, 'status' => $status]);
    return ['ok' => true, 'activated' => false, 'ready_for_manual_activation' => true, 'message' => $message, 'status' => $status];
}
