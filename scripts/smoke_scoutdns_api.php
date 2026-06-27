<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/scoutdns.php';

$callApi = in_array('--api', $argv, true);

echo "ScoutDNS connector smoke\n";
echo "DB: " . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Configured: " . (scoutdns_is_configured() ? "yes" : "no") . "\n";

$missing = scoutdns_missing_config();
echo "Missing: " . ($missing ? implode(', ', $missing) : 'none') . "\n";
echo "Base URL: " . scoutdns_api_base_url() . "\n";

if (!$callApi) {
    echo "API call skipped. Pass --api to test live ScoutDNS credentials.\n";
    exit(0);
}

if ($missing) {
    echo "API call blocked because ScoutDNS config is incomplete.\n";
    exit(0);
}

try {
    echo "Calling ScoutDNS sites endpoint...\n";
    $response = scoutdns_list_sites();
    $items = scoutdns_response_items($response, ['sites']);

    echo "HTTP: " . (int)($response['http_status'] ?? 0) . "\n";
    echo "Sites returned: " . count($items) . "\n";

    foreach (array_slice($items, 0, 5) as $item) {
        if (!is_array($item)) {
            continue;
        }

        echo "- "
            . (string)($item['name'] ?? $item['site_name'] ?? $item['id'] ?? 'unknown')
            . " | id="
            . (string)($item['id'] ?? $item['site_id'] ?? 'unknown')
            . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ScoutDNS smoke failed: " . scoutdns_mask_sensitive($e->getMessage()) . "\n");
    exit(1);
}
