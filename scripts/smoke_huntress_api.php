<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/huntress.php';

$callApi = in_array('--api', $argv, true);

echo "Huntress connector smoke\n";
echo "DB: " . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Configured: " . (huntress_is_configured() ? "yes" : "no") . "\n";

$missing = huntress_missing_config();
echo "Missing: " . ($missing ? implode(', ', $missing) : 'none') . "\n";
echo "Base URL: " . huntress_api_base_url() . "\n";

$sample = [
    'id' => 'agent-123',
    'hostname' => 'MMIT-LAB-WS01',
    'organization_id' => 'org-456',
    'organization_name' => 'LnK Consulting, LLC',
    'status' => 'active',
    'last_seen_at' => '2026-06-26 12:00:00',
];

echo "Normalizer sample:\n";
echo json_encode(huntress_normalize_agent_status($sample), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

if (!$callApi) {
    echo "API call skipped. Pass --api to test live Huntress credentials.\n";
    exit(0);
}

if ($missing) {
    echo "API call blocked because Huntress config is incomplete.\n";
    exit(0);
}

try {
    echo "Calling Huntress organizations endpoint...\n";
    $response = huntress_list_organizations(['limit' => 5]);
    $items = huntress_response_items($response, ['organizations']);

    echo "HTTP: " . (int)($response['http_status'] ?? 0) . "\n";
    echo "Organizations returned: " . count($items) . "\n";

    foreach (array_slice($items, 0, 5) as $item) {
        if (!is_array($item)) {
            continue;
        }

        echo "- "
            . (string)($item['name'] ?? $item['organization_name'] ?? $item['id'] ?? 'unknown')
            . " | id="
            . (string)($item['id'] ?? $item['organization_id'] ?? 'unknown')
            . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Huntress smoke failed: " . huntress_mask_sensitive($e->getMessage()) . "\n");
    exit(1);
}
