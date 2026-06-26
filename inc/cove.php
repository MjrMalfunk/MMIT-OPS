<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor_integrations.php';

function cove_config_string(string $name, string $default = ''): string
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

function cove_config_int(string $name, int $default = 0): int
{
    $value = cove_config_string($name, '');
    if ($value === '') {
        return $default;
    }

    return max(0, (int)$value);
}

function cove_missing_config(): array
{
    $required = [
        'COVE_JSONRPC_URL',
        'COVE_PARTNER_NAME',
        'COVE_USERNAME',
        'COVE_PASSWORD',
    ];

    $missing = [];
    foreach ($required as $name) {
        if (cove_config_string($name) === '') {
            $missing[] = $name;
        }
    }

    return $missing;
}

function cove_is_configured(): bool
{
    return cove_missing_config() === [];
}

function cove_mask_sensitive(string $message): string
{
    foreach (['COVE_PASSWORD', 'COVE_USERNAME'] as $name) {
        $value = cove_config_string($name);
        if ($value !== '') {
            $message = str_replace($value, '[redacted]', $message);
        }
    }

    return $message;
}

function cove_jsonrpc_url(): string
{
    $url = cove_config_string('COVE_JSONRPC_URL');
    if ($url === '') {
        throw new RuntimeException('COVE_JSONRPC_URL is not configured.');
    }

    return $url;
}

function cove_response_result(array $response): mixed
{
    $result = $response['result'] ?? null;

    if (is_array($result) && array_key_exists('result', $result)) {
        return $result['result'];
    }

    return $result;
}

function cove_response_visa(array $response): ?string
{
    $visa = $response['visa'] ?? null;
    return is_string($visa) && trim($visa) !== '' ? trim($visa) : null;
}

function cove_jsonrpc_request(string $method, array $params = [], ?string $visa = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL extension is required for Cove API calls.');
    }

    $payload = [
        'jsonrpc' => '2.0',
        'id' => 'mmit-' . bin2hex(random_bytes(6)),
        'method' => $method,
        'params' => $params,
    ];

    if ($visa !== null && trim($visa) !== '') {
        $payload['visa'] = trim($visa);
    }

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode Cove JSON-RPC payload.');
    }

    $ch = curl_init(cove_jsonrpc_url());
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        throw new RuntimeException(cove_mask_sensitive('Cove API request failed: ' . ($curlError ?: 'empty response')));
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Cove API returned non-JSON response. HTTP ' . $httpStatus . '. Body: ' . mb_substr((string)$body, 0, 300, 'UTF-8'));
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('Cove API HTTP ' . $httpStatus . ': ' . mb_substr(json_encode($decoded, JSON_UNESCAPED_SLASHES) ?: '', 0, 300, 'UTF-8'));
    }

    if (array_key_exists('error', $decoded) && $decoded['error'] !== null) {
        throw new RuntimeException('Cove JSON-RPC error for ' . $method . ': ' . mb_substr(json_encode($decoded['error'], JSON_UNESCAPED_SLASHES) ?: '', 0, 500, 'UTF-8'));
    }

    return $decoded;
}

function cove_login(): array
{
    $response = cove_jsonrpc_request('Login', [
        'partner' => cove_config_string('COVE_PARTNER_NAME'),
        'username' => cove_config_string('COVE_USERNAME'),
        'password' => cove_config_string('COVE_PASSWORD'),
    ]);

    $visa = cove_response_visa($response);
    if ($visa === null) {
        throw new RuntimeException('Cove login succeeded structurally but no visa was returned.');
    }

    return [
        'visa' => $visa,
        'response' => $response,
        'user' => cove_response_result($response),
    ];
}

function cove_enumerate_account_statistics(string $visa, int $partnerId, int $start = 0, int $count = 25, ?array $columns = null): array
{
    if ($partnerId <= 0) {
        throw new InvalidArgumentException('Cove PartnerId is required for EnumerateAccountStatistics.');
    }

    $columns = $columns ?: [
        'I0',     // Device ID
        'I1',     // Device name
        'I8',     // Customer
        'I10',    // Product
        'I14',    // Used storage bytes
        'I16',    // OS version
        'I17',    // Client version
        'I18',    // Computer name
        'I32',    // OS type: 1 workstation, 2 server
        'I36',    // Storage status
        'I78',    // Active data sources
        'D09F06', // Error count
        'D09F09', // Last successful timestamp
        'D09F12', // Duration minutes
        'D09F15', // Last completed timestamp fallback
        'D09F16', // Last successful status
        'D09F17', // Last completed status
        'D09F18', // Last completed timestamp
    ];

    return cove_jsonrpc_request('EnumerateAccountStatistics', [
        'query' => [
            'PartnerId' => $partnerId,
            'SelectionMode' => 'Merged',
            'StartRecordNumber' => max(0, $start),
            'RecordsCount' => max(1, min(500, $count)),
            'Columns' => $columns,
        ],
    ], $visa);
}

function cove_account_statistics_rows(array $response): array
{
    $result = cove_response_result($response);
    return is_array($result) ? $result : [];
}

function cove_settings_map(array $row): array
{
    $settings = $row['Settings'] ?? [];
    $map = [];

    if (!is_array($settings)) {
        return $map;
    }

    foreach ($settings as $setting) {
        if (!is_array($setting)) {
            continue;
        }

        foreach ($setting as $key => $value) {
            $map[(string)$key] = $value;
        }
    }

    return $map;
}

function cove_device_role_from_os_type(mixed $value): ?string
{
    $osType = (int)$value;

    return match ($osType) {
        1 => 'workstation',
        2 => 'server',
        default => null,
    };
}

function cove_storage_bytes(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    return max(0, (int)$value);
}
