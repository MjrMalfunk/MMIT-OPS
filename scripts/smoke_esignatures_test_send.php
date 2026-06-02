<?php
declare(strict_types=1);

// Smoke checks for the eSignatures phase-1 staging/test integration. This
// intentionally avoids bootstrap, private config, database access, and network calls.
define('ESIGNATURES_ENABLED', true);
define('ESIGNATURES_API_TOKEN', 'smoke-token-must-not-log');
define('ESIGNATURES_TEMPLATE_ID', '20086199-7b34-44a3-b0bd-08010540cda2');
define('ESIGNATURES_TEST_MODE', false);
define('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');
define('ESIGNATURES_WEBHOOK_URL', 'https://ops-test.midwestmanagedit.com/webhooks/esignatures.php');

define('APP_ENV', 'production');

require_once __DIR__ . '/../inc/esignatures.php';

$contract = [
    'first_name' => 'Demo',
    'last_name' => 'Signer',
    'contract_id' => 9,
    'client_id' => 7,
    'contact_email' => 'demo.signer@example.test',
    'client_email' => '',
    'dba_name' => 'Demo Company',
    'legal_name' => 'Demo Company LLC',
    'sla_level' => 'Manage',
    'contract_name' => 'Demo MSP Agreement',
    'base_amount' => 199.99,
    'contract_number' => 'MSA-0009',
    'billing_cycle' => 'MONTHLY',
    'covered_devices' => 12,
    'covered_users' => 10,
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'auto_renew' => 1,
    'address1' => '123 Main St',
    'city' => 'Springfield',
    'state' => 'IL',
    'postal_code' => '62701',
];

$_SERVER['HTTP_HOST'] = 'ops.midwestmanagedit.com';
$liveDisabled = esignatures_is_enabled() === false;

$_SERVER['HTTP_HOST'] = 'ops-test.midwestmanagedit.com';
$payload = esignatures_build_payload($contract, 199.99);
$stagingPayloadHasTestYes = ($payload['test'] ?? null) === 'yes';
$customWebhookUrl = $payload['custom_webhook_url'] ?? null;
$customWebhookUrlIsConfigured = $customWebhookUrl === 'https://ops-test.midwestmanagedit.com/webhooks/esignatures.php';
$customWebhookUrlPointsToEndpoint = is_string($customWebhookUrl) && str_ends_with($customWebhookUrl, '/webhooks/esignatures.php');
$metadata = $payload['metadata'] ?? null;
$metadataIsString = is_string($metadata);
$metadataContainsIds = is_string($metadata) && str_contains($metadata, 'contract_id=9') && str_contains($metadata, 'client_id=7');
$metadataLengthUnderLimit = is_string($metadata) && strlen($metadata) < 200;
$placeholderFields = $payload['placeholder_fields'] ?? null;
$placeholderFieldsIsList = is_array($placeholderFields) && array_keys($placeholderFields) === range(0, count($placeholderFields) - 1);
$placeholderFieldsByKey = [];
if (is_array($placeholderFields)) {
    foreach ($placeholderFields as $field) {
        if (is_array($field) && isset($field['placeholder_key'], $field['replace_with_text'])) {
            $placeholderFieldsByKey[(string)$field['placeholder_key']] = (string)$field['replace_with_text'];
        }
    }
}
$expectedPlaceholderEntries = [
    'contract_number' => 'MSA-0009',
    'contract_title' => 'Demo MSP Agreement',
    'client_name' => 'Demo Signer',
    'company_name' => 'Demo Company',
    'primary_contact' => 'Demo Signer',
    'contact_email' => 'demo.signer@example.test',
    'service_plan' => 'Manage',
    'productivity_platform' => 'No productivity platform selected',
    'license_level' => 'None selected',
    'billing_cycle' => 'Monthly',
    'recurring_total' => '199.99',
    'covered_workstations' => '12',
    'covered_users' => '10',
    'covered_servers' => '',
    'start_date' => '2026-01-01',
    'end_date' => '2026-12-31',
    'renewal_terms' => 'Auto-renews after initial term unless non-renewed in writing',
    'service_address' => "123 Main St
Springfield, IL 62701",
    'included_services' => '',
    'selected_addons' => '',
    'monthly_amount' => '199.99',
];
$placeholderFieldsHaveExpectedEntries = $placeholderFieldsByKey === $expectedPlaceholderEntries;
$placeholderFieldsPreserveLegacyEntries = array_intersect_key($placeholderFieldsByKey, [
    'client_name' => true,
    'company_name' => true,
    'service_plan' => true,
    'monthly_amount' => true,
]) === [
    'client_name' => 'Demo Signer',
    'company_name' => 'Demo Company',
    'service_plan' => 'Manage',
    'monthly_amount' => '199.99',
];

$sanitized = esignatures_sanitize_log_context([
    'api_token' => ESIGNATURES_API_TOKEN,
    'url' => esignatures_send_url(),
    'payload' => $payload,
]);
$encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES);
$tokenNotLogged = is_string($encoded) && !str_contains($encoded, ESIGNATURES_API_TOKEN) && str_contains($encoded, '[redacted]');

$checks = [
    'staging payload includes test yes' => $stagingPayloadHasTestYes,
    'send payload includes configured custom_webhook_url' => $customWebhookUrlIsConfigured,
    'custom_webhook_url points to /webhooks/esignatures.php' => $customWebhookUrlPointsToEndpoint,
    'metadata is a compact string' => $metadataIsString,
    'metadata contains contract_id and client_id' => $metadataContainsIds,
    'metadata length is under eSignatures limit' => $metadataLengthUnderLimit,
    'placeholder_fields is a list' => $placeholderFieldsIsList,
    'placeholder_fields entries use placeholder_key and replace_with_text' => $placeholderFieldsHaveExpectedEntries,
    'placeholder_fields preserve legacy four-field compatibility' => $placeholderFieldsPreserveLegacyEntries,
    'API token is not logged' => $tokenNotLogged,
    'live sends disabled without explicit live confirmation' => $liveDisabled,
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'eSignatures smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    fwrite(STDERR, json_encode(['payload' => $payload, 'sanitized' => $sanitized], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

echo 'eSignatures test-send smoke check passed.' . PHP_EOL;
