<?php
declare(strict_types=1);

// DB-free smoke checks for customer-facing invoice payment URLs.
// Verifies invoice emails point at the OPS payment page instead of Stripe hosted invoices.

if (!defined('APP_ENV')) define('APP_ENV', 'staging');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'UTC');
if (!defined('BASE_URL')) define('BASE_URL', 'https://ops-test.midwestmanagedit.com');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', 'sk_test_local_smoke');
if (!defined('STRIPE_ACH_PREFERRED_MIN_INVOICE_AMOUNT')) define('STRIPE_ACH_PREFERRED_MIN_INVOICE_AMOUNT', 1000);
if (!defined('MAIL_SANDBOX_ENABLED')) define('MAIL_SANDBOX_ENABLED', false);
if (!defined('MAIL_SANDBOX_TO')) define('MAIL_SANDBOX_TO', '');
if (!defined('MAIL_FROM_EMAIL')) define('MAIL_FROM_EMAIL', 'billing@midwestmanagedit.com');
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'unused');
if (!defined('DB_USER')) define('DB_USER', 'unused');
if (!defined('DB_PASS')) define('DB_PASS', 'unused');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

require_once __DIR__ . '/../inc/payment_gateway.php';
require_once __DIR__ . '/../inc/billing_portal.php';

function smoke_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

function smoke_invoice(array $overrides = []): array {
    return array_merge([
        'invoice_id' => 321,
        'client_id' => 654,
        'invoice_number' => 'INV 1000/ACH',
        'invoice_date' => '2026-05-31',
        'due_date' => '2026-06-15',
        'balance_due' => 1000.00,
        'status' => 'ISSUED',
        'source_system' => 'PORTAL',
        'legal_name' => 'Smoke Test Client LLC',
        'dba_name' => '',
        'client_email' => 'ap@example.test',
        'stripe_hosted_invoice_url' => 'https://invoice.stripe.com/acct_test/invst_smoke',
    ], $overrides);
}

$largeInvoice = smoke_invoice();
$smallInvoice = smoke_invoice([
    'invoice_number' => 'INV-95',
    'balance_due' => 95.00,
]);
$recurringInvoice = smoke_invoice([
    'invoice_number' => 'INV-RECUR',
    'balance_due' => 100.00,
    'source_system' => 'RECURRING_BATCH',
]);

$largeOpsUrl = accounting_invoice_customer_payment_url($largeInvoice);
$smallOpsUrl = accounting_invoice_customer_payment_url($smallInvoice);

smoke_assert($largeOpsUrl === BASE_URL . '/payments/pay.php?invoice=INV%201000%2FACH', 'normal invoice customer payment URL uses OPS payment page with rawurlencoded invoice number');
smoke_assert(strpos($largeOpsUrl, 'method=') === false, 'large invoice customer payment URL is methodless');
smoke_assert(strpos($smallOpsUrl, 'method=') === false, 'small invoice customer payment URL is methodless');
smoke_assert(strpos($largeOpsUrl, 'invoice.stripe.com') === false, 'Stripe hosted invoice URL is not the customer payment URL');
smoke_assert(billing_portal_invoice_pay_url($largeInvoice) === $largeOpsUrl, 'customer billing portal payment buttons use OPS payment page even when hosted_invoice_url exists');

$html = accounting_render_invoice_email_html($largeInvoice, 'Your invoice is ready.', $largeOpsUrl, false, 'ap@example.test');
smoke_assert(strpos($html, 'https://ops-test.midwestmanagedit.com/payments/pay.php?invoice=INV%201000%2FACH') !== false, 'invoice email HTML button/link uses OPS payment page');
smoke_assert(strpos($html, 'method=CARD') === false, 'invoice email HTML does not force method=CARD');
smoke_assert(strpos($html, 'invoice.stripe.com/acct_test') === false, 'invoice email HTML does not use Stripe hosted_invoice_url as primary button/link');

$plainBody = "Invoice ready\n\nSecure payment link:\n" . $largeOpsUrl . "\n";
smoke_assert(strpos($plainBody, BASE_URL . '/payments/pay.php?invoice=') !== false, 'invoice email text body uses OPS payment page');
smoke_assert(strpos($plainBody, 'method=CARD') === false, 'invoice email text body is methodless by default');
smoke_assert(strpos($plainBody, 'invoice.stripe.com') === false, 'invoice email text body does not include Stripe hosted invoice URL as primary link');

smoke_assert(payment_gateway_resolve_requested_method($largeInvoice, '') === 'ACH', '$1000+ methodless invoice resolves to ACH-first');
smoke_assert(payment_gateway_stripe_checkout_payment_method_types($largeInvoice, payment_gateway_resolve_requested_method($largeInvoice, '')) === ['us_bank_account', 'card'], '$1000+ methodless invoice checkout offers bank plus card');
smoke_assert(payment_gateway_resolve_requested_method($smallInvoice, '') === 'ACH', 'small methodless invoice resolves to ACH-first');
smoke_assert(payment_gateway_stripe_checkout_payment_method_types($smallInvoice, payment_gateway_resolve_requested_method($smallInvoice, '')) === ['us_bank_account', 'card'], 'small methodless invoice checkout offers bank plus card');
smoke_assert(payment_gateway_resolve_requested_method($recurringInvoice, '') === 'ACH', 'recurring methodless invoice resolves to ACH-first');

smoke_assert(accounting_invoice_payment_link((string)$largeInvoice['invoice_number'], 'ACH') === $largeOpsUrl . '&method=ACH', 'explicit admin ACH override link still works');
smoke_assert(accounting_invoice_payment_link((string)$largeInvoice['invoice_number'], 'CARD') === $largeOpsUrl . '&method=CARD', 'explicit admin CARD override link still works');
smoke_assert(payment_gateway_stripe_checkout_payment_method_types($smallInvoice, payment_gateway_resolve_requested_method($smallInvoice, 'CARD')) === ['card'], 'explicit small invoice CARD override creates card-only checkout');

$achPayload = payment_gateway_stripe_checkout_session_payload($largeInvoice, payment_gateway_resolve_requested_method($largeInvoice, ''));
smoke_assert(($achPayload['payment_method_types[0]'] ?? '') === 'us_bank_account', 'methodless ACH-default checkout payload requests US bank account first');
smoke_assert(($achPayload['payment_method_types[1]'] ?? '') === 'card', 'methodless ACH-default checkout payload keeps card fallback second');
smoke_assert(!array_key_exists('payment_method_types[2]', $achPayload), 'methodless ACH-default checkout payload does not request extra payment methods');
smoke_assert(($achPayload['wallet_options[link][display]'] ?? '') === 'never', 'methodless ACH-default checkout payload hides Stripe Link');
smoke_assert(!array_key_exists('automatic_payment_methods[enabled]', $achPayload), 'methodless ACH-default checkout payload avoids Stripe automatic payment methods');

$explicitAchPayload = payment_gateway_stripe_checkout_session_payload($largeInvoice, payment_gateway_resolve_requested_method($largeInvoice, 'ACH'));
smoke_assert(($explicitAchPayload['payment_method_types[0]'] ?? '') === 'us_bank_account' && ($explicitAchPayload['payment_method_types[1]'] ?? '') === 'card', 'explicit ACH checkout payload requests only bank and card');

$cardPayload = payment_gateway_stripe_checkout_session_payload($smallInvoice, payment_gateway_resolve_requested_method($smallInvoice, 'CARD'));
smoke_assert(($cardPayload['payment_method_types[0]'] ?? '') === 'card', 'explicit CARD checkout payload requests card only');
smoke_assert(!array_key_exists('payment_method_types[1]', $cardPayload), 'explicit CARD checkout payload does not request bank or alternate methods');
smoke_assert(($cardPayload['wallet_options[link][display]'] ?? '') === 'never', 'explicit CARD checkout payload hides Stripe Link');
smoke_assert(!array_key_exists('automatic_payment_methods[enabled]', $cardPayload), 'explicit CARD checkout payload avoids Stripe automatic payment methods');

smoke_assert(payment_gateway_resolve_requested_method($smallInvoice, 'invalid') === 'ACH', 'invalid small invoice method falls back to ACH-first');
