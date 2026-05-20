<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clients.php';

function syncro_is_configured(): bool
{
    return defined('SYNCRO_API_KEY') && trim((string)SYNCRO_API_KEY) !== ''
        && defined('SYNCRO_SUBDOMAIN') && trim((string)SYNCRO_SUBDOMAIN) !== '';
}

function syncro_base_url(): string
{
    if (defined('SYNCRO_BASE_URL') && trim((string)SYNCRO_BASE_URL) !== '') {
        return rtrim((string)SYNCRO_BASE_URL, '/') . '/';
    }
    $subdomain = trim((string)(defined('SYNCRO_SUBDOMAIN') ? SYNCRO_SUBDOMAIN : ''));
    return $subdomain !== '' ? 'https://' . $subdomain . '.syncromsp.com/api/v1/' : '';
}

function syncro_debug_log(string $message, array $context = []): void
{
    $line = '[syncro] ' . $message;
    if ($context) {
        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $line .= ' ' . $json;
        }
    }
    error_log($line);
}

function syncro_flatten_errors(mixed $value, string $prefix = ''): array
{
    $errors = [];
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
        $message = trim((string)$value);
        if ($message !== '') {
            $errors[] = $prefix !== '' ? ($prefix . ': ' . $message) : $message;
        }
        return $errors;
    }
    if (!is_array($value)) {
        return $errors;
    }
    foreach ($value as $key => $item) {
        $label = is_string($key) && $key !== '' ? str_replace('_', ' ', $key) : $prefix;
        if (is_array($item)) {
            $errors = array_merge($errors, syncro_flatten_errors($item, $label));
        } else {
            $message = trim((string)$item);
            if ($message !== '') {
                $errors[] = $label !== '' ? ($label . ': ' . $message) : $message;
            }
        }
    }
    return $errors;
}

function syncro_api_request(string $method, string $path, array $query = [], ?array $payload = null): array
{
    if (!syncro_is_configured()) {
        return ['ok' => false, 'errors' => ['Syncro integration is not configured yet.']];
    }

    $url = syncro_base_url() . ltrim($path, '/');
    $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
    $query['api_key'] = (string)SYNCRO_API_KEY;
    $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);

    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    $method = strtoupper($method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($payload !== null) {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    }

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'errors' => ['Syncro request failed: ' . ($curlError ?: 'unknown cURL error')]];
    }

    $decoded = json_decode($body, true);
    if ($status >= 200 && $status < 300) {
        return ['ok' => true, 'status' => $status, 'data' => is_array($decoded) ? $decoded : ['raw' => $body]];
    }

    $errors = [];
    if (is_array($decoded)) {
        foreach (['message', 'error', 'errors', 'full_messages', 'details'] as $key) {
            if (!empty($decoded[$key])) {
                $errors = array_merge($errors, syncro_flatten_errors($decoded[$key], is_string($key) && $key !== 'errors' ? $key : ''));
            }
        }
        if (!$errors) {
            $errors = syncro_flatten_errors($decoded);
        }
    }
    $errors = array_values(array_unique(array_filter(array_map('trim', $errors))));
    if (!$errors) {
        $errors[] = 'Syncro API returned HTTP ' . $status . '.';
    }
    return ['ok' => false, 'status' => $status, 'errors' => $errors, 'data' => $decoded, 'raw_body' => $body];
}

function syncro_client_columns_ready(): bool
{
    return db_column_exists('clients', 'syncro_customer_id') && db_column_exists('clients', 'syncro_sync_status');
}

function syncro_normalize_subdomain(string $value): string
{
    $value = trim($value);
    $value = preg_replace('~^https?://~i', '', $value) ?? $value;
    $value = preg_replace('~/.*$~', '', $value) ?? $value;
    $value = preg_replace('~\.syncromsp\.com$~i', '', $value) ?? $value;
    return trim($value, '/ ');
}

function syncro_customer_payload_from_client(array $client): array
{
    $contact = null;
    if (!empty($client['client_id'])) {
        $contacts = client_get_contacts((int)$client['client_id']);
        $contact = $contacts[0] ?? null;
    }
    $location = null;
    if (!empty($client['client_id'])) {
        $locations = client_get_locations((int)$client['client_id']);
        $location = $locations[0] ?? null;
    }

    $first = trim((string)($contact['first_name'] ?? ''));
    $last = trim((string)($contact['last_name'] ?? ''));
    $fallbackName = preg_split('/\s+/', trim((string)($client['legal_name'] ?? '')), 2);
    if ($first === '' && !empty($fallbackName[0])) $first = $fallbackName[0];
    if ($last === '' && !empty($fallbackName[1])) $last = $fallbackName[1];

    return [
        'business_name' => (string)($client['dba_name'] ?: $client['legal_name'] ?: ''),
        'firstname' => $first,
        'lastname' => $last,
        'email' => (string)($contact['email'] ?? $client['email'] ?? ''),
        'phone' => (string)($contact['phone'] ?? $client['phone'] ?? ''),
        'address' => (string)($location['address1'] ?? ''),
        'address_2' => (string)($location['address2'] ?? ''),
        'city' => (string)($location['city'] ?? ''),
        'state' => (string)($location['state'] ?? ''),
        'zip' => (string)($location['postal_code'] ?? ''),
        'website' => (string)($client['website'] ?? ''),
        'notes' => (string)($client['notes'] ?? ''),
    ];
}

function syncro_required_fields_status(array $client): array
{
    $payload = syncro_customer_payload_from_client($client);
    $requirements = [
        'business_name' => 'Company name',
        'email' => 'Email',
        'phone' => 'Phone',
        'address' => 'Address',
        'city' => 'City',
        'state' => 'State',
        'zip' => 'Postal code',
    ];
    $missing = [];
    foreach ($requirements as $key => $label) {
        if (trim((string)($payload[$key] ?? '')) === '') {
            $missing[$key] = $label;
        }
    }
    return ['payload' => $payload, 'missing' => $missing];
}

function syncro_validate_client_readiness(array $client): array
{
    $status = syncro_required_fields_status($client);
    $payload = $status['payload'];
    $errors = [];
    foreach ($status['missing'] as $label) {
        $errors[] = $label . ' is required before this client can sync to Syncro.';
    }
    $email = trim((string)($payload['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is invalid for Syncro sync.';
    }
    return ['ok' => !$errors, 'errors' => $errors, 'payload' => $payload, 'missing' => $status['missing']];
}


function syncro_normalize_match_text(?string $value): string
{
    $value = trim(mb_strtolower((string)$value, 'UTF-8'));
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    return trim($value);
}

function syncro_normalize_phone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    if (strlen($digits) > 10 && str_starts_with($digits, '1')) {
        $digits = substr($digits, -10);
    }
    return $digits;
}

function syncro_customer_records_from_response(array $data): array
{
    $keys = ['customers', 'customer', 'customer_organizations', 'customer_organization', 'records', 'items', 'results', 'data'];
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $value = $data[$key];
        if (is_array($value)) {
            if (isset($value['id']) && is_array($value)) {
                return [$value];
            }
            if (array_is_list($value)) {
                return array_values(array_filter($value, static fn($row): bool => is_array($row)));
            }
        }
    }
    if (isset($data['id'])) {
        return [$data];
    }
    if (array_is_list($data)) {
        return array_values(array_filter($data, static fn($row): bool => is_array($row)));
    }
    return [];
}

function syncro_customer_match_result(array $row, array $payload): ?array
{
    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $business = syncro_normalize_match_text((string)($row['business_name'] ?? $row['name'] ?? ''));
    $email = syncro_normalize_match_text((string)($row['email'] ?? ''));
    $phone = syncro_normalize_phone((string)($row['phone'] ?? $row['mobile'] ?? ''));
    $city = syncro_normalize_match_text((string)($row['city'] ?? ''));
    $state = syncro_normalize_match_text((string)($row['state'] ?? ''));
    $zip = syncro_normalize_match_text((string)($row['zip'] ?? $row['postal_code'] ?? ''));

    $targetBusiness = syncro_normalize_match_text((string)($payload['business_name'] ?? ''));
    $targetEmail = syncro_normalize_match_text((string)($payload['email'] ?? ''));
    $targetPhone = syncro_normalize_phone((string)($payload['phone'] ?? ''));
    $targetCity = syncro_normalize_match_text((string)($payload['city'] ?? ''));
    $targetState = syncro_normalize_match_text((string)($payload['state'] ?? ''));
    $targetZip = syncro_normalize_match_text((string)($payload['zip'] ?? ''));

    $emailMatch = $targetEmail !== '' && $email !== '' && $targetEmail === $email;
    $businessMatch = $targetBusiness !== '' && $business !== '' && $targetBusiness === $business;
    $phoneMatch = $targetPhone !== '' && $phone !== '' && $targetPhone === $phone;
    $cityMatch = $targetCity !== '' && $city !== '' && $targetCity === $city;
    $stateMatch = $targetState !== '' && $state !== '' && $targetState === $state;
    $zipMatch = $targetZip !== '' && $zip !== '' && $targetZip === $zip;

    if (!$emailMatch && !$businessMatch && !$phoneMatch && !($cityMatch && $stateMatch && $zipMatch)) {
        return null;
    }

    $score = 0;
    $signals = [];
    if ($emailMatch) {
        $score += 10;
        $signals[] = 'email';
    }
    if ($businessMatch) {
        $score += 8;
        $signals[] = 'business';
    }
    if ($phoneMatch) {
        $score += 4;
        $signals[] = 'phone';
    }
    if ($cityMatch) {
        $score += 1;
        $signals[] = 'city';
    }
    if ($stateMatch) {
        $score += 1;
        $signals[] = 'state';
    }
    if ($zipMatch) {
        $score += 2;
        $signals[] = 'zip';
    }

    $confident = ($emailMatch && ($businessMatch || $phoneMatch || $zipMatch || ($cityMatch && $stateMatch)))
        || ($businessMatch && ($phoneMatch || $zipMatch || ($cityMatch && $stateMatch)))
        || ($emailMatch && $businessMatch);

    return [
        'id' => $id,
        'row' => $row,
        'score' => $score,
        'signals' => $signals,
        'confident' => $confident,
        'label' => (string)($row['business_name'] ?? $row['name'] ?? ('Customer #' . $id)),
    ];
}

function syncro_find_existing_customer(array $payload): array
{
    $matches = [];
    $page = 1;
    $maxPages = 15;

    while ($page <= $maxPages) {
        $resp = syncro_api_request('GET', 'customers', ['page' => $page]);
        if (empty($resp['ok'])) {
            return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to search existing Syncro customers.']];
        }

        $rows = syncro_customer_records_from_response((array)($resp['data'] ?? []));
        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            $match = syncro_customer_match_result($row, $payload);
            if ($match !== null) {
                $matches[$match['id']] = $match;
            }
        }

        $page++;
    }

    if (!$matches) {
        return ['ok' => true, 'found' => false, 'matches' => []];
    }

    $matches = array_values($matches);
    usort($matches, static function (array $a, array $b): int {
        return [$b['score'], $b['id']] <=> [$a['score'], $a['id']];
    });

    $best = $matches[0] ?? null;
    $confident = array_values(array_filter($matches, static fn(array $m): bool => !empty($m['confident'])));

    if (!$best) {
        return ['ok' => true, 'found' => false, 'matches' => []];
    }

    if (count($confident) === 1) {
        return ['ok' => true, 'found' => true, 'match' => $confident[0], 'matches' => $matches];
    }

    if (count($confident) > 1) {
        $topScore = (int)($confident[0]['score'] ?? 0);
        $top = array_values(array_filter($confident, static fn(array $m): bool => (int)($m['score'] ?? 0) === $topScore));
        if (count($top) === 1) {
            return ['ok' => true, 'found' => true, 'match' => $top[0], 'matches' => $matches];
        }
        return ['ok' => true, 'found' => false, 'ambiguous' => true, 'matches' => $top];
    }

    if (count($matches) === 1 && !empty($best['score']) && (int)$best['score'] >= 12) {
        return ['ok' => true, 'found' => true, 'match' => $best, 'matches' => $matches];
    }

    return ['ok' => true, 'found' => false, 'ambiguous' => true, 'matches' => array_slice($matches, 0, 3)];
}

function syncro_attempt_existing_customer_link(int $clientId, array $payload): array
{
    $search = syncro_find_existing_customer($payload);
    if (empty($search['ok'])) {
        return ['ok' => false, 'errors' => $search['errors'] ?? ['Unable to search Syncro for an existing customer.']];
    }
    if (!empty($search['ambiguous'])) {
        $ids = array_map(static fn(array $m): string => '#' . (string)($m['id'] ?? 0), (array)($search['matches'] ?? []));
        $message = 'Multiple existing Syncro customers matched this client (' . implode(', ', $ids) . '). Review the organization in Syncro and relink manually before retrying.';
        syncro_mark_client($clientId, null, 'MANUAL_REVIEW', $message);
        return ['ok' => false, 'errors' => [$message], 'status' => 'MANUAL_REVIEW'];
    }
    if (empty($search['found']) || empty($search['match']['id'])) {
        return ['ok' => false, 'not_found' => true, 'errors' => ['No existing Syncro customer matched this client.']];
    }

    $syncroId = (int)$search['match']['id'];
    $resp = syncro_api_request('PUT', 'customers/' . $syncroId, [], $payload);
    if (empty($resp['ok'])) {
        $msg = implode(' ', (array)($resp['errors'] ?? []));
        syncro_debug_log('relink_update_failed', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
        syncro_mark_client($clientId, $syncroId, 'ERROR', $msg !== '' ? $msg : 'Unable to update the existing Syncro customer.');
        return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to update the existing Syncro customer.']];
    }

    $matchedBy = implode(', ', (array)($search['match']['signals'] ?? []));
    $message = 'Linked to existing Syncro customer' . ($matchedBy !== '' ? ' by ' . $matchedBy : '') . ' and updated successfully.';
    syncro_mark_client($clientId, $syncroId, 'SYNCED', $message);
    return ['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'relinked', 'status' => 'SYNCED', 'message' => $message];
}

function syncro_action_success_message(?string $action): string
{
    return match (strtolower(trim((string)$action))) {
        'updated' => 'Syncro customer updated successfully.',
        'created' => 'Syncro customer created successfully.',
        'relinked' => 'Existing Syncro customer linked and updated successfully.',
        default => 'Syncro sync completed successfully.',
    };
}

function syncro_status_badge_html(?string $status, ?int $syncroCustomerId = null): string
{
    $status = strtoupper(trim((string)($status ?: 'PENDING')));
    $map = [
        'PENDING' => ['bg' => 'rgba(148,163,184,.18)', 'border' => 'rgba(148,163,184,.28)', 'color' => '#cbd5e1', 'label' => 'PENDING'],
        'SYNCED' => ['bg' => 'rgba(34,197,94,.18)', 'border' => 'rgba(34,197,94,.28)', 'color' => '#bbf7d0', 'label' => 'SYNCED'],
        'CONFLICT' => ['bg' => 'rgba(245,158,11,.20)', 'border' => 'rgba(245,158,11,.32)', 'color' => '#fde68a', 'label' => 'CONFLICT'],
        'MANUAL_REVIEW' => ['bg' => 'rgba(168,85,247,.20)', 'border' => 'rgba(168,85,247,.30)', 'color' => '#e9d5ff', 'label' => 'MANUAL REVIEW'],
        'ERROR' => ['bg' => 'rgba(248,113,113,.18)', 'border' => 'rgba(248,113,113,.30)', 'color' => '#fecaca', 'label' => 'ERROR'],
    ];
    $style = $map[$status] ?? $map['PENDING'];
    $html = '<span class="status-badge" style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;background:'
        . $style['bg'] . ';border:1px solid ' . $style['border'] . ';color:' . $style['color'] . ';">'
        . htmlspecialchars($style['label'], ENT_QUOTES, 'UTF-8') . '</span>';
    if (($syncroCustomerId ?? 0) > 0) {
        $html .= '<span style="font-size:12px;opacity:.72;">Customer #' . (int)$syncroCustomerId . '</span>';
    }
    return $html;
}

function syncro_retry_contract_sync(int $contractId): array
{
    $contractId = (int)$contractId;
    if ($contractId <= 0) {
        return ['ok' => false, 'errors' => ['Invalid contract for Syncro retry.']];
    }
    return syncro_contract_activation_sync($contractId);
}

function syncro_mark_client(int $clientId, ?int $syncroCustomerId, string $status, string $message = ''): void
{
    if (!syncro_client_columns_ready()) return;
    $pdo = db();
    $parts = [];
    $params = [];
    if (db_column_exists('clients', 'syncro_customer_id')) {
        $parts[] = 'syncro_customer_id = ?';
        $params[] = $syncroCustomerId;
    }
    if (db_column_exists('clients', 'syncro_sync_status')) {
        $parts[] = 'syncro_sync_status = ?';
        $params[] = strtoupper($status);
    }
    if (db_column_exists('clients', 'syncro_last_sync_at')) {
        $parts[] = 'syncro_last_sync_at = NOW()';
    }
    if (db_column_exists('clients', 'syncro_last_error')) {
        $parts[] = 'syncro_last_error = ?';
        $params[] = $message !== '' ? mb_substr($message, 0, 255) : null;
    }
    if (!$parts) return;
    $params[] = $clientId;
    $pdo->prepare('UPDATE clients SET ' . implode(', ', $parts) . ' WHERE client_id = ?')->execute($params);
}

function syncro_sync_client(int $clientId): array
{
    if ($clientId <= 0) return ['ok' => false, 'errors' => ['Invalid client for Syncro sync.']];
    if (!syncro_client_columns_ready()) return ['ok' => false, 'errors' => ['Run the Syncro integration SQL migration first.']];
    if (!syncro_is_configured()) return ['ok' => false, 'errors' => ['Add your Syncro API key to inc/config.php before syncing.']];

    $client = client_get_by_id($clientId);
    if (!$client) return ['ok' => false, 'errors' => ['Client record not found.']];

    $validation = syncro_validate_client_readiness($client);
    $payload = $validation['payload'];
    if (empty($validation['ok'])) {
        $msg = implode(' ', $validation['errors']);
        syncro_mark_client($clientId, !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : null, 'ERROR', $msg);
        return ['ok' => false, 'errors' => $validation['errors'], 'missing' => $validation['missing']];
    }

    $syncroId = !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : 0;

    if ($syncroId > 0) {
        $resp = syncro_api_request('PUT', 'customers/' . $syncroId, [], $payload);
        if (!empty($resp['ok'])) {
            syncro_mark_client($clientId, $syncroId, 'SYNCED', 'Updated in Syncro.');
            return ['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'updated', 'status' => 'SYNCED'];
        }

        $msg = implode(' ', (array)($resp['errors'] ?? []));
        $normalized = strtolower($msg);
        if (($resp['status'] ?? 0) === 404 || str_contains($normalized, 'not found')) {
            syncro_debug_log('stale_customer_link', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'message' => $msg]);
            $syncroId = 0;
        } else {
            syncro_debug_log('update_failed', ['client_id' => $clientId, 'syncro_customer_id' => $syncroId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
            syncro_mark_client($clientId, $syncroId, 'ERROR', $msg);
            return ['ok' => false, 'errors' => $resp['errors'] ?? ['Unable to update Syncro customer.']];
        }
    }

    $existing = syncro_attempt_existing_customer_link($clientId, $payload);
    if (!empty($existing['ok'])) {
        return $existing;
    }
    if (empty($existing['not_found']) && !empty($existing['errors'])) {
        return $existing;
    }

    $resp = syncro_api_request('POST', 'customers', [], $payload);
    if (empty($resp['ok'])) {
        $errors = (array)($resp['errors'] ?? ['Unable to create Syncro customer.']);
        $msg = implode(' ', $errors);
        $normalized = strtolower($msg);

        if (str_contains($normalized, 'already been taken') || str_contains($normalized, 'already exists') || str_contains($normalized, 'duplicate')) {
            $relinked = syncro_attempt_existing_customer_link($clientId, $payload);
            if (!empty($relinked['ok'])) {
                return $relinked;
            }
            $status = !empty($relinked['status']) ? (string)$relinked['status'] : 'CONFLICT';
            $fallbackErrors = !empty($relinked['errors']) ? (array)$relinked['errors'] : [];
            if (!$fallbackErrors) {
                $fallbackErrors[] = 'Syncro reported this client already exists, but OPS could not confidently relink it automatically.';
                $fallbackErrors[] = 'Verify the customer in Syncro and link the stored syncro_customer_id before retrying.';
            }
            $msg = implode(' ', array_values(array_unique(array_merge($errors, $fallbackErrors))));
            syncro_debug_log('create_conflict_unresolved', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null]);
            syncro_mark_client($clientId, null, $status, $msg);
            return ['ok' => false, 'errors' => array_values(array_unique(array_merge($errors, $fallbackErrors))), 'status' => $status];
        }

        syncro_debug_log('create_failed', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null, 'raw_body' => $resp['raw_body'] ?? null, 'status' => 'ERROR']);
        syncro_mark_client($clientId, null, 'ERROR', $msg);
        return ['ok' => false, 'errors' => $errors, 'status' => 'ERROR'];
    }

    $customer = $resp['data']['customer'] ?? $resp['data']['customers'] ?? $resp['data'];
    if (isset($customer[0]) && is_array($customer[0])) $customer = $customer[0];
    $syncroId = (int)($customer['id'] ?? 0);
    if ($syncroId <= 0) {
        syncro_debug_log('missing_customer_id', ['client_id' => $clientId, 'payload' => $payload, 'response' => $resp['data'] ?? null]);
        syncro_mark_client($clientId, null, 'ERROR', 'Syncro customer ID missing from response.');
        return ['ok' => false, 'errors' => ['Syncro did not return a customer ID.']];
    }

    syncro_mark_client($clientId, $syncroId, 'SYNCED', 'Created in Syncro.');
    return ['ok' => true, 'syncro_customer_id' => $syncroId, 'action' => 'created', 'status' => 'SYNCED'];
}

function syncro_contract_activation_sync(int $contractId): array
{
    $contractId = (int)$contractId;
    if ($contractId <= 0) return ['ok' => false, 'errors' => ['Invalid contract for Syncro sync.']];
    $stmt = db()->prepare('SELECT ctr.contract_id, ctr.client_id, ctr.status, c.syncro_customer_id, c.syncro_sync_status FROM contract ctr INNER JOIN clients c ON c.client_id = ctr.client_id WHERE ctr.contract_id = ? LIMIT 1');
    $stmt->execute([$contractId]);
    $row = $stmt->fetch();
    if (!$row) return ['ok' => false, 'errors' => ['Contract not found for Syncro sync.']];
    $status = strtoupper((string)$row['status']);
    if (!in_array($status, ['ACTIVE', 'ONBOARDING', 'SIGNED_PENDING_ONBOARDING'], true)) return ['ok' => true, 'skipped' => true, 'message' => 'Contract is not ready for Syncro sync yet.'];
    return syncro_sync_client((int)$row['client_id']);
}
