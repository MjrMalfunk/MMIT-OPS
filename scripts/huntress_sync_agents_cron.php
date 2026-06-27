<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';
require_once __DIR__ . '/../inc/huntress.php';

function huntress_sync_usage(): string
{
    return "Usage: php scripts/huntress_sync_agents_cron.php [--apply] [--client-id=123] [--limit=500]\n";
}

function huntress_sync_arg_value(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }

    return null;
}

function huntress_sync_datetime(mixed $value): ?string
{
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return null;
    }

    try {
        return (new DateTimeImmutable($raw))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

function huntress_sync_device_role(array $agent): string
{
    $os = strtolower((string)($agent['os'] ?? ''));
    $platform = strtolower((string)($agent['platform'] ?? ''));

    if (str_contains($os, 'server')) {
        return 'server';
    }

    if ($platform === 'windows' || str_contains($os, 'windows')) {
        return 'workstation';
    }

    return 'unknown';
}

function huntress_sync_status_detail(array $agent): string
{
    $parts = [];

    foreach ([
        'Defender' => $agent['defender_status'] ?? null,
        'Defender detail' => $agent['defender_substatus'] ?? null,
        'Policy' => $agent['defender_policy_status'] ?? null,
        'Firewall' => $agent['firewall_status'] ?? null,
        'Agent' => $agent['version'] ?? null,
        'EDR' => $agent['edr_version'] ?? null,
    ] as $label => $value) {
        $value = trim((string)($value ?? ''));
        if ($value !== '') {
            $parts[] = "{$label}: {$value}";
        }
    }

    $detail = $parts ? implode('; ', $parts) : 'Huntress agent telemetry is available.';
    return mb_substr($detail, 0, 255, 'UTF-8');
}

function huntress_sync_normalize_agent(array $agent, array $orgNamesById): array
{
    $agentId = trim((string)($agent['id'] ?? $agent['agent_id'] ?? ''));
    $hostname = trim((string)($agent['hostname'] ?? $agent['name'] ?? $agent['computer_name'] ?? ''));
    $orgId = trim((string)($agent['organization_id'] ?? ''));
    $orgName = (string)($orgNamesById[$orgId] ?? '');

    $lastSeen = huntress_sync_datetime($agent['last_callback_at'] ?? null)
        ?? huntress_sync_datetime($agent['last_survey_at'] ?? null)
        ?? huntress_sync_datetime($agent['updated_at'] ?? null)
        ?? vendor_telemetry_now();

    return [
        'vendor_org_id' => $orgId,
        'vendor_org_name' => $orgName,
        'vendor_device_id' => $agentId !== '' ? $agentId : ('huntress-' . vendor_telemetry_normalize_device_key($hostname)),
        'device_name' => $hostname !== '' ? $hostname : 'Huntress Agent ' . ($agentId !== '' ? $agentId : 'unknown'),
        'device_role' => huntress_sync_device_role($agent),
        'status' => 'ACTIVE',
        'status_label' => 'Active',
        'status_detail' => huntress_sync_status_detail($agent),
        'last_seen_at' => $lastSeen,
        'last_success_at' => $lastSeen,
        'raw_summary' => [
            'source' => 'huntress_agents',
            'organization_id' => $orgId,
            'organization_name' => $orgName,
            'platform' => $agent['platform'] ?? null,
            'has_defender_status' => trim((string)($agent['defender_status'] ?? '')) !== '',
        ],
    ];
}

$apply = in_array('--apply', $argv, true);
$clientIdFilter = max(0, (int)(huntress_sync_arg_value($argv, '--client-id=') ?? 0));
$limit = max(1, min(500, (int)(huntress_sync_arg_value($argv, '--limit=') ?? 500)));

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo huntress_sync_usage();
    exit(0);
}

echo 'Huntress agent sync starting in ' . ($apply ? 'apply' : 'dry-run') . " mode.\n";
echo 'DB: ' . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";

$missing = huntress_missing_config();
if ($missing !== []) {
    echo 'Huntress API is not configured. Missing: ' . implode(', ', $missing) . "\n";
    exit(0);
}

$where = "vcl.vendor_code = 'huntress' AND vcl.link_status = 'ACTIVE'";
$params = [];

if ($clientIdFilter > 0) {
    $where .= ' AND vcl.client_id = ?';
    $params[] = $clientIdFilter;
}

$sql = "
    SELECT
        vcl.*,
        c.legal_name,
        c.dba_name,
        c.client_code
    FROM vendor_client_links vcl
    INNER JOIN clients c ON c.client_id = vcl.client_id
    WHERE {$where}
    ORDER BY vcl.client_id
";

$st = db()->prepare($sql);
$st->execute($params);
$links = $st->fetchAll(PDO::FETCH_ASSOC);

if ($links === []) {
    echo "No active Huntress client links found. Nothing to sync.\n";
    echo "Create rows in vendor_client_links first.\n";
    exit(0);
}

$runId = vendor_sync_run_start('huntress', $apply ? 'cron_apply' : 'cron_dry_run', [
    'client_id' => $clientIdFilter ?: null,
    'limit' => $limit,
]);

$result = [
    'status' => 'COMPLETE',
    'clients_seen' => 0,
    'devices_seen' => 0,
    'devices_updated' => 0,
    'error_count' => 0,
    'message' => 'Huntress agent sync completed.',
    'raw' => [
        'apply' => $apply,
        'client_id' => $clientIdFilter ?: null,
        'limit' => $limit,
    ],
];

try {
    echo "Fetching Huntress organizations...\n";
    $orgResponse = huntress_list_organizations(['limit' => 500]);
    $orgRows = huntress_response_items($orgResponse, ['organizations']);

    $orgNamesById = [];
    foreach ($orgRows as $org) {
        if (!is_array($org)) {
            continue;
        }

        $id = trim((string)($org['id'] ?? ''));
        if ($id !== '') {
            $orgNamesById[$id] = trim((string)($org['name'] ?? ''));
        }
    }

    echo 'Organizations returned: ' . count($orgRows) . "\n";

    echo "Fetching Huntress agents...\n";
    $agentResponse = huntress_list_agents(['limit' => $limit]);
    $agentRows = huntress_response_items($agentResponse, ['agents']);

    echo 'Agents returned: ' . count($agentRows) . "\n";

    foreach ($links as $link) {
        $clientId = (int)$link['client_id'];
        $vendorOrgId = trim((string)$link['vendor_org_id']);
        $clientName = (string)($link['legal_name'] ?: $link['dba_name'] ?: ('Client #' . $clientId));
        $orgName = (string)($orgNamesById[$vendorOrgId] ?? ($link['vendor_org_name'] ?: ''));

        $result['clients_seen']++;

        echo "\nClient #{$clientId}: {$clientName}\n";
        echo "Huntress organization_id: {$vendorOrgId}" . ($orgName !== '' ? " ({$orgName})" : '') . "\n";

        if ($vendorOrgId === '') {
            echo "Skipping: missing Huntress organization id.\n";
            $result['error_count']++;
            continue;
        }

        $matched = 0;

        foreach ($agentRows as $agent) {
            if (!is_array($agent)) {
                continue;
            }

            $agentOrgId = trim((string)($agent['organization_id'] ?? ''));
            if ($agentOrgId !== $vendorOrgId) {
                continue;
            }

            $matched++;
            $result['devices_seen']++;

            $payload = huntress_sync_normalize_agent($agent, $orgNamesById);

            echo json_encode([
                'client_id' => $clientId,
                'vendor_org_id' => $payload['vendor_org_id'],
                'vendor_device_id' => $payload['vendor_device_id'],
                'device_name' => $payload['device_name'],
                'role' => $payload['device_role'],
                'status' => $payload['status'],
                'last_seen_at' => $payload['last_seen_at'],
                'apply' => $apply,
            ], JSON_UNESCAPED_SLASHES) . "\n";

            if ($apply) {
                vendor_device_status_upsert($clientId, 'huntress', $payload);
                $result['devices_updated']++;
            }
        }

        echo "Matched agents: {$matched}\n";

        if ($apply) {
            vendor_client_link_upsert($clientId, 'huntress', $vendorOrgId, [
                'vendor_org_name' => $orgName !== '' ? $orgName : (string)($link['vendor_org_name'] ?: $clientName),
                'link_status' => 'ACTIVE',
                'matched_by' => (string)($link['matched_by'] ?: 'manual'),
                'notes' => (string)($link['notes'] ?: ''),
                'last_sync_at' => vendor_telemetry_now(),
                'last_error' => '',
            ]);
        }
    }

    if ($apply) {
        vendor_integration_update_status('huntress', 'SYNCED');
    }

    vendor_sync_run_finish($runId, $result);

    echo "\nHuntress agent sync complete.\n";
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $e) {
    $message = huntress_mask_sensitive($e->getMessage());

    $result['status'] = 'ERROR';
    $result['error_count']++;
    $result['message'] = $message;

    vendor_sync_run_finish($runId, $result);

    if ($apply) {
        vendor_integration_update_status('huntress', 'ERROR', $message);
    }

    fwrite(STDERR, 'Huntress agent sync failed: ' . $message . "\n");
    exit(1);
}
