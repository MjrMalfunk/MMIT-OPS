<?php
declare(strict_types=1);

// Smoke checks for real eSignatures webhook payload parsing. This intentionally
// avoids bootstrap, private config, database access, and network calls.
define('ESIGNATURES_ENABLED', true);
define('ESIGNATURES_API_TOKEN', 'smoke-token-must-not-log');
define('ESIGNATURES_TEMPLATE_ID', '20086199-7b34-44a3-b0bd-08010540cda2');
define('ESIGNATURES_TEST_MODE', false);
define('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');
define('ESIGNATURES_WEBHOOK_URL', 'https://ops-test.midwestmanagedit.com/webhooks/esignatures.php');
define('APP_ENV', 'production');

require_once __DIR__ . '/../inc/esignatures.php';

$realStyleWebhook = [
    'status' => 'contract-signed',
    'data' => [
        'contract' => [
            'id' => '1contr11-2222',
            'title' => 'Sample NDA',
            'metadata' => 'contract_id=10;client_id=7',
            'source' => 'api',
            'test' => 'yes',
            'contract_pdf_url' => 'https://example.test/contracts/1contr11-2222.pdf?secret_token=x123y',
            'signers' => [[
                'id' => '6signer6-9999',
                'name' => 'Sam Signer',
                'email' => 'sam@example.test',
            ]],
        ],
    ],
];

$compactWebhook = [
    'metadata' => 'contract_id=10;client_id=7',
    'status' => 'signed',
    'event' => 'contract_signed',
    'id' => 'manual-contract-10',
];

$arrayMetadataWebhook = [
    'status' => 'completed',
    'contract' => [
        'contract_id' => 'array-contract-10',
        'metadata' => ['contract_id' => 10, 'client_id' => 7],
    ],
];

$jsonStringMetadataWebhook = [
    'status' => 'completed',
    'metadata' => '{"contract_id":10,"client_id":7}',
    'document_id' => 'json-contract-10',
];

$pendingDocumentWebhook = [
    'status' => 'contract-signed',
    'data' => ['contract' => ['id' => '1contr11-2222', 'metadata' => 'contract_id=10;client_id=7']],
];

$checks = [
    'real-style webhook extracts nested metadata' => esignatures_extract_metadata($realStyleWebhook) === ['contract_id' => '10', 'client_id' => '7'],
    'real-style webhook extracts nested provider contract id' => esignatures_extract_contract_id($realStyleWebhook) === '1contr11-2222',
    'real-style webhook extracts contract_pdf_url as signed document reference' => esignatures_extract_signed_document_reference($realStyleWebhook) === 'https://example.test/contracts/1contr11-2222.pdf?secret_token=x123y',
    'real-style webhook is recognized as signed' => esignatures_is_signed_status(esignatures_extract_event_status($realStyleWebhook)),
    'real-style webhook extracts signer name' => esignatures_extract_signed_by($realStyleWebhook) === 'Sam Signer',
    'compact metadata string remains supported' => esignatures_extract_metadata($compactWebhook) === ['contract_id' => '10', 'client_id' => '7'],
    'array metadata remains supported' => esignatures_extract_metadata($arrayMetadataWebhook) === ['contract_id' => 10, 'client_id' => 7],
    'JSON-string metadata remains supported' => esignatures_extract_metadata($jsonStringMetadataWebhook) === ['contract_id' => 10, 'client_id' => 7],
    'signed webhook with no document has no signed document reference' => esignatures_extract_signed_document_reference($pendingDocumentWebhook) === '',
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'eSignatures webhook smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'eSignatures webhook payload smoke check passed.' . PHP_EOL;
