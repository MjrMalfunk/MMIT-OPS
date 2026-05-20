<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/config.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';

if (function_exists('date_default_timezone_set')) {
    @date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/Indiana/Indianapolis');
}

header('Content-Type: application/json');

$payload = payment_gateway_read_raw_body();
$signatureHeader = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
$deliveryId = (string)($_SERVER['HTTP_STRIPE_EVENT_ID'] ?? '');

if (!payment_gateway_stripe_verify_signature($payload, $signatureHeader)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid Stripe signature']);
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$eventId = trim((string)($event['id'] ?? ''));
$eventType = trim((string)($event['type'] ?? 'unknown'));
if ($eventId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing event id']);
    exit;
}

payment_gateway_webhook_log('STRIPE', $eventType, $eventId, $payload, 'RECEIVED', $deliveryId ?: null);

try {
    $result = payment_gateway_process_stripe_event($event);
    if (!empty($result['ok']) || !empty($result['ignored'])) {
        http_response_code(200);
        echo json_encode(['ok' => true, 'event' => $eventType, 'ignored' => !empty($result['ignored'])]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'event' => $eventType, 'errors' => $result['errors'] ?? ['Stripe webhook processing failed.']]);
    }
} catch (Throwable $e) {
    payment_gateway_webhook_mark_processed('STRIPE', $eventId, 'FAILED', $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
