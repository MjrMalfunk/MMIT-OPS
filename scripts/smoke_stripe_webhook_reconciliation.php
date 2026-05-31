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

