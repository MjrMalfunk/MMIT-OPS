<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor_integrations.php';

function huntress_config_string(string $name, string $default = ''): string
{
    if (defined($name)) {
        $value = constant($name);
        if (is_scalar($value)) {
            return trim((string)$value);
        }
    }

    $env = getenv($name);
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    return $default;
}

function huntress_api_base_url(): string
{
    $baseUrl = huntress_config_string('HUNTRESS_API_BASE_URL', 'https://api.huntress.io/v1');
    $baseUrl = rtrim($baseUrl, '/');

    if ($baseUrl === '') {
        throw new RuntimeException('HUNTRESS_API_BASE_URL is not configured.');
    }

    return $baseUrl;
}

function huntress_missing_config(): array
{
    $required = [
        'HUNTRESS_API_KEY',
        'HUNTRESS_API_SECRET',
    ];

    $missing = [];
    foreach ($required as $name) {
        if (huntress_config_string($name) === '') {
            $missing[] = $name;
        }
    }

    return $missing;
}

function huntress_is_configured(): bool
{
    return huntress_missing_config() === [];
}

function huntress_mask_sensitive(string $message): string
{
    foreach (['HUNTRESS_API_KEY', 'HUNTRESS_API_SECRET'] as $name) {
        $value = huntress_config_string($name);
        if ($value !== '') {
            $message = str_replace($value, '[redacted]', $message);
        }
    }

    $key = huntress_config_string('HUNTRESS_API_KEY');
    $secret = huntress_config_string('HUNTRESS_API_SECRET');
    if ($key !== '' && $secret !== '') {
        $message = str_replace(base64_encode($key . ':' . $secret), '[redacted-basic-auth]', $message);
    }

    return $message;
}

function huntress_api_url(string $path, array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $url = huntress_api_base_url() . $path;

    if ($query !== []) {
        $clean = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[(string)$key] = $value;
        }

        if ($clean !== []) {
            $url .= '?' . http_build_query($clean);
        }
    }

    return $url;
}

function huntress_api_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Huntress API calls.');
    }

    $missing = huntress_missing_config();
    if ($missing !== []) {
        throw new RuntimeException('Huntress API is not configured. Missing: ' . implode(', ', $missing));
    }

    $method = strtoupper(trim($method));
    if ($method === '') {
        throw new InvalidArgumentException('Huntress API method is required.');
    }

    $key = huntress_config_string('HUNTRESS_API_KEY');
    $secret = huntress_config_string('HUNTRESS_API_SECRET');
    $url = huntress_api_url($path, $query);

    $headers = [
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($key . ':' . $secret),
    ];

    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('Unable to encode Huntress API payload.');
        }
        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false || $responseBody === '') {
        throw new RuntimeException(huntress_mask_sensitive('Huntress API request failed: ' . ($curlError ?: 'empty response')));
    }

    $decoded = json_decode((string)$responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Huntress API returned non-JSON response. HTTP ' . $httpStatus . '. Body: ' . mb_substr((string)$responseBody, 0, 300, 'UTF-8'));
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(huntress_mask_sensitive('Huntress API HTTP ' . $httpStatus . ': ' . mb_substr(json_encode($decoded, JSON_UNESCAPED_SLASHES) ?: '', 0, 500, 'UTF-8')));
    }

    return [
        'ok' => true,
        'http_status' => $httpStatus,
        'body' => $decoded,
    ];
}

function huntress_api_get(string $path, array $query = []): array
{
    return huntress_api_request('GET', $path, $query);
}

function huntress_response_items(array $response, array $preferredKeys = []): array
{
    $body = $response['body'] ?? $response;
    if (!is_array($body)) {
        return [];
    }

    if (array_is_list($body)) {
        return $body;
    }

    foreach (array_merge($preferredKeys, ['data', 'items', 'results', 'records', 'organizations', 'agents']) as $key) {
        if (isset($body[$key]) && is_array($body[$key])) {
            return $body[$key];
        }
    }

    return [];
}

function huntress_list_organizations(array $query = []): array
{
    return huntress_api_get('/organizations', $query);
}

function huntress_list_agents(array $query = []): array
{
    return huntress_api_get('/agents', $query);
}

function huntress_normalize_agent_status(array $agent): array
{
    $hostname = trim((string)($agent['hostname'] ?? $agent['host_name'] ?? $agent['computer_name'] ?? $agent['name'] ?? ''));
    $agentId = trim((string)($agent['id'] ?? $agent['agent_id'] ?? $agent['uuid'] ?? ''));
    $organizationId = trim((string)($agent['organization_id'] ?? $agent['organizationId'] ?? $agent['org_id'] ?? ''));
    $organizationName = trim((string)($agent['organization_name'] ?? $agent['organizationName'] ?? $agent['org_name'] ?? ''));

    $rawStatus = strtoupper(trim((string)($agent['status'] ?? $agent['state'] ?? $agent['health'] ?? 'REPORTED')));
    $lastSeen = (string)($agent['last_seen_at'] ?? $agent['last_seen'] ?? $agent['last_check_in_at'] ?? '');

    return [
        'vendor_org_id' => $organizationId,
        'vendor_org_name' => $organizationName,
        'vendor_device_id' => $agentId !== '' ? $agentId : ('huntress-' . vendor_telemetry_normalize_device_key($hostname !== '' ? $hostname : json_encode($agent))),
        'device_name' => $hostname !== '' ? $hostname : 'Huntress Agent ' . ($agentId !== '' ? $agentId : 'unknown'),
        'device_role' => 'workstation',
        'status' => $rawStatus !== '' ? $rawStatus : 'REPORTED',
        'status_label' => 'Reported by Huntress',
        'status_detail' => 'Huntress agent telemetry is available.',
        'last_seen_at' => $lastSeen !== '' ? $lastSeen : vendor_telemetry_now(),
        'last_success_at' => $lastSeen !== '' ? $lastSeen : vendor_telemetry_now(),
        'raw' => $agent,
    ];
}
