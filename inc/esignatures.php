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

function esignatures_webhook_url(): string {
    $configured = esignatures_config_string('ESIGNATURES_WEBHOOK_URL');
    if ($configured !== '') {
        return $configured;
    }
    if (esignatures_is_staging_mode()) {
        return 'https://ops-test.midwestmanagedit.com/webhooks/esignatures.php';
    }
    return '';
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
    if (!db_table_exists('esignatures_webhook_event')) {
        db()->exec("CREATE TABLE IF NOT EXISTS esignatures_webhook_event (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at DATETIME NOT NULL,
            remote_ip VARCHAR(64) NULL,
            user_agent VARCHAR(500) NULL,
            request_method VARCHAR(16) NULL,
            raw_body LONGTEXT NULL,
            parsed_json LONGTEXT NULL,
            extracted_metadata LONGTEXT NULL,
            provider_contract_id VARCHAR(191) NULL,
            event_status VARCHAR(128) NULL,
            matched TINYINT(1) NOT NULL DEFAULT 0,
            match_result_json LONGTEXT NULL,
            response_code INT NULL,
            processing_errors LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_esignatures_webhook_received (received_at),
            KEY idx_esignatures_webhook_provider (provider_contract_id),
            KEY idx_esignatures_webhook_matched (matched)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    if (!db_table_exists('esignatures_contract_send')) return false;

    $eventColumns = [
        'extracted_metadata' => 'LONGTEXT NULL',
        'provider_contract_id' => 'VARCHAR(191) NULL',
        'event_status' => 'VARCHAR(128) NULL',
        'matched' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'match_result_json' => 'LONGTEXT NULL',
        'response_code' => 'INT NULL',
        'processing_errors' => 'LONGTEXT NULL',
    ];
    if (db_table_exists('esignatures_webhook_event')) {
        foreach ($eventColumns as $column => $definition) {
            if (!db_column_exists('esignatures_webhook_event', $column)) {
                db()->exec('ALTER TABLE esignatures_webhook_event ADD COLUMN ' . $column . ' ' . $definition);
            }
        }
    }

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
    $fallback = (float)($contract['base_amount'] ?? 0);
    $billingCycle = (string)($contract['billing_cycle'] ?? 'MONTHLY');
    $cycleMultiplier = function_exists('accounting_billing_cycle_month_multiplier') ? accounting_billing_cycle_month_multiplier($billingCycle) : 1;
    if ($cycleMultiplier > 1 && $fallback > 0) {
        $fallback = $fallback / $cycleMultiplier;
    }
    if ($contractId <= 0 || !db_table_exists('contract_service')) {
        return $fallback;
    }
    $st = db()->prepare('SELECT COALESCE(SUM(CASE WHEN is_included = 0 THEN quantity * unit_price ELSE 0 END), 0) FROM contract_service WHERE contract_id = ?');
    $st->execute([$contractId]);
    $total = (float)$st->fetchColumn();
    return $total > 0 ? $total : $fallback;
}

function esignatures_format_money(float $amount): string {
    return number_format($amount, 2, '.', '');
}

function esignatures_format_quantity(float $quantity): string {
    return $quantity > 0 ? number_format($quantity, 0, '.', '') : '';
}

function esignatures_service_address(array $contract): string {
    $lines = [];
    $addr1 = trim((string)($contract['address1'] ?? ''));
    $addr2 = trim((string)($contract['address2'] ?? ''));
    $city = trim((string)($contract['city'] ?? ''));
    $state = trim((string)($contract['state'] ?? ''));
    $postal = trim((string)($contract['postal_code'] ?? ''));
    $country = trim((string)($contract['country'] ?? ''));

    $street = trim($addr1 . ' ' . $addr2);
    if ($street !== '') {
        $lines[] = $street;
    }
    $cityLine = trim(preg_replace('/\s+/', ' ', trim($city . ', ' . $state . ' ' . $postal)) ?? '');
    $cityLine = trim($cityLine, ', ');
    if ($cityLine !== '') {
        $lines[] = $cityLine;
    }
    if ($country !== '' && strtoupper($country) !== 'US' && strtoupper($country) !== 'USA') {
        $lines[] = $country;
    }
    return implode("\n", $lines);
}

function esignatures_productivity_item_codes(): array {
    if (!function_exists('accounting_productivity_catalog')) {
        return [];
    }
    $codes = [];
    foreach (accounting_productivity_catalog() as $platformMeta) {
        foreach ((array)($platformMeta['licenses'] ?? []) as $licenseMeta) {
            $code = strtoupper(trim((string)($licenseMeta['item_code'] ?? '')));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }
    }
    return array_keys($codes);
}

function esignatures_contract_base_service_package(array $contract, array $services): ?array {
    if (!function_exists('accounting_service_packages')) {
        return null;
    }
    $packages = accounting_service_packages();
    $baseServiceCode = '';
    foreach ($services as $svc) {
        $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
        if ($code !== '' && str_starts_with($code, 'MSP-')) {
            $baseServiceCode = preg_replace('/^MSP-/', '', $code) ?? '';
            break;
        }
    }
    if ($baseServiceCode !== '' && isset($packages[$baseServiceCode])) {
        return $packages[$baseServiceCode];
    }
    $serviceLevel = trim((string)($contract['sla_level'] ?? ''));
    foreach ($packages as $pkg) {
        if (strcasecmp((string)($pkg['name'] ?? ''), $serviceLevel) === 0) {
            return $pkg;
        }
    }
    return null;
}

function esignatures_contract_addon_labels(array $services): array {
    $productivityCodes = esignatures_productivity_item_codes();
    $labels = [];
    foreach ($services as $svc) {
        $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
        if (!empty($svc['is_included']) || $code === '' || str_starts_with($code, 'MSP-') || in_array($code, $productivityCodes, true)) {
            continue;
        }
        $label = trim((string)($svc['service_name'] ?? $svc['description'] ?? ''));
        if ($label === '') {
            $label = $code;
        }
        $quantity = (float)($svc['quantity'] ?? 0);
        if ($quantity > 0) {
            $label .= ' (' . number_format($quantity, 0) . ')';
        }
        $labels[] = $label;
    }
    return $labels;
}

function esignatures_contract_included_service_labels(?array $servicePackage, array $services): array {
    $labels = $servicePackage ? (array)($servicePackage['included_services'] ?? []) : [];
    if ($labels !== []) {
        return array_values(array_map('strval', $labels));
    }
    foreach ($services as $svc) {
        if (empty($svc['is_included'])) {
            continue;
        }
        $label = trim((string)($svc['service_name'] ?? $svc['description'] ?? ''));
        if ($label !== '' && !in_array($label, $labels, true)) {
            $labels[] = $label;
        }
    }
    return array_values(array_map('strval', $labels));
}

function esignatures_contract_placeholder_values(array $contract, float $monthlyAmount, array $services = []): array {
    if ($services !== [] && function_exists('accounting_expand_contract_service_rows')) {
        $services = accounting_expand_contract_service_rows($services);
    }

    $signerName = trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? ''));
    $companyName = trim((string)($contract['dba_name'] ?? ''));
    if ($companyName === '') $companyName = trim((string)($contract['legal_name'] ?? ''));
    $servicePlan = trim((string)($contract['sla_level'] ?? ''));
    if ($servicePlan === '') $servicePlan = trim((string)($contract['contract_name'] ?? ''));
    $contactEmail = trim((string)($contract['contact_email'] ?? ''));
    if ($contactEmail === '') {
        $contactEmail = trim((string)($contract['client_email'] ?? ''));
    }

    $billingCycle = (string)($contract['billing_cycle'] ?? 'MONTHLY');
    $billingCycleLabel = function_exists('accounting_billing_cycle_label') ? accounting_billing_cycle_label($billingCycle) : ucfirst(strtolower(str_replace('_', '-', $billingCycle)));
    $cycleMultiplier = function_exists('accounting_billing_cycle_month_multiplier') ? accounting_billing_cycle_month_multiplier($billingCycle) : 1;
    $recurringTotal = round($monthlyAmount * $cycleMultiplier, 2);
    if ($recurringTotal <= 0 && isset($contract['base_amount'])) {
        $recurringTotal = (float)$contract['base_amount'];
    }
    if ($monthlyAmount <= 0 && $recurringTotal > 0) {
        $monthlyAmount = $recurringTotal;
    }

    $servicePackage = esignatures_contract_base_service_package($contract, $services);
    $productivitySelection = function_exists('accounting_productivity_selection_details') ? accounting_productivity_selection_details($services) : [];
    $coveredServers = 0.0;
    foreach ($services as $svc) {
        $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
        if (in_array($code, ['SRVR-MGMT', 'SRVR-BKUP', 'SRVR-BK-500'], true)) {
            $coveredServers = max($coveredServers, (float)($svc['quantity'] ?? 0));
        }
    }
    $includedServices = esignatures_contract_included_service_labels($servicePackage, $services);
    $selectedAddons = esignatures_contract_addon_labels($services);
    $renewalTerms = !empty($contract['auto_renew'])
        ? 'Auto-renews after initial term unless non-renewed in writing'
        : 'No auto-renew';

    return [
        'contract_number' => trim((string)($contract['contract_number'] ?? '')),
        'contract_title' => trim((string)($contract['contract_name'] ?? '')),
        'client_name' => $signerName,
        'company_name' => $companyName,
        'primary_contact' => $signerName,
        'contact_email' => $contactEmail,
        'service_plan' => ($servicePackage['name'] ?? '') !== '' ? (string)$servicePackage['name'] : $servicePlan,
        'productivity_platform' => (string)($productivitySelection['platform_name'] ?? 'No productivity platform selected'),
        'license_level' => (string)($productivitySelection['license_name'] ?? 'None selected'),
        'billing_cycle' => $billingCycleLabel,
        'recurring_total' => esignatures_format_money($recurringTotal),
        'covered_workstations' => esignatures_format_quantity((float)($contract['covered_devices'] ?? 0)),
        'covered_users' => esignatures_format_quantity((float)($contract['covered_users'] ?? 0)),
        'covered_servers' => esignatures_format_quantity($coveredServers),
        'start_date' => trim((string)($contract['start_date'] ?? '')),
        'end_date' => trim((string)($contract['end_date'] ?? '')),
        'renewal_terms' => $renewalTerms,
        'service_address' => esignatures_service_address($contract),
        'included_services' => implode("\n", $includedServices),
        'selected_addons' => implode("\n", $selectedAddons),
        'monthly_amount' => esignatures_format_money($monthlyAmount),
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

function esignatures_build_payload(array $contract, float $monthlyAmount, array $services = []): array {
    $placeholders = esignatures_contract_placeholder_values($contract, $monthlyAmount, $services);
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
    $webhookUrl = esignatures_webhook_url();
    if ($webhookUrl !== '') {
        $payload['custom_webhook_url'] = $webhookUrl;
    }
    if (esignatures_test_mode()) {
        $payload['test'] = 'yes';
    }
    return $payload;
}

function esignatures_extract_contract_id(array $decoded): string {
    return esignatures_first_scalar($decoded, [
        'contract_id', 'id', 'document_id', 'provider_contract_id', 'esignatures_contract_id',
        ['data', 'contract_id'], ['data', 'id'], ['data', 'document_id'],
        ['contract', 'id'], ['contract', 'contract_id'],
        ['data', 'contract', 'id'], ['data', 'contract', 'contract_id'],
        ['webhook', 'contract_id'], ['webhook', 'id'],
    ]);
}

function esignatures_extract_status(array $decoded): string {
    $status = esignatures_first_scalar($decoded, [
        'status', 'contract_status', 'event', 'event_type',
        ['data', 'status'], ['data', 'event'],
        ['contract', 'status'], ['data', 'contract', 'status'],
    ]);
    return $status !== '' ? $status : 'sent';
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
    $services = function_exists('accounting_get_contract_services') ? accounting_get_contract_services($contractId) : [];
    $placeholders = esignatures_contract_placeholder_values($contract, $monthlyAmount, $services);
    $errors = esignatures_validate_contract_for_send($contract, $placeholders);
    $payload = esignatures_build_payload($contract, $monthlyAmount, $services);
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
    foreach ([
        ['metadata'], ['data', 'metadata'], ['contract', 'metadata'], ['data', 'contract', 'metadata'],
        ['custom_fields'], ['data', 'custom_fields'], ['data', 'contract', 'custom_fields'],
    ] as $path) {
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
    $status = esignatures_first_scalar($payload, ['status', 'contract_status', ['data', 'status'], ['contract', 'status'], ['data', 'contract', 'status']]);
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
        'contract_pdf_url', 'pdf_url',
        ['data', 'signed_document_url'], ['data', 'signed_pdf_url'], ['data', 'download_url'], ['data', 'document_url'], ['data', 'contract_pdf_url'],
        ['contract', 'signed_document_url'], ['contract', 'signed_pdf_url'], ['contract', 'download_url'], ['contract', 'document_url'], ['contract', 'contract_pdf_url'],
        ['data', 'contract', 'signed_document_url'], ['data', 'contract', 'signed_pdf_url'], ['data', 'contract', 'download_url'], ['data', 'contract', 'document_url'], ['data', 'contract', 'contract_pdf_url'],
    ]);
}

function esignatures_extract_audit_reference(array $payload): string {
    return esignatures_first_scalar($payload, [
        'audit_trail_path', 'audit_trail_url', 'audit_url', 'certificate_url',
        ['data', 'audit_trail_path'], ['data', 'audit_trail_url'], ['data', 'audit_url'], ['data', 'certificate_url'],
        ['contract', 'audit_trail_path'], ['contract', 'audit_trail_url'], ['contract', 'audit_url'], ['contract', 'certificate_url'],
        ['data', 'contract', 'audit_trail_path'], ['data', 'contract', 'audit_trail_url'], ['data', 'contract', 'audit_url'], ['data', 'contract', 'certificate_url'],
    ]);
}

function esignatures_extract_signed_by(array $payload): string {
    foreach ([['signer'], ['data', 'signer'], ['contract', 'signer'], ['data', 'contract', 'signer']] as $path) {
        $signer = esignatures_deep_get($payload, $path);
        if (is_array($signer)) {
            $name = esignatures_first_scalar($signer, ['name', 'full_name', 'email']);
            if ($name !== '') return $name;
        }
    }
    foreach ([['signers'], ['contract', 'signers'], ['data', 'signers'], ['data', 'contract', 'signers']] as $path) {
        $signers = esignatures_deep_get($payload, $path);
        if (is_array($signers)) {
            foreach ($signers as $signer) {
                if (is_array($signer)) {
                    $name = esignatures_first_scalar($signer, ['name', 'full_name', 'email']);
                    if ($name !== '') return $name;
                }
            }
        }
    }
    return 'eSignatures';
}

function esignatures_webhook_capture_ready(): bool {
    return esignatures_storage_ready() && db_table_exists('esignatures_webhook_event');
}

function esignatures_capture_webhook_event(string $rawBody, ?array $payload, array $requestMeta): int {
    if (!esignatures_webhook_capture_ready()) return 0;
    $parsedJson = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null;
    $metadataJson = $payload !== null ? json_encode(esignatures_extract_metadata($payload), JSON_UNESCAPED_SLASHES) : null;
    db()->prepare('INSERT INTO esignatures_webhook_event (received_at, remote_ip, user_agent, request_method, raw_body, parsed_json, extracted_metadata, provider_contract_id, event_status) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            (string)($requestMeta['remote_ip'] ?? ''),
            substr((string)($requestMeta['user_agent'] ?? ''), 0, 500),
            substr((string)($requestMeta['request_method'] ?? ''), 0, 16),
            $rawBody,
            is_string($parsedJson) ? $parsedJson : null,
            is_string($metadataJson) ? $metadataJson : null,
            $payload !== null ? esignatures_extract_contract_id($payload) : null,
            $payload !== null ? esignatures_extract_event_status($payload) : 'invalid_json',
        ]);
    return (int)db()->lastInsertId();
}

function esignatures_update_webhook_capture(int $eventId, array $result, int $responseCode): void {
    if ($eventId <= 0 || !db_table_exists('esignatures_webhook_event')) return;
    $errors = $result['errors'] ?? ($result['error'] ?? []);
    if (is_string($errors)) $errors = [$errors];
    $matchJson = json_encode($result, JSON_UNESCAPED_SLASHES);
    $errorsJson = json_encode(array_values(array_map('strval', (array)$errors)), JSON_UNESCAPED_SLASHES);
    db()->prepare('UPDATE esignatures_webhook_event SET matched = ?, match_result_json = ?, response_code = ?, processing_errors = ?, created_at = created_at WHERE id = ?')
        ->execute([!empty($result['matched']) ? 1 : 0, is_string($matchJson) ? $matchJson : null, $responseCode, is_string($errorsJson) ? $errorsJson : null, $eventId]);
}

function esignatures_download_binary(string $url): array {
    if (!preg_match('/^https?:\/\//i', $url)) return ['ok' => false, 'errors' => ['Download URL is not HTTP/S.']];
    if (!function_exists('curl_init')) return ['ok' => false, 'errors' => ['PHP cURL is not available.']];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 45]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno !== 0 || $code < 200 || $code >= 300 || !is_string($body) || $body === '') {
        return ['ok' => false, 'errors' => ['Unable to download eSignatures document: ' . ($error !== '' ? $error : 'HTTP ' . $code)]];
    }
    return ['ok' => true, 'body' => $body];
}

function esignatures_store_contract_document(int $contractId, string $kind, string $bytes): array {
    if ($contractId <= 0 || $bytes === '') return ['ok' => false, 'errors' => ['Document content is empty.']];
    $root = dirname(__DIR__);
    $dir = $root . '/uploads/contracts';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return ['ok' => false, 'errors' => ['Unable to create uploads directory.']];
    $safeKind = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($kind)) ?: 'document';
    $targetName = 'contract-' . $contractId . '-' . $safeKind . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.pdf';
    $target = $dir . '/' . $targetName;
    if (file_put_contents($target, $bytes) === false) return ['ok' => false, 'errors' => ['Unable to store eSignatures document.']];
    return ['ok' => true, 'relative_path' => 'uploads/contracts/' . $targetName];
}

function esignatures_get_contract_details(string $providerContractId): array {
    $providerContractId = trim($providerContractId);
    if ($providerContractId === '' || esignatures_config_string('ESIGNATURES_API_TOKEN') === '') return [];
    $url = esignatures_base_url() . '/contracts/' . rawurlencode($providerContractId) . '?token=' . rawurlencode(esignatures_config_string('ESIGNATURES_API_TOKEN'));
    if (!function_exists('curl_init')) return [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !is_string($raw) || $raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
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
    $signedAt = esignatures_extract_datetime($payload, ['signed_at', 'completed_at', 'finalized_at', ['data', 'signed_at'], ['data', 'completed_at'], ['contract', 'signed_at'], ['data', 'contract', 'signed_at'], ['data', 'contract', 'completed_at']]);
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
        'signed_by' => esignatures_extract_signed_by($payload),
    ];
}

function esignatures_mark_signed_pending_documents(array $send, array $webhookInfo, array $errors = []): array {
    if (!function_exists('accounting_contract_status_update')) {
        require_once __DIR__ . '/accounting.php';
    }
    $result = accounting_contract_status_update((int)$send['contract_id'], 'SIGNED_PENDING_DOCUMENTS', 0, [
        'signed_date' => trim((string)($webhookInfo['signed_at'] ?? '')) ?: date('Y-m-d H:i:s'),
        'signed_by' => trim((string)($webhookInfo['signed_by'] ?? 'eSignatures')) ?: 'eSignatures',
        'signed_ip' => '',
    ]);
    $message = 'eSignatures confirmed this contract was signed, but OPS has not retrieved the signed PDF/audit trail yet.';
    $result['pending_download'] = true;
    $result['message'] = $message;
    if ($errors !== []) $result['errors'] = $errors;
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        db()->prepare('UPDATE esignatures_contract_send SET status = ?, completion_result_json = ?, updated_at = NOW() WHERE id = ?')
            ->execute(['signed_pending_documents', $encoded, (int)$send['id']]);
    }
    return $result;
}

function esignatures_complete_ops_contract(array $send, array $webhookInfo): array {
    $contractId = (int)$send['contract_id'];
    $providerContractId = trim((string)($webhookInfo['provider_contract_id'] ?? $send['provider_contract_id'] ?? $send['esignatures_contract_id'] ?? ''));
    $signedReference = trim((string)($webhookInfo['signed_document_reference'] ?? ''));
    $auditReference = trim((string)($webhookInfo['audit_reference'] ?? ''));

    if ($signedReference === '' && $providerContractId !== '') {
        $details = esignatures_get_contract_details($providerContractId);
        if ($details !== []) {
            $signedReference = esignatures_extract_signed_document_reference($details);
            $auditReference = $auditReference !== '' ? $auditReference : esignatures_extract_audit_reference($details);
        }
    }

    if (preg_match('/^https?:\/\//i', $signedReference)) {
        $download = esignatures_download_binary($signedReference);
        if (!empty($download['ok'])) {
            $stored = esignatures_store_contract_document($contractId, 'signed', (string)$download['body']);
            if (!empty($stored['ok'])) {
                $signedReference = (string)$stored['relative_path'];
            } else {
                return esignatures_mark_signed_pending_documents($send, $webhookInfo, $stored['errors'] ?? ['Unable to store signed PDF.']);
            }
        } else {
            return esignatures_mark_signed_pending_documents($send, $webhookInfo, $download['errors'] ?? ['Unable to retrieve signed PDF.']);
        }
    }

    if ($auditReference !== '' && preg_match('/^https?:\/\//i', $auditReference)) {
        $download = esignatures_download_binary($auditReference);
        if (!empty($download['ok'])) {
            $stored = esignatures_store_contract_document($contractId, 'audit', (string)$download['body']);
            if (!empty($stored['ok'])) {
                $auditReference = (string)$stored['relative_path'];
            }
        }
    }
    if ($auditReference === '' && $signedReference !== '') {
        // eSignatures appends Electronic Signature Records & Audit Trail pages to the final PDF.
        $auditReference = $signedReference;
    }

    if ($signedReference === '') {
        return esignatures_mark_signed_pending_documents($send, $webhookInfo);
    }

    if (!function_exists('accounting_contract_complete_signed_copy')) {
        require_once __DIR__ . '/accounting.php';
    }
    $result = accounting_contract_complete_signed_copy($contractId, $signedReference, [
        'signed_at' => (string)($webhookInfo['signed_at'] ?? ''),
        'signed_by' => trim((string)($webhookInfo['signed_by'] ?? 'eSignatures')) ?: 'eSignatures',
        'signed_ip' => '',
        'audit_document_reference' => $auditReference,
        'user_id' => 0,
    ]);
    $encoded = json_encode($result, JSON_UNESCAPED_SLASHES);
    if (is_string($encoded)) {
        db()->prepare("UPDATE esignatures_contract_send SET status = ?, signed_document_path = COALESCE(NULLIF(?, ''), signed_document_path), audit_trail_path = COALESCE(NULLIF(?, ''), audit_trail_path), completion_result_json = ?, updated_at = NOW() WHERE id = ?")
            ->execute([!empty($result['ok']) ? 'completed' : 'signed_pending_documents', $signedReference, $auditReference, $encoded, (int)$send['id']]);
    }
    return $result;
}

function esignatures_process_webhook_payload(array $payload): array {
    $send = esignatures_find_send_for_webhook($payload);
    if (!$send) {
        esignatures_log('webhook_unmatched', ['provider_contract_id' => esignatures_extract_contract_id($payload), 'metadata' => esignatures_extract_metadata($payload)]);
        return ['ok' => true, 'matched' => false, 'unmatched' => true, 'message' => 'eSignatures webhook stored for OPS review; no matching OPS contract was found.'];
    }

    $info = esignatures_update_send_from_webhook($send, $payload);
    $status = esignatures_extract_event_status($payload);
    if (!esignatures_is_signed_status($status)) {
        return ['ok' => true, 'matched' => true, 'completed' => false, 'message' => 'eSignatures webhook status stored.'];
    }

    $completion = esignatures_complete_ops_contract($send, $info);
    if (!empty($completion['pending_download'])) {
        return [
            'ok' => true,
            'matched' => true,
            'completed' => false,
            'pending_download' => true,
            'message' => (string)($completion['message'] ?? 'eSignatures confirmed this contract was signed, but OPS has not retrieved the signed PDF/audit trail yet.'),
            'errors' => $completion['errors'] ?? [],
        ];
    }
    if (empty($completion['ok'])) {
        return ['ok' => false, 'matched' => true, 'completed' => false, 'pending_download' => false, 'errors' => $completion['errors'] ?? ['Unable to complete signed eSignatures contract.']];
    }
    return ['ok' => true, 'matched' => true, 'completed' => true, 'message' => 'Signed eSignatures contract archived and onboarding started.'];
}

function esignatures_handle_webhook(): void {
    $raw = file_get_contents('php://input');
    $rawBody = is_string($raw) ? $raw : '';
    $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
    $requestMeta = [
        'remote_ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        'request_method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    ];
    $eventId = 0;
    try {
        $eventId = esignatures_capture_webhook_event($rawBody, is_array($decoded) ? $decoded : null, $requestMeta);
    } catch (Throwable $e) {
        esignatures_log('webhook_capture_error', ['error' => $e->getMessage()]);
    }

    esignatures_log('webhook_received', [
        'remote_addr' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
        'event' => is_array($decoded) ? ($decoded['event'] ?? $decoded['status'] ?? 'unknown') : 'invalid_json',
        'webhook_event_id' => $eventId,
    ]);

    if (!is_array($decoded)) {
        $result = ['ok' => false, 'matched' => false, 'error' => 'Invalid JSON webhook payload.'];
        esignatures_update_webhook_capture($eventId, $result, 400);
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
        return;
    }

    try {
        $result = esignatures_process_webhook_payload($decoded);
        $responseCode = 202;
        esignatures_update_webhook_capture($eventId, $result, $responseCode);
        http_response_code($responseCode);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        esignatures_log('webhook_error', ['error' => $e->getMessage(), 'webhook_event_id' => $eventId]);
        $result = ['ok' => false, 'matched' => false, 'error' => 'Unable to process eSignatures webhook.'];
        esignatures_update_webhook_capture($eventId, $result, 500);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode($result, JSON_UNESCAPED_SLASHES);
    }
}
