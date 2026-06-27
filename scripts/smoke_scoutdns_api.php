<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/scoutdns.php';

$callApi = in_array('--api', $argv, true);

function scoutdns_smoke_bool_label(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_scalar($value)) {
        $text = strtolower(trim((string)$value));
        if (in_array($text, ['1', 'true', 'yes', 'success'], true)) {
            return 'true';
        }

        if (in_array($text, ['0', 'false', 'no', 'failed', 'failure'], true)) {
            return 'false';
        }
    }

    return 'unknown';
}

function scoutdns_smoke_safe_text(mixed $value): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? '';
    return mb_substr($text, 0, 120, 'UTF-8');
}

echo "ScoutDNS getClients smoke\n";
echo "DB: " . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Configured: " . (scoutdns_is_configured() ? "yes" : "no") . "\n";

$missing = scoutdns_missing_config();
echo "Missing: " . ($missing ? implode(', ', $missing) : 'none') . "\n";
echo "Base URL: " . scoutdns_api_base_url() . "\n";
echo "Expected shape: top-level ok,http_status,body; client rows in body.data; safe row fields clientName,username,osName; sync output includes unmapped_reason and mapping_strategy.\n";

if (!$callApi) {
    echo "API call skipped. Pass --api to test live ScoutDNS credentials.\n";
    exit(0);
}

if ($missing) {
    echo "API call blocked because ScoutDNS config is incomplete.\n";
    exit(0);
}

try {
    $response = scoutdns_list_clients();
    $body = $response['body'] ?? [];
    $items = scoutdns_response_items($response, ['clients', 'clientList', 'data']);
    $success = is_array($body) ? ($body['success'] ?? $body['SUCCESS'] ?? $response['ok'] ?? null) : ($response['ok'] ?? null);
    $count = is_array($body) && isset($body['count']) && is_numeric($body['count']) ? (int)$body['count'] : count($items);

    echo "HTTP_STATUS=" . (int)($response['http_status'] ?? 0) . "\n";
    echo "SUCCESS=" . scoutdns_smoke_bool_label($success) . "\n";
    echo "COUNT=" . $count . "\n";

    echo "ROWS_PATH=body.data\n";

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $clientName = scoutdns_smoke_safe_text($item['clientName'] ?? $item['client_name'] ?? $item['name'] ?? '');
        $username = scoutdns_smoke_safe_text($item['username'] ?? $item['userName'] ?? $item['user'] ?? '');
        $osName = scoutdns_smoke_safe_text($item['osName'] ?? $item['os_name'] ?? $item['os'] ?? '');

        echo "- clientName=" . ($clientName !== '' ? $clientName : 'unknown')
            . " | username=" . ($username !== '' ? $username : 'unknown')
            . " | osName=" . ($osName !== '' ? $osName : 'unknown')
            . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ScoutDNS getClients smoke failed. Check credentials and server logs.\n");
    exit(1);
}
