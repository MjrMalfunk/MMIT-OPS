<?php
declare(strict_types=1);

function payment_gateway_stripe_request(string $method, string $path, array $payload = [], array $headers = []): array {
    $secret = trim((string)payment_gateway_setting('STRIPE_SECRET_KEY', ''));
    if ($secret === '') {
        throw new RuntimeException('Stripe is not configured yet.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is not available on this PHP host.');
    }

    $method = strtoupper(trim($method));
    $url = 'https://api.stripe.com' . $path;
    $body = null;
    if ($method === 'GET' && $payload !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
    } elseif ($payload !== []) {
        $body = http_build_query($payload);
    }

    $httpHeaders = array_merge([
        'Authorization: Bearer ' . $secret,
        'Accept: application/json',
    ], $headers);
    if ($body !== null) {
        $httpHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 45,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($errno) {
        throw new RuntimeException('Gateway transport error: ' . $error);
    }

    $json = json_decode((string)$raw, true);
    if ($status < 200 || $status >= 300 || !is_array($json)) {
        $message = is_array($json) ? (string)($json['error']['message'] ?? 'Stripe request failed.') : 'Stripe request failed.';
        throw new RuntimeException($message);
    }

    return ['status' => $status, 'body' => (string)$raw, 'json' => $json];
}

function payment_gateway_stripe_store_client_sync(int $clientId, array $fields): void {
    if ($clientId <= 0 || !db_table_exists('clients')) {
        return;
    }
    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (db_column_exists('clients', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }
    if ($sets === []) {
        return;
    }
    $params[] = $clientId;
    $st = db()->prepare('UPDATE clients SET ' . implode(', ', $sets) . ' WHERE client_id = ?');
    $st->execute($params);
}

function payment_gateway_stripe_store_invoice_sync(int $invoiceId, array $fields): void {
    if ($invoiceId <= 0 || !db_table_exists('customer_invoice')) {
        return;
    }
    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (db_column_exists('customer_invoice', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }
    if ($sets === []) {
        return;
    }
    $params[] = $invoiceId;
    $st = db()->prepare('UPDATE customer_invoice SET ' . implode(', ', $sets) . ' WHERE invoice_id = ?');
    $st->execute($params);
}

function payment_gateway_stripe_find_local_invoice(?int $localInvoiceId = null, string $stripeInvoiceId = ''): ?array {
    if ($localInvoiceId !== null && $localInvoiceId > 0) {
        return accounting_get_invoice($localInvoiceId);
    }
    $stripeInvoiceId = trim($stripeInvoiceId);
    if ($stripeInvoiceId === '' || !db_column_exists('customer_invoice', 'stripe_invoice_id')) {
        return null;
    }
    $st = db()->prepare('SELECT invoice_id FROM customer_invoice WHERE stripe_invoice_id = ? ORDER BY invoice_id DESC LIMIT 1');
    $st->execute([$stripeInvoiceId]);
    $id = $st->fetchColumn();
    return $id === false ? null : accounting_get_invoice((int)$id);
}

function payment_gateway_stripe_primary_client_row(int $clientId): ?array {
    if ($clientId <= 0 || !db_table_exists('clients')) {
        return null;
    }
    $select = [
        'c.client_id',
        'c.client_code',
        'c.legal_name',
        'c.dba_name',
        'c.email',
        'c.phone',
    ];
    foreach (['stripe_customer_id', 'stripe_sync_status', 'stripe_last_sync_at', 'stripe_last_error'] as $column) {
        if (db_column_exists('clients', $column)) {
            $select[] = 'c.' . $column;
        }
    }
    $sql = 'SELECT ' . implode(', ', $select) . ',
                   cl.address1, cl.address2, cl.city, cl.state, cl.postal_code, cl.country
            FROM clients c
            LEFT JOIN client_location cl ON cl.location_id = (
                SELECT cl2.location_id
                FROM client_location cl2
                WHERE cl2.client_id = c.client_id
                ORDER BY cl2.is_primary DESC, cl2.location_id ASC
                LIMIT 1
            )
            WHERE c.client_id = ?
            LIMIT 1';
    $st = db()->prepare($sql);
    $st->execute([$clientId]);
    $row = $st->fetch();
    return $row ?: null;
}

function payment_gateway_stripe_ensure_customer(array $invoice): array {
    $clientId = (int)($invoice['client_id'] ?? 0);
    $client = payment_gateway_stripe_primary_client_row($clientId);
    if (!$client) {
        throw new RuntimeException('Unable to load the client details for Stripe sync.');
    }

    $existingId = trim((string)($client['stripe_customer_id'] ?? ''));
    if ($existingId !== '') {
        try {
            $resp = payment_gateway_stripe_request('GET', '/v1/customers/' . rawurlencode($existingId));
            if (!empty($resp['json']['id']) && empty($resp['json']['deleted'])) {
                return $resp['json'];
            }
        } catch (Throwable $e) {
            payment_gateway_stripe_store_client_sync($clientId, [
                'stripe_sync_status' => 'ERROR',
                'stripe_last_error' => $e->getMessage(),
            ]);
        }
    }

    $name = trim((string)($client['dba_name'] ?: $client['legal_name'] ?: $invoice['dba_name'] ?: $invoice['legal_name'] ?: 'Client'));
    $payload = [
        'name' => $name,
        'metadata[local_client_id]' => (string)$clientId,
        'metadata[local_client_code]' => (string)($client['client_code'] ?? ''),
        'metadata[source]' => 'MMIT_PORTAL',
    ];
    $email = trim((string)($client['email'] ?? ''));
    if ($email !== '') {
        $payload['email'] = $email;
    }
    $phone = trim((string)($client['phone'] ?? ''));
    if ($phone !== '') {
        $payload['phone'] = $phone;
    }
    foreach ([
        'line1' => (string)($client['address1'] ?? ''),
        'line2' => (string)($client['address2'] ?? ''),
        'city' => (string)($client['city'] ?? ''),
        'state' => (string)($client['state'] ?? ''),
        'postal_code' => (string)($client['postal_code'] ?? ''),
        'country' => (string)($client['country'] ?? ''),
    ] as $key => $value) {
        $value = trim($value);
        if ($value !== '') {
            $payload['address[' . $key . ']'] = $value;
        }
    }

    $resp = payment_gateway_stripe_request('POST', '/v1/customers', $payload);
    $customer = $resp['json'];
    payment_gateway_stripe_store_client_sync($clientId, [
        'stripe_customer_id' => (string)($customer['id'] ?? ''),
        'stripe_sync_status' => 'SYNCED',
        'stripe_last_sync_at' => date('Y-m-d H:i:s'),
        'stripe_last_error' => null,
    ]);
    return $customer;
}

function payment_gateway_stripe_retrieve_invoice(string $stripeInvoiceId): array {
    $stripeInvoiceId = trim($stripeInvoiceId);
    if ($stripeInvoiceId === '') {
        throw new RuntimeException('Missing Stripe invoice id.');
    }
    $resp = payment_gateway_stripe_request('GET', '/v1/invoices/' . rawurlencode($stripeInvoiceId));
    return $resp['json'];
}


function payment_gateway_stripe_local_balance_cents(array $invoice): int {
    return (int)round(((float)($invoice['balance_due'] ?? 0)) * 100);
}

function payment_gateway_stripe_local_payments_cents(array $invoice): int {
    if (!function_exists('accounting_invoice_payment_snapshot')) {
        return 0;
    }
    $snapshot = accounting_invoice_payment_snapshot($invoice);
    return (int)round(((float)($snapshot['payments_received'] ?? 0)) * 100);
}

function payment_gateway_stripe_invoice_is_current(array $invoice, array $stripeInvoice): bool {
    $localBalance = payment_gateway_stripe_local_balance_cents($invoice);
    $stripeRemaining = (int)($stripeInvoice['amount_remaining'] ?? $stripeInvoice['amount_due'] ?? -1);
    if ($stripeRemaining < 0) {
        return false;
    }

    $stripeStatus = strtolower(trim((string)($stripeInvoice['status'] ?? '')));
    if ($localBalance > 0 && in_array($stripeStatus, ['void', 'paid', 'uncollectible', 'deleted'], true)) {
        return false;
    }

    return $localBalance === $stripeRemaining;
}

function payment_gateway_stripe_retire_invoice(string $stripeInvoiceId, ?array $stripeInvoice = null): void {
    $stripeInvoiceId = trim($stripeInvoiceId);
    if ($stripeInvoiceId === '') {
        return;
    }
    $stripeInvoice = is_array($stripeInvoice) ? $stripeInvoice : payment_gateway_stripe_retrieve_invoice($stripeInvoiceId);
    $status = strtolower(trim((string)($stripeInvoice['status'] ?? '')));
    if ($status === '' || in_array($status, ['void', 'paid', 'deleted'], true)) {
        return;
    }
    if ($status === 'draft') {
        payment_gateway_stripe_request('DELETE', '/v1/invoices/' . rawurlencode($stripeInvoiceId));
        return;
    }
    if (in_array($status, ['open', 'uncollectible'], true)) {
        payment_gateway_stripe_request('POST', '/v1/invoices/' . rawurlencode($stripeInvoiceId) . '/void');
        return;
    }
    throw new RuntimeException('Existing Stripe invoice cannot be replaced while it is in status ' . strtoupper($status) . '.');
}

function payment_gateway_stripe_sync_local_invoice(int $invoiceId, bool $force = false): array {
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) {
        return ['ok' => false, 'errors' => ['Invoice not found.']];
    }
    $status = strtoupper(trim((string)($invoice['status'] ?? 'DRAFT')));
    if (!in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true)) {
        return ['ok' => false, 'errors' => ['Only issued invoices can be synced to Stripe.']];
    }
    if (!db_column_exists('customer_invoice', 'stripe_invoice_id')) {
        return ['ok' => false, 'errors' => ['Stripe invoice columns are not installed yet. Run the Stripe SQL patch first.']];
    }

    $existingStripeInvoiceId = trim((string)($invoice['stripe_invoice_id'] ?? ''));
    if ($existingStripeInvoiceId !== '' && !$force) {
        try {
            $stripeInvoice = payment_gateway_stripe_retrieve_invoice($existingStripeInvoiceId);
            if (payment_gateway_stripe_invoice_is_current($invoice, $stripeInvoice)) {
                payment_gateway_stripe_store_invoice_sync($invoiceId, [
                    'stripe_customer_id' => (string)($stripeInvoice['customer'] ?? ''),
                    'stripe_invoice_id' => (string)($stripeInvoice['id'] ?? ''),
                    'stripe_invoice_status' => (string)($stripeInvoice['status'] ?? ''),
                    'stripe_hosted_invoice_url' => (string)($stripeInvoice['hosted_invoice_url'] ?? ''),
                    'stripe_invoice_pdf_url' => (string)($stripeInvoice['invoice_pdf'] ?? ''),
                    'stripe_sync_status' => 'SYNCED',
                    'stripe_last_sync_at' => date('Y-m-d H:i:s'),
                    'stripe_last_error' => null,
                ]);
                return [
                    'ok' => true,
                    'stripe_invoice_id' => (string)($stripeInvoice['id'] ?? ''),
                    'stripe_customer_id' => (string)($stripeInvoice['customer'] ?? ''),
                    'hosted_invoice_url' => (string)($stripeInvoice['hosted_invoice_url'] ?? ''),
                    'invoice_pdf_url' => (string)($stripeInvoice['invoice_pdf'] ?? ''),
                    'message' => 'Stripe hosted payment page refreshed.',
                ];
            }

            payment_gateway_stripe_retire_invoice($existingStripeInvoiceId, $stripeInvoice);
            payment_gateway_stripe_store_invoice_sync($invoiceId, [
                'stripe_invoice_id' => null,
                'stripe_invoice_status' => null,
                'stripe_hosted_invoice_url' => null,
                'stripe_invoice_pdf_url' => null,
                'stripe_sync_status' => 'PENDING',
                'stripe_last_sync_at' => date('Y-m-d H:i:s'),
                'stripe_last_error' => 'Prior Stripe invoice was retired so a fresh payment page could be created for the current balance.',
            ]);
            $existingStripeInvoiceId = '';
        } catch (Throwable $e) {
            payment_gateway_stripe_store_invoice_sync($invoiceId, [
                'stripe_sync_status' => 'ERROR',
                'stripe_last_error' => $e->getMessage(),
                'stripe_last_sync_at' => date('Y-m-d H:i:s'),
            ]);
            $existingStripeInvoiceId = '';
        }
    }

    try {
        $customer = payment_gateway_stripe_ensure_customer($invoice);
        $customerId = (string)($customer['id'] ?? '');
        if ($customerId === '') {
            throw new RuntimeException('Stripe customer id was not returned.');
        }

        $invoicePayload = [
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'auto_advance' => 'false',
            'description' => 'MMIT invoice ' . (string)$invoice['invoice_number'],
            'metadata[local_invoice_id]' => (string)$invoiceId,
            'metadata[local_invoice_number]' => (string)($invoice['invoice_number'] ?? ''),
            'metadata[local_client_id]' => (string)($invoice['client_id'] ?? ''),
        ];
        $dueDate = trim((string)($invoice['due_date'] ?? ''));
        if ($dueDate !== '') {
            $ts = strtotime($dueDate . ' 12:00:00');
            if ($ts !== false) {
                $invoicePayload['due_date'] = (string)$ts;
            }
        }
        if (trim((string)($invoice['memo'] ?? '')) !== '') {
            $invoicePayload['footer'] = trim((string)$invoice['memo']);
        }
        $invoiceResp = payment_gateway_stripe_request('POST', '/v1/invoices', $invoicePayload);
        $stripeInvoiceId = (string)($invoiceResp['json']['id'] ?? '');
        if ($stripeInvoiceId === '') {
            throw new RuntimeException('Stripe draft invoice id was not returned.');
        }

        $lines = accounting_invoice_lines($invoiceId);
        foreach ($lines as $line) {
            $amountCents = (int)round(((float)($line['line_total'] ?? 0)) * 100);
            if ($amountCents === 0) {
                continue;
            }
            $parts = [];
            $code = trim((string)($line['service_code'] ?? ''));
            if ($code !== '') {
                $parts[] = '[' . $code . ']';
            }
            $parts[] = trim((string)($line['description'] ?? 'Invoice line'));
            $qty = (float)($line['quantity'] ?? 0);
            $unit = (float)($line['unit_price'] ?? 0);
            $parts[] = 'Qty ' . number_format($qty, 2) . ' @ $' . number_format($unit, 2);
            payment_gateway_stripe_request('POST', '/v1/invoiceitems', [
                'customer' => $customerId,
                'invoice' => $stripeInvoiceId,
                'currency' => 'usd',
                'amount' => (string)$amountCents,
                'description' => implode(' ', array_filter($parts)),
                'metadata[local_invoice_id]' => (string)$invoiceId,
                'metadata[local_invoice_line_id]' => (string)($line['invoice_line_id'] ?? 0),
            ]);
        }
        $taxAmount = round((float)($invoice['tax_amount'] ?? 0), 2);
        if ($taxAmount > 0) {
            payment_gateway_stripe_request('POST', '/v1/invoiceitems', [
                'customer' => $customerId,
                'invoice' => $stripeInvoiceId,
                'currency' => 'usd',
                'amount' => (string)((int)round($taxAmount * 100)),
                'description' => 'Sales tax',
                'metadata[local_invoice_id]' => (string)$invoiceId,
                'metadata[local_tax_line]' => '1',
            ]);
        }
        $priorPaymentsCents = payment_gateway_stripe_local_payments_cents($invoice);
        if ($priorPaymentsCents > 0) {
            $snapshot = function_exists('accounting_invoice_payment_snapshot') ? accounting_invoice_payment_snapshot($invoice) : [];
            $creditDescription = 'Payments already received outside Stripe';
            $lastPaymentDate = trim((string)($snapshot['last_payment_date'] ?? ''));
            if ($lastPaymentDate !== '') {
                $creditDescription .= ' through ' . $lastPaymentDate;
            }
            payment_gateway_stripe_request('POST', '/v1/invoiceitems', [
                'customer' => $customerId,
                'invoice' => $stripeInvoiceId,
                'currency' => 'usd',
                'amount' => (string)(0 - $priorPaymentsCents),
                'description' => $creditDescription,
                'metadata[local_invoice_id]' => (string)$invoiceId,
                'metadata[local_prior_payments]' => '1',
            ]);
        }

        $finalizeResp = payment_gateway_stripe_request('POST', '/v1/invoices/' . rawurlencode($stripeInvoiceId) . '/finalize', [
            'auto_advance' => 'false',
        ]);
        $finalInvoice = $finalizeResp['json'];
        payment_gateway_stripe_store_invoice_sync($invoiceId, [
            'stripe_customer_id' => $customerId,
            'stripe_invoice_id' => (string)($finalInvoice['id'] ?? $stripeInvoiceId),
            'stripe_invoice_status' => (string)($finalInvoice['status'] ?? 'open'),
            'stripe_hosted_invoice_url' => (string)($finalInvoice['hosted_invoice_url'] ?? ''),
            'stripe_invoice_pdf_url' => (string)($finalInvoice['invoice_pdf'] ?? ''),
            'stripe_sync_status' => 'SYNCED',
            'stripe_last_sync_at' => date('Y-m-d H:i:s'),
            'stripe_last_error' => null,
        ]);
        return [
            'ok' => true,
            'stripe_customer_id' => $customerId,
            'stripe_invoice_id' => (string)($finalInvoice['id'] ?? $stripeInvoiceId),
            'hosted_invoice_url' => (string)($finalInvoice['hosted_invoice_url'] ?? ''),
            'invoice_pdf_url' => (string)($finalInvoice['invoice_pdf'] ?? ''),
            'message' => 'Stripe hosted payment page is ready.',
        ];
    } catch (Throwable $e) {
        payment_gateway_stripe_store_invoice_sync($invoiceId, [
            'stripe_sync_status' => 'ERROR',
            'stripe_last_sync_at' => date('Y-m-d H:i:s'),
            'stripe_last_error' => $e->getMessage(),
        ]);
        return ['ok' => false, 'errors' => ['Stripe sync failed: ' . $e->getMessage()]];
    }
}

function payment_gateway_stripe_update_local_payment(int $paymentId, array $fields): void {
    if ($paymentId <= 0 || !db_table_exists('payment_receipt')) {
        return;
    }
    $sets = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (db_column_exists('payment_receipt', $column)) {
            $sets[] = $column . ' = ?';
            $params[] = $value;
        }
    }
    if ($sets === []) {
        return;
    }
    $params[] = $paymentId;
    $st = db()->prepare('UPDATE payment_receipt SET ' . implode(', ', $sets) . ' WHERE payment_id = ?');
    $st->execute($params);
}

function payment_gateway_stripe_retrieve_charge(string $chargeId, bool $expandBalance = true): array {
    $payload = [];
    if ($expandBalance) {
        $payload['expand[0]'] = 'balance_transaction';
    }
    $resp = payment_gateway_stripe_request('GET', '/v1/charges/' . rawurlencode($chargeId), $payload);
    return $resp['json'];
}

function payment_gateway_stripe_retrieve_payment_intent(string $paymentIntentId): array {
    $paymentIntentId = trim($paymentIntentId);
    if ($paymentIntentId === '') {
        throw new RuntimeException('Missing Stripe payment intent id.');
    }
    $resp = payment_gateway_stripe_request('GET', '/v1/payment_intents/' . rawurlencode($paymentIntentId), [
        'expand[0]' => 'latest_charge.balance_transaction',
        'expand[1]' => 'payment_method',
    ]);
    return $resp['json'];
}

function payment_gateway_stripe_charge_has_reconciliation_details(array $charge): bool {
    if ($charge === []) {
        return false;
    }
    return is_array($charge['payment_method_details'] ?? null)
        && is_array($charge['balance_transaction'] ?? null);
}

function payment_gateway_stripe_list_invoice_payments(string $stripeInvoiceId): array {
    $stripeInvoiceId = trim($stripeInvoiceId);
    if ($stripeInvoiceId === '') {
        return [];
    }
    $resp = payment_gateway_stripe_request('GET', '/v1/invoice_payments', [
        'invoice' => $stripeInvoiceId,
        'limit' => '10',
    ]);
    $rows = $resp['json']['data'] ?? [];
    return is_array($rows) ? $rows : [];
}

function payment_gateway_stripe_payment_refs_from_invoice(string $stripeInvoiceId): array {
    $refs = [
        'payment_intent_id' => '',
        'charge_id' => '',
    ];
    foreach (payment_gateway_stripe_list_invoice_payments($stripeInvoiceId) as $invoicePayment) {
        if (!is_array($invoicePayment)) {
            continue;
        }
        if (strtolower(trim((string)($invoicePayment['status'] ?? ''))) !== 'paid') {
            continue;
        }
        $payment = $invoicePayment['payment'] ?? [];
        if (!is_array($payment)) {
            continue;
        }
        $type = strtolower(trim((string)($payment['type'] ?? '')));
        if ($refs['payment_intent_id'] === '' && $type === 'payment_intent') {
            $refs['payment_intent_id'] = trim((string)($payment['payment_intent'] ?? ''));
        }
        if ($refs['charge_id'] === '' && $type === 'charge') {
            $refs['charge_id'] = trim((string)($payment['charge'] ?? ''));
        }
        if ($refs['payment_intent_id'] !== '' || $refs['charge_id'] !== '') {
            break;
        }
    }
    return $refs;
}

function payment_gateway_stripe_create_refund(array $payment, ?float $amount = null, string $reason = ''): array {
    $chargeId = trim((string)($payment['processor_charge_id'] ?? ''));
    $paymentIntentId = trim((string)($payment['processor_payment_intent_id'] ?? ''));
    $processorTxnId = trim((string)($payment['processor_txn_id'] ?? ''));
    if ($chargeId === '' && $paymentIntentId === '' && preg_match('/^in_/i', $processorTxnId) === 1) {
        $refs = payment_gateway_stripe_payment_refs_from_invoice($processorTxnId);
        $paymentIntentId = trim((string)($refs['payment_intent_id'] ?? ''));
        $chargeId = trim((string)($refs['charge_id'] ?? ''));
    }
    if ($chargeId === '' && $paymentIntentId === '') {
        throw new RuntimeException('No Stripe charge, payment intent, or refundable invoice payment could be resolved for this payment.');
    }

    $payload = [];
    if ($chargeId !== '') {
        $payload['charge'] = $chargeId;
    } else {
        $payload['payment_intent'] = $paymentIntentId;
    }
    if ($amount !== null) {
        $amountCents = (int)round($amount * 100);
        if ($amountCents <= 0) {
            throw new RuntimeException('Refund amount must be greater than zero.');
        }
        $payload['amount'] = (string)$amountCents;
    }
    $payload['metadata[local_payment_id]'] = (string)($payment['payment_id'] ?? 0);
    $payload['metadata[source]'] = 'MMIT_PORTAL';
    $reason = trim($reason);
    if ($reason !== '') {
        $payload['metadata[local_reason]'] = function_exists('mb_substr') ? mb_substr($reason, 0, 200) : substr($reason, 0, 200);
    }

    $resp = payment_gateway_stripe_request('POST', '/v1/refunds', $payload);
    return $resp['json'];
}

function payment_gateway_stripe_metadata_invoice_id(array $object): int {
    $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
    foreach (['invoice_id', 'local_invoice_id'] as $key) {
        $invoiceId = (int)($metadata[$key] ?? 0);
        if ($invoiceId > 0) {
            return $invoiceId;
        }
    }
    $paymentIntent = $object['payment_intent'] ?? null;
    if (is_array($paymentIntent)) {
        $nested = is_array($paymentIntent['metadata'] ?? null) ? $paymentIntent['metadata'] : [];
        foreach (['invoice_id', 'local_invoice_id'] as $key) {
            $invoiceId = (int)($nested[$key] ?? 0);
            if ($invoiceId > 0) {
                return $invoiceId;
            }
        }
    }
    return 0;
}


function payment_gateway_stripe_charge_fee_amount(array $charge): float {
    $balanceTransaction = $charge['balance_transaction'] ?? null;
    if (is_array($balanceTransaction) && isset($balanceTransaction['fee'])) {
        return round(((int)$balanceTransaction['fee']) / 100, 2);
    }
    return 0.00;
}

function payment_gateway_stripe_charge_net_amount(array $charge): ?float {
    $balanceTransaction = $charge['balance_transaction'] ?? null;
    if (is_array($balanceTransaction) && isset($balanceTransaction['net'])) {
        return round(((int)$balanceTransaction['net']) / 100, 2);
    }
    return null;
}

function payment_gateway_stripe_payment_method_details(array $charge = [], array $paymentIntent = []): array {
    $details = is_array($charge['payment_method_details'] ?? null) ? $charge['payment_method_details'] : [];
    if ($details !== []) {
        return $details;
    }

    $latestCharge = is_array($paymentIntent['latest_charge'] ?? null) ? $paymentIntent['latest_charge'] : [];
    $details = is_array($latestCharge['payment_method_details'] ?? null) ? $latestCharge['payment_method_details'] : [];
    if ($details !== []) {
        return $details;
    }

    $paymentMethod = is_array($paymentIntent['payment_method'] ?? null) ? $paymentIntent['payment_method'] : [];
    $type = strtolower(trim((string)($paymentMethod['type'] ?? '')));
    if ($type === '') {
        return [];
    }

    $fallback = ['type' => $type];
    if (is_array($paymentMethod[$type] ?? null)) {
        $fallback[$type] = $paymentMethod[$type];
    }
    return $fallback;
}

function payment_gateway_stripe_payment_method_label_from_details(array $details): string {
    $type = strtolower(trim((string)($details['type'] ?? '')));
    if ($type === '') {
        return '';
    }
    if ($type === 'card') {
        $card = is_array($details['card'] ?? null) ? $details['card'] : [];
        $brand = trim((string)($card['brand'] ?? 'card'));
        $last4 = trim((string)($card['last4'] ?? ''));
        return $last4 !== '' ? strtoupper($brand) . ' •••• ' . $last4 : $brand;
    }
    if ($type === 'us_bank_account') {
        $bank = is_array($details['us_bank_account'] ?? null) ? $details['us_bank_account'] : [];
        $parts = [];
        $bankName = trim((string)($bank['bank_name'] ?? ''));
        if ($bankName !== '') {
            $parts[] = $bankName;
        }
        $accountType = trim((string)($bank['account_type'] ?? ''));
        if ($accountType !== '') {
            $parts[] = $accountType;
        }
        $label = $parts !== [] ? implode(' ', $parts) : 'bank account';
        $last4 = trim((string)($bank['last4'] ?? ''));
        return $last4 !== '' ? $label . ' •••• ' . $last4 : $label;
    }
    return $type;
}

function payment_gateway_stripe_payment_method_label(array $charge): string {
    return payment_gateway_stripe_payment_method_label_from_details(payment_gateway_stripe_payment_method_details($charge));
}

function payment_gateway_stripe_payment_method_from_details(array $details): string {
    $type = strtolower(trim((string)($details['type'] ?? '')));
    if ($type === 'us_bank_account' || $type === 'ach_debit') {
        return 'ACH';
    }
    if ($type === 'card') {
        return 'CARD';
    }
    return 'CARD';
}

function payment_gateway_stripe_payment_method_from_charge(array $charge): string {
    return payment_gateway_stripe_payment_method_from_details(payment_gateway_stripe_payment_method_details($charge));
}

function payment_gateway_stripe_record_charge_payment(array $invoice, array $charge): array {
    $chargeId = trim((string)($charge['id'] ?? ''));
    $paymentIntentId = trim((string)($charge['payment_intent'] ?? ''));
    $existing = payment_gateway_invoice_already_recorded('STRIPE', $chargeId, $paymentIntentId);
    if ($existing !== null) {
        $existingPayment = function_exists('accounting_get_payment') ? accounting_get_payment($existing) : null;
        if (is_array($existingPayment) && strtoupper(trim((string)($existingPayment['payment_status'] ?? ''))) === 'PENDING') {
            $result = accounting_post_pending_invoice_payment($existing, (int)$invoice['invoice_id'], [
                'payment_date' => date('Y-m-d', (int)($charge['created'] ?? time())),
                'payment_method' => payment_gateway_stripe_payment_method_from_charge($charge),
                'gross_amount' => round(((int)($charge['amount'] ?? 0)) / 100, 2),
                'fee_amount' => payment_gateway_stripe_charge_fee_amount($charge),
                'deposit_account_id' => payment_gateway_default_deposit_account_id(),
                'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
                'reference_number' => $chargeId !== '' ? $chargeId : (string)($invoice['invoice_number'] ?? ''),
                'settled_at' => date('Y-m-d H:i:s', (int)($charge['created'] ?? time())),
            ], 0);
            if (!empty($result['ok'])) {
                payment_gateway_stripe_update_local_payment($existing, [
                    'payment_method' => payment_gateway_stripe_payment_method_from_charge($charge),
                    'processor_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
                    'processor_charge_id' => $chargeId !== '' ? $chargeId : null,
                    'processor_receipt_url' => trim((string)($charge['receipt_url'] ?? '')) ?: null,
                    'processor_payment_method_label' => payment_gateway_stripe_payment_method_label($charge) ?: null,
                    'processor_environment' => payment_gateway_stripe_mode(),
                ]);
            }
            return $result;
        }
        return ['ok' => true, 'payment_id' => $existing, 'message' => 'Stripe payment already recorded.'];
    }
    if (!accounting_invoice_can_receive_payment($invoice)) {
        return ['ok' => false, 'ignored' => true, 'errors' => ['Local invoice is not open for payment posting.']];
    }

    $grossAmount = round(((int)($charge['amount'] ?? 0)) / 100, 2);
    $feeAmount = payment_gateway_stripe_charge_fee_amount($charge);
    if ($grossAmount <= 0) {
        return ['ok' => false, 'ignored' => true, 'errors' => ['Stripe charge amount was not usable.']];
    }

    $result = accounting_record_invoice_payment((int)$invoice['invoice_id'], [
        'payment_date' => date('Y-m-d', (int)($charge['created'] ?? time())),
        'payment_method' => payment_gateway_stripe_payment_method_from_charge($charge),
        'gross_amount' => $grossAmount,
        'fee_amount' => $feeAmount,
        'deposit_account_id' => payment_gateway_default_deposit_account_id(),
        'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
        'reference_number' => $chargeId !== '' ? $chargeId : (string)($invoice['invoice_number'] ?? ''),
        'memo' => 'Stripe payment for ' . (string)($invoice['invoice_number'] ?? 'invoice'),
        'processor_name' => 'STRIPE',
        'processor_txn_id' => $chargeId,
        'payment_status' => 'POSTED',
    ], 0);
    if (!empty($result['ok'])) {
        payment_gateway_stripe_update_local_payment((int)$result['payment_id'], [
            'processor_payment_intent_id' => $paymentIntentId !== '' ? $paymentIntentId : null,
            'processor_charge_id' => $chargeId !== '' ? $chargeId : null,
            'processor_customer_id' => trim((string)($charge['customer'] ?? '')) ?: null,
            'processor_receipt_url' => trim((string)($charge['receipt_url'] ?? '')) ?: null,
            'processor_payment_method_label' => payment_gateway_stripe_payment_method_label($charge) ?: null,
            'processor_environment' => payment_gateway_stripe_mode(),
            'settled_at' => date('Y-m-d H:i:s', (int)($charge['created'] ?? time())),
        ]);
    }
    return $result;
}

function payment_gateway_stripe_record_invoice_fallback_payment(array $invoice, array $stripeInvoice): array {
    if (!accounting_invoice_can_receive_payment($invoice)) {
        $existingPaymentId = null;
        if (db_table_exists('payment_invoice_apply')) {
            $st = db()->prepare("SELECT p.payment_id FROM payment_receipt p INNER JOIN payment_invoice_apply pia ON pia.payment_id = p.payment_id WHERE pia.invoice_id = ? AND COALESCE(p.processor_name, '') = ? ORDER BY p.payment_id DESC LIMIT 1");
            $st->execute([(int)$invoice['invoice_id'], 'STRIPE']);
            $existingPaymentId = $st->fetchColumn();
        }
        if ($existingPaymentId !== false && $existingPaymentId !== null) {
            return ['ok' => true, 'payment_id' => (int)$existingPaymentId, 'message' => 'Stripe invoice was already recorded locally.'];
        }
        return ['ok' => false, 'ignored' => true, 'errors' => ['Local invoice is not open for payment posting.']];
    }

    $amountPaid = round(((int)($stripeInvoice['amount_paid'] ?? 0)) / 100, 2);
    if ($amountPaid <= 0) {
        return ['ok' => false, 'ignored' => true, 'errors' => ['Stripe invoice reports no paid amount yet.']];
    }
    $amountToApply = min(round((float)($invoice['balance_due'] ?? 0), 2), $amountPaid);
    if ($amountToApply <= 0) {
        return ['ok' => false, 'ignored' => true, 'errors' => ['Nothing remains to apply locally.']];
    }

    $reference = trim((string)($stripeInvoice['number'] ?? $stripeInvoice['id'] ?? ''));
    $paymentDate = date('Y-m-d', (int)($stripeInvoice['status_transitions']['paid_at'] ?? time()));
    $processorCustomerId = trim((string)($stripeInvoice['customer'] ?? ''));
    $refs = payment_gateway_stripe_payment_refs_from_invoice((string)($stripeInvoice['id'] ?? ''));
    $charge = [];
    $chargeId = trim((string)($refs['charge_id'] ?? ''));
    if ($chargeId !== '') {
        try {
            $charge = payment_gateway_stripe_retrieve_charge($chargeId);
        } catch (Throwable $e) {
            $charge = [];
        }
    }
    $feeAmount = $charge !== [] ? payment_gateway_stripe_charge_fee_amount($charge) : 0.00;
    $result = accounting_record_invoice_payment((int)$invoice['invoice_id'], [
        'payment_date' => $paymentDate,
        'payment_method' => $charge !== [] ? payment_gateway_stripe_payment_method_from_charge($charge) : 'CARD',
        'gross_amount' => $amountToApply,
        'fee_amount' => $feeAmount,
        'deposit_account_id' => payment_gateway_default_deposit_account_id(),
        'fee_expense_account_id' => payment_gateway_default_fee_expense_account_id(),
        'reference_number' => $reference,
        'memo' => 'Stripe hosted invoice payment for ' . (string)($invoice['invoice_number'] ?? 'invoice'),
        'processor_name' => 'STRIPE',
        'processor_txn_id' => trim((string)($stripeInvoice['id'] ?? '')),
        'payment_status' => 'POSTED',
    ], 0);
    if (!empty($result['ok'])) {
        payment_gateway_stripe_update_local_payment((int)$result['payment_id'], [
            'processor_customer_id' => $processorCustomerId !== '' ? $processorCustomerId : null,
            'processor_payment_intent_id' => trim((string)($refs['payment_intent_id'] ?? '')) !== '' ? trim((string)$refs['payment_intent_id']) : null,
            'processor_charge_id' => $chargeId !== '' ? $chargeId : null,
            'processor_receipt_url' => $charge !== [] ? (trim((string)($charge['receipt_url'] ?? '')) ?: null) : null,
            'processor_payment_method_label' => $charge !== [] ? (payment_gateway_stripe_payment_method_label($charge) ?: null) : null,
            'processor_environment' => payment_gateway_stripe_mode(),
            'settled_at' => date('Y-m-d H:i:s', strtotime($paymentDate . ' 12:00:00')),
        ]);
    }
    return $result;
}

function payment_gateway_process_stripe_event(array $decoded): array {
    payment_gateway_ensure_schema();

    $eventId = trim((string)($decoded['id'] ?? ''));
    $eventType = trim((string)($decoded['type'] ?? 'unknown'));
    $object = $decoded['data']['object'] ?? [];

    if ($eventId === '') {
        return ['ok' => false, 'errors' => ['Stripe event id missing.']];
    }

    $mark = static function (string $status, string $note, ?int $paymentId = null, ?int $invoiceId = null) use ($eventId): void {
        payment_gateway_webhook_mark_processed('STRIPE', $eventId, $status, $note, $paymentId, $invoiceId);
    };

    try {
        if ($eventType === 'checkout.session.completed') {
            $session = is_array($object) ? $object : [];
            $invoiceId = payment_gateway_stripe_metadata_invoice_id($session);
            if ($invoiceId <= 0) {
                $mark('FAILED', 'Unmatched Stripe checkout session: missing invoice metadata.');
                return ['ok' => false, 'ignored' => true, 'errors' => ['Stripe checkout session missing invoice metadata.']];
            }
            $invoice = accounting_get_invoice($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found for Stripe checkout session.');
            }
            $result = payment_gateway_record_stripe_invoice_payment($invoice, $session);
            if (!empty($result['ok'])) {
                $paymentId = (int)($result['payment_id'] ?? 0) ?: null;
                $note = (string)($result['message'] ?? 'Stripe checkout payment processed.');
                $mark('PROCESSED', $note, $paymentId, $invoiceId);
                return ['ok' => true, 'payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'message' => $note];
            }
            $note = implode('; ', $result['errors'] ?? ['Stripe session not in a final paid state.']);
            $mark('IGNORED', $note, null, $invoiceId ?: null);
            return ['ok' => false, 'ignored' => true, 'invoice_id' => $invoiceId, 'errors' => [$note]];
        }

        if ($eventType === 'payment_intent.succeeded' || $eventType === 'payment_intent.processing') {
            $intent = is_array($object) ? $object : [];
            $invoiceId = payment_gateway_stripe_metadata_invoice_id($intent);
            if ($invoiceId <= 0) {
                $mark('FAILED', 'Unmatched Stripe PaymentIntent: missing invoice metadata.');
                return ['ok' => false, 'ignored' => true, 'errors' => ['PaymentIntent missing invoice metadata.']];
            }
            $invoice = accounting_get_invoice($invoiceId);
            if (!$invoice) {
                throw new RuntimeException('Invoice not found for Stripe PaymentIntent.');
            }
            $pseudoSession = [
                'id' => (string)($intent['id'] ?? $eventId),
                'payment_status' => $eventType === 'payment_intent.succeeded' ? 'paid' : 'unpaid',
                'status' => 'complete',
                'amount_total' => $eventType === 'payment_intent.succeeded' ? (int)($intent['amount_received'] ?: ($intent['amount'] ?? 0)) : (int)($intent['amount'] ?? 0),
                'payment_method_types' => $intent['payment_method_types'] ?? [],
                'metadata' => $intent['metadata'] ?? [],
                'payment_intent' => $intent,
            ];
            $result = payment_gateway_record_stripe_invoice_payment($invoice, $pseudoSession);
            if (!empty($result['ok'])) {
                $paymentId = (int)($result['payment_id'] ?? 0) ?: null;
                $note = (string)($result['message'] ?? 'Stripe PaymentIntent processed.');
                $mark('PROCESSED', $note, $paymentId, $invoiceId);
                return ['ok' => true, 'payment_id' => $paymentId, 'invoice_id' => $invoiceId, 'message' => $note];
            }
            $note = implode('; ', $result['errors'] ?? ['Stripe PaymentIntent could not be posted.']);
            $mark('IGNORED', $note, null, $invoiceId ?: null);
            return ['ok' => false, 'ignored' => true, 'invoice_id' => $invoiceId, 'errors' => [$note]];
        }

        if ($eventType === 'charge.succeeded') {
            $charge = is_array($object) ? $object : [];
            $localInvoiceId = payment_gateway_stripe_metadata_invoice_id($charge);
            $stripeInvoiceId = trim((string)($charge['invoice'] ?? ''));
            $invoice = payment_gateway_stripe_find_local_invoice($localInvoiceId > 0 ? $localInvoiceId : null, $stripeInvoiceId);
            if (!$invoice) {
                $mark('FAILED', 'Unmatched Stripe charge: no local invoice matched.');
                return ['ok' => false, 'ignored' => true, 'errors' => ['No local invoice matched the Stripe charge.']];
            }
            if (!is_array($charge['balance_transaction'] ?? null) && !empty($charge['id'])) {
                $charge = payment_gateway_stripe_retrieve_charge((string)$charge['id']);
            }
            $result = payment_gateway_stripe_record_charge_payment($invoice, $charge);
            if (!empty($result['ok'])) {
                $paymentId = (int)($result['payment_id'] ?? 0) ?: null;
                $note = (string)($result['message'] ?? 'Stripe charge payment processed.');
                $mark('PROCESSED', $note, $paymentId, (int)$invoice['invoice_id']);
                return ['ok' => true, 'payment_id' => $paymentId, 'invoice_id' => (int)$invoice['invoice_id'], 'message' => $note];
            }
            $note = implode('; ', $result['errors'] ?? ['Stripe charge could not be posted.']);
            $mark(!empty($result['ignored']) ? 'IGNORED' : 'FAILED', $note, null, (int)$invoice['invoice_id']);
            return !empty($result['ignored'])
                ? ['ok' => false, 'ignored' => true, 'invoice_id' => (int)$invoice['invoice_id'], 'errors' => [$note]]
                : ['ok' => false, 'invoice_id' => (int)$invoice['invoice_id'], 'errors' => [$note]];
        }

        if ($eventType === 'invoice.paid') {
            $stripeInvoice = is_array($object) ? $object : [];
            $localInvoiceId = (int)($stripeInvoice['metadata']['local_invoice_id'] ?? $stripeInvoice['metadata']['invoice_id'] ?? 0);
            $invoice = payment_gateway_stripe_find_local_invoice($localInvoiceId > 0 ? $localInvoiceId : null, (string)($stripeInvoice['id'] ?? ''));
            if (!$invoice) {
                $mark('FAILED', 'Unmatched Stripe invoice.paid event: no local invoice matched.');
                return ['ok' => false, 'ignored' => true, 'errors' => ['No local invoice matched the Stripe invoice.paid event.']];
            }
            payment_gateway_stripe_store_invoice_sync((int)$invoice['invoice_id'], [
                'stripe_customer_id' => (string)($stripeInvoice['customer'] ?? ''),
                'stripe_invoice_id' => (string)($stripeInvoice['id'] ?? ''),
                'stripe_invoice_status' => (string)($stripeInvoice['status'] ?? 'paid'),
                'stripe_hosted_invoice_url' => (string)($stripeInvoice['hosted_invoice_url'] ?? ''),
                'stripe_invoice_pdf_url' => (string)($stripeInvoice['invoice_pdf'] ?? ''),
                'stripe_sync_status' => 'SYNCED',
                'stripe_last_sync_at' => date('Y-m-d H:i:s'),
                'stripe_last_error' => null,
            ]);
            $result = payment_gateway_stripe_record_invoice_fallback_payment($invoice, $stripeInvoice);
            if (!empty($result['ok'])) {
                $paymentId = (int)($result['payment_id'] ?? 0) ?: null;
                $note = (string)($result['message'] ?? 'Stripe invoice payment processed.');
                $mark('PROCESSED', $note, $paymentId, (int)$invoice['invoice_id']);
                return ['ok' => true, 'payment_id' => $paymentId, 'invoice_id' => (int)$invoice['invoice_id'], 'message' => $note];
            }
            $note = implode('; ', $result['errors'] ?? ['Stripe invoice could not be posted.']);
            $mark(!empty($result['ignored']) ? 'IGNORED' : 'FAILED', $note, null, (int)$invoice['invoice_id']);
            return !empty($result['ignored'])
                ? ['ok' => false, 'ignored' => true, 'invoice_id' => (int)$invoice['invoice_id'], 'errors' => [$note]]
                : ['ok' => false, 'invoice_id' => (int)$invoice['invoice_id'], 'errors' => [$note]];
        }

        $mark('IGNORED', 'Event type not handled by Stripe payment wiring.');
        return ['ok' => false, 'ignored' => true, 'errors' => ['Event type not handled by Stripe payment wiring.']];
    } catch (Throwable $e) {
        $mark('FAILED', $e->getMessage());
        return ['ok' => false, 'errors' => [$e->getMessage()]];
    }
}
