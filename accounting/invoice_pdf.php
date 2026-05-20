<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';

require_login();
accounting_require_ready();

$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(404);
    exit('Invoice not found');
}

$invoice = accounting_get_invoice($invoiceId);
if (!$invoice) {
    http_response_code(404);
    exit('Invoice not found');
}

$lines = accounting_invoice_lines($invoiceId);
$includedGroups = !empty($invoice['contract_id']) ? accounting_contract_included_services_summary((int)$invoice['contract_id']) : [];
$invoiceNumber = (string)($invoice['invoice_number'] ?? ('INV-' . $invoiceId));
$filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoiceNumber) . '.pdf';

try {
    $pdf = accounting_render_invoice_pdf_bytes($invoice, $lines, $includedGroups);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdf;
    exit;
} catch (Throwable $e) {
    error_log('invoice_pdf failure for invoice ' . $invoiceId . ': ' . $e->getMessage());
}

http_response_code(500);
exit('Invoice PDF could not be generated.');
