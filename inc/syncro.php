<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

function syncro_is_configured(): bool
{
    return defined('SYNCRO_API_KEY') && trim((string)SYNCRO_API_KEY) !== ''
        && defined('SYNCRO_SUBDOMAIN') && trim((string)SYNCRO_SUBDOMAIN) !== '';
}

function syncro_base_url(): string
{
    if (defined('SYNCRO_BASE_URL') && trim((string)SYNCRO_BASE_URL) !== '') {
        return rtrim((string)SYNCRO_BASE_URL, '/') . '/';
    }
    $subdomain = trim((string)(defined('SYNCRO_SUBDOMAIN') ? SYNCRO_SUBDOMAIN : ''));
    return $subdomain !== '' ? 'https://' . $subdomain . '.syncromsp.com/api/v1/' : '';
}

function syncro_debug_log(string $message, array $context = []): void
{
    $line = '[syncro] ' . $message;
    if ($context) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }
    error_log($line);
}

function syncro_flatten_errors(mixed $value, string $prefix = ''): array
{
    $errors = [];
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
        $message = trim((string)$value);
        if ($message !== '') {
            $errors[] = $prefix !== '' ? ($prefix . ': ' . $message) : $message;
        }
        return $errors;
    }
    if (!is_array($value)) {
        return $errors;
    }
    foreach ($value as $key => $item) {
        $label = is_string($key) && $key !== '' ? str_replace('_', ' ', $key) : $prefix;
        if (is_array($item)) {
            $errors = array_merge($errors, syncro_flatten_errors($item, $label));
        } else {
            $message = trim((string)$item);
            if ($message !== '') {
                $errors[] = $label !== '' ? ($label . ': ' . $message) : $message;
            }
        }
    }
    return $errors;
}



function syncro_sanitized_request_path(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $path = is_string($path) && $path !== '' ? $path : '/';
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return $path;
    }

    parse_str($query, $params);
    unset($params['api_key']);
    if (!$params) {
        return $path;
    }

    return $path . '?' . http_build_query($params);
}

function syncro_redacted_response_excerpt(mixed $body, int $limit = 500): string
{
    $text = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($text)) {
        $text = '';
    }
    $text = syncro_mask_secrets($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if (mb_strlen($text, 'UTF-8') <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit, 'UTF-8') . '…';
}

function syncro_mask_secrets(string $message): string
{
    $message = preg_replace('/(api[_-]?key=)[^\s&]+/i', '$1[redacted]', $message) ?? $message;
    $message = preg_replace('/(authorization\s*[:=]\s*)(bearer\s+)?[^\s,;]+/i', '$1[redacted]', $message) ?? $message;
    if (defined('SYNCRO_API_KEY') && trim((string)SYNCRO_API_KEY) !== '') {
        $message = str_replace((string)SYNCRO_API_KEY, '[redacted]', $message);
    }
    return trim($message);
}

function syncro_is_staging_mode(): bool
{
    if (function_exists('ops_is_staging_env') && ops_is_staging_env()) {
        return true;
    }

    $appEnv = defined('APP_ENV') ? strtolower(trim((string)APP_ENV)) : '';
    if (in_array($appEnv, ['staging', 'test', 'testing'], true)) {
        return true;
    }

    $hosts = [];
    foreach (['HTTP_HOST', 'SERVER_NAME'] as $key) {
        $host = strtolower(trim((string)($_SERVER[$key] ?? '')));
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        if ($host !== '') {
            $hosts[] = $host;
        }
    }

    if (defined('BASE_URL')) {
        $baseHost = parse_url((string)BASE_URL, PHP_URL_HOST);
        if (is_string($baseHost) && $baseHost !== '') {
            $hosts[] = strtolower($baseHost);
        }
    }

    return in_array('ops-test.midwestmanagedit.com', array_unique($hosts), true);
}

function syncro_is_write_method(string $method): bool
{
    return in_array(strtoupper(trim($method)), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

function syncro_staging_writes_allowed(): bool
{
    return defined('SYNCRO_ALLOW_STAGING_WRITES') && (bool)SYNCRO_ALLOW_STAGING_WRITES;
}

function syncro_staging_write_status_message(): string
{
    if (!syncro_is_staging_mode()) {
        return 'Syncro staging write guard is not active outside staging/test.';
    }
    return syncro_staging_writes_allowed()
        ? 'WARNING: OPS staging Syncro POST/PUT/PATCH writes are enabled for controlled manual testing; DELETE remains blocked.'
        : 'OPS staging Syncro writes are blocked by default.';
}

function syncro_staging_blocked_result(): array
{
    $message = 'Staging mode: Syncro write skipped.';
    return [
        'ok' => false,
        'skipped' => true,
        'staging_blocked' => true,
        'status' => 'STAGING_BLOCKED',
        'errors' => [$message],
        'message' => $message,
    ];
}

function syncro_block_staging_write_if_needed(string $method, string $path): ?array
{
    $method = strtoupper(trim($method));
    if (!syncro_is_staging_mode() || !syncro_is_write_method($method)) {
        return null;
    }

    if ($method !== 'DELETE' && syncro_staging_writes_allowed()) {
        static $warned = false;
        if (!$warned) {
            syncro_debug_log('staging_writes_allowed_warning', [
                'status' => 'STAGING_WRITES_ALLOWED',
                'methods_allowed' => ['POST', 'PUT', 'PATCH'],
                'delete_blocked' => true,
            ]);
            $warned = true;
        }
        return null;
    }

    // OPS LIVE (ops.midwestmanagedit.com) may push customers/assets to Syncro.
    // OPS TEST/staging (ops-test.midwestmanagedit.com) blocks writes by default.
    // A local SYNCRO_ALLOW_STAGING_WRITES=true override permits POST/PUT/PATCH only;
    // DELETE remains blocked here so UI, cron, and onboarding paths are protected.
    syncro_debug_log('staging_write_blocked', [
        'method' => $method,
        'path' => ltrim($path, '/'),
        'status' => 'STAGING_BLOCKED',
    ]);

    $blocked = syncro_staging_blocked_result();
    $base = syncro_base_url();
    $blocked['request'] = [
        'method' => $method,
        'path' => $base !== '' ? syncro_sanitized_request_path($base . ltrim($path, '/')) : ('/' . ltrim($path, '/')),
    ];
    return $blocked;
}

function syncro_api_request(string $method, string $path, array $query = [], ?array $payload = null): array
{
    $method = strtoupper($method);
    $stagingBlock = syncro_block_staging_write_if_needed($method, $path);
    if ($stagingBlock !== null) {
        return $stagingBlock;
    }

    if (!empty($GLOBALS['syncro_api_request_mock']) && is_callable($GLOBALS['syncro_api_request_mock'])) {
        return (array)call_user_func($GLOBALS['syncro_api_request_mock'], $method, $path, $query, $payload);
    }

    if (!syncro_is_configured()) {
        return ['ok' => false, 'errors' => ['Syncro integration is not configured yet.']];
    }

    $url = syncro_base_url() . ltrim($path, '/');
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    $query['api_key'] = (string)SYNCRO_API_KEY;
    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $request = [
        'method' => $method,
        'path' => syncro_sanitized_request_path($url),
    ];

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'status' => $status,
            'errors' => ['Syncro request failed: ' . ($curlError ?: 'unknown cURL error')],
            'request' => $request,
            'response_excerpt' => '',
        ];
    }

    $decoded = json_decode($body, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => is_array($decoded) ? $decoded : ['raw' => $body], 'request' => $request];
    }

    $errors = [];
    if (is_array($decoded)) {
        foreach (['message', 'error', 'errors', 'full_messages', 'details'] as $key) {
            if (!empty($decoded[$key])) {
                $errors = array_merge($errors, syncro_flatten_errors($decoded[$key], is_string($key) && $key !== 'errors' ? $key : ''));
            }
        }
        if (!$errors) {
            $errors = syncro_flatten_errors($decoded);
        }
    }
    $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));
    if (!$errors) {
        $errors[] = 'Syncro API returned HTTP ' . $status . '.';
    }
    return [
        'ok' => false,
        'status' => $status,
        'errors' => $errors,
        'data' => $decoded,
        'raw_body' => $body,
        'request' => $request,
        'response_excerpt' => syncro_redacted_response_excerpt($body),
    ];
}

function syncro_client_columns_ready(): bool
{
    return db_column_exists('clients', 'syncro_customer_id') && db_column_exists('clients', 'syncro_sync_status');
}


function syncro_policy_folder_standard_tree(): array
{
    return [
        'Deploy' => ['Workstations', 'Servers'],
        'Production' => ['Workstations', 'Servers'],
    ];
}

function syncro_policy_assignment_map(): array
{
    $defaults = [
        'manage.deploy.workstations' => null,
        'manage.deploy.servers' => null,
        'manage.production.workstations' => null,
        'manage.production.servers' => null,
        'protect.deploy.workstations' => null,
        'protect.deploy.servers' => null,
        'protect.production.workstations' => null,
        'protect.production.servers' => null,
        'govern.deploy.workstations' => null,
        'govern.deploy.servers' => null,
        'govern.production.workstations' => null,
        'govern.production.servers' => null,
    ];
    $configured = defined('SYNCRO_POLICY_ASSIGNMENTS') && is_array(SYNCRO_POLICY_ASSIGNMENTS) ? SYNCRO_POLICY_ASSIGNMENTS : [];
    foreach ($defaults as $key => $_) {
        if (array_key_exists($key, $configured) && trim((string)$configured[$key]) !== '') {
            $defaults[$key] = (int)$configured[$key] > 0 ? (int)$configured[$key] : trim((string)$configured[$key]);
        }
    }
    return $defaults;
}

function syncro_policy_assignment_missing_ids(): array
{
    $missing = [];
    foreach (syncro_policy_assignment_map() as $key => $policyId) {
        if ($policyId === null || trim((string)$policyId) === '') {
            $missing[] = $key;
        }
    }
    return $missing;
}

function syncro_policy_assignment_status(): string
{
    $missing = syncro_policy_assignment_missing_ids();
    if (!$missing) {
        return 'CONFIGURED';
    }
    return count($missing) === count(syncro_policy_assignment_map()) ? 'PENDING_MANUAL' : 'PARTIAL_CONFIGURED';
}

function syncro_policy_assignment_status_message(): string
{
    $missing = syncro_policy_assignment_missing_ids();
    if (!$missing) {
        return 'All Syncro policy assignment IDs are configured. Policy assignment writes remain idempotent and only target OPS-managed folders.';
    }
    return 'PENDING_MANUAL: Missing Syncro policy IDs for ' . implode(', ', $missing) . '. No policy assignment writes will be attempted until every required policy ID is configured.';
}

function syncro_folder_map_table_ready(): bool
{
    if (!db_table_exists('client_syncro_folder_map')) {
        db()->exec("CREATE TABLE IF NOT EXISTS client_syncro_folder_map (
            client_id BIGINT(20) UNSIGNED NOT NULL,
            syncro_customer_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            deploy_workstations_folder_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            deploy_servers_folder_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            production_workstations_folder_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            production_servers_folder_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
            provision_status VARCHAR(64) NOT NULL DEFAULT 'PENDING',
            provision_message TEXT NULL,
            last_error TEXT NULL,
            policy_assignment_status VARCHAR(64) NOT NULL DEFAULT 'PENDING_MANUAL',
            policy_assignment_message TEXT NULL,
            policy_assigned_at DATETIME NULL DEFAULT NULL,
            provisioned_at DATETIME NULL DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (client_id),
            KEY idx_client_syncro_folder_map_customer (syncro_customer_id),
            KEY idx_client_syncro_folder_map_status (provision_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    $columns = [
        'syncro_customer_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
        'deploy_workstations_folder_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
        'deploy_servers_folder_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
        'production_workstations_folder_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
        'production_servers_folder_id' => 'BIGINT(20) UNSIGNED NULL DEFAULT NULL',
        'provision_status' => "VARCHAR(64) NOT NULL DEFAULT 'PENDING'",
        'provision_message' => 'TEXT NULL',
        'last_error' => 'TEXT NULL',
        'policy_assignment_status' => "VARCHAR(64) NOT NULL DEFAULT 'PENDING_MANUAL'",
        'policy_assignment_message' => 'TEXT NULL',
        'policy_assigned_at' => 'DATETIME NULL DEFAULT NULL',
        'provisioned_at' => 'DATETIME NULL DEFAULT NULL',
        'updated_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
    ];
    foreach ($columns as $column => $definition) {
        if (!db_column_exists('client_syncro_folder_map', $column)) {
            db()->exec('ALTER TABLE client_syncro_folder_map ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
    return true;
}

function syncro_get_client_folder_map(int $clientId): ?array
{
    if ($clientId <= 0 || !syncro_folder_map_table_ready()) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM client_syncro_folder_map WHERE client_id = ? LIMIT 1');
    $stmt->execute([$clientId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function syncro_folder_map_complete(array $map): bool
{
    foreach (['deploy_workstations_folder_id', 'deploy_servers_folder_id', 'production_workstations_folder_id', 'production_servers_folder_id'] as $column) {
        if ((int)($map[$column] ?? 0) <= 0) {
            return false;
        }
    }
    return true;
}

function syncro_upsert_client_folder_map_status(int $clientId, ?int $syncroCustomerId, string $status, string $message = '', string $lastError = '', bool $provisioned = false): array
{
    syncro_folder_map_table_ready();
    $existing = syncro_get_client_folder_map($clientId) ?: [];
    $status = strtoupper(trim($status)) ?: 'PENDING';
    $message = syncro_mask_secrets($message);
    $lastError = syncro_mask_secrets($lastError);

    $sql = 'INSERT INTO client_syncro_folder_map (
            client_id, syncro_customer_id, deploy_workstations_folder_id, deploy_servers_folder_id,
            production_workstations_folder_id, production_servers_folder_id, provision_status,
            provision_message, last_error, provisioned_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . ($provisioned ? 'NOW()' : 'NULL') . ', NOW())
        ON DUPLICATE KEY UPDATE
            syncro_customer_id = VALUES(syncro_customer_id),
            deploy_workstations_folder_id = COALESCE(client_syncro_folder_map.deploy_workstations_folder_id, VALUES(deploy_workstations_folder_id)),
            deploy_servers_folder_id = COALESCE(client_syncro_folder_map.deploy_servers_folder_id, VALUES(deploy_servers_folder_id)),
            production_workstations_folder_id = COALESCE(client_syncro_folder_map.production_workstations_folder_id, VALUES(production_workstations_folder_id)),
            production_servers_folder_id = COALESCE(client_syncro_folder_map.production_servers_folder_id, VALUES(production_servers_folder_id)),
            provision_status = VALUES(provision_status),
            provision_message = VALUES(provision_message),
            last_error = VALUES(last_error),
            provisioned_at = CASE WHEN VALUES(provisioned_at) IS NULL THEN client_syncro_folder_map.provisioned_at ELSE VALUES(provisioned_at) END,
            updated_at = NOW()';

    db()->prepare($sql)->execute([
        $clientId,
        $syncroCustomerId,
        !empty($existing['deploy_workstations_folder_id']) ? (int)$existing['deploy_workstations_folder_id'] : null,
        !empty($existing['deploy_servers_folder_id']) ? (int)$existing['deploy_servers_folder_id'] : null,
        !empty($existing['production_workstations_folder_id']) ? (int)$existing['production_workstations_folder_id'] : null,
        !empty($existing['production_servers_folder_id']) ? (int)$existing['production_servers_folder_id'] : null,
        $status,
        $message !== '' ? $message : null,
        $lastError !== '' ? $lastError : null,
    ]);

    return syncro_get_client_folder_map($clientId) ?: [];
}


function syncro_folder_map_columns(): array
{
    return [
        'deploy_workstations_folder_id' => ['parent' => 'Deploy', 'child' => 'Workstations', 'path' => 'Deploy/Workstations'],
        'deploy_servers_folder_id' => ['parent' => 'Deploy', 'child' => 'Servers', 'path' => 'Deploy/Servers'],
        'production_workstations_folder_id' => ['parent' => 'Production', 'child' => 'Workstations', 'path' => 'Production/Workstations'],
        'production_servers_folder_id' => ['parent' => 'Production', 'child' => 'Servers', 'path' => 'Production/Servers'],
    ];
}

function syncro_update_client_folder_map_ids(int $clientId, int $syncroCustomerId, array $ids, string $status, string $message, string $lastError = '', bool $provisioned = false, string $policyStatus = 'PENDING_MANUAL', string $policyMessage = ''): array
{
    syncro_folder_map_table_ready();
    $sql = 'INSERT INTO client_syncro_folder_map (
            client_id, syncro_customer_id, deploy_workstations_folder_id, deploy_servers_folder_id,
            production_workstations_folder_id, production_servers_folder_id, provision_status,
            provision_message, last_error, policy_assignment_status, policy_assignment_message,
            policy_assigned_at, provisioned_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ' . ($provisioned ? 'NOW()' : 'NULL') . ', NOW())
        ON DUPLICATE KEY UPDATE
            syncro_customer_id = VALUES(syncro_customer_id),
            deploy_workstations_folder_id = COALESCE(VALUES(deploy_workstations_folder_id), client_syncro_folder_map.deploy_workstations_folder_id),
            deploy_servers_folder_id = COALESCE(VALUES(deploy_servers_folder_id), client_syncro_folder_map.deploy_servers_folder_id),
            production_workstations_folder_id = COALESCE(VALUES(production_workstations_folder_id), client_syncro_folder_map.production_workstations_folder_id),
            production_servers_folder_id = COALESCE(VALUES(production_servers_folder_id), client_syncro_folder_map.production_servers_folder_id),
            provision_status = VALUES(provision_status),
            provision_message = VALUES(provision_message),
            last_error = VALUES(last_error),
            policy_assignment_status = VALUES(policy_assignment_status),
            policy_assignment_message = VALUES(policy_assignment_message),
            provisioned_at = CASE WHEN VALUES(provisioned_at) IS NULL THEN client_syncro_folder_map.provisioned_at ELSE VALUES(provisioned_at) END,
            updated_at = NOW()';
    db()->prepare($sql)->execute([
        $clientId,
        $syncroCustomerId,
        !empty($ids['deploy_workstations_folder_id']) ? (int)$ids['deploy_workstations_folder_id'] : null,
        !empty($ids['deploy_servers_folder_id']) ? (int)$ids['deploy_servers_folder_id'] : null,
        !empty($ids['production_workstations_folder_id']) ? (int)$ids['production_workstations_folder_id'] : null,
        !empty($ids['production_servers_folder_id']) ? (int)$ids['production_servers_folder_id'] : null,
        strtoupper(trim($status)) ?: 'PENDING',
        syncro_mask_secrets($message) ?: null,
        syncro_mask_secrets($lastError) ?: null,
        strtoupper(trim($policyStatus)) ?: 'PENDING_MANUAL',
        syncro_mask_secrets($policyMessage) ?: null,
    ]);
    return syncro_get_client_folder_map($clientId) ?: [];
}

function syncro_extract_policy_folders(mixed $data): array
{
    if (!is_array($data)) {
        return [];
    }
    foreach (['policy_folders', 'policyFolders', 'folders', 'data'] as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            $data = $data[$key];
            break;
        }
    }
    if (isset($data['id'])) {
        return [$data];
    }
    return array_values(array_filter($data, static fn($item): bool => is_array($item) && isset($item['id'])));
}

function syncro_policy_folder_name(array $folder): string
{
    foreach (['name', 'title', 'folder_name', 'policy_folder_name'] as $key) {
        if (isset($folder[$key]) && trim((string)$folder[$key]) !== '') {
            return trim((string)$folder[$key]);
        }
    }
    return '';
}

function syncro_policy_folder_parent_id(array $folder): ?int
{
    foreach (['parent_id', 'parent_policy_folder_id', 'parent_folder_id'] as $key) {
        if (isset($folder[$key]) && (int)$folder[$key] > 0) {
            return (int)$folder[$key];
        }
    }
    return null;
}


function syncro_policy_folder_normalized_name(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
    return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
}

function syncro_client_policy_root_name_candidates(array $client = []): array
{
    $candidates = [];
    foreach (['legal_name', 'dba_name', 'business_name', 'customer_name', 'name'] as $key) {
        if (isset($client[$key]) && trim((string)$client[$key]) !== '') {
            $candidates[] = trim((string)$client[$key]);
        }
    }
    return array_values(array_unique($candidates));
}

function syncro_resolve_customer_root_policy_folder(array $folders, array $client = []): array
{
    $roots = [];
    foreach ($folders as $folder) {
        if (!is_array($folder)) {
            continue;
        }
        $id = (int)($folder['id'] ?? 0);
        $name = syncro_policy_folder_name($folder);
        if ($id <= 0 || $name === '' || syncro_policy_folder_parent_id($folder) !== null) {
            continue;
        }
        $roots[] = ['id' => $id, 'name' => $name, 'folder' => $folder];
    }

    if (!$roots) {
        return [
            'ok' => false,
            'status' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL',
            'message' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS could list Syncro policy folders, but no customer root folder with parent_id null was returned. No folders were created or changed.',
            'roots' => [],
        ];
    }

    $candidates = syncro_client_policy_root_name_candidates($client);
    if ($candidates) {
        $candidateNames = array_map('syncro_policy_folder_normalized_name', $candidates);
        $matches = [];
        foreach ($roots as $root) {
            if (in_array(syncro_policy_folder_normalized_name((string)$root['name']), $candidateNames, true)) {
                $matches[] = $root;
            }
        }
        if (count($matches) === 1) {
            return ['ok' => true, 'root' => $matches[0], 'match' => 'client_name'];
        }
        if (count($matches) > 1) {
            $names = array_map(static fn(array $root): string => '#' . $root['id'] . ' "' . $root['name'] . '"', $matches);
            return [
                'ok' => false,
                'status' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL',
                'message' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: Multiple Syncro customer root policy folders matched the OPS client/customer name (' . implode(', ', $names) . '). No folders were created or changed.',
                'roots' => $roots,
            ];
        }
    }

    if (count($roots) === 1) {
        return ['ok' => true, 'root' => $roots[0], 'match' => 'single_root'];
    }

    $names = array_map(static fn(array $root): string => '#' . $root['id'] . ' "' . $root['name'] . '"', $roots);
    $candidateText = $candidates ? (' Candidate OPS names: ' . implode(', ', $candidates) . '.') : ' No OPS client/customer name candidates were available.';
    return [
        'ok' => false,
        'status' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL',
        'message' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: Multiple Syncro customer root policy folders with parent_id null were returned and none could be resolved unambiguously (' . implode(', ', $names) . ').' . $candidateText . ' No folders were created or changed.',
        'roots' => $roots,
    ];
}

function syncro_list_customer_policy_folders(int $syncroCustomerId): array
{
    $resp = syncro_api_request('GET', 'policy_folders', ['customer_id' => $syncroCustomerId]);
    if (empty($resp['ok'])) {
        return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to list Syncro policy folders.'], 'response' => $resp];
    }
    return ['ok' => true, 'folders' => syncro_extract_policy_folders($resp['data'] ?? [])];
}

function syncro_create_policy_folder(int $syncroCustomerId, string $name, ?int $parentId = null): array
{
    $payload = ['name' => $name, 'customer_id' => $syncroCustomerId];
    if ($parentId !== null && $parentId > 0) {
        $payload['parent_id'] = $parentId;
    }
    $resp = syncro_api_request('POST', 'policy_folders', [], $payload);
    if (empty($resp['ok'])) {
        $message = implode(' ', array_map('strval', (array)($resp['errors'] ?? ['Unable to create Syncro policy folder.'])));
        syncro_debug_log('policy_folder_create_failed', [
            'customer_id' => $syncroCustomerId,
            'folder_name' => $name,
            'has_parent' => $parentId !== null,
            'status' => $resp['status'] ?? null,
            'message' => syncro_mask_secrets($message),
        ]);
        return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to create Syncro policy folder.'], 'response' => $resp];
    }
    $folders = syncro_extract_policy_folders($resp['data'] ?? []);
    $folder = $folders[0] ?? ($resp['data']['policy_folder'] ?? $resp['data'] ?? []);
    $id = is_array($folder) ? (int)($folder['id'] ?? 0) : 0;
    return $id > 0 ? ['ok' => true, 'id' => $id, 'folder' => $folder] : ['ok' => false, 'errors' => ['Syncro created a policy folder but did not return its ID.'], 'response' => $resp];
}

function syncro_ensure_customer_policy_folder_tree(int $syncroCustomerId, array $existingMap = [], bool $refresh = false, array $client = []): array
{
    if (!$refresh && syncro_folder_map_complete($existingMap)) {
        return ['ok' => true, 'ids' => [], 'message' => 'Existing Syncro folder IDs retained; no folder changes were made.'];
    }

    $listed = syncro_list_customer_policy_folders($syncroCustomerId);
    if (empty($listed['ok'])) {
        $message = 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS could not list Syncro policy folders through GET /policy_folders?customer_id=. No folders were created or changed.';
        return ['ok' => false, 'manual_required' => true, 'message' => $message, 'errors' => $listed['errors'] ?? [$message]];
    }

    $folders = (array)($listed['folders'] ?? []);
    $rootResolution = syncro_resolve_customer_root_policy_folder($folders, $client);
    if (empty($rootResolution['ok'])) {
        $message = (string)($rootResolution['message'] ?? 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS could not resolve the Syncro customer root policy folder. No folders were created or changed.');
        return ['ok' => false, 'manual_required' => true, 'message' => $message, 'errors' => [$message], 'root_resolution' => $rootResolution];
    }

    $root = (array)($rootResolution['root'] ?? []);
    $rootId = (int)($root['id'] ?? 0);
    $rootName = (string)($root['name'] ?? '');
    if ($rootId <= 0 || $rootName === '') {
        $message = 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS resolved an invalid Syncro customer root policy folder. No folders were created or changed.';
        return ['ok' => false, 'manual_required' => true, 'message' => $message, 'errors' => [$message], 'root_resolution' => $rootResolution];
    }

    $byParentAndName = [];
    foreach ($folders as $folder) {
        if (!is_array($folder)) {
            continue;
        }
        $id = (int)($folder['id'] ?? 0);
        $name = syncro_policy_folder_name($folder);
        $parentId = syncro_policy_folder_parent_id($folder);
        if ($id <= 0 || $name === '' || $parentId === null) {
            continue;
        }
        $byParentAndName[$parentId . ':' . syncro_policy_folder_normalized_name($name)] = $id;
    }

    $parentIds = [];
    foreach (array_keys(syncro_policy_folder_standard_tree()) as $parentName) {
        $parentKey = $rootId . ':' . syncro_policy_folder_normalized_name($parentName);
        $parentIds[$parentName] = $byParentAndName[$parentKey] ?? null;
        if (!$parentIds[$parentName]) {
            $created = syncro_create_policy_folder($syncroCustomerId, $parentName, $rootId);
            if (empty($created['ok'])) {
                $message = 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS resolved Syncro customer root #' . $rootId . ' "' . $rootName . '" but could not create missing folder "' . $parentName . '" under that root via POST /policy_folders. No deletes, renames, or asset moves were attempted.';
                return ['ok' => false, 'manual_required' => true, 'message' => $message, 'errors' => $created['errors'] ?? [$message], 'root' => $root];
            }
            $parentIds[$parentName] = (int)$created['id'];
            $byParentAndName[$parentKey] = (int)$created['id'];
        }
    }

    $ids = [];
    foreach (syncro_folder_map_columns() as $column => $meta) {
        if (!$refresh && !empty($existingMap[$column])) {
            $ids[$column] = (int)$existingMap[$column];
            continue;
        }
        $parentId = (int)($parentIds[$meta['parent']] ?? 0);
        $childKey = $parentId . ':' . syncro_policy_folder_normalized_name((string)$meta['child']);
        $ids[$column] = $byParentAndName[$childKey] ?? null;
        if (!$ids[$column]) {
            $created = syncro_create_policy_folder($syncroCustomerId, (string)$meta['child'], $parentId);
            if (empty($created['ok'])) {
                $message = 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: OPS resolved Syncro customer root #' . $rootId . ' "' . $rootName . '" but could not create missing child folder "' . $meta['path'] . '" via POST /policy_folders. No deletes, renames, or asset moves were attempted.';
                return ['ok' => false, 'manual_required' => true, 'message' => $message, 'errors' => $created['errors'] ?? [$message], 'root' => $root];
            }
            $ids[$column] = (int)$created['id'];
            $byParentAndName[$childKey] = (int)$created['id'];
        }
    }

    return [
        'ok' => true,
        'ids' => $ids,
        'root' => $root,
        'message' => 'Syncro policy folder tree verified/created under customer root #' . $rootId . ' "' . $rootName . '" idempotently. No deletes, renames, or asset moves were attempted.',
    ];
}

function syncro_assign_policies_to_folder_tree(int $syncroCustomerId, array $folderMap): array
{
    $missing = syncro_policy_assignment_missing_ids();
    if ($missing) {
        $message = syncro_policy_assignment_status_message();
        return ['ok' => true, 'skipped' => true, 'status' => 'PENDING_MANUAL', 'message' => $message, 'missing' => $missing];
    }

    $policies = syncro_policy_assignment_map();
    $folderBySegment = [
        'deploy.workstations' => (int)($folderMap['deploy_workstations_folder_id'] ?? 0),
        'deploy.servers' => (int)($folderMap['deploy_servers_folder_id'] ?? 0),
        'production.workstations' => (int)($folderMap['production_workstations_folder_id'] ?? 0),
        'production.servers' => (int)($folderMap['production_servers_folder_id'] ?? 0),
    ];
    foreach ($folderBySegment as $segment => $folderId) {
        if ($folderId <= 0) {
            return ['ok' => true, 'skipped' => true, 'status' => 'PENDING_MANUAL', 'message' => 'PENDING_MANUAL: Folder ID missing for ' . $segment . '; policy assignment was not attempted.'];
        }
    }

    $assignments = [];
    foreach ($policies as $key => $policyId) {
        $parts = explode('.', $key, 2);
        $segment = $parts[1] ?? '';
        $assignments[] = ['policy_folder_id' => $folderBySegment[$segment] ?? 0, 'policy_id' => $policyId];
    }

    $resp = syncro_api_request('PATCH', 'customers/' . $syncroCustomerId . '/policy_assignments', [], ['assignments' => $assignments]);
    if (empty($resp['ok'])) {
        $message = 'PENDING_MANUAL: Syncro policy assignment write was not accepted by PATCH /customers/{customer_id}/policy_assignments. Existing unrelated assignments were not removed or overwritten.';
        syncro_debug_log('policy_assignment_pending_manual', [
            'customer_id' => $syncroCustomerId,
            'assignment_count' => count($assignments),
            'status' => $resp['status'] ?? null,
            'message' => syncro_mask_secrets(implode(' ', array_map('strval', (array)($resp['errors'] ?? [])))),
        ]);
        return ['ok' => true, 'skipped' => true, 'status' => 'PENDING_MANUAL', 'message' => $message, 'errors' => $resp['errors'] ?? []];
    }

    return ['ok' => true, 'status' => 'READY', 'message' => 'Syncro policies assigned to OPS-managed folder tree.'];
}

function syncro_provision_client_folder_map(int $clientId, ?int $syncroCustomerId = null, bool $refresh = false): array
{
    if ($clientId <= 0) {
        return ['ok' => false, 'status' => 'ERROR', 'message' => 'Invalid client for Syncro folder provisioning.', 'errors' => ['Invalid client for Syncro folder provisioning.']];
    }

    $client = client_get_by_id($clientId);
    if (!$client) {
        return ['ok' => false, 'status' => 'ERROR', 'message' => 'Client record not found for Syncro folder provisioning.', 'errors' => ['Client record not found for Syncro folder provisioning.']];
    }
    if (($syncroCustomerId ?? 0) <= 0 && !empty($client['syncro_customer_id'])) {
        $syncroCustomerId = (int)$client['syncro_customer_id'];
    }
    if (($syncroCustomerId ?? 0) <= 0) {
        $message = 'Syncro folder provisioning is pending until the client has a Syncro customer ID.';
        $map = syncro_upsert_client_folder_map_status($clientId, null, 'PENDING', $message);
        return ['ok' => true, 'skipped' => true, 'status' => 'PENDING', 'message' => $message, 'folder_map' => $map];
    }

    if (syncro_is_staging_mode() && !syncro_staging_writes_allowed()) {
        $message = 'Staging mode: Syncro folder provisioning write calls are blocked. Set local SYNCRO_ALLOW_STAGING_WRITES=true only for controlled OPS staging tests; DELETE remains blocked.';
        $map = syncro_upsert_client_folder_map_status($clientId, (int)$syncroCustomerId, 'STAGING_BLOCKED', $message);
        return ['ok' => true, 'skipped' => true, 'staging_blocked' => true, 'status' => 'STAGING_BLOCKED', 'message' => $message, 'folder_map' => $map];
    }

    $existing = syncro_get_client_folder_map($clientId) ?: [];
    if (!$refresh && syncro_folder_map_complete($existing)) {
        $policy = syncro_assign_policies_to_folder_tree((int)$syncroCustomerId, $existing);
        $policyStatus = (string)($policy['status'] ?? syncro_policy_assignment_status());
        $policyMessage = (string)($policy['message'] ?? syncro_policy_assignment_status_message());
        $message = 'Existing Syncro folder IDs retained; no folder changes were made.';
        $map = syncro_update_client_folder_map_ids($clientId, (int)$syncroCustomerId, [], 'READY', $message, '', true, $policyStatus, $policyMessage);
        return ['ok' => true, 'status' => 'READY', 'message' => $message . ' Policy assignment: ' . $policyMessage, 'folder_map' => $map, 'policy_assignment_status' => $policyStatus, 'policy_assignment_message' => $policyMessage];
    }

    $tree = syncro_ensure_customer_policy_folder_tree((int)$syncroCustomerId, $existing, $refresh, $client);
    if (empty($tree['ok'])) {
        $message = (string)($tree['message'] ?? 'POLICY_FOLDER_PROVISION_PENDING_MANUAL: Syncro policy folder endpoint support is incomplete.');
        $map = syncro_upsert_client_folder_map_status($clientId, (int)$syncroCustomerId, 'POLICY_FOLDER_PROVISION_PENDING_MANUAL', $message, implode(' ', array_map('strval', (array)($tree['errors'] ?? []))));
        return ['ok' => true, 'skipped' => true, 'manual_required' => true, 'status' => 'POLICY_FOLDER_PROVISION_PENDING_MANUAL', 'message' => $message, 'errors' => $tree['errors'] ?? [], 'folder_map' => $map, 'policy_assignment_status' => syncro_policy_assignment_status(), 'policy_assignment_message' => syncro_policy_assignment_status_message()];
    }

    $ids = array_merge(array_intersect_key($existing, syncro_folder_map_columns()), (array)($tree['ids'] ?? []));
    $provisionalMap = array_merge($existing, $ids);
    $policy = syncro_assign_policies_to_folder_tree((int)$syncroCustomerId, $provisionalMap);
    $policyStatus = (string)($policy['status'] ?? syncro_policy_assignment_status());
    $policyMessage = (string)($policy['message'] ?? syncro_policy_assignment_status_message());
    $message = (string)($tree['message'] ?? 'Syncro policy folder tree verified/created idempotently.');
    $map = syncro_update_client_folder_map_ids($clientId, (int)$syncroCustomerId, $ids, 'READY', $message, '', true, $policyStatus, $policyMessage);

    return ['ok' => true, 'status' => 'READY', 'message' => $message . ' Policy assignment: ' . $policyMessage, 'folder_map' => $map, 'policy_assignment_status' => $policyStatus, 'policy_assignment_message' => $policyMessage];
}

function syncro_attach_folder_provisioning_result(array $result, int $clientId, ?int $syncroCustomerId): array
{
    $provision = syncro_provision_client_folder_map($clientId, $syncroCustomerId);
    $result['folder_provisioning'] = $provision;
    $result['folder_provision_status'] = (string)($provision['status'] ?? 'PENDING');
    $result['folder_provision_message'] = (string)($provision['message'] ?? '');
    if (!isset($result['message']) || trim((string)$result['message']) === '') {
        $result['message'] = syncro_action_success_message((string)($result['action'] ?? ''));
    }
    if ($result['folder_provision_message'] !== '') {
        $result['message'] .= ' Folder provisioning: ' . $result['folder_provision_message'];
    }
    return $result;
}

function syncro_normalize_subdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~^https?://~i', '', $value) ?? $value;
    $value = preg_replace('~/.*$~', '', $value) ?? $value;
    $value = preg_replace('~\.syncromsp\.com$~i', '', $value) ?? $value;
    return trim($value, '/ ');
}

function syncro_customer_payload_from_client(array $client): array
{
    $contact = null;
    if (!empty($client['client_id'])) {
        $contacts = client_get_contacts((int)$client['client_id']);
        $contact = $contacts[0] ?? null;
    }
    $location = null;
    if (!empty($client['client_id'])) {
        $locations = client_get_locations((int)$client['client_id']);
        $location = $locations[0] ?? null;
    }

    $first = trim((string)($contact['first_name'] ?? ''));
    $last = trim((string)($contact['last_name'] ?? ''));
    $fallbackName = preg_split('/\s+/', trim((string)($client['legal_name'] ?? '')), 2);
    if ($first === '' && !empty($fallbackName[0])) $first = $fallbackName[0];
    if ($last === '' && !empty($fallbackName[1])) $last = $fallbackName[1];

    return [
        'business_name' => (string)($client['dba_name'] ?: $client['legal_name'] ?: ''),
        'firstname' => $first,
        'lastname' => $last,
        'email' => (string)($contact['email'] ?? $client['email'] ?? ''),
        'phone' => (string)($contact['phone'] ?? $client['phone'] ?? ''),
        'address' => (string)($location['address1'] ?? ''),
        'address_2' => (string)($location['address2'] ?? ''),
        'city' => (string)($location['city'] ?? ''),
        'state' => (string)($location['state'] ?? ''),
        'zip' => (string)($location['postal_code'] ?? ''),
        'website' => (string)($client['website'] ?? ''),
        'notes' => (string)($client['notes'] ?? ''),
    ];
}

function syncro_required_fields_status(array $client): array
{
    $payload = syncro_customer_payload_from_client($client);
    $requirements = [
        'business_name' => 'Company name',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Postal code',
    ];
    $missing = [];
    foreach ($requirements as $key => $label) {
        if (trim((string)($payload[$key] ?? '')) === '') {
            $missing[$key] = $label;
        }
    }
    return ['payload' => $payload, 'missing' => $missing];
}

function syncro_validate_client_readiness(array $client): array
{
    $status = syncro_required_fields_status($client);
    $payload = $status['payload'];
    $errors = [];
    foreach ($status['missing'] as $label) {
        $errors[] = $label . ' is required before this client can sync to Syncro.';
    }
    $email = trim((string)($payload['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid for Syncro sync.';
    }
    return ['ok' => !$errors, 'errors' => $errors, 'payload' => $payload, 'missing' => $status['missing']];
}


function syncro_normalize_match_text(?string $value): string
{
    $value = trim(mb_strtolower((string)$value, 'UTF-8'));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function syncro_normalize_phone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (strlen($digits) > 10 && str_starts_with($digits, '1')) {
        $digits = substr($digits, -10);
    }
    return $digits;
}

function syncro_customer_records_from_response(array $data): array
{
    $keys = ['customers', 'customer', 'customer_organizations', 'customer_organization', 'records', 'items', 'results', 'data'];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = $data[$key];
        if (is_array($value)) {
            if (isset($value['id']) && is_array($value)) {
                return [$value];
            }
            if (array_is_list($value)) {
                return array_values(array_filter($value, static fn($row): bool => is_array($row)));
            }
        }
    }
    if (isset($data['id'])) {
        return [$data];
    }
    if (array_is_list($data)) {
        return array_values(array_filter($data, static fn($row): bool => is_array($row)));
    }
    return [];
}

function syncro_customer_match_result(array $row, array $payload): ?array
{
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $business = syncro_normalize_match_text((string)($row['business_name'] ?? $row['name'] ?? ''));
    $email = syncro_normalize_match_text((string)($row['email'] ?? ''));
    $phone = syncro_normalize_phone((string)($row['phone'] ?? $row['mobile'] ?? ''));
    $city = syncro_normalize_match_text((string)($row['city'] ?? ''));
    $state = syncro_normalize_match_text((string)($row['state'] ?? ''));
    $zip = syncro_normalize_match_text((string)($row['zip'] ?? $row['postal_code'] ?? ''));

    $targetBusiness = syncro_normalize_match_text((string)($payload['business_name'] ?? ''));
    $targetEmail = syncro_normalize_match_text((string)($payload['email'] ?? ''));
    $targetPhone = syncro_normalize_phone((string)($payload['phone'] ?? ''));
    $targetCity = syncro_normalize_match_text((string)($payload['city'] ?? ''));
    $targetState = syncro_normalize_match_text((string)($payload['state'] ?? ''));
    $targetZip = syncro_normalize_match_text((string)($payload['zip'] ?? ''));

    $emailMatch = $targetEmail !== '' && $email !== '' && $targetEmail === $email;
    $businessMatch = $targetBusiness !== '' && $business !== '' && $targetBusiness === $business;
    $phoneMatch = $targetPhone !== '' && $phone !== '' && $targetPhone === $phone;
    $cityMatch = $targetCity !== '' && $city !== '' && $targetCity === $city;
    $stateMatch = $targetState !== '' && $state !== '' && $targetState === $state;
    $zipMatch = $targetZip !== '' && $zip !== '' && $targetZip === $zip;

    if (!$emailMatch && !$businessMatch && !$phoneMatch && !($cityMatch && $stateMatch && $zipMatch)) {
        return null;
    }

    $score = 0;
    $signals = [];
    if ($emailMatch) {
        $score += 10;
        $signals[] = 'email';
    }
    if ($businessMatch) {
        $score += 8;
        $signals[] = 'business';
    }
    if ($phoneMatch) {
        $score += 4;
        $signals[] = 'phone';
    }
    if ($cityMatch) {
        $score += 1;
        $signals[] = 'city';
    }
    if ($stateMatch) {
        $score += 1;
        $signals[] = 'state';
    }
    if ($zipMatch) {
        $score += 2;
        $signals[] = 'zip';
    }

    $confident = ($emailMatch && ($businessMatch || $phoneMatch || $zipMatch || ($cityMatch && $stateMatch)))
        || ($businessMatch && ($phoneMatch || $zipMatch || ($cityMatch && $stateMatch)))
        || ($emailMatch && $businessMatch);

    return [
        'id' => $id,
        'row' => $row,
        'score' => $score,
        'signals' => $signals,
        'confident' => $confident,
        'label' => (string)($row['business_name'] ?? $row['name'] ?? ('Customer #' . $id)),
    ];
}

function syncro_find_existing_customer(array $payload): array
{
    $matches = [];
    $page = 1;
    $maxPages = 15;

    while ($page <= $maxPages) {
        $resp = syncro_api_request('GET', 'customers', ['page' => $page]);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to search existing Syncro customers.']];
        }

        $rows = syncro_customer_records_from_response((array)($resp['data'] ?? []));
        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            $match = syncro_customer_match_result($row, $payload);
            if ($match !== null) {
                $matches[$match['id']] = $match;
            }
        }

        $page++;
    }

    if (!$matches) {
        return ['ok' => true, 'found' => false, 'matches' => []];
    }

    $matches = array_values($matches);
    usort($matches, static function (array $a, array $b): int {
        return [$b['score'], $b['id']] <=> [$a['score'], $a['id']];
    });

    $best = $matches[0] ?? null;
    $confident = array_values(array_filter($matches, static fn(array $m): bool => !empty($m['confident'])));

    if (!$best) {
        return ['ok' => true, 'found' => false, 'matches' => []];
    }

    if (count($confident) === 1) {
        return ['ok' => true, 'found' => true, 'match' => $confident[0], 'matches' => $matches];
    }

    if (count($confident) > 1) {
        $topScore = (int)($confident[0]['score'] ?? 0);
        $top = array_values(array_filter($confident, static fn(array $m): bool => (int)($m['score'] ?? 0) === $topScore));
        if (count($top) === 1) {
            return ['ok' => true, 'found' => true, 'match' => $top[0], 'matches' => $matches];
        }
        return ['ok' => true, 'found' => false, 'ambiguous' => true, 'matches' => $top];
    }

    if (count($matches) === 1 && !empty($best['score']) && (int)$best['score'] >= 12) {
        return ['ok' => true, 'found' => true, 'match' => $best, 'matches' => $matches];
    }

    return ['ok' => true, 'found' => false, 'ambiguous' => true, 'matches' => array_slice($matches, 0, 3)];
}

function syncro_attempt_existing_customer_link(int $clientId, array $payload): array
{
    $search = syncro_find_existing_customer($payload);
    if (empty($search['ok'])) {
        return ['ok' => false, 'errors' => $search['errors'] ?? ['Unable to search Syncro for an existing customer.']];
    }
    if (!empty($search['ambiguous'])) {
        $ids = array_map(static fn(array $m): string => '#' . (string)($m['id'] ?? 0), (array)($search['matches'] ?? []));
        $message = 'Multiple existing Syncro customers matched this client (' . implode(', ', $ids) . '). Review the organization in Syncro and relink manually before retrying.';
        syncro_mark_client($clientId, null, 'MANUAL_REVIEW', $message);
        return ['ok' => false, 'errors' => [$message], 'status' => 'MANUAL_REVIEW'];
    }
    if (empty($search['found']) || empty($search['match']['id'])) {
        return ['ok' => false, 'not_found' => true, 'errors' => ['No existing Syncro customer matched this client.']];
    }

    $syncroId = (int)$search['match']['id'];
    $resp = syncro_api_request('PUT', 'customers/' . $syncroId, [], $payload);
    if (empty($resp['ok'])) {
        $msg = implode(' ', (array)($resp['errors'] ?? []));
        syncro_debug_log('relink_update_failed', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
        syncro_mark_client($clientId, $syncroId, 'ERROR', $msg !== '' ? $msg : 'Unable to update the existing Syncro customer.');
        return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to update the existing Syncro customer.']];
    }

    $matchedBy = implode(', ', (array)($search['match']['signals'] ?? []));
    $message = 'Linked to existing Syncro customer' . ($matchedBy !== '' ? ' by ' . $matchedBy : '') . ' and updated successfully.';
    syncro_mark_client($clientId, $syncroId, 'SYNCED', $message);
    return syncro_attach_folder_provisioning_result(['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'relinked', 'status' => 'SYNCED', 'message' => $message], $clientId, $syncroId);
}

function syncro_action_success_message(?string $action): string
{
    return match (strtolower(trim((string)$action))) {
        'updated' => 'Syncro customer updated successfully.',
        'created' => 'Syncro customer created successfully.',
        'relinked' => 'Existing Syncro customer linked and updated successfully.',
        default => 'Syncro sync completed successfully.',
    };
}

function syncro_status_badge_html(?string $status, ?int $syncroCustomerId = null): string
{
    $status = strtoupper(trim((string)($status ?: 'PENDING')));
    $map = [
        'PENDING' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1', 'label' => 'PENDING'],
        'SYNCED' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0', 'label' => 'SYNCED'],
        'CONFLICT' => ['bg' => 'rgba(245,158,11,.20)', 'border' => 'rgba(245,158,11,.32)', 'color' => '#fde68a', 'label' => 'CONFLICT'],
        'MANUAL_REVIEW' => ['bg' => 'rgba(168,85,247,.20)', 'border' => 'rgba(168,85,247,.30)', 'color' => '#e9d5ff', 'label' => 'MANUAL REVIEW'],
        'STAGING_BLOCKED' => ['bg' => 'rgba(14,165,233,.18)', 'border' => 'rgba(14,165,233,.32)', 'color' => '#bae6fd', 'label' => 'STAGING BLOCKED'],
        'ERROR' => ['bg' => 'rgba(248,113,113,.18)', 'border' => 'rgba(248,113,113,.30)', 'color' => '#fecaca', 'label' => 'ERROR'],
    ];
    $style = $map[$status] ?? $map['PENDING'];
    $html = '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">'
        . htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    if (($syncroCustomerId ?? 0) > 0) {
        $html .= '<span style="font-size:12px;opacity:.72;">Customer #' . (int)$syncroCustomerId . '</span>';
    }
    return $html;
}

function syncro_retry_contract_sync(int $contractId): array
{
    $contractId = (int)$contractId;
    if ($contractId <= 0) {
        return ['ok' => false, 'errors' => ['Invalid contract for Syncro retry.']];
    }
    return syncro_contract_activation_sync($contractId);
}

function syncro_mark_client(int $clientId, ?int $syncroCustomerId, string $status, string $message = ''): void
{
    if (!syncro_client_columns_ready()) return;
    $pdo = db();
    $parts = [];
    $params = [];
    if (db_column_exists('clients', 'syncro_customer_id')) {
        $parts[] = 'syncro_customer_id = ?';
        $params[] = $syncroCustomerId;
    }
    if (db_column_exists('clients', 'syncro_sync_status')) {
        $parts[] = 'syncro_sync_status = ?';
        $params[] = strtoupper($status);
    }
    if (db_column_exists('clients', 'syncro_last_sync_at')) {
        $parts[] = 'syncro_last_sync_at = NOW()';
    }
    if (db_column_exists('clients', 'syncro_last_error')) {
        $parts[] = 'syncro_last_error = ?';
        $params[] = $message !== '' ? mb_substr($message, 0, 255) : null;
    }
    if (!$parts) return;
    $params[] = $clientId;
    $pdo->prepare('UPDATE clients SET ' . implode(', ', $parts) . ' WHERE client_id = ?')->execute($params);
}

function syncro_sync_client(int $clientId): array
{
    if ($clientId <= 0) return ['ok' => false, 'errors' => ['Invalid client for Syncro sync.']];
    if (!syncro_client_columns_ready()) return ['ok' => false, 'errors' => ['Run the Syncro integration SQL migration first.']];

    $client = client_get_by_id($clientId);
    if (!$client) return ['ok' => false, 'errors' => ['Client record not found.']];

    if (syncro_is_staging_mode()) {
        $result = syncro_staging_blocked_result();
        $syncroId = !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : null;
        syncro_debug_log('staging_client_sync_blocked', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'status' => 'STAGING_BLOCKED']);
        syncro_mark_client($clientId, $syncroId, 'STAGING_BLOCKED', (string)$result['message']);
        if ($syncroId !== null && $syncroId > 0) {
            $result = syncro_attach_folder_provisioning_result($result, $clientId, $syncroId);
        }
        return $result;
    }

    if (!syncro_is_configured()) return ['ok' => false, 'errors' => ['Add your Syncro API key to inc/config.php before syncing.']];

    $validation = syncro_validate_client_readiness($client);
    $payload = $validation['payload'];
    if (empty($validation['ok'])) {
        $msg = implode(' ', $validation['errors']);
        syncro_mark_client($clientId, !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : null, 'ERROR', $msg);
        return ['ok' => false, 'errors' => $validation['errors'], 'missing' => $validation['missing']];
    }

    $syncroId = !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : 0;

    if ($syncroId > 0) {
        $resp = syncro_api_request('PUT', 'customers/' . $syncroId, [], $payload);
        if (!empty($resp['ok'])) {
            syncro_mark_client($clientId, $syncroId, 'SYNCED', 'Updated in Syncro.');
            return syncro_attach_folder_provisioning_result(['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'updated', 'status' => 'SYNCED'], $clientId, $syncroId);
        }

        $msg = implode(' ', (array)($resp['errors'] ?? []));
        $normalized = strtolower($msg);
        if (($resp['status'] ?? 0) === 404 || str_contains($normalized, 'not found')) {
            syncro_debug_log('stale_customer_link', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'message' => $msg]);
            $syncroId = 0;
        } else {
            syncro_debug_log('update_failed', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
            syncro_mark_client($clientId, $syncroId, 'ERROR', $msg);
            return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to update Syncro customer.']];
        }
    }

    $existing = syncro_attempt_existing_customer_link($clientId, $payload);
    if (!empty($existing['ok'])) {
        return $existing;
    }
    if (empty($existing['not_found']) && !empty($existing['errors'])) {
        return $existing;
    }

    $resp = syncro_api_request('POST', 'customers', [], $payload);
    if (empty($resp['ok'])) {
        $errors = (array)($resp['errors'] ?? ['Unable to create Syncro customer.']);
        $msg = implode(' ', $errors);
        $normalized = strtolower($msg);

        if (str_contains($normalized, 'already been taken') || str_contains($normalized, 'already exists') || str_contains($normalized, 'duplicate')) {
            $relinked = syncro_attempt_existing_customer_link($clientId, $payload);
            if (!empty($relinked['ok'])) {
                return $relinked;
            }
            $status = !empty($relinked['status']) ? (string)$relinked['status'] : 'CONFLICT';
            $fallbackErrors = !empty($relinked['errors']) ? (array)$relinked['errors'] : [];
            if (!$fallbackErrors) {
                $fallbackErrors[] = 'Syncro reported this client already exists, but OPS could not confidently relink it automatically.';
                $fallbackErrors[] = 'Verify the customer in Syncro and link the stored syncro_customer_id before retrying.';
            }
            $msg = implode(' ', array_values(array_unique(array_merge($errors, $fallbackErrors))));
            syncro_debug_log('create_conflict_unresolved', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
            syncro_mark_client($clientId, null, $status, $msg);
            return ['ok' => false, 'errors' => array_values(array_unique(array_merge($errors, $fallbackErrors))), 'status' => $status];
        }

        syncro_debug_log('create_failed', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null, 'status' => 'ERROR']);
        syncro_mark_client($clientId, null, 'ERROR', $msg);
        return ['ok' => false, 'errors' => $errors, 'status' => 'ERROR'];
    }

    $customer = $resp['data']['customer'] ?? $resp['data']['customers'] ?? $resp['data'];
    if (isset($customer[0]) && is_array($customer[0])) $customer = $customer[0];
    $syncroId = (int)($customer['id'] ?? 0);
    if ($syncroId <= 0) {
        syncro_debug_log('missing_customer_id', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null]);
        syncro_mark_client($clientId, null, 'ERROR', 'Syncro customer ID missing from response.');
        return ['ok' => false, 'errors' => ['Syncro did not return a customer ID.']];
    }

    syncro_mark_client($clientId, $syncroId, 'SYNCED', 'Created in Syncro.');
    return syncro_attach_folder_provisioning_result(['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'created', 'status' => 'SYNCED'], $clientId, $syncroId);
}

function syncro_contract_activation_sync(int $contractId): array
{
    $contractId = (int)$contractId;
    if ($contractId <= 0) return ['ok' => false, 'errors' => ['Invalid contract for Syncro sync.']];
    $stmt = db()->prepare('SELECT ctr.contract_id, ctr.client_id, ctr.status, c.syncro_customer_id, c.syncro_sync_status FROM contract ctr INNER JOIN clients c ON c.client_id = ctr.client_id WHERE ctr.contract_id = ? LIMIT 1');
    $stmt->execute([$contractId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'errors' => ['Contract not found for Syncro sync.']];
    $status = strtoupper((string)$row['status']);
    if (!in_array($status, ['ACTIVE', 'ONBOARDING', 'SIGNED_PENDING_ONBOARDING'], true)) return ['ok' => true, 'skipped' => true, 'message' => 'Contract is not ready for Syncro sync yet.'];
    return syncro_sync_client((int)$row['client_id']);
}
