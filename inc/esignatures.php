<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function esignatures_config_bool(string $name, bool $default = false): bool {
    if (!defined($name)) return $default;
    $value = constant($name);
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 1;
    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function esignatures_config_string(string $name, string $default = ''): string {
    if (!defined($name)) return $default;
    return trim((string)constant($name));
}

function esignatures_is_staging_mode(): bool {
    if (function_exists('ops_is_staging_env') && ops_is_staging_env()) {
        return true;
    }
    $appEnv = defined('APP_ENV') ? strtolower(trim((string)APP_ENV)) : '';
    if (in_array($appEnv, ['staging', 'test', 'testing'], true)) {
        return true;
    }
    $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    return $host === 'ops-test.midwestmanagedit.com';
}

function esignatures_test_mode(): bool {
    if (esignatures_is_staging_mode()) {
        return true;
    }
    return esignatures_config_bool('ESIGNATURES_TEST_MODE', false);
}

function esignatures_is_configured(): bool {
    return esignatures_config_string('ESIGNATURES_API_TOKEN') !== ''
        && esignatures_config_string('ESIGNATURES_TEMPLATE_ID') !== '';
}

function esignatures_is_enabled(): bool {
    if (!esignatures_config_bool('ESIGNATURES_ENABLED', false)) {
        return false;
    }
    if (!esignatures_is_configured()) {
        return false;
    }
    if (esignatures_is_staging_mode()) {
        return true;
    }
    return esignatures_config_bool('ESIGNATURES_TEST_MODE', false) || esignatures_config_bool('ESIGNATURES_LIVE_CONFIRMED', false);
}

function esignatures_base_url(): string {
    $base = esignatures_config_string('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');
    return rtrim($base, '/');
}

function esignatures_send_url(): string {
    return esignatures_base_url() . '/contracts?token=' . rawurlencode(esignatures_config_string('ESIGNATURES_API_TOKEN'));
}

function esignatures_sanitize_log_context(mixed $value): mixed {
    if (is_array($value)) {
        $sanitized = [];
        foreach ($value as $key => $item) {
            $keyString = is_string($key) ? strtolower($key) : '';
            if (str_contains($keyString, 'token') || str_contains($keyString, 'secret') || str_contains($keyString, 'password') || str_contains($keyString, 'authorization')) {
                $sanitized[$key] = '[redacted]';
            } elseif ($keyString === 'url' && is_string($item)) {
                $sanitized[$key] = preg_replace('/([?&]token=)[^&]+/i', '$1[redacted]', $item) ?? '[redacted-url]';
            } else {
                $sanitized[$key] = esignatures_sanitize_log_context($item);
            }
        }
        return $sanitized;
    }
    return $value;
}

function esignatures_log(string $event, array $context = []): void {
    $line = '[esignatures] ' . $event;
    if ($context !== []) {
        $encoded = json_encode(esignatures_sanitize_log_context($context), JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && $encoded !== '') {
            $line .= ' ' . $encoded;
        }
    }
    error_log($line);
}

function esignatures_latest_send(int $contractId): ?array {
    if ($contractId <= 0 || !db_table_exists('esignatures_contract_send')) return null;
    $st = db()->prepare('SELECT * FROM esignatures_contract_send WHERE contract_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$contractId]);
    return $st->fetch() ?: null;
}

function esignatures_storage_ready(): bool {
    if (!db_table_exists('esignatures_contract_send')) {
        db()->exec("CREATE TABLE IF NOT EXISTS esignatures_contract_send (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            contract_id BIGINT UNSIGNED NOT NULL,
            esignatures_contract_id VARCHAR(191) NULL,
            provider_contract_id VARCHAR(191) NULL,
            status VARCHAR(64) NULL,
            provider_status VARCHAR(64) NULL,
            test_mode TINYINT(1) NOT NULL DEFAULT 1,
            request_payload_json LONGTEXT NULL,
            response_json LONGTEXT NULL,
            signed_document_path VARCHAR(500) NULL,
            signed_document_url VARCHAR(1000) NULL,
            audit_trail_json LONGTEXT NULL,
            audit_trail_path VARCHAR(500) NULL,
            sent_at DATETIME NULL,
            viewed_at DATETIME NULL,
            signed_at DATETIME NULL,
            last_webhook_at DATETIME NULL,
            completion_result_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_esignatures_contract_send_contract (contract_id),
            KEY idx_esignatures_contract_send_remote (esignatures_contract_id),
            KEY idx_esignatures_contract_send_provider (provider_contract_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    if (!db_table_exists('esignatures_contract_send')) return false;

    $columns = [
        'provider_contract_id' => 'VARCHAR(191) NULL',
        'provider_status' => 'VARCHAR(64) NULL',
        'signed_document_path' => 'VARCHAR(500) NULL',
        'signed_document_url' => 'VARCHAR(1000) NULL',
        'audit_trail_json' => 'LONGTEXT NULL',
        'audit_trail_path' => 'VARCHAR(500) NULL',
        'sent_at' => 'DATETIME NULL',
        'viewed_at' => 'DATETIME NULL',
        'signed_at' => 'DATETIME NULL',
        'last_webhook_at' => 'DATETIME NULL',
        'completion_result_json' => 'LONGTEXT NULL',
    ];
    foreach ($columns as $column => $definition) {
        if (!db_column_exists('esignatures_contract_send', $column)) {
            db()->exec('ALTER TABLE esignatures_contract_send ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
    return true;
}
function esignatures_find_monthly_amount(int $contractId, array $contract): float {
    if ($contractId <= 0 || !db_table_exists('contract_service')) {
        return (float)($contract['base_amount'] ?? 0);
    }
    $st = db()->prepare('SELECT COALESCE(SUM(CASE WHEN is_included = 0 THEN quantity * unit_price ELSE 0 END), 0) FROM contract_service WHERE contract_id = ?');
    $st->execute([$contractId]);
    $total = (float)$st->fetchColumn();
    return $total > 0 ? $total : (float)($contract['base_amount'] ?? 0);
}

function esignatures_contract_placeholder_values(array $contract, float $monthlyAmount): array {
    $signerName = trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? ''));
    $companyName = trim((string)($contract['dba_name'] ?? ''));
    if ($companyName === '') $companyName = trim((string)($contract['legal_name'] ?? ''));
    $servicePlan = trim((string)($contract['sla_level'] ?? ''));
    if ($servicePlan === '') $servicePlan = trim((string)($contract['contract_name'] ?? ''));

    return [
        'client_name' => $signerName,
        'company_name' => $companyName,
        'service_plan' => $servicePlan,
        'monthly_amount' => number_format($monthlyAmount, 2, '.', ''),
    ];
}

function esignatures_placeholder_fields_payload(array $placeholders): array {
    $fields = [];
    foreach ($placeholders as $key => $value) {
        $fields[] = [
            'placeholder_key' => (string)$key,
            'replace_with_text' => (string)$value,
        ];
    }
    return $fields;
}

function esignatures_metadata_payload(array $contract): string {
    return 'contract_id=' . (int)($contract['contract_id'] ?? 0) . ';client_id=' . (int)($contract['client_id'] ?? 0);
}

function esignatures_validate_contract_for_send(array $contract, array $placeholders): array {
    $errors = [];
    $signerName = trim((string)$placeholders['client_name']);
    $signerEmail = trim((string)($contract['contact_email'] ?? ''));
    if ($signerEmail === '') {
        $signerEmail = trim((string)($contract['client_email'] ?? ''));
    }
    if ($signerName === '') $errors[] = 'Add a primary contact first and last name before sending with eSignatures.';
    if ($signerEmail === '' || !filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Add a valid primary contact or client email before sending with eSignatures.';
    if (trim((string)$placeholders['company_name']) === '') $errors[] = 'Add the client company name before sending with eSignatures.';
    if (trim((string)$placeholders['service_plan']) === '') $errors[] = 'Add the contract service plan before sending with eSignatures.';
    if ((float)$placeholders['monthly_amount'] <= 0) $errors[] = 'Add a monthly amount greater than zero before sending with eSignatures.';
    return $errors;
}

function esignatures_build_payload(array $contract, float $monthlyAmount): array {
    $placeholders = esignatures_contract_placeholder_values($contract, $monthlyAmount);
    $signerEmail = trim((string)($contract['contact_email'] ?? ''));
    if ($signerEmail === '') {
        $signerEmail = trim((string)($contract['client_email'] ?? ''));
    }
    $payload = [
        'template_id' => esignatures_config_string('ESIGNATURES_TEMPLATE_ID'),
        'signers' => [[
            'name' => $placeholders['client_name'],
            'email' => $signerEmail,
        ]],
        'placeholder_fields' => esignatures_placeholder_fields_payload($placeholders),
        'metadata' => esignatures_metadata_payload($contract),
    ];
    if (esignatures_test_mode()) {
        $payload['test'] = 'yes';
    }
    return $payload;
}

function esignatures_extract_contract_id(array $decoded): string {
    foreach (['contract_id', 'id'] as $key) {
        if (!empty($decoded[$key]) && is_scalar($decoded[$key])) return (string)$decoded[$key];
    }
    $paths = [
        ['data', 'contract_id'],
        ['data', 'id'],
        ['contract', 'id'],
        ['contract', 'contract_id'],
    ];
    foreach ($paths as $path) {
        $value = $decoded;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                $value = null;
                break;
            }
            $value = $value[$key];
        }
        if (is_scalar($value) && trim((string)$value) !== '') return (string)$value;
    }
    return '';
}

function esignatures_extract_status(array $decoded): string {
    foreach (['status', 'contract_status'] as $key) {
        if (!empty($decoded[$key]) && is_scalar($decoded[$key])) return (string)$decoded[$key];
    }
    if (isset($decoded['data']['status']) && is_scalar($decoded['data']['status'])) return (string)$decoded['data']['status'];
    if (isset($decoded['contract']['status']) && is_scalar($decoded['contract']['status'])) return (string)$decoded['contract']['status'];
    return 'sent';
}

function esignatures_http_post_json(string $url, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['ok' => false, 'http_code' => 0, 'errors' => ['Unable to encode eSignatures payload.'], 'raw_body' => ''];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http_code' => 0, 'errors' => ['PHP cURL is not available.'], 'raw_body' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0) {
        return ['ok' => false, 'http_code' => $code, 'errors' => ['eSignatures request failed: ' . $error], 'raw_body' => is_string($raw) ? $raw : ''];
    }
    return ['ok' => $code >= 200 && $code < 300, 'http_code' => $code, 'raw_body' => is_string($raw) ? $raw : ''];
}

function esignatures_record_send(int $contractId, array $payload, array $response, array $decoded): void {
    if ($contractId <= 0 || !esignatures_storage_ready()) return;
    $providerContractId = esignatures_extract_contract_id($decoded) ?: null;
    $providerStatus = esignatures_extract_status($decoded) ?: null;
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $responseJson = json_encode($decoded !== [] ? $decoded : ['raw_body' => (string)($response['raw_body'] ?? '')], JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO esignatures_contract_send (contract_id, esignatures_contract_id, provider_contract_id, status, provider_status, test_mode, request_payload_json, response_json, sent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([
            $contractId,
            $providerContractId,
            $providerContractId,
            $providerStatus,
            $providerStatus,
            esignatures_test_mode() ? 1 : 0,
            is_string($payloadJson) ? $payloadJson : null,
            is_string($responseJson) ? $responseJson : null,
        ]);
}
function esignatures_send_test_contract(int $contractId, ?callable $transport = null): array {
    if (!esignatures_is_enabled()) {
        return ['ok' => false, 'errors' => ['eSignatures is not enabled/configured for this OPS environment.']];
    }
    if (!esignatures_is_staging_mode() && !esignatures_config_bool('ESIGNATURES_LIVE_CONFIRMED', false)) {
        return ['ok' => false, 'errors' => ['Live eSignatures sends are disabled unless explicitly configured.']];
    }
    if (!function_exists('accounting_get_contract')) {
        return ['ok' => false, 'errors' => ['Contract helper is unavailable.']];
    }
    $contract = accounting_get_contract($contractId);
    if (!$contract) return ['ok' => false, 'errors' => ['Contract not found.']];

    $monthlyAmount = esignatures_find_monthly_amount($contractId, $contract);
    $placeholders = esignatures_contract_placeholder_values($contract, $monthlyAmount);
    $errors = esignatures_validate_contract_for_send($contract, $placeholders);
    $payload = esignatures_build_payload($contract, $monthlyAmount);
    if ($errors !== []) {
        esignatures_log('validation_failed', ['contract_id' => $contractId, 'errors' => $errors]);
        return ['ok' => false, 'errors' => $errors];
    }

    if (esignatures_is_staging_mode()) {
        $payload['test'] = 'yes';
    }

    esignatures_log('send_started', ['contract_id' => $contractId, 'test_mode' => esignatures_test_mode(), 'payload' => $payload]);
    $response = $transport ? $transport(esignatures_send_url(), $payload) : esignatures_http_post_json(esignatures_send_url(), $payload);
    if (!is_array($response)) {
        $response = ['ok' => false, 'http_code' => 0, 'errors' => ['Invalid eSignatures transport response.'], 'raw_body' => ''];
    }
    $decoded = [];
    $raw = (string)($response['raw_body'] ?? '');
    if ($raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) $decoded = $json;
    }

    if (empty($response['ok'])) {
        esignatures_log('send_failed', ['contract_id' => $contractId, 'http_code' => (int)($response['http_code'] ?? 0), 'response' => $decoded ?: $raw]);
        return ['ok' => false, 'errors' => $response['errors'] ?? ['eSignatures send failed.']];
    }

    esignatures_record_send($contractId, $payload, $response, $decoded);
    if (function_exists('accounting_contract_status_update')) {
        accounting_contract_status_update($contractId, 'PENDING_SIGNATURE', (int)(current_user()['user_id'] ?? 0));
    }
    esignatures_log('send_succeeded', ['contract_id' => $contractId, 'esignatures_contract_id' => esignatures_extract_contract_id($decoded), 'status' => esignatures_extract_status($decoded), 'test_mode' => esignatures_test_mode()]);
    return [
        'ok' => true,
        'message' => 'eSignatures TEST contract sent.',
        'esignatures_contract_id' => esignatures_extract_contract_id($decoded),
        'status' => esignatures_extract_status($decoded),
    ];
}

function esignatures_deep_get(array $payload, array $path): mixed {
    $value = $payload;
    foreach ($path as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return null;
        $value = $value[$key];
    }
    return $value;
}

function esignatures_first_scalar(array $payload, array $paths): string {
    foreach ($paths as $path) {
        $value = is_array($path) ? esignatures_deep_get($payload, $path) : ($payload[$path] ?? null);
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') return $text;
        }
    }
    return '';
}

function esignatures_parse_metadata_string(string $metadata): array {
    $metadata = trim($metadata);
    if ($metadata === '') return [];

    $decodedJson = json_decode($metadata, true);
    if (is_array($decodedJson)) {
        return $decodedJson;
    }

    $parsed = [];
    foreach (explode(';', $metadata) as $part) {
        $part = trim($part);
        if ($part === '' || !str_contains($part, '=')) continue;
        [$key, $value] = explode('=', $part, 2);
        $key = trim($key);
        if ($key === '') continue;
        $parsed[$key] = trim($value);
    }
    return $parsed;
}

function esignatures_extract_metadata(array $payload): array {
    foreach ([['metadata'], ['data', 'metadata'], ['contract', 'metadata'], ['custom_fields'], ['data', 'custom_fields']] as $path) {
        $value = esignatures_deep_get($payload, $path);
        if (is_array($value)) return $value;
        if (is_scalar($value)) {
            $metadata = esignatures_parse_metadata_string((string)$value);
            if ($metadata !== []) return $metadata;
        }
    }
    return [];
}

function esignatures_extract_event_status(array $payload): string {
    $status = esignatures_first_scalar($payload, ['status', 'contract_status', ['data', 'status'], ['contract', 'status']]);
    $event = esignatures_first_scalar($payload, ['event', 'event_type', ['data', 'event'], ['webhook', 'event']]);
    return strtolower(trim($status !== '' ? $status : $event));
}

function esignatures_is_signed_status(string $status): bool {
    $status = strtolower(trim($status));
    foreach (['signed', 'completed', 'complete', 'finalized', 'executed'] as $needle) {
        if ($status === $needle || str_contains($status, $needle)) return true;
    }
    return false;
}

function esignatures_extract_datetime(array $payload, array $paths): string {
    $value = esignatures_first_scalar($payload, $paths);
    if ($value === '') return '';
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : $value;
}

function esignatures_extract_signed_document_reference(array $payload): string {
    return esignatures_first_scalar($payload, [
        'signed_document_path', 'signed_pdf_path', 'signed_document_url', 'signed_pdf_url', 'download_url', 'document_url',
        ['data', 'signed_document_url'], ['data', 'signed_pdf_url'], ['data', 'download_url'], ['data', 'document_url'],
        ['contract', 'signed_document_url'], ['contract', 'signed_pdf_url'], ['contract', 'download_url'], ['contract', 'document_url'],
    ]);
}

function esignatures_extract_audit_reference(array $payload): string {
    return esignatures_first_scalar($payload, [
        'audit_trail_path', 'audit_trail_url', 'audit_url', 'certificate_url',
        ['data', 'audit_trail_path'], ['data', 'audit_trail_url'], ['data', 'audit_url'], ['data', 'certificate_url'],
        ['contract', 'audit_trail_path'], ['contract', 'audit_trail_url'], ['contract', 'audit_url'], ['contract', 'certificate_url'],
    ]);
}

function esignatures_find_send_for_webhook(array $payload): ?array {
    if (!esignatures_storage_ready()) return null;
    $providerContractId = esignatures_extract_contract_id($payload);
    if ($providerContractId !== '') {
        $st = db()->prepare('SELECT * FROM esignatures_contract_send WHERE provider_contract_id = ? OR esignatures_contract_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$providerContractId, $providerContractId]);
        $row = $st->fetch();
        if ($row) return $row;
    }

    $metadata = esignatures_extract_metadata($payload);
    $contractId = (int)($metadata['contract_id'] ?? $payload['contract_id'] ?? 0);
    $clientId = (int)($metadata['client_id'] ?? $payload['client_id'] ?? 0);
    if ($contractId > 0) {
        $sql = 'SELECT * FROM esignatures_contract_send WHERE contract_id = ?';
        $params = [$contractId];
        if ($clientId > 0 && db_table_exists('contract')) {
            $sql .= ' AND contract_id IN (SELECT contract_id FROM contract WHERE client_id = ?)';
            $params[] = $clientId;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';
        $st = db()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        if ($row) return $row;
    }
    return null;
}

function esignatures_update_send_from_webhook(array $send, array $payload): array {
    $providerContractId = esignatures_extract_contract_id($payload) ?: (string)($send['provider_contract_id'] ?? $send['esignatures_contract_id'] ?? '');
    $providerStatus = esignatures_extract_status($payload);
    $signedAt = esignatures_extract_datetime($payload, ['signed_at', 'completed_at', 'finalized_at', ['data', 'signed_at'], ['data', 'completed_at'], ['contract', 'signed_at']]);
    $viewedAt = esignatures_extract_datetime($payload, ['viewed_at', ['data', 'viewed_at'], ['contract', 'viewed_at']]);
    $signedReference = esignatures_extract_signed_document_reference($payload);
    $auditReference = esignatures_extract_audit_reference($payload);
    $auditJson = '';
    foreach ([['audit_trail'], ['data', 'audit_trail'], ['contract', 'audit_trail']] as $path) {
        $value = esignatures_deep_get($payload, $path);
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded)) $auditJson = $encoded;
            break;
        }
    }
    $webhookJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

    db()->prepare('UPDATE esignatures_contract_send SET esignatures_contract_id = COALESCE(NULLIF(?, \'\'), esignatures_contract_id), provider_contract_id = COALESCE(NULLIF(?, \'\'), provider_contract_id), status = COALESCE(NULLIF(?, \'\'), status), provider_status = COALESCE(NULLIF(?, \'\'), provider_status), viewed_at = COALESCE(?, viewed_at), signed_at = COALESCE(?, signed_at), signed_document_url = COALESCE(?, signed_document_url), signed_document_path = COALESCE(?, signed_document_path), audit_trail_json = COALESCE(?, audit_trail_json), audit_trail_path = COALESCE(?, audit_trail_path), response_json = ?, last_webhook_at = NOW(), updated_at = NOW() WHERE id = ?')
        ->execute([
            $providerContractId,
            $providerContractId,
            $providerStatus,
            $providerStatus,
            $viewedAt !== '' ? $viewedAt : null,
            $signedAt !== '' ? $signedAt : null,
            preg_match('/^https?:\/\//i', $signedReference) ? $signedReference : null,
            !preg_match('/^https?:\/\//i', $signedReference) ? ($signedReference !== '' ? $signedReference : null) : null,
            $auditJson !== '' ? $auditJson : null,
            $auditReference !== '' ? $auditReference : null,
            is_string($webhookJson) ? $webhookJson : null,
            (int)$send['id'],
        ]);

    return [
        'provider_contract_id' => $providerContractId,
        'provider_status' => $providerStatus,
        'signed_at' => $signedAt,
        'signed_document_reference' => $signedReference,
        'audit_reference' => $auditReference,
    ];
}

function esignatures_complete_ops_contract(array $send, array $webhookInfo): array {
    $signedReference = trim((string)($webhookInfo['signed_document_reference'] ?? ''));
    $auditReference = trim((string)($webhookInfo['audit_reference'] ?? ''));
    if ($signedReference === '') {
        return ['ok' => false, 'pending_download' => true, 'errors' => ['eSignatures marked the contract signed, but no signed document URL/path was provided. Download and upload the signed PDF manually.']];
    }
    if (!function_exists('accounting_contract_complete_signed_copy')) {
        require_once __DIR__ . '/accounting.php';
    }
    $result = accounting_contract_complete_signed_copy((int)$send['contract_id'], $signedReference, [
        'signed_at' => (string)($webhookInfo['signed_at'] ?? ''),
        'signed_by' => 'eSignatures',
        'signed_ip' => '',
        'audit_document_reference' => $auditReference,
        'user_id' => 0,
    ]);
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        db()->prepare('UPDATE esignatures_contract_send SET completion_result_json = ?, updated_at = NOW() WHERE id = ?')->execute([$encoded, (int)$send['id']]);
    }
    return $result;
}

function esignatures_process_webhook_payload(array $payload): array {
    $send = esignatures_find_send_for_webhook($payload);
    if (!$send) {
        esignatures_log('webhook_unmatched', ['provider_contract_id' => esignatures_extract_contract_id($payload), 'metadata' => esignatures_extract_metadata($payload)]);
        return ['ok' => false, 'matched' => false, 'errors' => ['No matching OPS contract found for eSignatures webhook.']];
    }

    $info = esignatures_update_send_from_webhook($send, $payload);
    $status = esignatures_extract_event_status($payload);
    if (!esignatures_is_signed_status($status)) {
        return ['ok' => true, 'matched' => true, 'completed' => false, 'message' => 'eSignatures webhook status stored.'];
    }

    $completion = esignatures_complete_ops_contract($send, $info);
    if (empty($completion['ok'])) {
        return ['ok' => false, 'matched' => true, 'completed' => false, 'pending_download' => !empty($completion['pending_download']), 'errors' => $completion['errors'] ?? ['Unable to complete signed eSignatures contract.']];
    }
    return ['ok' => true, 'matched' => true, 'completed' => true, 'message' => 'Signed eSignatures contract archived and onboarding started.'];
}

function esignatures_handle_webhook(): void {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    esignatures_log('webhook_received', [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'event' => is_array($decoded) ? ($decoded['event'] ?? $decoded['status'] ?? 'unknown') : 'invalid_json',
    ]);

    if (!is_array($decoded)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON webhook payload.']);
        return;
    }

    try {
        $result = esignatures_process_webhook_payload($decoded);
        http_response_code(!empty($result['matched']) || !empty($result['ok']) ? 202 : 404);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        esignatures_log('webhook_error', ['error' => $e->getMessage()]);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Unable to process eSignatures webhook.']);
    }
}
