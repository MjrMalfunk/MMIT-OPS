<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit(1);
}

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/fieldnation.php';

$callApi = in_array('--api', $argv, true);
$limit = 10;
$endpoint = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(1, min(100, (int)substr($arg, 8)));
    }
    if (str_starts_with($arg, '--endpoint=')) {
        $endpoint = '/' . ltrim(trim(substr($arg, 11)), '/');
    }
}

$candidates = $endpoint !== '' ? [$endpoint] : fieldnation_discovery_candidate_endpoints();

try {
    echo "Field Nation connector smoke\n";
    echo "DB: " . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
    echo "Configured: " . (fieldnation_is_configured() ? "yes" : "no") . "\n";
    $missing = fieldnation_missing_config();
    echo "Missing: " . ($missing ? implode(', ', $missing) : 'none') . "\n";
    echo "Base URL: " . fieldnation_api_base_url() . "\n";
    echo "Auth mode: " . fieldnation_config_string('FIELDNATION_AUTH_MODE', 'bearer') . "\n";
    echo "Candidate endpoints:\n";
    foreach ($candidates as $candidate) {
        echo "- " . $candidate . "\n";
    }

    if (!$callApi) {
        echo "API call skipped. Pass --api to test live read-only Field Nation credentials.\n";
        exit(0);
    }

    if ($missing) {
        echo "API call blocked because Field Nation config is incomplete.\n";
        exit(0);
    }

    foreach ($candidates as $candidate) {
        $result = fieldnation_probe_endpoint($candidate, $limit);
        $items = fieldnation_response_items($result, ['work_orders', 'workorders', 'data', 'results']);

        echo "Endpoint: " . $candidate . "\n";
        echo "HTTP: " . (int)($result['http_status'] ?? 0) . "\n";
        echo "Classification: " . (string)($result['classification'] ?? $result['status'] ?? 'FIELDNATION_PROVIDER_SCOPE_UNKNOWN') . "\n";
        echo "Count: " . count($items) . "\n";

        foreach (array_slice($items, 0, $limit) as $item) {
            if (!is_array($item)) {
                continue;
            }
            echo "- " . json_encode(fieldnation_sanitize_work_order_summary($item), JSON_UNESCAPED_SLASHES) . "\n";
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Field Nation smoke failed: " . fieldnation_mask_sensitive($e->getMessage()) . "\n");
    exit(1);
}
