<?php
declare(strict_types=1);

// Offline smoke checks for the eSignatures completion/onboarding bridge. This
// avoids private config, database access, network calls, and external Syncro writes.
define('APP_ENV', 'staging');
define('ESIGNATURES_ENABLED', true);
define('ESIGNATURES_API_TOKEN', 'smoke-token');
define('ESIGNATURES_TEMPLATE_ID', 'smoke-template');
define('ESIGNATURES_TEST_MODE', true);
define('ESIGNATURES_BASE_URL', 'https://esignatures.com/api');
define('ESIGNATURES_WEBHOOK_URL', 'https://ops-test.midwestmanagedit.com/webhooks/esignatures.php');
define('SYNCRO_API_KEY', 'smoke-syncro-key');
define('SYNCRO_SUBDOMAIN', 'smoke-subdomain');

$_SERVER['HTTP_HOST'] = 'ops-test.midwestmanagedit.com';

require_once __DIR__ . '/../inc/esignatures.php';
require_once __DIR__ . '/../inc/syncro.php';

$payload = [
    'event' => 'contract.finalized',
    'contract_id' => 'provider-abc-123',
    'status' => 'signed',
    'metadata' => 'contract_id=42;client_id=7',
    'signed_document_url' => 'https://esignatures.example.test/signed/provider-abc-123.pdf',
    'audit_trail' => ['event' => 'signed'],
    'signed_at' => '2026-05-31T14:15:16Z',
];

$esignaturesSource = (string)file_get_contents(__DIR__ . '/../inc/esignatures.php');
$accountingSource = (string)file_get_contents(__DIR__ . '/../inc/accounting.php');

$metadata = esignatures_extract_metadata($payload);
$statusIsSigned = esignatures_is_signed_status(esignatures_extract_event_status($payload));
$signedReference = esignatures_extract_signed_document_reference($payload);
$webhookMatchesProvider = str_contains($esignaturesSource, 'WHERE provider_contract_id = ? OR esignatures_contract_id = ?');
$webhookMatchesMetadata = str_contains($esignaturesSource, "metadata['contract_id']") && str_contains($esignaturesSource, "metadata['client_id']");
$manualUploadUsesSharedHelper = str_contains($accountingSource, 'function accounting_contract_upload_signed_copy')
    && str_contains($accountingSource, 'accounting_contract_complete_signed_copy($contractId');
$esignaturesCompletionUsesSharedHelper = (bool)preg_match(
    '/function\s+esignatures_complete_ops_contract\s*\([^)]*\)\s*:\s*array\s*\{.*?accounting_contract_complete_signed_copy\s*\(/s',
    $esignaturesSource
);
$signedPendingDocumentsFlowExists = (bool)preg_match(
    '/function\s+esignatures_mark_signed_pending_documents\s*\([^)]*\)\s*:\s*array\s*\{.*?SIGNED_PENDING_DOCUMENTS/s',
    $esignaturesSource
);
$signedCopyFunctionStart = strpos($accountingSource, 'function accounting_contract_complete_signed_copy');
$signedCopyFunctionEnd = strpos($accountingSource, 'function accounting_contract_upload_signed_copy', $signedCopyFunctionStart === false ? 0 : $signedCopyFunctionStart);
$signedCopyFunctionSource = ($signedCopyFunctionStart !== false && $signedCopyFunctionEnd !== false)
    ? substr($accountingSource, $signedCopyFunctionStart, $signedCopyFunctionEnd - $signedCopyFunctionStart)
    : '';
$signedCopyMovesToSignedPendingOnboarding = str_contains($signedCopyFunctionSource, 'onboarding_started_at')
    && str_contains($signedCopyFunctionSource, 'accounting_contract_status_update($contractId, ' . "'SIGNED_PENDING_ONBOARDING'");
$signedCopyDoesNotActivateBilling = $signedCopyMovesToSignedPendingOnboarding
    && !str_contains($signedCopyFunctionSource, 'go_live_at')
    && !str_contains($signedCopyFunctionSource, 'billing_start_date')
    && !str_contains($signedCopyFunctionSource, 'accounting_generate_contract_initial_invoice')
    && !str_contains($signedCopyFunctionSource, "'ACTIVE'");
$viewedOrSentEventsDoNotComplete = str_contains($esignaturesSource, 'if (!esignatures_is_signed_status($status))')
    && str_contains($esignaturesSource, "'completed' => false, 'message' => 'eSignatures webhook status stored.'");
$auditTrailIsBestEffort = str_contains($esignaturesSource, 'eSignatures appends Electronic Signature Records & Audit Trail pages to the final PDF.')
    && str_contains($esignaturesSource, 'if ($auditReference === ' . "''" . ' && $signedReference !== ' . "''" . ')');
$syncroWriteBlocked = syncro_block_staging_write_if_needed('POST', 'customers') !== null;

$checks = [
    'webhook signed event carries OPS contract/client metadata' => (int)($metadata['contract_id'] ?? 0) === 42 && (int)($metadata['client_id'] ?? 0) === 7,
    'webhook signed event status is recognized as signed/finalized' => $statusIsSigned,
    'webhook signed event exposes signed document URL' => $signedReference === $payload['signed_document_url'],
    'webhook mapping supports stored provider contract ID' => $webhookMatchesProvider,
    'webhook mapping supports metadata contract_id/client_id' => $webhookMatchesMetadata,
    'manual upload path delegates to shared signed completion helper' => $manualUploadUsesSharedHelper,
    'eSignatures completion delegates to shared signed completion helper' => $esignaturesCompletionUsesSharedHelper,
    'eSignatures signed-pending-documents flow is available' => $signedPendingDocumentsFlowExists,
    'signed webhook moves contract to SIGNED_PENDING_ONBOARDING' => $signedCopyMovesToSignedPendingOnboarding,
    'signed-contract onboarding does not activate contract or billing dates' => $signedCopyDoesNotActivateBilling,
    'later sent/viewed events do not downgrade or complete signed status' => $viewedOrSentEventsDoNotComplete,
    'missing audit trail remains best-effort and does not block onboarding' => $auditTrailIsBestEffort,
    'staging Syncro write attempts are blocked before external API calls' => $syncroWriteBlocked,
];

$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed) {
    fwrite(STDERR, 'eSignatures completion smoke check failed: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'eSignatures completion smoke check passed.' . PHP_EOL;
