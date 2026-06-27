<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor_integrations.php';

function fieldnation_config_string(string $name, string $default = ''): string
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

function fieldnation_config_int(string $name, int $default = 0): int
{
    $value = fieldnation_config_string($name, '');
    if ($value === '') {
        return $default;
    }

    return max(0, (int)$value);
}

function fieldnation_api_base_url(): string
{
    $baseUrl = fieldnation_config_string('FIELDNATION_API_BASE_URL', 'https://api.fieldnation.com/api/rest/v2');
    $baseUrl = rtrim($baseUrl, '/');

    if ($baseUrl === '') {
        throw new RuntimeException('FIELDNATION_API_BASE_URL is not configured.');
    }

    return $baseUrl;
}

function fieldnation_missing_config(): array
{
    $mode = strtolower(fieldnation_config_string('FIELDNATION_AUTH_MODE', 'bearer'));
    $required = ['FIELDNATION_AUTH_MODE'];

    if ($mode === 'bearer') {
        $required[] = 'FIELDNATION_ACCESS_TOKEN';
    } elseif ($mode === 'api_token') {
        $required[] = 'FIELDNATION_API_TOKEN';
    } else {
        $required[] = 'FIELDNATION_ACCESS_TOKEN';
    }

    $missing = [];
    foreach ($required as $name) {
        if (fieldnation_config_string($name) === '') {
            $missing[] = $name;
        }
    }

    return $missing;
}

function fieldnation_is_configured(): bool
{
    return fieldnation_missing_config() === [];
}

function fieldnation_mask_sensitive(string $message): string
{
    foreach (['FIELDNATION_API_TOKEN', 'FIELDNATION_ACCESS_TOKEN', 'FIELDNATION_REFRESH_TOKEN', 'FIELDNATION_CLIENT_SECRET'] as $name) {
        $value = fieldnation_config_string($name);
        if ($value !== '') {
            $message = str_replace($value, '[redacted]', $message);
        }
    }

    $message = preg_replace('/Authorization:\s*Bearer\s+[^\s,;]+/i', 'Authorization: Bearer [redacted]', $message) ?? $message;
    $message = preg_replace('/Authorization:\s*Basic\s+[^\s,;]+/i', 'Authorization: Basic [redacted]', $message) ?? $message;
    $message = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=:-]+/i', 'Bearer [redacted]', $message) ?? $message;
    $message = preg_replace('/Basic\s+[A-Za-z0-9._~+\/=:-]+/i', 'Basic [redacted]', $message) ?? $message;

    return $message;
}

function fieldnation_api_url(string $path, array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $url = fieldnation_api_base_url() . $path;

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

function fieldnation_api_request(string $method, string $path, array $query = [], ?array $body = null): array
{
    $method = strtoupper(trim($method));
    if ($method !== 'GET') {
        return [
            'ok' => false,
            'http_status' => 0,
            'status' => 'FIELDNATION_PROVIDER_SCOPE_UNKNOWN',
            'error' => 'Field Nation connector is read-only; only GET requests are allowed.',
        ];
    }

    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Field Nation API calls.');
    }

    $missing = fieldnation_missing_config();
    if ($missing !== []) {
        return [
            'ok' => false,
            'http_status' => 0,
            'status' => 'FIELDNATION_NOT_CONFIGURED',
            'error' => 'Field Nation API is not configured. Missing: ' . implode(', ', $missing),
        ];
    }

    $headers = ['Accept: application/json'];
    $mode = strtolower(fieldnation_config_string('FIELDNATION_AUTH_MODE', 'bearer'));
    if ($mode === 'api_token') {
        $headers[] = 'Authorization: Bearer ' . fieldnation_config_string('FIELDNATION_API_TOKEN');
    } else {
        $headers[] = 'Authorization: Bearer ' . fieldnation_config_string('FIELDNATION_ACCESS_TOKEN');
    }

    $ch = curl_init(fieldnation_api_url($path, $query));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException(fieldnation_mask_sensitive('Field Nation API request failed: ' . ($curlError ?: 'empty response') . '. HTTP ' . $httpStatus));
    }

    $decoded = null;
    if ((string)$responseBody !== '') {
        $decoded = json_decode((string)$responseBody, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [
                'ok' => false,
                'http_status' => $httpStatus,
                'status' => fieldnation_classify_discovery_result($httpStatus, []),
                'error' => 'Field Nation API returned a non-JSON response.',
            ];
        }
    }

    return [
        'ok' => $httpStatus >= 200 && $httpStatus < 300,
        'http_status' => $httpStatus,
        'status' => fieldnation_classify_discovery_result($httpStatus, is_array($decoded) ? $decoded : []),
        'body' => is_array($decoded) ? $decoded : [],
    ];
}

function fieldnation_api_get(string $path, array $query = []): array
{
    return fieldnation_api_request('GET', $path, $query);
}

function fieldnation_response_items(array $response, array $preferredKeys = []): array
{
    $body = $response['body'] ?? $response;
    if (!is_array($body)) {
        return [];
    }

    if (array_is_list($body)) {
        return $body;
    }

    foreach (array_merge($preferredKeys, ['data', 'items', 'results', 'records', 'work_orders', 'workorders', 'workorders_list']) as $key) {
        if (isset($body[$key]) && is_array($body[$key])) {
            return $body[$key];
        }
    }

    return [];
}

function fieldnation_discovery_candidate_endpoints(): array
{
    return [
        '/workorders',
        '/work_orders',
        '/provider/workorders',
        '/provider/work_orders',
        '/workorders/available',
        '/work_orders/available',
    ];
}

function fieldnation_probe_endpoint(string $endpoint, int $limit = 10): array
{
    $limit = max(1, min(100, $limit));
    $response = fieldnation_api_get($endpoint, ['limit' => $limit, 'per_page' => $limit, 'page' => 1]);
    $items = fieldnation_response_items($response, ['work_orders', 'workorders', 'data', 'results']);
    $response['count'] = count($items);
    $response['classification'] = fieldnation_classify_discovery_result((int)($response['http_status'] ?? 0), $response['body'] ?? [], $items);
    return $response;
}

function fieldnation_discover_work_orders(int $limit = 10): array
{
    $results = [];
    foreach (fieldnation_discovery_candidate_endpoints() as $endpoint) {
        $results[$endpoint] = fieldnation_probe_endpoint($endpoint, $limit);
    }

    return $results;
}

function fieldnation_classify_discovery_result(int $httpStatus, array $body = [], ?array $items = null): string
{
    if (!fieldnation_is_configured()) {
        return 'FIELDNATION_NOT_CONFIGURED';
    }

    if ($httpStatus === 401) {
        return 'FIELDNATION_AUTH_FAILED';
    }

    if ($httpStatus === 403) {
        return 'FIELDNATION_FORBIDDEN';
    }

    if ($httpStatus >= 200 && $httpStatus < 300) {
        $items = $items ?? fieldnation_response_items(['body' => $body], ['work_orders', 'workorders', 'data', 'results']);
        if (count($items) > 0) {
            return 'FIELDNATION_WORKORDERS_VISIBLE';
        }

        return 'FIELDNATION_WORKORDERS_EMPTY';
    }

    if ($httpStatus > 0) {
        return 'FIELDNATION_PROVIDER_SCOPE_UNKNOWN';
    }

    return 'FIELDNATION_AUTH_OK';
}

function fieldnation_sanitize_work_order_summary(array $workOrder): array
{
    $id = $workOrder['id'] ?? $workOrder['work_order_id'] ?? $workOrder['workorder_id'] ?? null;
    $status = $workOrder['status'] ?? $workOrder['state'] ?? null;
    $type = $workOrder['type'] ?? $workOrder['category'] ?? $workOrder['service_type'] ?? null;
    $created = $workOrder['created_at'] ?? $workOrder['created'] ?? null;
    $updated = $workOrder['updated_at'] ?? $workOrder['updated'] ?? null;

    return [
        'id' => is_scalar($id) ? (string)$id : '',
        'status' => is_scalar($status) ? (string)$status : '',
        'type' => is_scalar($type) ? (string)$type : '',
        'created_at' => is_scalar($created) ? (string)$created : '',
        'updated_at' => is_scalar($updated) ? (string)$updated : '',
    ];
}
