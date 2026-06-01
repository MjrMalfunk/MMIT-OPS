<?php
declare(strict_types=1);

// Lightweight, DB-free smoke checks for the Stripe webhook reconciliation helpers.
// Full end-to-end posting requires a staging database plus real Stripe test events.

if (!defined('APP_ENV')) define('APP_ENV', 'staging');
if (!defined('APP_TIMEZONE')) define('APP_TIMEZONE', 'UTC');
if (!defined('BASE_URL')) define('BASE_URL', 'https://ops-test.example.test');
if (!defined('STRIPE_SECRET_KEY')) define('STRIPE_SECRET_KEY', 'sk_test_local_smoke');
if (!defined('STRIPE_WEBHOOK_SECRET')) define('STRIPE_WEBHOOK_SECRET', 'whsec_fallback_smoke');
if (!defined('STRIPE_TEST_WEBHOOK_SECRET')) define('STRIPE_TEST_WEBHOOK_SECRET', 'whsec_test_smoke');
if (!defined('STRIPE_LIVE_WEBHOOK_SECRET')) define('STRIPE_LIVE_WEBHOOK_SECRET', 'whsec_live_smoke');
if (!defined('PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE')) define('PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE', '1000');
if (!defined('PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE')) define('PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE', '5070');
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'unused');
if (!defined('DB_USER')) define('DB_USER', 'unused');
if (!defined('DB_PASS')) define('DB_PASS', 'unused');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

require_once __DIR__ . '/../inc/payment_gateway.php';

function smoke_assert(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
}

$testPayload = json_encode(['id' => 'evt_test', 'livemode' => false, 'type' => 'payment_intent.succeeded'], JSON_UNESCAPED_SLASHES);
$livePayload = json_encode(['id' => 'evt_live', 'livemode' => true, 'type' => 'payment_intent.succeeded'], JSON_UNESCAPED_SLASHES);
$timestamp = (string)time();
$testSignature = hash_hmac('sha256', $timestamp . '.' . $testPayload, STRIPE_TEST_WEBHOOK_SECRET);
$liveSignature = hash_hmac('sha256', $timestamp . '.' . $livePayload, STRIPE_LIVE_WEBHOOK_SECRET);

smoke_assert(payment_gateway_stripe_verify_signature((string)$testPayload, 't=' . $timestamp . ',v1=' . $testSignature), 'test-mode webhook signature verifies with STRIPE_TEST_WEBHOOK_SECRET');
smoke_assert(payment_gateway_stripe_verify_signature((string)$livePayload, 't=' . $timestamp . ',v1=' . $liveSignature), 'live-mode webhook signature verifies with STRIPE_LIVE_WEBHOOK_SECRET');
smoke_assert(!payment_gateway_stripe_verify_signature((string)$testPayload, 't=' . $timestamp . ',v1=' . $liveSignature), 'test payload does not verify with live signature');

smoke_assert(payment_gateway_stripe_metadata_invoice_id(['metadata' => ['invoice_id' => '123']]) === 123, 'invoice_id metadata is extracted');
smoke_assert(payment_gateway_stripe_metadata_invoice_id(['metadata' => ['local_invoice_id' => '456']]) === 456, 'local_invoice_id metadata is extracted');
smoke_assert(payment_gateway_stripe_metadata_invoice_id(['metadata' => [], 'payment_intent' => ['metadata' => ['invoice_id' => '789']]]) === 789, 'nested PaymentIntent invoice metadata is extracted');
smoke_assert(payment_gateway_stripe_payment_method_label(['payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242'], 'type' => 'card']]) === 'VISA •••• 4242', 'card payment method label includes brand and last4');


smoke_assert(payment_gateway_stripe_checkout_payment_method_types(['balance_due' => 1500], 'CARD') === ['card'], 'card checkout keeps card-only Stripe method list');
smoke_assert(payment_gateway_stripe_checkout_payment_method_types(['balance_due' => 1500], 'ACH') === ['us_bank_account', 'card'], 'staging ACH checkout offers bank first with card fallback');
$checkoutSmokeInvoice = [
    'invoice_id' => 77,
    'client_id' => 88,
    'invoice_number' => 'INV-SMOKE',
    'balance_due' => 1500.00,
    'client_email' => 'ap@example.test',
    'dba_name' => '',
    'legal_name' => 'Smoke Client LLC',
];
$achCheckoutPayload = payment_gateway_stripe_checkout_session_payload($checkoutSmokeInvoice, 'ACH');
smoke_assert(($achCheckoutPayload['payment_method_types[0]'] ?? '') === 'us_bank_account' && ($achCheckoutPayload['payment_method_types[1]'] ?? '') === 'card', 'ACH checkout payload requests only bank and card');
smoke_assert(($achCheckoutPayload['wallet_options[link][display]'] ?? '') === 'never', 'ACH checkout payload disables Link wallet display');
smoke_assert(!array_key_exists('automatic_payment_methods[enabled]', $achCheckoutPayload), 'ACH checkout payload does not enable automatic payment methods');
$cardCheckoutPayload = payment_gateway_stripe_checkout_session_payload($checkoutSmokeInvoice, 'CARD');
smoke_assert(($cardCheckoutPayload['payment_method_types[0]'] ?? '') === 'card' && !array_key_exists('payment_method_types[1]', $cardCheckoutPayload), 'CARD checkout payload requests card only');
smoke_assert(($cardCheckoutPayload['wallet_options[link][display]'] ?? '') === 'never', 'CARD checkout payload disables Link wallet display');
smoke_assert(!array_key_exists('automatic_payment_methods[enabled]', $cardCheckoutPayload), 'CARD checkout payload does not enable automatic payment methods');
smoke_assert(accounting_invoice_payment_link('INV-95') === BASE_URL . '/payments/pay.php?invoice=INV-95', 'default payment link omits method so checkout resolves invoice default at pay time');
smoke_assert(accounting_invoice_payment_link('INV-1000') === BASE_URL . '/payments/pay.php?invoice=INV-1000', 'large invoice payment link does not force CARD in the URL');
smoke_assert(accounting_invoice_payment_link('INV-1000', 'CARD') === BASE_URL . '/payments/pay.php?invoice=INV-1000&method=CARD', 'explicit card payment link preserves intentional card override');
smoke_assert(accounting_invoice_payment_link('INV-1000', 'ACH') === BASE_URL . '/payments/pay.php?invoice=INV-1000&method=ACH', 'explicit ACH payment link preserves intentional ACH override');
smoke_assert(accounting_invoice_payment_link('INV-1000', '') === BASE_URL . '/payments/pay.php?invoice=INV-1000', 'blank payment link method omits method parameter');
smoke_assert(payment_gateway_resolve_requested_method(['balance_due' => 95, 'source_system' => 'PORTAL'], '') === 'ACH', 'small invoice blank method resolves to ACH-first');
smoke_assert(payment_gateway_resolve_requested_method(['balance_due' => 1000, 'source_system' => 'PORTAL'], '') === 'ACH', 'large invoice blank method resolves to ACH-first');
smoke_assert(payment_gateway_resolve_requested_method(['balance_due' => 95, 'source_system' => 'PORTAL'], 'invalid') === 'ACH', 'invalid payment method falls back to ACH invoice default');
smoke_assert(payment_gateway_resolve_requested_method(['balance_due' => 1000, 'source_system' => 'PORTAL'], 'card') === 'CARD', 'explicit lower-case card request still resolves to card override');
smoke_assert(payment_gateway_invoice_prefers_ach(['balance_due' => 2500, 'source_system' => 'PORTAL']) === true, 'large staging invoice prefers ACH');
smoke_assert(payment_gateway_invoice_prefers_ach(['balance_due' => 95, 'source_system' => 'PORTAL']) === true, 'small staging invoice prefers ACH');
smoke_assert(payment_gateway_invoice_prefers_ach(['balance_due' => 100, 'source_system' => 'RECURRING_BATCH']) === true, 'recurring managed service invoice prefers ACH');
smoke_assert(payment_gateway_stripe_pending_status_from_session(['status' => 'complete', 'payment_status' => 'paid']) === 'POSTED', 'card success state posts immediately');
smoke_assert(payment_gateway_stripe_pending_status_from_session(['status' => 'complete', 'payment_status' => 'unpaid', 'payment_intent' => ['status' => 'processing']]) === 'PENDING', 'ACH processing state remains pending');
smoke_assert(payment_gateway_stripe_charge_fee_amount(['balance_transaction' => ['fee' => 175, 'net' => 9825]]) === 1.75, 'Stripe balance transaction fee is extracted');
smoke_assert(payment_gateway_stripe_charge_net_amount(['balance_transaction' => ['fee' => 175, 'net' => 9825]]) === 98.25, 'Stripe balance transaction net deposit is extracted');
smoke_assert(payment_gateway_stripe_payment_method_from_charge(['payment_method_details' => ['type' => 'us_bank_account']]) === 'ACH', 'ACH/bank charge records ACH payment method');
smoke_assert(payment_gateway_stripe_payment_method_from_charge(['payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '4444']]]) === 'CARD', 'card charge records CARD payment method');
smoke_assert(payment_gateway_stripe_payment_method_from_details(payment_gateway_stripe_payment_method_details([], ['latest_charge' => ['payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]]])) === 'CARD', 'ACH-preferred checkout card fallback uses actual latest_charge card details');
smoke_assert(payment_gateway_stripe_payment_method_from_details(payment_gateway_stripe_payment_method_details([], ['latest_charge' => ['payment_method_details' => ['type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'STRIPE TEST BANK', 'account_type' => 'checking', 'last4' => '6789']]]])) === 'ACH', 'latest_charge bank details record ACH/BANK method');
smoke_assert(payment_gateway_stripe_payment_method_from_charge(['payment_method_details' => ['type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'wallet' => ['type' => 'link']]]]) === 'CARD', 'Link-backed card records CARD from underlying card details');
smoke_assert(payment_gateway_stripe_payment_method_from_charge(['payment_method_details' => ['type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'LINK BANK', 'last4' => '9999']]]) === 'ACH', 'Link-backed bank records ACH/BANK from underlying bank details');
smoke_assert(payment_gateway_stripe_payment_method_label(['payment_method_details' => ['type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'STRIPE TEST BANK', 'account_type' => 'checking', 'last4' => '6789']]]) === 'STRIPE TEST BANK checking •••• 6789', 'bank payment method label includes bank name, type, and last4');
smoke_assert(payment_gateway_stripe_charge_fee_amount(['payment_method_details' => ['type' => 'card'], 'balance_transaction' => ['fee' => 320, 'net' => 9680]]) === 3.20, 'card balance transaction fee is extracted');
smoke_assert(payment_gateway_stripe_charge_net_amount(['payment_method_details' => ['type' => 'card'], 'balance_transaction' => ['fee' => 320, 'net' => 9680]]) === 96.80, 'card balance transaction net deposit is extracted');
smoke_assert(payment_gateway_stripe_charge_fee_amount(['payment_method_details' => ['type' => 'us_bank_account'], 'balance_transaction' => ['fee' => 500, 'net' => 99500]]) === 5.00, 'ACH balance transaction fee is extracted');
smoke_assert(payment_gateway_stripe_charge_net_amount(['payment_method_details' => ['type' => 'us_bank_account'], 'balance_transaction' => ['fee' => 500, 'net' => 99500]]) === 995.00, 'ACH balance transaction net deposit is extracted');
smoke_assert(payment_gateway_stripe_charge_has_reconciliation_details(['payment_method_details' => ['type' => 'card'], 'balance_transaction' => ['fee' => 320, 'net' => 9680]]) === true, 'expanded charge with method details and balance transaction is reconciliation-ready');
smoke_assert(function_exists('payment_gateway_invoice_already_recorded'), 'duplicate webhook idempotency matcher is available for replayed processor ids');
