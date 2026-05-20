<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function onedrive_is_configured(): bool
{
    return trim((string)(defined('ONEDRIVE_CLIENT_ID') ? ONEDRIVE_CLIENT_ID : '')) !== ''
        && trim((string)(defined('ONEDRIVE_CLIENT_SECRET') ? ONEDRIVE_CLIENT_SECRET : '')) !== ''
        && trim((string)(defined('ONEDRIVE_REDIRECT_URI') ? ONEDRIVE_REDIRECT_URI : '')) !== '';
}

function onedrive_storage_dir(): string
{
    return dirname(__DIR__) . '/storage/onedrive';
}

function onedrive_token_file(?int $userId = null): string
{
    $suffix = $userId && $userId > 0 ? ('receipts_connection_user_' . $userId . '.json') : 'receipts_connection.json';
    return onedrive_storage_dir() . '/' . $suffix;
}

function onedrive_session_token_key(): string
{
    $userId = (int)(current_user()['user_id'] ?? 0);
    return 'onedrive_token_data_' . max(0, $userId);
}

function onedrive_ensure_storage_dir(): void
{
    $dir = onedrive_storage_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }
    $index = dirname(__DIR__) . '/storage/index.html';
    if (!file_exists($index)) {
        @file_put_contents($index, '');
    }
    $htaccess = dirname(__DIR__) . '/storage/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Deny from all\n");
    }
}

function onedrive_crypto_key(): string
{
    $raw = defined('APP_ENC_KEY_B64') ? base64_decode((string)APP_ENC_KEY_B64, true) : false;
    if (is_string($raw) && strlen($raw) >= 32) {
        return substr($raw, 0, 32);
    }
    return hash('sha256', APP_NAME . '|' . BASE_URL . '|onedrive-receipts', true);
}

function onedrive_encrypt_array(array $payload): string
{
    $json = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $key = onedrive_crypto_key();
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($json, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher) || $cipher === '') {
        throw new RuntimeException('Unable to encrypt OneDrive token payload.');
    }
    return json_encode([
        'v' => 1,
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'ciphertext' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function onedrive_decrypt_array(string $blob): ?array
{
    $data = json_decode($blob, true);
    if (!is_array($data)) {
        return null;
    }
    $iv = base64_decode((string)($data['iv'] ?? ''), true);
    $tag = base64_decode((string)($data['tag'] ?? ''), true);
    $cipher = base64_decode((string)($data['ciphertext'] ?? ''), true);
    if (!is_string($iv) || !is_string($tag) || !is_string($cipher)) {
        return null;
    }
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', onedrive_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain) || $plain === '') {
        return null;
    }
    $decoded = json_decode($plain, true);
    return is_array($decoded) ? $decoded : null;
}

function onedrive_save_token_data(array $payload): array
{
    onedrive_ensure_storage_dir();
    $payload['saved_at'] = date('c');

    $sessionKey = onedrive_session_token_key();
    $blob = onedrive_encrypt_array($payload);
    $userId = (int)(current_user()['user_id'] ?? 0);
    $target = onedrive_token_file($userId > 0 ? $userId : null);
    $written = @file_put_contents($target, $blob, LOCK_EX);
    if ($written !== false) {
        $payload['_persisted'] = true;
        $_SESSION[$sessionKey] = $payload;
        return ['ok' => true, 'persisted' => true, 'path' => $target];
    }

    $legacy = onedrive_token_file();
    $legacyWritten = @file_put_contents($legacy, $blob, LOCK_EX);
    if ($legacyWritten !== false) {
        $payload['_persisted'] = true;
        $_SESSION[$sessionKey] = $payload;
        return ['ok' => true, 'persisted' => true, 'path' => $legacy];
    }

    $payload['_persisted'] = false;
    $_SESSION[$sessionKey] = $payload;
    return [
        'ok' => true,
        'persisted' => false,
        'path' => $target,
        'error' => 'Connected for this session, but the token file could not be written. Check write permissions on storage/onedrive.',
    ];
}

function onedrive_load_token_data(): ?array
{
    $sessionKey = onedrive_session_token_key();
    if (!empty($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) {
        return $_SESSION[$sessionKey];
    }

    $userId = (int)(current_user()['user_id'] ?? 0);
    $files = [];
    if ($userId > 0) {
        $files[] = onedrive_token_file($userId);
    }
    $files[] = onedrive_token_file();

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $blob = @file_get_contents($file);
        if (!is_string($blob) || trim($blob) === '') {
            continue;
        }
        $decoded = onedrive_decrypt_array($blob);
        if (is_array($decoded)) {
            $_SESSION[$sessionKey] = $decoded;
            return $decoded;
        }
    }
    return null;
}

function onedrive_clear_token_data(): void
{
    unset($_SESSION[onedrive_session_token_key()]);
    $userId = (int)(current_user()['user_id'] ?? 0);
    $files = [];
    if ($userId > 0) {
        $files[] = onedrive_token_file($userId);
    }
    $files[] = onedrive_token_file();
    foreach (array_unique($files) as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

function onedrive_connection_status(): array
{
    $connected = onedrive_load_token_data();
    $hasAccess = is_array($connected) && trim((string)($connected['access_token'] ?? '')) !== '';
    $hasRefresh = is_array($connected) && trim((string)($connected['refresh_token'] ?? '')) !== '';
    return [
        'configured' => onedrive_is_configured(),
        'connected' => $hasAccess || $hasRefresh,
        'account_label' => trim((string)($connected['account_label'] ?? '')),
        'updated_at' => trim((string)($connected['saved_at'] ?? '')),
        'has_refresh_token' => $hasRefresh,
        'session_only' => is_array($connected) && empty($connected['_persisted'] ?? false),
    ];
}

function onedrive_tenant_path(): string
{
    if (defined('ONEDRIVE_TENANT_ID')) {
        $tenant = trim((string)ONEDRIVE_TENANT_ID);
        if ($tenant !== '') {
            return $tenant;
        }
    }
    return 'common';
}

function onedrive_append_query(string $url, array $params): string
{
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . http_build_query($params);
}

function onedrive_authorize_url(string $returnTo = ''): string
{
    $state = bin2hex(random_bytes(16));
    $_SESSION['onedrive_oauth_state'] = $state;
    $_SESSION['onedrive_oauth_return_to'] = $returnTo;

    $params = [
        'client_id' => (string)ONEDRIVE_CLIENT_ID,
        'response_type' => 'code',
        'redirect_uri' => (string)ONEDRIVE_REDIRECT_URI,
        'response_mode' => 'query',
        'scope' => 'offline_access Files.ReadWrite User.Read',
        'state' => $state,
        'prompt' => 'select_account',
    ];
    return 'https://login.microsoftonline.com/' . onedrive_tenant_path() . '/oauth2/v2.0/authorize?' . http_build_query($params);
}

function onedrive_http_request(string $method, string $url, array $headers = [], ?string $body = null, bool $expectJson = true): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'error' => 'cURL is not available on this server.'];
    }

    $ch = curl_init($url);
    $responseHeaders = [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
            $len = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $len;
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'OneDrive request failed: ' . $error . ' (' . $errno . ')'];
    }

    $decoded = null;
    if ($expectJson) {
        $decoded = json_decode($raw, true);
    }

    $ok = $status >= 200 && $status < 300;
    return [
        'ok' => $ok,
        'status' => $status,
        'headers' => $responseHeaders,
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
        'error' => $ok ? null : (is_array($decoded) ? (string)($decoded['error_description'] ?? $decoded['error']['message'] ?? ('HTTP ' . $status)) : ('HTTP ' . $status)),
    ];
}

function onedrive_exchange_code(string $code): array
{
    $payload = http_build_query([
        'client_id' => (string)ONEDRIVE_CLIENT_ID,
        'client_secret' => (string)ONEDRIVE_CLIENT_SECRET,
        'redirect_uri' => (string)ONEDRIVE_REDIRECT_URI,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'scope' => 'offline_access Files.ReadWrite User.Read',
    ]);
    return onedrive_http_request('POST', 'https://login.microsoftonline.com/' . onedrive_tenant_path() . '/oauth2/v2.0/token', [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $payload, true);
}

function onedrive_refresh_access_token(array $tokenData): array
{
    $refreshToken = trim((string)($tokenData['refresh_token'] ?? ''));
    if ($refreshToken === '') {
        return ['ok' => false, 'error' => 'Missing OneDrive refresh token.'];
    }
    $payload = http_build_query([
        'client_id' => (string)ONEDRIVE_CLIENT_ID,
        'client_secret' => (string)ONEDRIVE_CLIENT_SECRET,
        'redirect_uri' => (string)ONEDRIVE_REDIRECT_URI,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'scope' => 'offline_access Files.ReadWrite User.Read',
    ]);
    return onedrive_http_request('POST', 'https://login.microsoftonline.com/' . onedrive_tenant_path() . '/oauth2/v2.0/token', [
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ], $payload, true);
}

function onedrive_build_saved_token_payload(array $tokenResponse, array $profile = []): array
{
    $expiresIn = max(300, (int)($tokenResponse['expires_in'] ?? 3600));
    $savedAt = date('c');
    $accountLabel = trim((string)($profile['userPrincipalName'] ?? $profile['mail'] ?? $profile['displayName'] ?? ''));
    if ($accountLabel === '') {
        $accountLabel = 'Connected OneDrive account';
    }
    return [
        'access_token' => (string)($tokenResponse['access_token'] ?? ''),
        'refresh_token' => (string)($tokenResponse['refresh_token'] ?? ''),
        'token_type' => (string)($tokenResponse['token_type'] ?? 'Bearer'),
        'expires_at' => time() + $expiresIn - 60,
        'saved_at' => $savedAt,
        'account_label' => $accountLabel,
    ];
}

function onedrive_get_profile(string $accessToken): array
{
    return onedrive_http_request('GET', 'https://graph.microsoft.com/v1.0/me?$select=displayName,mail,userPrincipalName', [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ], null, true);
}

function onedrive_get_valid_access_token(): array
{
    if (!onedrive_is_configured()) {
        return ['ok' => false, 'error' => 'OneDrive is not configured yet.'];
    }

    $tokenData = onedrive_load_token_data();
    if (!is_array($tokenData)) {
        return ['ok' => false, 'error' => 'OneDrive is not connected yet.'];
    }

    $accessToken = trim((string)($tokenData['access_token'] ?? ''));
    $expiresAt = (int)($tokenData['expires_at'] ?? 0);
    if ($accessToken !== '' && $expiresAt > (time() + 60)) {
        return ['ok' => true, 'access_token' => $accessToken, 'token_data' => $tokenData];
    }

    if (trim((string)($tokenData['refresh_token'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'OneDrive connected, but Microsoft did not return a refresh token. Reconnect it once and try again.'];
    }

    $refresh = onedrive_refresh_access_token($tokenData);
    if (empty($refresh['ok']) || !is_array($refresh['json'])) {
        onedrive_clear_token_data();
        return ['ok' => false, 'error' => 'OneDrive connection expired. Please reconnect it.'];
    }

    $response = $refresh['json'];
    if (trim((string)($response['refresh_token'] ?? '')) === '') {
        $response['refresh_token'] = (string)$tokenData['refresh_token'];
    }
    $saved = onedrive_build_saved_token_payload($response, ['displayName' => $tokenData['account_label'] ?? '']);
    $saveResult = onedrive_save_token_data($saved);
    if (!empty($saveResult['persisted'])) {
        $saved['_persisted'] = true;
    }

    return ['ok' => true, 'access_token' => (string)$saved['access_token'], 'token_data' => $saved];
}

function onedrive_api_json(string $method, string $url, string $accessToken, ?array $payload = null): array
{
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Accept: application/json',
    ];
    $body = null;
    if ($payload !== null) {
        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    return onedrive_http_request($method, $url, $headers, $body, true);
}

function onedrive_sanitize_segment(string $value): string
{
    $value = trim($value);
    $value = preg_replace('/[\\\\\/:*?"<>|]+/', '-', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    $value = trim($value, " .\t\n\r\0\x0B");
    if ($value === '') {
        $value = 'Uncategorized';
    }
    return mb_substr($value, 0, 80);
}

function onedrive_encode_path(string $path): string
{
    $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn($p) => $p !== ''));
    $encoded = array_map(static fn($p) => rawurlencode($p), $parts);
    return implode('/', $encoded);
}

function onedrive_folder_exists(string $accessToken, string $folderPath): bool
{
    $encoded = onedrive_encode_path($folderPath);
    $res = onedrive_api_json('GET', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encoded, $accessToken, null);
    return !empty($res['ok']) && is_array($res['json']) && isset($res['json']['folder']);
}

function onedrive_create_folder(string $accessToken, string $parentPath, string $folderName): bool
{
    $folderName = onedrive_sanitize_segment($folderName);
    $url = $parentPath === ''
        ? 'https://graph.microsoft.com/v1.0/me/drive/root/children'
        : 'https://graph.microsoft.com/v1.0/me/drive/root:/' . onedrive_encode_path($parentPath) . ':/children';
    $res = onedrive_api_json('POST', $url, $accessToken, [
        'name' => $folderName,
        'folder' => new stdClass(),
        '@microsoft.graph.conflictBehavior' => 'fail',
    ]);
    if (!empty($res['ok'])) {
        return true;
    }
    if ((int)($res['status'] ?? 0) === 409) {
        return true;
    }
    return false;
}

function onedrive_ensure_folder_path(string $accessToken, string $folderPath): bool
{
    $folderPath = trim($folderPath, '/');
    if ($folderPath === '') {
        return true;
    }
    $segments = array_values(array_filter(explode('/', $folderPath), static fn($p) => $p !== ''));
    $current = '';
    foreach ($segments as $segment) {
        $segment = onedrive_sanitize_segment($segment);
        $candidate = $current === '' ? $segment : ($current . '/' . $segment);
        if (!onedrive_folder_exists($accessToken, $candidate)) {
            if (!onedrive_create_folder($accessToken, $current, $segment)) {
                return false;
            }
        }
        $current = $candidate;
    }
    return true;
}

function onedrive_receipt_folder_path(array $expense): string
{
    $root = onedrive_sanitize_segment((string)(defined('ONEDRIVE_RECEIPTS_ROOT') ? ONEDRIVE_RECEIPTS_ROOT : 'MITT Receipts'));
    $expenseDate = trim((string)($expense['expense_date'] ?? ''));
    $year = preg_match('/^\d{4}/', $expenseDate, $m) ? $m[0] : date('Y');
    $vendor = onedrive_sanitize_segment((string)($expense['vendor_name'] ?? 'General'));
    return $root . '/Expenses/' . $year . '/' . $vendor;
}

function onedrive_receipt_remote_name(array $expense, string $originalName): string
{
    $originalName = trim($originalName);
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $base = pathinfo($originalName, PATHINFO_FILENAME);
    $base = onedrive_sanitize_segment($base);
    if ($base === '') {
        $expenseDate = trim((string)($expense['expense_date'] ?? '')) ?: date('Y-m-d');
        $base = 'receipt-' . str_replace('-', '', $expenseDate);
    }
    return $base . ($ext !== '' ? '.' . $ext : '');
}

function onedrive_item_exists(string $accessToken, string $itemPath): bool
{
    $encoded = onedrive_encode_path($itemPath);
    $res = onedrive_api_json('GET', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encoded, $accessToken, null);
    return !empty($res['ok']) && is_array($res['json']) && isset($res['json']['id']);
}

function onedrive_unique_remote_name(string $accessToken, string $folderPath, string $originalName): string
{
    $candidate = onedrive_receipt_remote_name([], $originalName);
    $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
    $base = pathinfo($candidate, PATHINFO_FILENAME);
    $candidatePath = trim($folderPath, '/') . '/' . $candidate;
    if (!onedrive_item_exists($accessToken, $candidatePath)) {
        return $candidate;
    }

    $stamp = date('His');
    $candidate = $base . '_' . $stamp . ($ext !== '' ? '.' . $ext : '');
    $candidatePath = trim($folderPath, '/') . '/' . $candidate;
    if (!onedrive_item_exists($accessToken, $candidatePath)) {
        return $candidate;
    }

    $stamp = date('His') . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    return $base . '_' . $stamp . ($ext !== '' ? '.' . $ext : '');
}

function onedrive_upload_receipt_file(string $accessToken, array $expense, array $file): array
{
    $tmp = (string)($file['tmp_name'] ?? '');
    $originalName = (string)($file['name'] ?? 'receipt');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'No valid upload was received.'];
    }
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The uploaded file is empty.'];
    }
    if ($size > (25 * 1024 * 1024)) {
        return ['ok' => false, 'error' => 'Receipt uploads are limited to 25 MB.'];
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    $mime = $finfo ? (string)finfo_file($finfo, $tmp) : 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/heic',
        'image/heif',
        'image/tiff',
    ];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Upload a PDF or receipt image (JPG, PNG, WEBP, GIF, HEIC, TIFF).'];
    }

    $folderPath = onedrive_receipt_folder_path($expense);
    if (!onedrive_ensure_folder_path($accessToken, $folderPath)) {
        return ['ok' => false, 'error' => 'Unable to prepare the OneDrive receipt folder.'];
    }

    $remoteName = onedrive_unique_remote_name($accessToken, $folderPath, $originalName);
    $encodedPath = onedrive_encode_path($folderPath . '/' . $remoteName);
    $bytes = @file_get_contents($tmp);
    if (!is_string($bytes)) {
        return ['ok' => false, 'error' => 'Unable to read the uploaded file.'];
    }

    $upload = onedrive_http_request('PUT', 'https://graph.microsoft.com/v1.0/me/drive/root:/' . $encodedPath . ':/content', [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: ' . $mime,
        'Accept: application/json',
    ], $bytes, true);

    if (empty($upload['ok']) || !is_array($upload['json'])) {
        return ['ok' => false, 'error' => (string)($upload['error'] ?? 'OneDrive upload failed.')];
    }

    $item = $upload['json'];
    return [
        'ok' => true,
        'provider' => 'ONEDRIVE',
        'provider_file_id' => (string)($item['id'] ?? ''),
        'file_name' => (string)($item['name'] ?? $remoteName),
        'file_url' => (string)($item['webUrl'] ?? ''),
        'mime_type' => $mime,
        'file_size' => (int)($item['size'] ?? $size),
        'checksum_sha256' => hash_file('sha256', $tmp) ?: null,
    ];
}
