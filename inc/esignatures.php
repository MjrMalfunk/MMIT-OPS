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
    if (db_table_exists('esignatures_contract_send')) return true;
    db()->exec("CREATE TABLE IF NOT EXISTS esignatures_contract_send (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        contract_id BIGINT UNSIGNED NOT NULL,
        esignatures_contract_id VARCHAR(191) NULL,
        status VARCHAR(64) NULL,
        test_mode TINYINT(1) NOT NULL DEFAULT 1,
        request_payload_json LONGTEXT NULL,
        response_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_esignatures_contract_send_contract (contract_id),
        KEY idx_esignatures_contract_send_remote (esignatures_contract_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return db_table_exists('esignatures_contract_send');
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
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $responseJson = json_encode($decoded !== [] ? $decoded : ['raw_body' => (string)($response['raw_body'] ?? '')], JSON_UNESCAPED_SLASHES);
    db()->prepare('INSERT INTO esignatures_contract_send (contract_id, esignatures_contract_id, status, test_mode, request_payload_json, response_json) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([
            $contractId,
            esignatures_extract_contract_id($decoded) ?: null,
            esignatures_extract_status($decoded) ?: null,
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

function esignatures_handle_webhook(): void {
    $raw = file_get_contents('php://input');
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    esignatures_log('webhook_received', [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'event' => is_array($decoded) ? ($decoded['event'] ?? $decoded['status'] ?? 'unknown') : 'invalid_json',
    ]);
    http_response_code(202);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'eSignatures webhook accepted for future processing.']);
}
