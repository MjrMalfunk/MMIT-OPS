<?php
declare(strict_types=1);

require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/payment_gateway_stripe.php';

function payment_gateway_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function payment_gateway_setting(string $name, $default = null) {
    return defined($name) ? constant($name) : $default;
}

function payment_gateway_default(): string {
    return 'STRIPE';
}

function payment_gateway_app_environment(): string {
    $env = strtolower(trim((string)payment_gateway_setting('APP_ENV', 'production')));
    return $env !== '' ? $env : 'production';
}

function payment_gateway_stripe_configured_webhook_secret(string $mode = ''): string {
    $mode = strtolower(trim($mode));
    if ($mode === 'live') {
        $secret = trim((string)payment_gateway_setting('STRIPE_LIVE_WEBHOOK_SECRET', ''));
        if ($secret !== '') {
            return $secret;
        }
    }
    if (in_array($mode, ['test', 'sandbox'], true)) {
        $secret = trim((string)payment_gateway_setting('STRIPE_TEST_WEBHOOK_SECRET', ''));
        if ($secret !== '') {
            return $secret;
        }
    }
    return trim((string)payment_gateway_setting('STRIPE_WEBHOOK_SECRET', ''));
}

function payment_gateway_stripe_webhook_secret_for_payload(string $payload): string {
    $decoded = json_decode($payload, true);
    if (is_array($decoded) && array_key_exists('livemode', $decoded)) {
        return payment_gateway_stripe_configured_webhook_secret(!empty($decoded['livemode']) ? 'live' : 'test');
    }
    return payment_gateway_stripe_configured_webhook_secret(payment_gateway_stripe_mode());
}

function payment_gateway_ensure_schema(): void {
    if (db_table_exists('payment_receipt')) {
        $paymentColumns = [
            'processor_checkout_session_id' => "VARCHAR(120) NULL DEFAULT NULL",
            'processor_receipt_url' => "TEXT NULL DEFAULT NULL",
            'processor_payment_method_label' => "VARCHAR(80) NULL DEFAULT NULL",
            'processor_environment' => "VARCHAR(30) NULL DEFAULT NULL",
        ];
        foreach ($paymentColumns as $column => $definition) {
            if (!db_column_exists('payment_receipt', $column)) {
                try {
                    db()->exec('ALTER TABLE payment_receipt ADD COLUMN ' . $column . ' ' . $definition);
                } catch (Throwable $e) {
                    error_log('Unable to add payment_receipt.' . $column . ': ' . $e->getMessage());
                }
            }
        }
        foreach ([
            'idx_payment_receipt_stripe_session' => 'processor_checkout_session_id',
            'idx_payment_receipt_stripe_intent' => 'processor_payment_intent_id',
        ] as $index => $column) {
            if (db_column_exists('payment_receipt', $column)) {
                try {
                    db()->exec('ALTER TABLE payment_receipt ADD INDEX ' . $index . ' (' . $column . ')');
                } catch (Throwable $e) {
                    // Duplicate index names are harmless across already-upgraded databases.
                }
            }
        }
    }

    if (!db_table_exists('gateway_webhook_event')) {
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS gateway_webhook_event (
                webhook_event_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                provider_name VARCHAR(40) NOT NULL,
                event_id VARCHAR(190) NOT NULL,
                event_type VARCHAR(120) NOT NULL,
                delivery_id VARCHAR(190) NULL DEFAULT NULL,
                payload_json LONGTEXT NULL DEFAULT NULL,
                processing_status VARCHAR(30) NOT NULL DEFAULT 'RECEIVED',
                note TEXT NULL DEFAULT NULL,
                related_payment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                related_invoice_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
                processed_at DATETIME NULL DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (webhook_event_id),
                UNIQUE KEY uq_gateway_webhook_provider_event (provider_name, event_id),
                KEY idx_gateway_webhook_status (provider_name, processing_status),
                KEY idx_gateway_webhook_invoice (related_invoice_id),
                KEY idx_gateway_webhook_payment (related_payment_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (Throwable $e) {
            error_log('Unable to ensure gateway_webhook_event table: ' . $e->getMessage());
        }
    }
}

function payment_gateway_stripe_enabled(): bool {
    return trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', '')) !== '';
}

function payment_gateway_stripe_mode(): string {
    $secret = trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', ''));
    if ($secret === '') {
        return 'disabled';
    }
    if (str_starts_with($secret, 'sk_live_')) {
        return 'live';
    }
    if (str_starts_with($secret, 'sk_test_')) {
        return 'test';
    }
    return 'unknown';
}



function payment_gateway_stripe_ach_checkout_allowed(): bool {
    $env = payment_gateway_app_environment();
    $stripeMode = payment_gateway_stripe_mode();
    return in_array($env, ['staging', 'stage', 'testing', 'test', 'development', 'local'], true)
        && in_array($stripeMode, ['test', 'unknown'], true);
}

function payment_gateway_invoice_prefers_ach(array $invoice): bool {
    if (!payment_gateway_stripe_ach_checkout_allowed()) {
        return false;
    }
    $balanceDue = round((float)($invoice['balance_due'] ?? 0), 2);
    $threshold = (float)payment_gateway_setting('STRIPE_ACH_PREFERRED_MIN_INVOICE_AMOUNT', 1000);
    $sourceSystem = strtoupper(trim((string)($invoice['source_system'] ?? '')));
    if ($sourceSystem === 'RECURRING_BATCH') {
        return true;
    }
    return $balanceDue >= $threshold;
}

function payment_gateway_default_method_for_invoice(?array $invoice = null): string {
    return $invoice !== null && payment_gateway_invoice_prefers_ach($invoice) ? 'ACH' : 'CARD';
}

function payment_gateway_resolve_requested_method(?array $invoice, string $requestedMethod): string {
    $requestedMethod = strtoupper(trim($requestedMethod));
    return in_array($requestedMethod, ['ACH', 'CARD'], true)
        ? $requestedMethod
        : payment_gateway_default_method_for_invoice($invoice);
}

function payment_gateway_stripe_checkout_payment_method_types(array $invoice, string $method): array {
    $method = strtoupper(trim($method));
    if ($method === 'ACH' && payment_gateway_stripe_ach_checkout_allowed()) {
        return ['us_bank_account', 'card'];
    }
    return ['card'];
}

function payment_gateway_available_for_method(string $method): array {
    $method = strtoupper(trim($method));
    $available = [];
    if (payment_gateway_stripe_enabled()) {
        if ($method === 'ACH' && !payment_gateway_stripe_ach_checkout_allowed()) {
            return [];
        }
        $available['STRIPE'] = [
            'code' => 'STRIPE',
            'label' => $method === 'CARD' ? 'Stripe Checkout' : 'Stripe ACH Checkout',
            'supports_realtime_posting' => $method === 'CARD',
            'note' => $method === 'CARD'
                ? 'Card payments return from Stripe and can auto-post to the invoice.'
                : 'ACH is offered first in staging Stripe Checkout, with card kept as a fallback. Bank payments remain pending until Stripe confirms settlement.',
        ];
    }
    return $available;
}

function payment_gateway_pick(string $method, ?string $requested = null): ?string {
    $available = payment_gateway_available_for_method($method);
    if ($available === []) {
        return null;
    }
    if ($requested !== null) {
        $requested = strtoupper(trim($requested));
        if (isset($available[$requested])) {
            return $requested;
        }
    }
    $preferred = payment_gateway_default();
    if ($method === 'ACH' && isset($available['STRIPE'])) {
        return 'STRIPE';
    }
    if (isset($available[$preferred])) {
        return $preferred;
    }
    return array_key_first($available);
}

function payment_gateway_default_deposit_account_id(): int {
    $code = trim((string)payment_gateway_setting('PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE', '1000'));
    $id = accounting_find_account_id_by_code($code);
    if ($id !== null) {
        return (int)$id;
    }
    $accounts = accounting_payment_account_options();
    return (int)($accounts[0]['account_id'] ?? 0);
}

function payment_gateway_default_fee_expense_account_id(): int {
    $code = trim((string)payment_gateway_setting('PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE', '5070'));
    $id = accounting_find_account_id_by_code($code);
    if ($id !== null) {
        return (int)$id;
    }
    return (int)(accounting_find_default_fee_expense_account_id() ?? 0);
}

function payment_gateway_curl_json(string $url, array $payload, array $headers = []): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }
    $ch = curl_init($url);
    $httpHeaders = array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('Gateway transport error: ' . $error);
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function payment_gateway_curl_form(string $method, string $url, array $payload, array $headers = []): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }
    $ch = curl_init($url);
    $httpHeaders = array_merge(['Accept: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('Gateway transport error: ' . $error);
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'body' => $body, 'json' => is_array($decoded) ? $decoded : null];
}

function payment_gateway_stripe_checkout(array $invoice, string $method): array {
    $secret = trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', ''));
    if ($secret === '') {
        throw new RuntimeException('Stripe is not configured yet.');
    }
    $method = strtoupper(trim($method));
    $amountCents = (int)round(((float)$invoice['balance_due']) * 100);
    if ($amountCents <= 0) {
        throw new RuntimeException('Invoice balance must be greater than zero.');
    }

    $paymentMethods = payment_gateway_stripe_checkout_payment_method_types($invoice, $method);
    $payload = [
        'mode' => 'payment',
        'success_url' => str_replace('{METHOD}', rawurlencode(strtolower($method)), (string)payment_gateway_setting('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&method={METHOD}&session_id={CHECKOUT_SESSION_ID}')),
        'cancel_url' => str_replace('{METHOD}', rawurlencode(strtolower($method)), (string)payment_gateway_setting('STRIPE_CHECKOUT_CANCEL_URL', BASE_URL . '/payments/pay.php?cancelled=1&method={METHOD}&invoice=' . rawurlencode((string)$invoice['invoice_number']))),
        'customer_email' => (string)($invoice['client_email'] ?? ''),
        'payment_intent_data[metadata][invoice_id]' => (string)$invoice['invoice_id'],
        'payment_intent_data[metadata][invoice_number]' => (string)$invoice['invoice_number'],
        'payment_intent_data[metadata][client_id]' => (string)$invoice['client_id'],
        'payment_intent_data[metadata][source]' => 'MMIT_OPS',
        'payment_intent_data[metadata][environment]' => payment_gateway_app_environment(),
        'metadata[invoice_id]' => (string)$invoice['invoice_id'],
        'metadata[invoice_number]' => (string)$invoice['invoice_number'],
        'metadata[client_id]' => (string)$invoice['client_id'],
        'metadata[source]' => 'MMIT_OPS',
        'metadata[environment]' => payment_gateway_app_environment(),
        'line_items[0][price_data][currency]' => 'usd',
        'line_items[0][price_data][product_data][name]' => 'Invoice ' . (string)$invoice['invoice_number'],
        'line_items[0][price_data][product_data][description]' => 'Payment for ' . ((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'invoice')),
        'line_items[0][price_data][unit_amount]' => (string)$amountCents,
        'line_items[0][quantity]' => '1',
        'submit_type' => 'pay',
        'invoice_creation[enabled]' => 'false',
    ];
    foreach ($paymentMethods as $i => $pm) {
        $payload['payment_method_types[' . $i . ']'] = $pm;
    }
    if (in_array('us_bank_account', $paymentMethods, true)) {
        $payload['payment_method_options[us_bank_account][verification_method]'] = 'automatic';
    }

    $resp = payment_gateway_curl_form('POST', 'https://api.stripe.com/v1/checkout/sessions', $payload, [
        'Authorization: Bearer ' . $secret,
    ]);
    if (($resp['status'] < 200 || $resp['status'] >= 300) || empty($resp['json']['url'])) {
        $msg = $resp['json']['error']['message'] ?? 'Stripe session creation failed.';
        throw new RuntimeException((string)$msg);
    }
    return $resp['json'];
}

function payment_gateway_stripe_session(string $sessionId): array {
    $secret = trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', ''));
    if ($secret === '') {
        throw new RuntimeException('Stripe is not configured yet.');
    }
    $url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId) . '?expand[]=payment_intent&expand[]=payment_intent.latest_charge';
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $secret, 'Accept: application/json'],
        CURLOPT_TIMEOUT => 45,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('Gateway transport error: ' . $error);
    }
    $decoded = json_decode((string)$body, true);
    if ($status < 200 || $status >= 300 || !is_array($decoded)) {
        throw new RuntimeException((string)($decoded['error']['message'] ?? 'Unable to verify Stripe session.'));
    }
    return $decoded;
}


function payment_gateway_invoice_already_recorded(string $processorName, string $processorTxnId = '', string $paymentIntentId = '', string $checkoutSessionId = ''): ?int {
    if (!db_table_exists('payment_receipt')) {
        return null;
    }
    $processorName = strtoupper(trim($processorName));
    if ($processorName === '') {
        return null;
    }
    $matchers = [];
    $params = [$processorName];
    if ($paymentIntentId !== '' && db_column_exists('payment_receipt', 'processor_payment_intent_id')) {
        $matchers[] = 'processor_payment_intent_id = ?';
        $params[] = $paymentIntentId;
    }
    if ($processorTxnId !== '' && db_column_exists('payment_receipt', 'processor_txn_id')) {
        $matchers[] = 'processor_txn_id = ?';
        $params[] = $processorTxnId;
    }
    if ($checkoutSessionId !== '' && db_column_exists('payment_receipt', 'processor_checkout_session_id')) {
        $matchers[] = 'processor_checkout_session_id = ?';
        $params[] = $checkoutSessionId;
    }
    if ($matchers === []) {
        return null;
    }
    $sql = 'SELECT payment_id FROM payment_receipt WHERE processor_name = ? AND (' . implode(' OR ', $matchers) . ') ORDER BY payment_id DESC LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute($params);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function payment_gateway_webhook_table_ready(): bool {
    payment_gateway_ensure_schema();
    return db_table_exists('gateway_webhook_event');
}

function payment_gateway_webhook_log(string $provider, string $eventType, string $eventId, string $payload, string $status = 'RECEIVED', ?string $deliveryId = null, ?string $note = null, ?int $paymentId = null, ?int $invoiceId = null): ?int {
    if (!payment_gateway_webhook_table_ready()) {
        return null;
    }
    $provider = strtoupper(trim($provider));
    $eventType = trim($eventType);
    $eventId = trim($eventId);
    if ($provider === '' || $eventType === '' || $eventId === '') {
        return null;
    }
    $pdo = db();
    $st = $pdo->prepare('SELECT webhook_event_id FROM gateway_webhook_event WHERE provider_name = ? AND event_id = ? LIMIT 1');
    $st->execute([$provider, $eventId]);
    $existing = $st->fetchColumn();
    if ($existing !== false) {
        $eventDbId = (int)$existing;
        $up = $pdo->prepare("UPDATE gateway_webhook_event SET event_type = ?, delivery_id = ?, payload_json = ?, processing_status = ?, note = ?, related_payment_id = ?, related_invoice_id = ?, processed_at = CASE WHEN ? IN ('PROCESSED','IGNORED','FAILED') THEN NOW() ELSE processed_at END, updated_at = NOW() WHERE webhook_event_id = ?");
        $up->execute([$eventType, $deliveryId, $payload, strtoupper(trim($status)) ?: 'RECEIVED', $note, $paymentId, $invoiceId, strtoupper(trim($status)) ?: 'RECEIVED', $eventDbId]);
        return $eventDbId;
    }
    $ins = $pdo->prepare("INSERT INTO gateway_webhook_event (provider_name, event_id, event_type, delivery_id, payload_json, processing_status, note, related_payment_id, related_invoice_id, processed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? IN ('PROCESSED','IGNORED','FAILED') THEN NOW() ELSE NULL END)");
    $normalizedStatus = strtoupper(trim($status)) ?: 'RECEIVED';
    $ins->execute([$provider, $eventId, $eventType, $deliveryId, $payload, $normalizedStatus, $note, $paymentId, $invoiceId, $normalizedStatus]);
    return (int)$pdo->lastInsertId();
}

function payment_gateway_webhook_mark_processed(string $provider, string $eventId, string $status, ?string $note = null, ?int $paymentId = null, ?int $invoiceId = null): void {
    if (!payment_gateway_webhook_table_ready()) {
        return;
    }
    $provider = strtoupper(trim($provider));
    $eventId = trim($eventId);
    if ($provider === '' || $eventId === '') {
        return;
    }
    $st = db()->prepare('UPDATE gateway_webhook_event SET processing_status = ?, note = ?, related_payment_id = COALESCE(?, related_payment_id), related_invoice_id = COALESCE(?, related_invoice_id), processed_at = NOW(), updated_at = NOW() WHERE provider_name = ? AND event_id = ?');
    $st->execute([strtoupper(trim($status)) ?: 'PROCESSED', $note, $paymentId, $invoiceId, $provider, $eventId]);
}

function payment_gateway_read_raw_body(): string {
    $body = file_get_contents('php://input');
    return is_string($body) ? $body : '';
}

function payment_gateway_gateway_status(): array {
    payment_gateway_ensure_schema();
    return [
        'stripe' => [
            'enabled' => payment_gateway_stripe_enabled(),
            'mode' => payment_gateway_stripe_mode(),
            'environment' => payment_gateway_app_environment(),
            'secret_key_present' => trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', '')) !== '',
            'webhook_secret_present' => payment_gateway_stripe_configured_webhook_secret(payment_gateway_stripe_mode()) !== '',
            'checkout_success_url' => (string)payment_gateway_setting('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&session_id={CHECKOUT_SESSION_ID}'),
            'webhook_url' => BASE_URL . '/payments/webhook_stripe.php',
        ],
        'webhook_table_ready' => payment_gateway_webhook_table_ready(),
    ];
}

function payment_gateway_webhook_stats(int $days = 7): array {
    if (!payment_gateway_webhook_table_ready()) {
        return [];
    }
    $days = max(1, min(365, $days));
    $sql = "SELECT provider_name,
                   SUM(CASE WHEN processing_status = 'RECEIVED' THEN 1 ELSE 0 END) AS received_count,
                   SUM(CASE WHEN processing_status = 'PROCESSED' THEN 1 ELSE 0 END) AS processed_count,
                   SUM(CASE WHEN processing_status = 'IGNORED' THEN 1 ELSE 0 END) AS ignored_count,
                   SUM(CASE WHEN processing_status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                   COUNT(*) AS total_count,
                   MAX(created_at) AS last_received_at,
                   MAX(processed_at) AS last_processed_at
            FROM gateway_webhook_event
            WHERE created_at >= (NOW() - INTERVAL ? DAY)
            GROUP BY provider_name
            ORDER BY provider_name";
    $st = db()->prepare($sql);
    $st->execute([$days]);
    return $st->fetchAll() ?: [];
}

function payment_gateway_webhook_totals(): array {
    if (!payment_gateway_webhook_table_ready()) {
        return [
            'total_count' => 0,
            'processed_count' => 0,
            'ignored_count' => 0,
            'failed_count' => 0,
            'received_count' => 0,
            'last_received_at' => null,
            'last_processed_at' => null,
        ];
    }
    $sql = "SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN processing_status = 'RECEIVED' THEN 1 ELSE 0 END) AS received_count,
                   SUM(CASE WHEN processing_status = 'PROCESSED' THEN 1 ELSE 0 END) AS processed_count,
                   SUM(CASE WHEN processing_status = 'IGNORED' THEN 1 ELSE 0 END) AS ignored_count,
                   SUM(CASE WHEN processing_status = 'FAILED' THEN 1 ELSE 0 END) AS failed_count,
                   MAX(created_at) AS last_received_at,
                   MAX(processed_at) AS last_processed_at
            FROM gateway_webhook_event";
    $row = db()->query($sql)->fetch();
    return is_array($row) ? $row : [];
}

function payment_gateway_recent_events(array $filters = [], int $limit = 100): array {
    if (!payment_gateway_webhook_table_ready()) {
        return [];
    }
    $limit = max(1, min(250, $limit));
    $where = [];
    $params = [];
    $provider = strtoupper(trim((string)($filters['provider'] ?? '')));
    $status = strtoupper(trim((string)($filters['status'] ?? '')));
    if ($provider !== '') {
        $where[] = 'gwe.provider_name = ?';
        $params[] = $provider;
    }
    if ($status !== '') {
        $where[] = 'gwe.processing_status = ?';
        $params[] = $status;
    }
    $sql = "SELECT gwe.*,
                   pr.processor_name,
                   pr.processor_txn_id,
                   pr.processor_payment_intent_id,
                   pr.processor_charge_id,
                   pr.payment_status,
                   pr.payment_method,
                   pr.gross_amount,
                   ci.invoice_number,
                   ci.status AS invoice_status,
                   c.client_code,
                   c.legal_name,
                   c.dba_name
            FROM gateway_webhook_event gwe
            LEFT JOIN payment_receipt pr ON pr.payment_id = gwe.related_payment_id
            LEFT JOIN customer_invoice ci ON ci.invoice_id = gwe.related_invoice_id
            LEFT JOIN clients c ON c.client_id = ci.client_id
            ";
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY gwe.created_at DESC, gwe.webhook_event_id DESC LIMIT ' . $limit;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
}


function payment_gateway_get_webhook_event(int $webhookEventId): ?array {
    if (!payment_gateway_webhook_table_ready() || $webhookEventId <= 0) {
        return null;
    }
    $st = db()->prepare("SELECT * FROM gateway_webhook_event WHERE webhook_event_id = ? LIMIT 1");
    $st->execute([$webhookEventId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function payment_gateway_reprocess_webhook_event(int $webhookEventId): array {
    $event = payment_gateway_get_webhook_event($webhookEventId);
    if (!$event) {
        return ['ok' => false, 'errors' => ['Webhook event not found.']];
    }

    $provider = strtoupper(trim((string)($event['provider_name'] ?? '')));
    $eventId = trim((string)($event['event_id'] ?? ''));
    $eventType = trim((string)($event['event_type'] ?? ''));
    $payload = (string)($event['payload_json'] ?? '');
    if ($provider === '' || $eventId === '' || $payload === '') {
        return ['ok' => false, 'errors' => ['Webhook event is missing required data.']];
    }

    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        payment_gateway_webhook_mark_processed($provider, $eventId, 'FAILED', 'Stored webhook payload is not valid JSON.');
        return ['ok' => false, 'errors' => ['Stored webhook payload is not valid JSON.']];
    }

    try {
        if ($provider === 'STRIPE') {
            return payment_gateway_process_stripe_event($decoded);
        }
        return ['ok' => false, 'errors' => ['Unsupported webhook provider.']];
    } catch (Throwable $e) {
        payment_gateway_webhook_mark_processed($provider, $eventId, 'FAILED', $e->getMessage());
        return ['ok' => false, 'errors' => [$e->getMessage()]];
    }
}

function payment_gateway_stripe_verify_signature(string $payload, string $signatureHeader): bool {
    $secret = payment_gateway_stripe_webhook_secret_for_payload($payload);
    if ($secret === '' || $signatureHeader === '') {
        return false;
    }
    $parts = [];
    foreach (explode(',', $signatureHeader) as $segment) {
        $pieces = explode('=', trim($segment), 2);
        if (count($pieces) === 2) {
            $parts[$pieces[0]][] = $pieces[1];
        }
    }
    $timestamp = $parts['t'][0] ?? null;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp === null || $signatures === []) {
        return false;
    }
    if (abs(time() - (int)$timestamp) > 300) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}


function payment_gateway_find_payment_by_processor(string $processorName, string $processorTxnId): ?array {
    if (!db_table_exists('payment_receipt') || !db_column_exists('payment_receipt', 'processor_txn_id')) {
        return null;
    }
    $st = db()->prepare('SELECT payment_id FROM payment_receipt WHERE processor_name = ? AND processor_txn_id = ? ORDER BY payment_id DESC LIMIT 1');
    $st->execute([strtoupper(trim($processorName)), trim($processorTxnId)]);
    $id = $st->fetchColumn();
    if ($id === false) {
        return null;
    }
    return accounting_get_payment((int)$id);
}


function payment_gateway_stripe_pending_status_from_session(array $session): string {
    $paymentStatus = strtolower(trim((string)($session['payment_status'] ?? '')));
    $sessionStatus = strtolower(trim((string)($session['status'] ?? '')));
    if ($sessionStatus === 'complete' && $paymentStatus === 'paid') {
        return 'POSTED';
    }
    if ($sessionStatus === 'complete' && in_array($paymentStatus, ['unpaid', 'no_payment_required'], true)) {
        return 'PENDING';
    }
    $paymentIntent = $session['payment_intent'] ?? [];
    $intentStatus = is_array($paymentIntent) ? strtolower(trim((string)($paymentIntent['status'] ?? ''))) : '';
    if (in_array($intentStatus, ['processing', 'requires_capture'], true)) {
        return 'PENDING';
    }
    return '';
}
function payment_gateway_finalize_pending_stripe_payment(int $paymentId, array $invoice, array $fields): array {
    $result = accounting_post_pending_invoice_payment($paymentId, (int)$invoice['invoice_id'], $fields, 0);
    if (!empty($result['ok'])) {
        $update = [];
        foreach (['processor_charge_id', 'processor_receipt_url', 'processor_payment_method_label', 'settled_at'] as $column) {
            if (array_key_exists($column, $fields)) {
                $update[$column] = $fields[$column];
            }
        }
        if ($update !== []) {
            payment_gateway_stripe_update_local_payment($paymentId, $update);
        }
    }
    return $result;
}

function payment_gateway_record_stripe_invoice_payment(array $invoice, array $session): array {
    payment_gateway_ensure_schema();

    $paymentStatus = strtoupper((string)($session['payment_status'] ?? ''));
    $sessionStatus = strtoupper((string)($session['status'] ?? ''));
    $sessionId = trim((string)($session['id'] ?? ''));
    $paymentIntent = $session['payment_intent'] ?? [];
    $paymentIntentId = is_array($paymentIntent) ? trim((string)($paymentIntent['id'] ?? '')) : trim((string)$paymentIntent);
    $charge = [];
    $chargeId = '';
    if (is_array($paymentIntent) && !empty($paymentIntent['latest_charge'])) {
        if (is_array($paymentIntent['latest_charge'])) {
            $charge = $paymentIntent['latest_charge'];
            $chargeId = trim((string)($charge['id'] ?? ''));
        } else {
            $chargeId = trim((string)$paymentIntent['latest_charge']);
        }
    }
    if ($charge === [] && $chargeId !== '') {
        try {
            $charge = payment_gateway_stripe_retrieve_charge($chargeId);
        } catch (Throwable $e) {
            // Receipt and method details are helpful but should not block local reconciliation.
        }
    }

    $existing = payment_gateway_invoice_already_recorded('STRIPE', $chargeId !== '' ? $chargeId : $sessionId, $paymentIntentId, $sessionId);
    $localStatus = payment_gateway_stripe_pending_status_from_session($session);
    if ($existing !== null) {
        $existingPayment = accounting_get_payment($existing);
        if ($localStatus === 'POSTED' && strtoupper(trim((string)($existingPayment['payment_status'] ?? ''))) === 'PENDING') {
            return payment_gateway_finalize_pending_stripe_payment($existing, $invoice, [
                'payment_date' => date('Y-m-d'),
                'gross_amount' => round(((int)($session['amount_total'] ?? 0)) / 100, 2),
                'fee_amount' => $charge !== [] ? payment_gateway_stripe_charge_fee_amount($charge) : 0.00,
                'deposit_account_id' => payment_gateway_default_deposit_account_id(),
                'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
                'reference_number' => $chargeId !== '' ? $chargeId : ($sessionId !== '' ? $sessionId : $paymentIntentId),
                'settled_at' => date('Y-m-d H:i:s'),
                'processor_charge_id' => $chargeId !== '' ? $chargeId : null,
                'processor_receipt_url' => $charge !== [] ? (trim((string)($charge['receipt_url'] ?? '')) ?: null) : null,
                'processor_payment_method_label' => $charge !== [] ? (payment_gateway_stripe_payment_method_label($charge) ?: null) : null,
            ]);
        }
        return ['ok' => true, 'payment_id' => $existing, 'message' => 'Payment already recorded.'];
    }
    if ($localStatus === '') {
        return ['ok' => false, 'deferred' => true, 'errors' => ['Stripe reports this payment as ' . strtolower($paymentStatus ?: $sessionStatus ?: 'pending') . '. It has not been posted into the invoice ledger yet.']];
    }

    $amountTotal = round(((int)($session['amount_total'] ?? 0)) / 100, 2);
    $balanceDue = round((float)($invoice['balance_due'] ?? 0), 2);
    $amountToApply = min($amountTotal, $balanceDue);
    if ($amountToApply <= 0) {
        return ['ok' => false, 'ignored' => true, 'errors' => ['Nothing remains to apply locally for this Stripe payment.']];
    }
    $methodTypes = $session['payment_method_types'] ?? [];
    $method = in_array('us_bank_account', is_array($methodTypes) ? $methodTypes : [], true) ? 'ACH' : 'CARD';
    if ($charge !== []) {
        $method = payment_gateway_stripe_payment_method_from_charge($charge);
    }
    $created = (int)($session['created'] ?? (is_array($paymentIntent) ? ($paymentIntent['created'] ?? 0) : 0) ?: ($charge['created'] ?? time()));
    $receiptUrl = is_array($charge) ? trim((string)($charge['receipt_url'] ?? '')) : '';
    $methodLabel = is_array($charge) ? payment_gateway_stripe_payment_method_label($charge) : (in_array('us_bank_account', is_array($methodTypes) ? $methodTypes : [], true) ? 'us_bank_account' : 'card');

    $result = accounting_record_invoice_payment((int)$invoice['invoice_id'], [
        'payment_date' => date('Y-m-d', $created > 0 ? $created : time()),
        'payment_method' => $method,
        'gross_amount' => $amountToApply,
        'fee_amount' => $charge !== [] ? payment_gateway_stripe_charge_fee_amount($charge) : 0.00,
        'deposit_account_id' => payment_gateway_default_deposit_account_id(),
        'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
        'reference_number' => $sessionId !== '' ? $sessionId : ($paymentIntentId !== '' ? $paymentIntentId : (string)($invoice['invoice_number'] ?? '')),
        'memo' => 'Stripe Checkout payment ' . ($sessionId !== '' ? $sessionId : $paymentIntentId),
        'processor_name' => 'STRIPE',
        'processor_txn_id' => $chargeId !== '' ? $chargeId : ($sessionId !== '' ? $sessionId : $paymentIntentId),
        'payment_status' => $localStatus,
    ], 0);
    if (!empty($result['ok'])) {
        payment_gateway_stripe_update_local_payment((int)$result['payment_id'], [
            'processor_checkout_session_id' => $sessionId !== '' && str_starts_with($sessionId, 'cs_') ? $sessionId : null,
            'processor_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
            'processor_charge_id' => $chargeId !== '' ? $chargeId : null,
            'processor_customer_id' => trim((string)($session['customer'] ?? ($charge['customer'] ?? ''))) ?: null,
            'processor_receipt_url' => $receiptUrl !== '' ? $receiptUrl : null,
            'processor_payment_method_label' => $methodLabel !== '' ? $methodLabel : null,
            'processor_environment' => payment_gateway_stripe_mode(),
            'settled_at' => $localStatus === 'POSTED' ? date('Y-m-d H:i:s', $created > 0 ? $created : time()) : null,
        ]);
    }
    return $result;
}
