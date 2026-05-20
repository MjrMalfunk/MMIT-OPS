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


function payment_gateway_available_for_method(string $method): array {
    $method = strtoupper(trim($method));
    $available = [];
    if (payment_gateway_stripe_enabled()) {
        $available['STRIPE'] = [
            'code' => 'STRIPE',
            'label' => $method === 'CARD' ? 'Stripe Checkout' : 'Stripe ACH Checkout',
            'supports_realtime_posting' => $method === 'CARD',
            'note' => $method === 'CARD'
                ? 'Card payments return from Stripe and can auto-post to the invoice.'
                : 'ACH payments can start in Stripe Checkout. Settlement timing depends on bank processing.',
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

    $paymentMethods = $method === 'ACH' ? ['us_bank_account'] : ['card'];
    $payload = [
        'mode' => 'payment',
        'success_url' => str_replace('{METHOD}', rawurlencode(strtolower($method)), (string)payment_gateway_setting('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&method={METHOD}&session_id={CHECKOUT_SESSION_ID}')),
        'cancel_url' => str_replace('{METHOD}', rawurlencode(strtolower($method)), (string)payment_gateway_setting('STRIPE_CHECKOUT_CANCEL_URL', BASE_URL . '/payments/pay.php?cancelled=1&method={METHOD}&invoice=' . rawurlencode((string)$invoice['invoice_number']))),
        'customer_email' => (string)($invoice['client_email'] ?? ''),
        'payment_intent_data[metadata][invoice_id]' => (string)$invoice['invoice_id'],
        'payment_intent_data[metadata][invoice_number]' => (string)$invoice['invoice_number'],
        'payment_intent_data[metadata][client_id]' => (string)$invoice['client_id'],
        'metadata[invoice_id]' => (string)$invoice['invoice_id'],
        'metadata[invoice_number]' => (string)$invoice['invoice_number'],
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
    if ($method === 'ACH') {
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
    $url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sessionId) . '?expand[]=payment_intent';
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


function payment_gateway_invoice_already_recorded(string $processorName, string $processorTxnId = '', string $paymentIntentId = ''): ?int {
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
    return [
        'stripe' => [
            'enabled' => payment_gateway_stripe_enabled(),
            'secret_key_present' => trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', '')) !== '',
            'webhook_secret_present' => trim((string)payment_gateway_setting('STRIPE_WEBHOOK_SECRET', '')) !== '',
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
    $secret = trim((string)payment_gateway_setting('STRIPE_WEBHOOK_SECRET', ''));
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

function payment_gateway_record_stripe_invoice_payment(array $invoice, array $session): array {
    $paymentStatus = strtoupper((string)($session['payment_status'] ?? ''));
    $sessionStatus = strtoupper((string)($session['status'] ?? ''));
    $paymentIntent = $session['payment_intent'] ?? [];
    $paymentIntentId = is_array($paymentIntent) ? (string)($paymentIntent['id'] ?? '') : (string)$paymentIntent;
    $chargeId = '';
    if (is_array($paymentIntent) && !empty($paymentIntent['latest_charge'])) {
        $chargeId = (string)$paymentIntent['latest_charge'];
    }
    $existing = payment_gateway_invoice_already_recorded('STRIPE', $chargeId !== '' ? $chargeId : (string)($session['id'] ?? ''), $paymentIntentId);
    if ($existing !== null) {
        return ['ok' => true, 'payment_id' => $existing, 'message' => 'Payment already recorded.'];
    }
    if ($sessionStatus !== 'COMPLETE' || $paymentStatus !== 'PAID') {
        return ['ok' => false, 'deferred' => true, 'errors' => ['Stripe reports this payment as ' . strtolower($paymentStatus ?: $sessionStatus ?: 'pending') . '. It has not been posted into the invoice ledger yet.']];
    }

    $amountTotal = ((int)($session['amount_total'] ?? 0)) / 100;
    $methodTypes = $session['payment_method_types'] ?? [];
    $method = in_array('us_bank_account', $methodTypes, true) ? 'ACH' : 'CARD';
    $result = accounting_record_invoice_payment((int)$invoice['invoice_id'], [
        'payment_date' => date('Y-m-d'),
        'payment_method' => $method,
        'gross_amount' => $amountTotal,
        'fee_amount' => 0.00,
        'deposit_account_id' => payment_gateway_default_deposit_account_id(),
        'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
        'reference_number' => (string)($session['id'] ?? ''),
        'memo' => 'Stripe Checkout session ' . (string)($session['id'] ?? ''),
        'processor_name' => 'STRIPE',
        'processor_txn_id' => $chargeId !== '' ? $chargeId : (string)($session['id'] ?? ''),
        'payment_status' => 'POSTED',
    ], 0);
    if (!empty($result['ok']) && db_column_exists('payment_receipt', 'processor_payment_intent_id')) {
        $fields = ['processor_payment_intent_id' => $paymentIntentId, 'processor_charge_id' => $chargeId];
        $sets = []; $params = [];
        foreach ($fields as $col => $val) {
            if ($val !== '' && db_column_exists('payment_receipt', $col)) {
                $sets[] = $col . ' = ?';
                $params[] = $val;
            }
        }
        if ($sets !== []) {
            $params[] = (int)$result['payment_id'];
            $st = db()->prepare('UPDATE payment_receipt SET ' . implode(', ', $sets) . ' WHERE payment_id = ?');
            $st->execute($params);
        }
    }
    return $result;
}
