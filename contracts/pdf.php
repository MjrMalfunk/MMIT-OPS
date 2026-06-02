<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
$contractId = (int)($_GET['id'] ?? 0);
if ($contractId <= 0) { http_response_code(404); exit('Contract not found'); }
$contract = accounting_get_contract($contractId);
if (!$contract) { http_response_code(404); exit('Contract not found'); }
$services = accounting_get_contract_services($contractId);
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$contract['contract_number']) . '-unsigned-draft.pdf';
$download = strtolower(trim((string)($_GET['download'] ?? ''))) !== '';
try {
    $pdf = accounting_render_contract_pdf_bytes($contract, $services);
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit;
} catch (Throwable $e) {
    error_log('contract_pdf failure for contract ' . $contractId . ': ' . $e->getMessage());
}
http_response_code(500);
exit('Contract PDF could not be generated.');
