<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor_integrations.php';

function scoutdns_config_string(string $name, string $default = ''): string
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

function scoutdns_api_base_url(): string
{
    $baseUrl = scoutdns_config_string('SCOUTDNS_API_BASE_URL', 'https://api.scoutdns.com/v1');
    $baseUrl = rtrim($baseUrl, '/');

    if ($baseUrl === '') {
        throw new RuntimeException('SCOUTDNS_API_BASE_URL is not configured.');
    }

    return $baseUrl;
}

function scoutdns_missing_config(): array
{
    $missing = [];

    foreach (['SCOUTDNS_API_TOKEN'] as $name) {
        if (scoutdns_config_string($name) === '') {
            $missing[] = $name;
        }
    }

    return $missing;
}

function scoutdns_is_configured(): bool
{
    return scoutdns_missing_config() === [];
}

function scoutdns_mask_sensitive(string $message): string
{
    $token = scoutdns_config_string('SCOUTDNS_API_TOKEN');
    if ($token !== '') {
        $message = str_replace($token, '[redacted]', $message);
    }

    return $message;
}

function scoutdns_api_url(string $path, array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $url = scoutdns_api_base_url() . $path;

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

function scoutdns_api_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for ScoutDNS API calls.');
    }

    $missing = scoutdns_missing_config();
    if ($missing !== []) {
        throw new RuntimeException('ScoutDNS API is not configured. Missing: ' . implode(', ', $missing));
    }

    $method = strtoupper(trim($method));
    if ($method === '') {
        throw new InvalidArgumentException('ScoutDNS API method is required.');
    }

    $headers = [
        'Accept: application/json',
        'Authorization: Bearer ' . scoutdns_config_string('SCOUTDNS_API_TOKEN'),
    ];

    $payload = null;
    if ($body !== null) {
        $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            throw new RuntimeException('Unable to encode ScoutDNS API payload.');
        }

        $headers[] = 'Content-Type: application/json';
    }

    $ch = curl_init(scoutdns_api_url($path, $query));
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

    if ($responseBody === false) {
        throw new RuntimeException(scoutdns_mask_sensitive('ScoutDNS API request failed: ' . ($curlError ?: 'empty response') . '. HTTP ' . $httpStatus));
    }

    if ($responseBody === '') {
        throw new RuntimeException('ScoutDNS API HTTP ' . $httpStatus . ' returned an empty response body.');
    }

    $decoded = json_decode((string)$responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('ScoutDNS API returned non-JSON response. HTTP ' . $httpStatus . '. Body: ' . mb_substr((string)$responseBody, 0, 300, 'UTF-8'));
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException(scoutdns_mask_sensitive('ScoutDNS API HTTP ' . $httpStatus . ': ' . mb_substr(json_encode($decoded, JSON_UNESCAPED_SLASHES) ?: '', 0, 500, 'UTF-8')));
    }

    return [
        'ok' => true,
        'http_status' => $httpStatus,
        'body' => $decoded,
    ];
}

function scoutdns_api_get(string $path, array $query = []): array
{
    return scoutdns_api_request('GET', $path, $query);
}

function scoutdns_response_items(array $response, array $preferredKeys = []): array
{
    $body = $response['body'] ?? $response;
    if (!is_array($body)) {
        return [];
    }

    if (array_is_list($body)) {
        return $body;
    }

    foreach (array_merge($preferredKeys, ['data', 'items', 'results', 'records', 'sites', 'networks', 'roaming_clients']) as $key) {
        if (isset($body[$key]) && is_array($body[$key])) {
            return $body[$key];
        }
    }

    return [];
}

function scoutdns_list_sites(array $query = []): array
{
    return scoutdns_api_get('/sites', $query);
}
