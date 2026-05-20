<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cgi-fcgi') {
    header('Content-Type: text/plain; charset=utf-8');
}

if (!accounting_is_enabled() || !accounting_is_ready()) {
    http_response_code(500);
    echo "Accounting module is not ready.
";
    exit(1);
}

$asOfDate = date('Y-m-d');
if (PHP_SAPI === 'cli') {
    global $argv;
    foreach (($argv ?? []) as $arg) {
        if (str_starts_with($arg, '--date=')) {
            $asOfDate = trim(substr($arg, 7)) ?: $asOfDate;
        }
    }
} elseif (isset($_GET['as_of'])) {
    $asOfDate = trim((string)$_GET['as_of']) ?: $asOfDate;
}

$result = accounting_generate_recurring_invoices($asOfDate, 0);

echo "Recurring billing processor date: {$result['as_of_date']}
";
echo 'Created: ' . count($result['created']) . "
";
echo 'Skipped: ' . count($result['skipped'] ?? []) . "
";
echo 'Errors: ' . count($result['errors'] ?? []) . "
";

if (!empty($result['created'])) {
    foreach ($result['created'] as $row) {
        echo '[CREATED] Recurring #' . (int)$row['recurring_service_id'] . ' -> Invoice #' . (int)$row['invoice_id'] . ' · ' . $row['description'] . "
";
    }
}
if (!empty($result['skipped'])) {
    foreach ($result['skipped'] as $row) {
        echo '[SKIPPED] Recurring #' . (int)$row['recurring_service_id'] . ' · ' . ($row['invoice_number'] ?? 'existing draft') . ' · ' . $row['reason'] . "
";
    }
}
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $error) {
        echo '[ERROR] ' . $error . "
";
    }
}

if (empty($result['created']) && empty($result['skipped']) && empty($result['errors'])) {
    echo "No invoices due for the selected date.
";
}
