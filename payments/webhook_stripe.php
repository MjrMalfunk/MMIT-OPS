<?php
declare(strict_types=1);

function stripe_webhook_json_response(int $statusCode, array $payload, array $headers = []): void {
    http_response_code($statusCode);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    stripe_webhook_json_response(405, ['ok' => false, 'error' => 'POST required.'], ['Allow' => 'POST']);
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';
if (!function_exists('payment_gateway_process_stripe_event')) {
    require_once __DIR__ . '/../inc/payment_gateway_stripe.php';
}

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Indiana/Indianapolis');
}

$payload = payment_gateway_read_raw_body();
$signatureHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$deliveryId = (string)($_SERVER['HTTP_STRIPE_EVENT_ID'] ?? '');

if (trim($signatureHeader) === '') {
    stripe_webhook_json_response(400, ['ok' => false, 'error' => 'Missing Stripe signature.']);
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    stripe_webhook_json_response(400, ['ok' => false, 'error' => 'Invalid JSON payload.']);
}

$webhookSecret = payment_gateway_stripe_webhook_secret_for_payload($payload);
if ($webhookSecret === '') {
    $mode = payment_gateway_stripe_mode();
    $message = 'Stripe webhook secret is not configured for mode: ' . $mode;
    error_log($message);
    stripe_webhook_json_response(503, [
        'ok' => false,
        'error' => 'Stripe webhook secret is not configured.',
        'mode' => $mode,
    ]);
}

if (!payment_gateway_stripe_verify_signature($payload, $signatureHeader)) {
    stripe_webhook_json_response(400, ['ok' => false, 'error' => 'Invalid Stripe signature.']);
}

$eventId = trim((string)($event['id'] ?? ''));
$eventType = trim((string)($event['type'] ?? 'unknown'));
if ($eventId === '') {
    stripe_webhook_json_response(400, ['ok' => false, 'error' => 'Missing event id.']);
}

payment_gateway_webhook_log('STRIPE', $eventType, $eventId, $payload, 'RECEIVED', $deliveryId ?: null);

try {
    $result = payment_gateway_process_stripe_event($event);
    if (!empty($result['ok']) || !empty($result['ignored'])) {
        stripe_webhook_json_response(200, ['ok' => true, 'event' => $eventType, 'ignored' => !empty($result['ignored'])]);
    }

    stripe_webhook_json_response(500, [
        'ok' => false,
        'event' => $eventType,
        'errors' => $result['errors'] ?? ['Stripe webhook processing failed.'],
    ]);
} catch (Throwable $e) {
    payment_gateway_webhook_mark_processed('STRIPE', $eventId, 'FAILED', $e->getMessage());
    stripe_webhook_json_response(500, ['ok' => false, 'error' => $e->getMessage()]);
}
