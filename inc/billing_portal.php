<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/accounting.php';

function billing_portal_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function billing_portal_hmac_key(): string
{
    $raw = defined('APP_ENC_KEY_B64') ? base64_decode((string) APP_ENC_KEY_B64, true) : false;
    if (is_string($raw) && strlen($raw) >= 32) {
        return $raw;
    }
    return hash('sha256', APP_NAME . '|' . BASE_URL . '|billing-portal', true);
}

function billing_portal_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function billing_portal_base64url_decode(string $value): string|false
{
    $padded = strtr($value, '-_', '+/');
    $remainder = strlen($padded) % 4;
    if ($remainder > 0) {
        $padded .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode($padded, true);
}

function billing_portal_create_access_token(string $email, int $ttlSeconds = 1200): string
{
    $email = billing_portal_normalize_email($email);
    $payload = [
        'email' => $email,
        'exp' => time() + max(300, $ttlSeconds),
        'nonce' => bin2hex(random_bytes(8)),
        'purpose' => 'billing_portal_access',
    ];
    $encodedPayload = billing_portal_base64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $signature = hash_hmac('sha256', $encodedPayload, billing_portal_hmac_key(), true);
    return $encodedPayload . '.' . billing_portal_base64url_encode($signature);
}

function billing_portal_verify_access_token(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || !str_contains($token, '.')) {
        return null;
    }

    [$encodedPayload, $encodedSignature] = explode('.', $token, 2);
    $payloadJson = billing_portal_base64url_decode($encodedPayload);
    $signature = billing_portal_base64url_decode($encodedSignature);
    if (!is_string($payloadJson) || !is_string($signature)) {
        return null;
    }

    $expected = hash_hmac('sha256', $encodedPayload, billing_portal_hmac_key(), true);
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode($payloadJson, true);
    if (!is_array($payload)) {
        return null;
    }

    $email = billing_portal_normalize_email((string) ($payload['email'] ?? ''));
    $exp = (int) ($payload['exp'] ?? 0);
    $purpose = (string) ($payload['purpose'] ?? '');
    if ($email === '' || $exp < time() || $purpose !== 'billing_portal_access') {
        return null;
    }

    return [
        'email' => $email,
        'expires_at' => $exp,
    ];
}

function billing_portal_client_matches_for_email(string $email): array
{
    $email = billing_portal_normalize_email($email);
    if ($email === '') {
        return [];
    }

    $sql = "SELECT DISTINCT c.client_id, c.client_code, c.legal_name, c.dba_name, c.email AS client_email,
                   CASE WHEN LOWER(TRIM(COALESCE(c.email, ''))) = ? THEN 1 ELSE 0 END AS matched_client_email,
                   cc.first_name, cc.last_name, cc.email AS matched_contact_email
            FROM clients c
            LEFT JOIN client_contact cc
              ON cc.client_id = c.client_id
             AND LOWER(TRIM(COALESCE(cc.email, ''))) = ?
            WHERE LOWER(TRIM(COALESCE(c.email, ''))) = ?
               OR EXISTS (
                    SELECT 1
                    FROM client_contact cc2
                    WHERE cc2.client_id = c.client_id
                      AND LOWER(TRIM(COALESCE(cc2.email, ''))) = ?
               )
            ORDER BY COALESCE(NULLIF(c.dba_name, ''), c.legal_name) ASC, c.client_id ASC";

    $st = db()->prepare($sql);
    $st->execute([$email, $email, $email, $email]);
    return $st->fetchAll();
}

function billing_portal_has_email_match(string $email): bool
{
    return billing_portal_client_matches_for_email($email) !== [];
}

function billing_portal_client_ids_for_email(string $email): array
{
    $rows = billing_portal_client_matches_for_email($email);
    $ids = [];
    foreach ($rows as $row) {
        $clientId = (int) ($row['client_id'] ?? 0);
        if ($clientId > 0) {
            $ids[$clientId] = $clientId;
        }
    }
    return array_values($ids);
}

function billing_portal_list_invoices_for_email(string $email, int $limit = 100): array
{
    $clientIds = billing_portal_client_ids_for_email($email);
    if ($clientIds === []) {
        return [];
    }

    $limit = max(1, min(250, $limit));
    $placeholders = implode(',', array_fill(0, count($clientIds), '?'));
    $sql = "SELECT i.invoice_id, i.client_id, i.contract_id, i.invoice_number, i.invoice_date, i.due_date, i.status,
                   i.subtotal_amount, i.tax_amount, i.total_amount, i.balance_due, i.memo,
                   i.stripe_hosted_invoice_url, i.stripe_invoice_pdf_url, i.stripe_invoice_status,
                   c.client_code, c.legal_name, c.dba_name,
                   ctr.contract_number, ctr.contract_name
            FROM customer_invoice i
            INNER JOIN clients c ON c.client_id = i.client_id
            LEFT JOIN contract ctr ON ctr.contract_id = i.contract_id
            WHERE i.client_id IN ({$placeholders})
            ORDER BY i.invoice_date DESC, i.invoice_id DESC
            LIMIT {$limit}";

    $st = db()->prepare($sql);
    $st->execute($clientIds);
    return $st->fetchAll();
}

function billing_portal_invoice_for_email(string $email, int $invoiceId): ?array
{
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) {
        return null;
    }

    $clientIds = billing_portal_client_ids_for_email($email);
    if ($clientIds === [] || !in_array((int) ($invoice['client_id'] ?? 0), $clientIds, true)) {
        return null;
    }

    return $invoice;
}

function billing_portal_invoice_pay_url(array $invoice): string
{
    $hosted = trim((string) ($invoice['stripe_hosted_invoice_url'] ?? ''));
    if ($hosted !== '') {
        return $hosted;
    }
    if (accounting_invoice_can_receive_payment($invoice)) {
        return accounting_invoice_payment_link((string) ($invoice['invoice_number'] ?? ''));
    }
    return '';
}

function billing_portal_summary(array $invoices): array
{
    $summary = [
        'invoice_count' => count($invoices),
        'open_count' => 0,
        'overdue_count' => 0,
        'open_balance' => 0.0,
        'paid_total' => 0.0,
    ];

    $today = date('Y-m-d');
    foreach ($invoices as $invoice) {
        $status = strtoupper(trim((string) ($invoice['status'] ?? 'DRAFT')));
        $balanceDue = (float) ($invoice['balance_due'] ?? 0);
        $totalAmount = (float) ($invoice['total_amount'] ?? 0);
        $dueDate = trim((string) ($invoice['due_date'] ?? ''));

        if (in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true) && $balanceDue > 0.00001) {
            $summary['open_count']++;
            $summary['open_balance'] += $balanceDue;
            if ($dueDate !== '' && $dueDate < $today) {
                $summary['overdue_count']++;
            }
        }

        if ($status === 'PAID') {
            $summary['paid_total'] += $totalAmount;
        }
    }

    return $summary;
}

function billing_portal_mail_from_email(): string
{
    return function_exists('accounting_mail_from_email') ? accounting_mail_from_email() : 'billing@midwestmanagedit.com';
}

function billing_portal_mail_from_name(): string
{
    return function_exists('accounting_mail_from_name') ? accounting_mail_from_name() : 'Midwest Managed IT';
}

function billing_portal_send_access_link(string $email): array
{
    $email = billing_portal_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }

    if (!billing_portal_has_email_match($email)) {
        return ['ok' => true, 'sent' => false];
    }

    $token = billing_portal_create_access_token($email);
    $accessUrl = rtrim(BASE_URL, '/') . '/billing/index.php?access=' . rawurlencode($token);

    $subject = 'Your secure Midwest Managed IT billing link';
    $message = "Hello,\n\n"
        . "A secure sign-in link was requested for the Midwest Managed IT billing center.\n\n"
        . "Open this link to review invoices, payment status, and billing details:\n"
        . $accessUrl . "\n\n"
        . "This link expires in 20 minutes. If you did not request it, you can safely ignore this email.\n\n"
        . billing_portal_mail_from_name() . "\n";

    $send = ops_mail_send([
        'sender_channel' => 'billing',
        'to' => $email,
        'subject' => $subject,
        'text_body' => $message,
    ]);
    if (empty($send['ok'])) {
        error_log('billing_portal_send_access_link failed for ' . $email . ': ' . (string) ($send['error'] ?? 'unknown mail error'));
        return ['ok' => false, 'error' => 'Secure link email could not be sent right now.'];
    }

    audit_event(null, 'BILLING_LINK_SENT', ['email' => $email]);
    return ['ok' => true, 'sent' => true];
}

function billing_portal_session_set(string $email, int $ttlSeconds = 14400): void
{
    $_SESSION['billing_portal'] = [
        'email' => billing_portal_normalize_email($email),
        'expires_at' => time() + max(600, $ttlSeconds),
    ];
}

function billing_portal_session_clear(): void
{
    unset($_SESSION['billing_portal']);
}

function billing_portal_session_email(): ?string
{
    $data = $_SESSION['billing_portal'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $expiresAt = (int) ($data['expires_at'] ?? 0);
    $email = billing_portal_normalize_email((string) ($data['email'] ?? ''));
    if ($email === '' || $expiresAt < time()) {
        billing_portal_session_clear();
        return null;
    }
    return $email;
}

function billing_portal_public_site_url(): string
{
    $url = BASE_URL;
    if (str_contains($url, '://ops.')) {
        return preg_replace('~://ops\.~', '://', $url, 1) ?? $url;
    }
    return $url;
}
