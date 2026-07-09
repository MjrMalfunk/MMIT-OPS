<?php
declare(strict_types=1);

// OPS-hosted Syncro auto mover for READY assets. Dry-run is the default.
// Apply mode requires --apply and still honors the central Syncro staging guard.
// DELETE calls are never used by this runner.
//
// Suggested cron entries (do not enable automatically):
// Dry-run:
// */5 * * * * cd /home/mjrmstlj/ops-test.midwestmanagedit.com && php scripts/syncro_auto_move_ready_assets_cron.php >> storage/logs/syncro_auto_move_ready_assets_cron.log 2>&1
//
// Apply:
// */5 * * * * cd /home/mjrmstlj/ops-test.midwestmanagedit.com && php scripts/syncro_auto_move_ready_assets_cron.php --apply >> storage/logs/syncro_auto_move_ready_assets_cron.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function syncro_auto_move_usage(): string
{
    return "Usage: php scripts/syncro_auto_move_ready_assets_cron.php [--host=ops.example.com] [--client-id=123] [--syncro-customer-id=456] [--limit=25] [--apply]\n\n"
        . "Scans active OPS clients with Syncro customer IDs, READY provisioned Syncro folder maps, and READY assets in each client's Deploy folders.\n"
        . "Default mode is dry-run. Apply mode performs PUT /api/v1/customer_assets/{asset_id}; no DELETE calls are used.\n";
}

function syncro_auto_move_option_value(array $options, string $name): ?string
{
    if (!array_key_exists($name, $options)) {
        return null;
    }
    $value = $options[$name];
    if (is_array($value)) {
        $value = end($value);
    }
    if ($value === false || $value === '') {
        return '';
    }
    return (string)$value;
}

function syncro_auto_move_option_int(array $options, string $name): int
{
    $value = syncro_auto_move_option_value($options, $name);
    return $value === null ? 0 : (int)$value;
}

function syncro_auto_move_normalize_host(string $host): string
{
    $host = strtolower(trim($host));
    $host = preg_replace('/^https?:\/\//', '', $host) ?? $host;
    $host = preg_replace('#[\\\\/].*$#', '', $host) ?? $host;
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return trim($host, '.');
}

function syncro_auto_move_host_looks_valid(string $host): bool
{
    if ($host === '' || strlen($host) > 253 || !str_contains($host, '.')) {
        return false;
    }
    return (bool)preg_match('/^(?=.{1,253}$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);
}

function syncro_auto_move_infer_host_from_app_root(): string
{
    $host = syncro_auto_move_normalize_host(basename(dirname(__DIR__)));
    return syncro_auto_move_host_looks_valid($host) ? $host : '';
}

function syncro_auto_move_resolve_cli_host(array $options): string
{
    $explicitHost = syncro_auto_move_option_value($options, 'host');
    if ($explicitHost !== null) {
        $host = syncro_auto_move_normalize_host($explicitHost);
        return syncro_auto_move_host_looks_valid($host) ? $host : '';
    }

    $envHost = getenv('MMIT_CLI_HTTP_HOST');
    if (is_string($envHost) && syncro_auto_move_host_looks_valid(syncro_auto_move_normalize_host($envHost))) {
        return syncro_auto_move_normalize_host($envHost);
    }

    $inferredHost = syncro_auto_move_infer_host_from_app_root();
    if ($inferredHost !== '') {
        return $inferredHost;
    }

    $existingHost = syncro_auto_move_normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    return syncro_auto_move_host_looks_valid($existingHost) ? $existingHost : '';
}

function syncro_auto_move_bootstrap_cli_server(array $options): void
{
    $host = syncro_auto_move_resolve_cli_host($options);
    if ($host === '') {
        fwrite(STDERR, "Unable to resolve an HTTP host for CLI bootstrap. Provide --host=ops.example.com or set MMIT_CLI_HTTP_HOST; alternatively run from an app root directory named like a hostname.\n" . syncro_auto_move_usage());
        exit(1);
    }

    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['SERVER_NAME'] = $host;
    $_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
}

function syncro_auto_move_log_path(): string
{
    $override = getenv('SYNCRO_AUTO_MOVE_READY_ASSETS_LOG_PATH');
    if (is_string($override) && trim($override) !== '') {
        return $override;
    }
    return dirname(__DIR__) . '/storage/logs/syncro_auto_move_ready_assets_cron.log';
}

function syncro_auto_move_log(string $message, array $context = []): void
{
    $line = '[' . date('c') . '] ' . $message;
    if ($context) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($json) && $json !== '') {
            $line .= ' ' . $json;
        }
    }
    echo $line . PHP_EOL;

    $path = syncro_auto_move_log_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function syncro_auto_move_lock_path(): string
{
    $override = getenv('SYNCRO_AUTO_MOVE_READY_ASSETS_LOCK_PATH');
    if (is_string($override) && trim($override) !== '') {
        return $override;
    }
    return dirname(__DIR__) . '/storage/locks/syncro_auto_move_ready_assets_cron.lock';
}

function syncro_auto_move_lock_stale_seconds(): int
{
    $override = getenv('SYNCRO_AUTO_MOVE_READY_ASSETS_LOCK_STALE_SECONDS');
    if (is_string($override) && trim($override) !== '' && (int)$override > 0) {
        return max(60, (int)$override);
    }
    return 3600;
}

function syncro_auto_move_acquire_lock(?string $path = null, ?int $staleSeconds = null): array
{
    $path = $path ?: syncro_auto_move_lock_path();
    $staleSeconds = $staleSeconds ?: syncro_auto_move_lock_stale_seconds();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => false, 'status' => 'LOCK_DIR_FAILED', 'message' => 'Unable to create lock directory: ' . $dir];
    }

    $existingMtime = is_file($path) ? (int)@filemtime($path) : 0;
    $existingAge = $existingMtime > 0 ? (time() - $existingMtime) : null;
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        return ['ok' => false, 'status' => 'LOCK_OPEN_FAILED', 'message' => 'Unable to open lock file: ' . $path];
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        $ageText = $existingAge === null ? 'unknown age' : ((string)$existingAge . 's old');
        return ['ok' => false, 'status' => 'LOCK_HELD', 'message' => 'Another Syncro auto-move cron run is already active; lock file is ' . $ageText . ': ' . $path];
    }

    $stale = $existingAge !== null && $existingAge > $staleSeconds;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode([
        'pid' => getmypid(),
        'started_at' => date('c'),
        'host' => $_SERVER['HTTP_HOST'] ?? null,
        'stale_lock_replaced' => $stale,
    ], JSON_UNESCAPED_SLASHES) ?: '');
    fflush($handle);

    return ['ok' => true, 'status' => $stale ? 'STALE_LOCK_REPLACED' : 'LOCK_ACQUIRED', 'path' => $path, 'handle' => $handle, 'stale' => $stale];
}

function syncro_auto_move_release_lock(array $lock): void
{
    $handle = $lock['handle'] ?? null;
    if (is_resource($handle)) {
        ftruncate($handle, 0);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
    }
    $path = (string)($lock['path'] ?? '');
    if ($path !== '' && is_file($path)) {
        @unlink($path);
    }
}

function syncro_auto_move_empty_totals(): array
{
    return [
        'clients_scanned' => 0,
        'assets_scanned' => 0,
        'workstation_candidates' => 0,
        'workstation_ticket_blocked' => 0,
        'workstation_finalize_candidates' => 0,
        'workstation_finalized' => 0,
        'workstation_moved' => 0,
        'servers_ready_disabled' => 0,
        'skipped' => 0,
        'failures' => 0,
    ];
}

function syncro_auto_move_merge_summary(array &$totals, array $summary): void
{
    foreach ($totals as $key => $_) {
        $totals[$key] += (int)($summary[$key] ?? 0);
    }
}

function syncro_auto_move_client_label(array $client): string
{
    $name = trim((string)($client['legal_name'] ?? $client['dba_name'] ?? $client['name'] ?? ''));
    $id = (int)($client['client_id'] ?? 0);
    return ($name !== '' ? $name : 'Client') . ($id > 0 ? ' (#' . $id . ')' : '');
}

function syncro_auto_move_active_clients(?int $clientId = null, ?int $syncroCustomerId = null, int $limit = 0): array
{
    if (!empty($GLOBALS['syncro_auto_move_clients_mock']) && is_callable($GLOBALS['syncro_auto_move_clients_mock'])) {
        $clients = (array)call_user_func($GLOBALS['syncro_auto_move_clients_mock'], $clientId, $syncroCustomerId, $limit);
        return array_values(array_filter($clients, static fn($client): bool => is_array($client)));
    }

    $folderMapReadySql = "fm.client_id IS NOT NULL
        AND COALESCE(fm.deploy_workstations_folder_id, 0) > 0
        AND COALESCE(fm.deploy_servers_folder_id, 0) > 0
        AND COALESCE(fm.production_workstations_folder_id, 0) > 0
        AND COALESCE(fm.production_servers_folder_id, 0) > 0
        AND UPPER(COALESCE(fm.provision_status, '')) = 'READY'";

    $params = [];
    $whereParts = [];
    if ($clientId !== null && $clientId > 0) {
        $whereParts[] = 'c.client_id = ?';
        $params[] = $clientId;
    } else {
        $whereParts[] = "UPPER(COALESCE(c.status, '')) = 'ACTIVE'";
    }
    if ($syncroCustomerId !== null && $syncroCustomerId > 0) {
        $whereParts[] = 'COALESCE(c.syncro_customer_id, fm.syncro_customer_id, 0) = ?';
        $params[] = $syncroCustomerId;
    } else {
        $whereParts[] = 'COALESCE(c.syncro_customer_id, fm.syncro_customer_id, 0) > 0';
    }
    $whereParts[] = $folderMapReadySql;

    $sql = 'SELECT c.*, fm.syncro_customer_id AS folder_map_syncro_customer_id,
            fm.deploy_workstations_folder_id, fm.deploy_servers_folder_id,
            fm.production_workstations_folder_id, fm.production_servers_folder_id,
            fm.provision_status AS folder_map_provision_status,
            fm.policy_assignment_status AS folder_map_policy_assignment_status
        FROM clients c
        INNER JOIN client_syncro_folder_map fm ON fm.client_id = c.client_id
        WHERE ' . implode(' AND ', $whereParts) . '
        ORDER BY c.legal_name ASC, c.client_id ASC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function syncro_auto_move_folder_map_from_client(array $client): array
{
    $map = [];
    foreach (['deploy_workstations_folder_id', 'deploy_servers_folder_id', 'production_workstations_folder_id', 'production_servers_folder_id', 'folder_map_provision_status', 'folder_map_policy_assignment_status'] as $key) {
        if (array_key_exists($key, $client)) {
            $map[$key] = $client[$key];
        }
    }
    if (isset($map['folder_map_provision_status']) && !isset($map['provision_status'])) {
        $map['provision_status'] = $map['folder_map_provision_status'];
    }
    if (isset($map['folder_map_policy_assignment_status']) && !isset($map['policy_assignment_status'])) {
        $map['policy_assignment_status'] = $map['folder_map_policy_assignment_status'];
    }
    return $map;
}

function syncro_auto_move_folder_map_safe(array $folderMap): bool
{
    if (!function_exists('syncro_folder_map_complete') || !syncro_folder_map_complete($folderMap)) {
        return false;
    }
    $ids = [];
    foreach (['deploy_workstations_folder_id', 'deploy_servers_folder_id', 'production_workstations_folder_id', 'production_servers_folder_id'] as $key) {
        $id = (int)($folderMap[$key] ?? 0);
        if ($id <= 0 || in_array($id, $ids, true)) {
            return false;
        }
        $ids[] = $id;
    }
    return strtoupper(trim((string)($folderMap['provision_status'] ?? ''))) === 'READY';
}

function syncro_auto_move_normalize_target(string $target): string
{
    $target = trim($target);
    $target = preg_replace('/\s*\/\s*/', '/', $target) ?? $target;
    $target = preg_replace('/\s+/', ' ', $target) ?? $target;
    return strtolower($target);
}

function syncro_auto_move_truthy(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    $text = strtolower(trim((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'y', 'on', 'checked'], true);
}

function syncro_auto_move_known_mmit_option_label(string $name, mixed $value): string
{
    $fieldKey = function_exists('syncro_normalize_match_text') ? syncro_normalize_match_text($name) : strtolower(trim($name));
    $valueKey = trim((string)$value);
    $known = [
        'mmit onboarding status' => ['135355' => 'READY'],
        'mmit ready to move' => ['135359' => 'Yes'],
    ];
    return (string)($known[$fieldKey][$valueKey] ?? '');
}

function syncro_auto_move_field(array $fields, string $name): string
{
    $wanted = function_exists('syncro_normalize_match_text') ? syncro_normalize_match_text($name) : strtolower(trim($name));
    foreach ($fields as $fieldName => $value) {
        $candidate = function_exists('syncro_normalize_match_text') ? syncro_normalize_match_text((string)$fieldName) : strtolower(trim((string)$fieldName));
        if ($candidate === $wanted) {
            if (is_bool($value)) {
                return $value ? 'Yes' : 'No';
            }
            $knownLabel = syncro_auto_move_known_mmit_option_label($name, $value);
            if ($knownLabel !== '') {
                return $knownLabel;
            }
            if (function_exists('syncro_display_custom_field_value')) {
                $display = syncro_display_custom_field_value($name, $value);
                if (trim((string)($display['value'] ?? '')) !== '') {
                    return trim((string)$display['value']);
                }
            }
            return trim((string)$value);
        }
    }
    return '';
}

function syncro_auto_move_asset_supported_windows_lane(array $asset): string
{
    $classification = syncro_classify_asset_os(syncro_asset_os_text($asset));
    if (($classification['platform'] ?? '') !== 'windows') {
        return 'unsupported';
    }
    return (($classification['role'] ?? '') === 'server') ? 'server' : 'workstation';
}


function syncro_auto_move_extract_numeric_ticket_id(mixed $value): int
{
    if ($value === null) {
        return 0;
    }
    if (function_exists('syncro_extract_ticket_id')) {
        $ticketId = syncro_extract_ticket_id($value);
        if ($ticketId > 0) {
            return $ticketId;
        }
    }
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }
    if (is_string($value)) {
        $text = trim($value);
        if ($text === '' || preg_match('/System\.Collections|\bArray\b|\bObject\b|Dictionary/i', $text) === 1) {
            return 0;
        }
        return preg_match('/^\d+$/', $text) === 1 ? (int)$text : 0;
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return 0;
    }
    foreach (['id', 'number', 'ticket_id', 'ticket_number'] as $key) {
        if (array_key_exists($key, $value)) {
            $ticketId = syncro_auto_move_extract_numeric_ticket_id($value[$key]);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }
    foreach (['ticket', 'data', 'record', 'item', 'result'] as $key) {
        if (isset($value[$key])) {
            $ticketId = syncro_auto_move_extract_numeric_ticket_id($value[$key]);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }
    if (array_is_list($value)) {
        foreach ($value as $item) {
            $ticketId = syncro_auto_move_extract_numeric_ticket_id($item);
            if ($ticketId > 0) {
                return $ticketId;
            }
        }
    }
    return 0;
}

function syncro_auto_move_ready_ticket_id_from_asset(array $fields): int
{
    return syncro_auto_move_extract_numeric_ticket_id(syncro_auto_move_field($fields, 'MMIT Ready Move Ticket ID'));
}

function syncro_auto_move_ticket_status_rank(array $ticket): int
{
    $status = strtolower(trim((string)($ticket['status'] ?? '')));
    return match ($status) {
        'new', 'open', 'in progress', 'in-progress', 'pending', 'waiting' => 0,
        default => 1,
    };
}

function syncro_auto_move_extract_numeric_ticket_value(mixed $value, array $preferredKeys): int
{
    if ($value === null) {
        return 0;
    }
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }
    if (is_string($value)) {
        $text = trim($value);
        if ($text === '' || preg_match('/System\.Collections|\bArray\b|\bObject\b|Dictionary/i', $text) === 1) {
            return 0;
        }
        return preg_match('/^\d+$/', $text) === 1 ? (int)$text : 0;
    }
    if (is_object($value)) {
        $value = get_object_vars($value);
    }
    if (!is_array($value)) {
        return 0;
    }

    foreach ($preferredKeys as $key) {
        if (array_key_exists($key, $value)) {
            $ticketValue = syncro_auto_move_extract_numeric_ticket_value($value[$key], $preferredKeys);
            if ($ticketValue > 0) {
                return $ticketValue;
            }
        }
    }
    foreach (['ticket', 'data', 'record', 'item', 'result'] as $key) {
        if (isset($value[$key])) {
            $ticketValue = syncro_auto_move_extract_numeric_ticket_value($value[$key], $preferredKeys);
            if ($ticketValue > 0) {
                return $ticketValue;
            }
        }
    }
    if (array_is_list($value)) {
        foreach ($value as $item) {
            $ticketValue = syncro_auto_move_extract_numeric_ticket_value($item, $preferredKeys);
            if ($ticketValue > 0) {
                return $ticketValue;
            }
        }
    }
    return 0;
}

function syncro_auto_move_ticket_id(array $ticket): int
{
    return syncro_auto_move_extract_numeric_ticket_value($ticket, ['id', 'ticket_id']);
}

function syncro_auto_move_ticket_number(array $ticket): int
{
    return syncro_auto_move_extract_numeric_ticket_value($ticket, ['number', 'ticket_number']);
}

function syncro_auto_move_ticket_identity(array $ticket): array
{
    $ticketId = syncro_auto_move_ticket_id($ticket);
    $ticketNumber = syncro_auto_move_ticket_number($ticket);
    return [
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber,
        'preferred_ticket_number' => $ticketNumber > 0 ? $ticketNumber : $ticketId,
    ];
}

function syncro_auto_move_extract_ticket_list(array $response): array
{
    $data = $response['data'] ?? $response;
    foreach (['tickets', 'records', 'items', 'results', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            if (array_is_list($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
            return syncro_auto_move_extract_ticket_list(['data' => $data[$key]]);
        }
    }
    return array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
}

function syncro_auto_move_ticket_matches(array $ticket, int $assetId, string $assetName): bool
{
    $status = strtolower(trim((string)($ticket['status'] ?? '')));
    if (in_array($status, ['resolved', 'closed', 'complete', 'completed', 'deleted'], true)) {
        return false;
    }
    $subject = trim((string)($ticket['subject'] ?? $ticket['title'] ?? ''));
    $body = (string)($ticket['body'] ?? $ticket['description'] ?? $ticket['notes'] ?? '');
    $json = strtolower(json_encode($ticket, JSON_UNESCAPED_SLASHES) ?: '');
    $hasAutoMoveMarker = str_contains($json, 'mmit_auto_move_ready') || stripos($subject, 'MMIT Auto Move Ready') !== false;
    $hasAsset = str_contains($json, 'asset id: ' . $assetId)
        || (str_contains($json, 'asset_id') && str_contains($json, (string)$assetId))
        || ($assetName !== '' && stripos($subject . "\n" . $body, $assetName) !== false);
    return $hasAutoMoveMarker && $hasAsset;
}

function syncro_auto_move_ticket_subject_matches_asset(array $ticket, string $assetName): bool
{
    if ($assetName === '') {
        return false;
    }
    $status = strtolower(trim((string)($ticket['status'] ?? '')));
    if (in_array($status, ['resolved', 'closed', 'complete', 'completed', 'deleted'], true)) {
        return false;
    }
    $subject = trim((string)($ticket['subject'] ?? $ticket['title'] ?? ''));
    return stripos($subject, 'MMIT Auto Move Ready') !== false && stripos($subject, $assetName) !== false;
}

function syncro_auto_move_get_ticket_by_id(int $ticketId): ?array
{
    if ($ticketId <= 0) {
        return null;
    }
    $response = syncro_api_request('GET', 'tickets/' . $ticketId);
    if (empty($response['ok'])) {
        return null;
    }
    $data = $response['data']['ticket'] ?? $response['data'] ?? [];
    return is_array($data) ? $data : null;
}

function syncro_auto_move_find_open_ticket(int $customerId, int $assetId, string $assetName, int $readyTicketId = 0): ?array
{
    $ticketById = syncro_auto_move_get_ticket_by_id($readyTicketId);
    if ($ticketById && syncro_auto_move_ticket_matches($ticketById, $assetId, $assetName)) {
        return $ticketById;
    }

    $response = syncro_api_request('GET', 'tickets', ['customer_id' => $customerId, 'asset_id' => $assetId, 'status' => 'open']);
    if (!empty($response['ok'])) {
        foreach (syncro_auto_move_extract_ticket_list($response) as $ticket) {
            if (syncro_auto_move_ticket_matches($ticket, $assetId, $assetName)) {
                return $ticket;
            }
        }
    }

    $fallbackResponse = syncro_api_request('GET', 'tickets', ['customer_id' => $customerId]);
    if (empty($fallbackResponse['ok'])) {
        return null;
    }
    $matches = [];
    foreach (syncro_auto_move_extract_ticket_list($fallbackResponse) as $ticket) {
        if (syncro_auto_move_ticket_subject_matches_asset($ticket, $assetName)) {
            $matches[] = $ticket;
        }
    }
    if ($matches === []) {
        return null;
    }
    usort($matches, static function (array $left, array $right): int {
        $rank = syncro_auto_move_ticket_status_rank($left) <=> syncro_auto_move_ticket_status_rank($right);
        if ($rank !== 0) {
            return $rank;
        }
        return syncro_auto_move_ticket_id($right) <=> syncro_auto_move_ticket_id($left);
    });
    return $matches[0];
}

function syncro_auto_move_add_ticket_comment(int $ticketId, int $ticketNumber, string $body): array
{
    // Syncro ticket comments require the internal ticket ID in the endpoint.
    // The visible ticket number stays human-facing only.
    $identifier = $ticketId;
    $identifierType = 'ticket_id';

    if ($identifier <= 0) {
        return [
            'ok' => false,
            'skipped' => true,
            'message' => 'No numeric internal ticket ID available for ticket comment.',
            'ticket_comment_identifier' => 0,
            'ticket_comment_identifier_type' => $identifierType,
        ];
    }

    $comment = syncro_api_request('POST', 'tickets/' . $identifier . '/comment', [], [
        'subject' => 'MMIT Auto Move Update',
        'body' => $body,
        'hidden' => false,
        'do_not_email' => true,
    ]);

    return $comment + [
        'ticket_comment_identifier' => $identifier,
        'ticket_comment_identifier_type' => $identifierType,
    ];
}
function syncro_auto_move_log_ticket_comment_result(array $context, array $comment): void
{
    $eventContext = $context;
    $eventContext['skipped'] = !empty($comment['skipped']);
    if (isset($comment['ticket_comment_identifier'])) {
        $eventContext['ticket_comment_identifier'] = (int)$comment['ticket_comment_identifier'];
        $eventContext['ticket_comment_identifier_type'] = (string)($comment['ticket_comment_identifier_type'] ?? '');
    }
    if (!empty($comment['ok'])) {
        syncro_auto_move_log('READY_MOVE_TICKET_COMMENT_CREATED', $eventContext);
        return;
    }

    $eventContext['errors'] = $comment['errors'] ?? [$comment['message'] ?? 'Ticket comment creation failed.'];
    syncro_auto_move_log('READY_MOVE_TICKET_COMMENT_FAILED', $eventContext);
}

function syncro_auto_move_update_asset_properties(int $assetId, array $properties): array
{
    return syncro_api_request('PUT', 'customer_assets/' . $assetId, [], ['properties' => $properties]);
}

function syncro_auto_move_update_completion_fields(int $assetId, string $result, string $completedAt): array
{
    return syncro_auto_move_update_asset_properties($assetId, [
        'MMIT Auto Move Result' => $result,
        'MMIT Onboarding Completed At' => $completedAt,
    ]);
}

function syncro_auto_move_update_auto_move_result(int $assetId, string $result): array
{
    return syncro_auto_move_update_asset_properties($assetId, ['MMIT Auto Move Result' => $result]);
}

function syncro_auto_move_backfill_ready_ticket_id(int $clientId, int $syncroCustomerId, int $assetId, string $assetName, int $existingTicketId, int $foundTicketId, int $foundTicketNumber): array
{
    $preferredTicketNumber = $foundTicketNumber > 0 ? $foundTicketNumber : $foundTicketId;
    if ($preferredTicketNumber <= 0) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'no_numeric_found_ticket_number', 'ticket_id' => $foundTicketId, 'ticket_number' => $foundTicketNumber];
    }
    if ($existingTicketId > 0 && $existingTicketId === $preferredTicketNumber) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'existing_visible_ticket_number', 'ticket_id' => $foundTicketId, 'ticket_number' => $foundTicketNumber];
    }

    $update = syncro_auto_move_update_asset_properties($assetId, ['MMIT Ready Move Ticket ID' => (string)$preferredTicketNumber]);
    if (!empty($update['ok'])) {
        syncro_auto_move_log('READY_MOVE_TICKET_ID_BACKFILLED', [
            'client_id' => $clientId,
            'syncro_customer_id' => $syncroCustomerId,
            'asset_id' => $assetId,
            'asset_name' => $assetName,
            'ticket_id' => $foundTicketId,
            'ticket_number' => $foundTicketNumber,
            'backfilled_ticket_number' => $preferredTicketNumber,
            'previous_ticket_id_field' => $existingTicketId,
        ]);
    }
    return $update + ['ticket_id' => $foundTicketId, 'ticket_number' => $foundTicketNumber, 'backfilled_ticket_number' => $preferredTicketNumber];
}

function syncro_auto_move_workstation_candidate(array $asset, array $folderMap): array
{
    $assetId = syncro_asset_id($asset);
    $assetName = syncro_asset_name($asset);
    $currentFolderId = syncro_asset_policy_folder_id($asset);
    $fields = syncro_extract_asset_custom_fields($asset);
    $status = syncro_auto_move_field($fields, 'MMIT Onboarding Status');
    $ready = syncro_auto_move_field($fields, 'MMIT Ready To Move');
    $target = syncro_auto_move_field($fields, 'MMIT Production Folder Target');
    $lane = syncro_auto_move_asset_supported_windows_lane($asset);
    $errors = [];

    if ($assetId <= 0) {
        $errors[] = 'missing_asset_id';
    }
    if ($lane !== 'workstation') {
        $errors[] = 'not_supported_windows_workstation';
    }
    if ($currentFolderId !== (int)$folderMap['deploy_workstations_folder_id']) {
        $errors[] = 'not_in_deploy_workstations';
    }
    if (strtoupper(trim($status)) !== 'READY') {
        $errors[] = 'onboarding_status_not_ready';
    }
    if (!syncro_auto_move_truthy($ready)) {
        $errors[] = 'ready_to_move_not_truthy';
    }
    if (syncro_auto_move_normalize_target($target) !== 'production/workstations') {
        $errors[] = 'target_not_production_workstations';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'asset_id' => $assetId,
        'asset_name' => $assetName,
        'current_folder_id' => $currentFolderId,
        'target_folder_id' => (int)$folderMap['production_workstations_folder_id'],
        'fields' => ['status' => $status, 'ready_to_move' => $ready, 'target' => $target],
        'lane' => $lane,
    ];
}

function syncro_auto_move_workstation_finalization_candidate(
    array $asset,
    array $folderMap
): array {
    $assetId = syncro_asset_id($asset);
    $assetName = syncro_asset_name($asset);

    $currentFolderId =
        syncro_asset_policy_folder_id($asset);

    $fields = syncro_extract_asset_custom_fields(
        $asset
    );

    $status = syncro_auto_move_field(
        $fields,
        'MMIT Onboarding Status'
    );

    $ready = syncro_auto_move_field(
        $fields,
        'MMIT Ready To Move'
    );

    $target = syncro_auto_move_field(
        $fields,
        'MMIT Production Folder Target'
    );

    $completedAt = syncro_auto_move_field(
        $fields,
        'MMIT Onboarding Completed At'
    );

    $lane = syncro_auto_move_asset_supported_windows_lane(
        $asset
    );

    $errors = [];

    if ($assetId <= 0) {
        $errors[] = 'missing_asset_id';
    }

    if ($lane !== 'workstation') {
        $errors[] =
            'not_supported_windows_workstation';
    }

    if (
        $currentFolderId
        !== (int)$folderMap[
            'production_workstations_folder_id'
        ]
    ) {
        $errors[] =
            'not_in_production_workstations';
    }

    if (strtoupper(trim($status)) !== 'READY') {
        $errors[] =
            'onboarding_status_not_ready';
    }

    if (!syncro_auto_move_truthy($ready)) {
        $errors[] =
            'ready_to_move_not_truthy';
    }

    if (
        syncro_auto_move_normalize_target($target)
        !== 'production/workstations'
    ) {
        $errors[] =
            'target_not_production_workstations';
    }

    if (trim($completedAt) !== '') {
        $errors[] =
            'onboarding_already_completed';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'asset_id' => $assetId,
        'asset_name' => $assetName,
        'current_folder_id' => $currentFolderId,
        'target_folder_id' => (int)$folderMap[
            'production_workstations_folder_id'
        ],
        'fields' => [
            'status' => $status,
            'ready_to_move' => $ready,
            'target' => $target,
            'completed_at' => $completedAt,
        ],
        'lane' => $lane,
    ];
}

function syncro_auto_move_server_candidate(array $asset, array $folderMap): array
{
    $assetId = syncro_asset_id($asset);
    $assetName = syncro_asset_name($asset);
    $currentFolderId = syncro_asset_policy_folder_id($asset);
    $fields = syncro_extract_asset_custom_fields($asset);
    $status = syncro_auto_move_field($fields, 'MMIT Onboarding Status');
    $ready = syncro_auto_move_field($fields, 'MMIT Ready To Move');
    $target = syncro_auto_move_field($fields, 'MMIT Production Folder Target');
    $lane = syncro_auto_move_asset_supported_windows_lane($asset);
    $ok = $assetId > 0
        && $lane === 'server'
        && $currentFolderId === (int)$folderMap['deploy_servers_folder_id']
        && strtoupper(trim($status)) === 'READY'
        && syncro_auto_move_truthy($ready)
        && syncro_auto_move_normalize_target($target) === 'production/servers';

    return [
        'ok' => $ok,
        'asset_id' => $assetId,
        'asset_name' => $assetName,
        'current_folder_id' => $currentFolderId,
        'target_folder_id' => (int)$folderMap['production_servers_folder_id'],
        'fields' => ['status' => $status, 'ready_to_move' => $ready, 'target' => $target],
        'lane' => $lane,
    ];
}

function syncro_auto_move_verify_asset_folder(
    int $assetId,
    int $targetFolderId,
    int $maxAttempts = 3,
    int $delayMicroseconds = 500000
): array {
    $maxAttempts = max(
        1,
        min(5, $maxAttempts)
    );

    $delayMicroseconds = max(
        0,
        $delayMicroseconds
    );

    $lastFetch = null;
    $lastFolderId = null;

    for (
        $attempt = 1;
        $attempt <= $maxAttempts;
        $attempt++
    ) {
        $fetch = syncro_fetch_customer_asset(
            $assetId
        );

        $lastFetch = $fetch;

        if (!empty($fetch['ok'])) {
            $verifiedAsset = (array)(
                $fetch['asset'] ?? []
            );

            $lastFolderId =
                syncro_asset_policy_folder_id(
                    $verifiedAsset
                );

            if ($lastFolderId === $targetFolderId) {
                return [
                    'ok' => true,
                    'status' => 'MOVE_VERIFIED',
                    'asset_id' => $assetId,
                    'target_folder_id' =>
                        $targetFolderId,
                    'verified_folder_id' =>
                        $lastFolderId,
                    'attempts' => $attempt,
                    'asset' => $verifiedAsset,
                    'fetch' => $fetch,
                ];
            }
        }

        if (
            $attempt < $maxAttempts
            && $delayMicroseconds > 0
        ) {
            usleep($delayMicroseconds);
        }
    }

    $errors = [];

    if (
        !is_array($lastFetch)
        || empty($lastFetch['ok'])
    ) {
        $errors = (array)(
            $lastFetch['errors']
            ?? [
                'Unable to reread Syncro asset '
                . 'after folder move.',
            ]
        );
    } else {
        $errors[] = sprintf(
            'Syncro asset folder verification '
            . 'returned policy_folder_id #%s; '
            . 'expected #%d.',
            $lastFolderId === null
                ? 'NULL'
                : (string)$lastFolderId,
            $targetFolderId
        );
    }

    return [
        'ok' => false,
        'status' => 'MOVE_VERIFY_FAILED',
        'asset_id' => $assetId,
        'target_folder_id' => $targetFolderId,
        'verified_folder_id' => $lastFolderId,
        'attempts' => $maxAttempts,
        'errors' => $errors,
        'fetch' => $lastFetch,
    ];
}

function syncro_auto_move_move_workstation(
    int $clientId,
    int $syncroCustomerId,
    array $asset,
    array $candidate,
    array $ticket
): array {
    $assetId = (int)$candidate['asset_id'];
    $assetName = (string)$candidate['asset_name'];

    $fieldsFromAsset = syncro_extract_asset_custom_fields($asset);

    $readyTicketId = syncro_auto_move_ready_ticket_id_from_asset(
        $fieldsFromAsset
    );

    $ticketIdentity = syncro_auto_move_ticket_identity($ticket);

    $ticketId = (int)$ticketIdentity['ticket_id'];
    $ticketNumber = (int)$ticketIdentity['ticket_number'];

    if ($ticketId <= 0) {
        return [
            'ok' => false,
            'status' => 'READY_MOVE_TICKET_ID_REQUIRED',
            'errors' => [
                'Matching ready-move ticket has no internal Syncro ticket ID.',
            ],
            'ticket_found' => true,
            'ticket_id' => 0,
            'ticket_number' => $ticketNumber,
        ];
    }

    $targetFolderId = (int)$candidate['target_folder_id'];

    $move = syncro_update_customer_asset_policy_folder(
        $assetId,
        $targetFolderId
    );

    if (empty($move['ok'])) {
        return [
            'ok' => false,
            'status' => 'MOVE_FAILED',
            'errors' => $move['errors'] ?? [
                'Syncro move failed.',
            ],
            'move' => $move,
            'ticket_found' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
        ];
    }

    $verify = syncro_auto_move_verify_asset_folder(
        $assetId,
        $targetFolderId
    );

    if (empty($verify['ok'])) {
        return [
            'ok' => false,
            'status' => 'MOVE_VERIFY_FAILED',
            'errors' => $verify['errors'] ?? [
                'Syncro folder move could not be verified.',
            ],
            'move' => $move,
            'verify' => $verify,
            'ticket_found' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
        ];
    }

    return syncro_auto_move_finalize_workstation(
        $clientId,
        $syncroCustomerId,
        $assetId,
        $assetName,
        $readyTicketId,
        $ticketId,
        $ticketNumber,
        $targetFolderId,
        $move,
        $verify
    );
}

function syncro_auto_move_finalize_workstation(
    int $clientId,
    int $syncroCustomerId,
    int $assetId,
    string $assetName,
    int $readyTicketId,
    int $ticketId,
    int $ticketNumber,
    int $targetFolderId,
    array $move,
    array $verify
): array {
    $completedAt = gmdate('Y-m-d\TH:i:s\Z');

    $resultText =
        'Moved to Production/Workstations by OPS Syncro auto mover at '
        . $completedAt
        . '.';

    $fields = syncro_auto_move_update_completion_fields(
        $assetId,
        $resultText,
        $completedAt
    );

    if (empty($fields['ok'])) {
        return [
            'ok' => false,
            'status' => !empty($fields['staging_blocked'])
                ? 'STAGING_BLOCKED'
                : 'COMPLETION_FIELD_UPDATE_FAILED',
            'message' =>
                'Production folder move was verified, but '
                . 'MMIT completion fields could not be persisted.',
            'errors' => $fields['errors'] ?? [
                'Unable to persist MMIT auto-move completion fields.',
            ],
            'move' => $move,
            'verify' => $verify,
            'field_update' => $fields,
            'ticket_found' => true,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
        ];
    }

    $ticketIdBackfill = syncro_auto_move_backfill_ready_ticket_id(
        $clientId,
        $syncroCustomerId,
        $assetId,
        $assetName,
        $readyTicketId,
        $ticketId,
        $ticketNumber
    );

    $ticketComment = syncro_auto_move_add_ticket_comment(
        $ticketId,
        $ticketNumber,
        $resultText
            . ' Asset #'
            . $assetId
            . ' moved to Production/Workstations policy_folder_id #'
            . $targetFolderId
            . '.'
    );

    syncro_auto_move_log_ticket_comment_result([
        'client_id' => $clientId,
        'syncro_customer_id' => $syncroCustomerId,
        'asset_id' => $assetId,
        'asset_name' => $assetName,
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber,
        'action' => 'workstation_moved',
    ], $ticketComment);

    return [
        'ok' => true,
        'status' => 'MOVED',
        'message' => $resultText,
        'move' => $move,
        'verify' => $verify,
        'field_update' => $fields,
        'ticket_id_backfill' => $ticketIdBackfill,
        'ticket_comment' => $ticketComment,
        'ticket_found' => true,
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber,
    ];
}

function syncro_auto_move_handle_server_ready_disabled(int $clientId, int $syncroCustomerId, array $asset, array $candidate): array
{
    $assetId = (int)$candidate['asset_id'];
    $assetName = (string)$candidate['asset_name'];
    $fieldsFromAsset = syncro_extract_asset_custom_fields($asset);
    $readyTicketId = syncro_auto_move_ready_ticket_id_from_asset($fieldsFromAsset);
    $resultText = 'Server READY but automatic server move is disabled.';
    $fieldUpdate = syncro_auto_move_update_auto_move_result($assetId, $resultText);
    $ticket = syncro_auto_move_find_open_ticket($syncroCustomerId, $assetId, $assetName, $readyTicketId);
    $ticketComment = ['ok' => true, 'skipped' => true];
    $ticketIdBackfill = ['ok' => true, 'skipped' => true, 'reason' => 'ticket_not_found'];
    $ticketId = 0;
    $ticketNumber = 0;

    if ($ticket) {
        $ticketIdentity = syncro_auto_move_ticket_identity($ticket);
        $ticketId = (int)$ticketIdentity['ticket_id'];
        $ticketNumber = (int)$ticketIdentity['ticket_number'];
        $ticketIdBackfill = syncro_auto_move_backfill_ready_ticket_id($clientId, $syncroCustomerId, $assetId, $assetName, $readyTicketId, $ticketId, $ticketNumber);
        $ticketComment = syncro_auto_move_add_ticket_comment($ticketId, $ticketNumber, $resultText . ' Asset #' . $assetId . ' remains in Deploy/Servers; manual server move is required.');
        syncro_auto_move_log_ticket_comment_result([
            'client_id' => $clientId,
            'syncro_customer_id' => $syncroCustomerId,
            'asset_id' => $assetId,
            'asset_name' => $assetName,
            'ticket_id' => $ticketId,
            'ticket_number' => $ticketNumber,
            'action' => 'server_ready_move_disabled',
        ], $ticketComment);
    }

    return [
        'ok' => !empty($fieldUpdate['ok']),
        'status' => 'SERVER_READY_MOVE_DISABLED',
        'message' => $resultText,
        'field_update' => $fieldUpdate,
        'ticket_id_backfill' => $ticketIdBackfill,
        'ticket_comment' => $ticketComment,
        'ticket_found' => $ticket !== null,
        'ticket_id' => $ticketId,
        'ticket_number' => $ticketNumber,
    ];
}

function syncro_auto_move_process_client(array $client, bool $dryRun): array
{
    $summary = syncro_auto_move_empty_totals();
    $summary['clients_scanned'] = 1;
    $clientId = (int)($client['client_id'] ?? 0);
    $syncroCustomerId = (int)($client['syncro_customer_id'] ?? $client['folder_map_syncro_customer_id'] ?? 0);
    $label = syncro_auto_move_client_label($client);
    $folderMap = syncro_auto_move_folder_map_from_client($client);

    if ($syncroCustomerId <= 0) {
        $summary['skipped']++;
        syncro_auto_move_log('CLIENT_SKIPPED_NO_SYNCRO_CUSTOMER_ID', ['client_id' => $clientId, 'client' => $label]);
        return ['ok' => true, 'summary' => $summary];
    }
    if (!syncro_auto_move_folder_map_safe($folderMap)) {
        $summary['skipped']++;
        syncro_auto_move_log('CLIENT_SKIPPED_UNSAFE_FOLDER_MAP', ['client_id' => $clientId, 'syncro_customer_id' => $syncroCustomerId, 'folder_map' => $folderMap]);
        return ['ok' => true, 'summary' => $summary];
    }

    syncro_auto_move_log('CLIENT_SCAN_START', ['client_id' => $clientId, 'client' => $label, 'syncro_customer_id' => $syncroCustomerId]);
    $listedAssets = syncro_list_customer_assets($syncroCustomerId);
    if (empty($listedAssets['ok'])) {
        $summary['failures']++;
        syncro_auto_move_log('CLIENT_ASSET_LIST_FAILED', ['client_id' => $clientId, 'syncro_customer_id' => $syncroCustomerId, 'errors' => $listedAssets['errors'] ?? []]);
        return ['ok' => false, 'summary' => $summary];
    }

    foreach ((array)($listedAssets['assets'] ?? []) as $asset) {
        if (!is_array($asset)) {
            continue;
        }
        $summary['assets_scanned']++;
        $assetId = syncro_asset_id($asset);
        $assetName = syncro_asset_name($asset);
        $currentFolderId = syncro_asset_policy_folder_id($asset);

        $serverCandidate = syncro_auto_move_server_candidate($asset, $folderMap);
        if (!empty($serverCandidate['ok'])) {
            $summary['servers_ready_disabled']++;
            $serverResult = $dryRun
                ? ['ok' => true, 'ticket_found' => false, 'ticket_id' => 0, 'ticket_number' => 0, 'field_update' => ['ok' => true, 'skipped' => true]]
                : syncro_auto_move_handle_server_ready_disabled($clientId, $syncroCustomerId, $asset, $serverCandidate);
            if (empty($serverResult['ok'])) {
                $summary['failures']++;
            }
            syncro_auto_move_log('SERVER_READY_MOVE_DISABLED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $assetId,
                'asset_name' => $assetName,
                'source_folder_id' => $currentFolderId,
                'target_folder_id' => $serverCandidate['target_folder_id'],
                'ticket_found' => !empty($serverResult['ticket_found']),
                'ticket_id' => (int)($serverResult['ticket_id'] ?? 0),
                'ticket_number' => (int)($serverResult['ticket_number'] ?? 0),
                'field_update_ok' => !empty($serverResult['field_update']['ok']),
                'mode' => $dryRun ? 'dry-run' : 'apply',
            ]);
            continue;
        }

        $finalizationCandidate =
            syncro_auto_move_workstation_finalization_candidate(
                $asset,
                $folderMap
            );

        if (!empty($finalizationCandidate['ok'])) {
            $summary['workstation_finalize_candidates']++;

            $fieldsFromAsset =
                syncro_extract_asset_custom_fields($asset);

            $readyTicketId =
                syncro_auto_move_ready_ticket_id_from_asset(
                    $fieldsFromAsset
                );

            $ticket = syncro_auto_move_find_open_ticket(
                $syncroCustomerId,
                (int)$finalizationCandidate['asset_id'],
                (string)$finalizationCandidate['asset_name'],
                $readyTicketId
            );

            if ($ticket === null) {
                $summary['workstation_ticket_blocked']++;
                $summary['skipped']++;

                syncro_auto_move_log(
                    'READY_MOVE_TICKET_REQUIRED',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'source_folder_id' =>
                            $finalizationCandidate[
                                'current_folder_id'
                            ],
                        'target_folder_id' =>
                            $finalizationCandidate[
                                'target_folder_id'
                            ],
                        'ready_ticket_id_field' =>
                            $readyTicketId,
                        'action' =>
                            'workstation_finalization_recovery',
                        'mode' =>
                            $dryRun ? 'dry-run' : 'apply',
                    ]
                );

                continue;
            }

            $ticketIdentity =
                syncro_auto_move_ticket_identity($ticket);

            $ticketId =
                (int)$ticketIdentity['ticket_id'];

            $ticketNumber =
                (int)$ticketIdentity['ticket_number'];

            if ($ticketId <= 0) {
                $summary['workstation_ticket_blocked']++;
                $summary['skipped']++;

                syncro_auto_move_log(
                    'READY_MOVE_TICKET_ID_REQUIRED',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'ticket_number' => $ticketNumber,
                        'action' =>
                            'workstation_finalization_recovery',
                        'mode' =>
                            $dryRun ? 'dry-run' : 'apply',
                    ]
                );

                continue;
            }

            if ($dryRun) {
                syncro_auto_move_log(
                    'WOULD_FINALIZE',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'current_folder_id' =>
                            $finalizationCandidate[
                                'current_folder_id'
                            ],
                        'target_folder_id' =>
                            $finalizationCandidate[
                                'target_folder_id'
                            ],
                        'ticket_found' => true,
                        'ticket_id' => $ticketId,
                        'ticket_number' => $ticketNumber,
                    ]
                );

                continue;
            }

            $moveContext = [
                'ok' => true,
                'status' => 'MOVE_ALREADY_VERIFIED',
                'asset_id' =>
                    (int)$finalizationCandidate['asset_id'],
                'target_folder_id' =>
                    (int)$finalizationCandidate[
                        'target_folder_id'
                    ],
                'skipped' => true,
            ];

            $verifyContext = [
                'ok' => true,
                'status' => 'MOVE_ALREADY_VERIFIED',
                'asset_id' =>
                    (int)$finalizationCandidate['asset_id'],
                'target_folder_id' =>
                    (int)$finalizationCandidate[
                        'target_folder_id'
                    ],
                'verified_folder_id' =>
                    (int)$finalizationCandidate[
                        'current_folder_id'
                    ],
                'attempts' => 0,
            ];

            $finalization =
                syncro_auto_move_finalize_workstation(
                    $clientId,
                    $syncroCustomerId,
                    (int)$finalizationCandidate['asset_id'],
                    (string)$finalizationCandidate['asset_name'],
                    $readyTicketId,
                    $ticketId,
                    $ticketNumber,
                    (int)$finalizationCandidate[
                        'target_folder_id'
                    ],
                    $moveContext,
                    $verifyContext
                );

            if (!empty($finalization['ok'])) {
                $summary['workstation_finalized']++;

                syncro_auto_move_log(
                    'FINALIZED',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'folder_id' =>
                            $finalizationCandidate[
                                'current_folder_id'
                            ],
                        'ticket_found' => true,
                        'ticket_id' => $ticketId,
                        'ticket_number' => $ticketNumber,
                        'recovery' => true,
                    ]
                );
            } elseif (
                ($finalization['status'] ?? '')
                === 'STAGING_BLOCKED'
                || !empty(
                    $finalization[
                        'field_update'
                    ]['staging_blocked']
                )
            ) {
                $summary['skipped']++;

                syncro_auto_move_log(
                    'STAGING_WRITE_BLOCKED',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'action' =>
                            'workstation_finalization_recovery',
                        'errors' =>
                            $finalization['errors'] ?? [],
                    ]
                );
            } else {
                $summary['failures']++;

                syncro_auto_move_log(
                    'FINALIZATION_FAILED',
                    [
                        'client_id' => $clientId,
                        'syncro_customer_id' =>
                            $syncroCustomerId,
                        'asset_id' =>
                            $finalizationCandidate['asset_id'],
                        'asset_name' =>
                            $finalizationCandidate['asset_name'],
                        'folder_id' =>
                            $finalizationCandidate[
                                'current_folder_id'
                            ],
                        'ticket_id' => $ticketId,
                        'ticket_number' => $ticketNumber,
                        'status' =>
                            $finalization['status'] ?? null,
                        'errors' =>
                            $finalization['errors'] ?? [],
                    ]
                );
            }

            continue;
        }

        $candidate = syncro_auto_move_workstation_candidate($asset, $folderMap);
        if (empty($candidate['ok'])) {
            $summary['skipped']++;
            continue;
        }

        $summary['workstation_candidates']++;

        $fieldsFromAsset = syncro_extract_asset_custom_fields($asset);

        $readyTicketId = syncro_auto_move_ready_ticket_id_from_asset(
            $fieldsFromAsset
        );

        $ticket = syncro_auto_move_find_open_ticket(
            $syncroCustomerId,
            (int)$candidate['asset_id'],
            (string)$candidate['asset_name'],
            $readyTicketId
        );

        if ($ticket === null) {
            $summary['workstation_ticket_blocked']++;
            $summary['skipped']++;

            syncro_auto_move_log('READY_MOVE_TICKET_REQUIRED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'source_folder_id' => $candidate['current_folder_id'],
                'target_folder_id' => $candidate['target_folder_id'],
                'ready_ticket_id_field' => $readyTicketId,
                'mode' => $dryRun ? 'dry-run' : 'apply',
            ]);

            continue;
        }

        $ticketIdentity = syncro_auto_move_ticket_identity($ticket);

        $ticketId = (int)$ticketIdentity['ticket_id'];
        $ticketNumber = (int)$ticketIdentity['ticket_number'];

        if ($ticketId <= 0) {
            $summary['workstation_ticket_blocked']++;
            $summary['skipped']++;

            syncro_auto_move_log('READY_MOVE_TICKET_ID_REQUIRED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'ticket_number' => $ticketNumber,
                'mode' => $dryRun ? 'dry-run' : 'apply',
            ]);

            continue;
        }

        if ($dryRun) {
            syncro_auto_move_log('WOULD_MOVE', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'source_folder_id' => $candidate['current_folder_id'],
                'target_folder_id' => $candidate['target_folder_id'],
                'ticket_found' => true,
                'ticket_id' => $ticketId,
                'ticket_number' => $ticketNumber,
            ]);

            continue;
        }

        $move = syncro_auto_move_move_workstation(
            $clientId,
            $syncroCustomerId,
            $asset,
            $candidate,
            $ticket
        );
        if (!empty($move['ok'])) {
            $summary['workstation_moved']++;
            syncro_auto_move_log('MOVED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'source_folder_id' => $candidate['current_folder_id'],
                'target_folder_id' => $candidate['target_folder_id'],
                'ticket_found' => !empty($move['ticket_found']),
                'ticket_id' => (int)($move['ticket_id'] ?? 0),
                'ticket_number' => (int)($move['ticket_number'] ?? 0),
            ]);
        } elseif (!empty($move['move']['staging_blocked'])) {
            $summary['skipped']++;
            syncro_auto_move_log('STAGING_WRITE_BLOCKED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'source_folder_id' => $candidate['current_folder_id'],
                'target_folder_id' => $candidate['target_folder_id'],
                'errors' => $move['errors'] ?? [],
            ]);
        } else {
            $summary['failures']++;
            syncro_auto_move_log('MOVE_FAILED', [
                'client_id' => $clientId,
                'syncro_customer_id' => $syncroCustomerId,
                'asset_id' => $candidate['asset_id'],
                'asset_name' => $candidate['asset_name'],
                'source_folder_id' => $candidate['current_folder_id'],
                'target_folder_id' => $candidate['target_folder_id'],
                'errors' => $move['errors'] ?? [],
            ]);
        }
    }

    syncro_auto_move_log('CLIENT_SCAN_COMPLETE', ['client_id' => $clientId, 'syncro_customer_id' => $syncroCustomerId, 'summary' => $summary]);
    return ['ok' => $summary['failures'] === 0, 'summary' => $summary];
}

function syncro_auto_move_main(array $argv = []): int
{
    $options = getopt('', ['host:', 'client-id:', 'syncro-customer-id:', 'limit:', 'apply', 'help']);
    if (!is_array($options)) {
        $options = [];
    }
    if (isset($options['help'])) {
        echo syncro_auto_move_usage();
        return 0;
    }
    foreach (['host', 'client-id', 'syncro-customer-id', 'limit'] as $valueOption) {
        if (syncro_auto_move_option_value($options, $valueOption) === '') {
            fwrite(STDERR, "Option --{$valueOption} requires a value.\n" . syncro_auto_move_usage());
            return 1;
        }
    }

    $clientId = syncro_auto_move_option_int($options, 'client-id');
    $syncroCustomerId = syncro_auto_move_option_int($options, 'syncro-customer-id');
    $limit = syncro_auto_move_option_int($options, 'limit');
    if ($limit < 0) {
        fwrite(STDERR, "Option --limit must be zero or greater.\n" . syncro_auto_move_usage());
        return 1;
    }

    syncro_auto_move_bootstrap_cli_server($options);
    require_once __DIR__ . '/../inc/bootstrap.php';
    require_once __DIR__ . '/../inc/syncro.php';

    $dryRun = !isset($options['apply']);
    $lock = syncro_auto_move_acquire_lock();
    if (empty($lock['ok'])) {
        syncro_auto_move_log('LOCK_NOT_ACQUIRED', ['message' => $lock['message'] ?? 'Unable to acquire cron lock.']);
        return 1;
    }

    try {
        syncro_auto_move_log('RUN_START', [
            'mode' => $dryRun ? 'dry-run' : 'apply',
            'client_id' => $clientId > 0 ? $clientId : null,
            'syncro_customer_id' => $syncroCustomerId > 0 ? $syncroCustomerId : null,
            'limit' => $limit > 0 ? $limit : null,
            'staging_write_guard' => function_exists('syncro_staging_write_status_message') ? syncro_staging_write_status_message() : null,
        ]);
        if (!empty($lock['stale'])) {
            syncro_auto_move_log('STALE_LOCK_REPLACED', ['path' => $lock['path'] ?? '']);
        }

        $clients = syncro_auto_move_active_clients($clientId > 0 ? $clientId : null, $syncroCustomerId > 0 ? $syncroCustomerId : null, $limit);
        $totals = syncro_auto_move_empty_totals();
        foreach ($clients as $client) {
            $result = syncro_auto_move_process_client($client, $dryRun);
            syncro_auto_move_merge_summary($totals, (array)($result['summary'] ?? []));
        }

        syncro_auto_move_log('RUN_COMPLETE', ['summary' => $totals]);
        echo 'SUMMARY ' . json_encode($totals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        return $totals['failures'] > 0 ? 1 : 0;
    } finally {
        syncro_auto_move_release_lock($lock);
    }
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(syncro_auto_move_main($argv ?? []));
}
