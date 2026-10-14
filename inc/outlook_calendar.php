<?php
declare(strict_types=1);

require_once __DIR__ . '/field_ops.php';
require_once __DIR__ . '/onedrive.php';

const OUTLOOK_CALENDAR_REQUIRED_SCOPE = 'Calendars.ReadWrite';
const OUTLOOK_CALENDAR_GRAPH_TIMEZONE = 'Eastern Standard Time';

function outlook_calendar_ensure_schema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    db()->exec("CREATE TABLE IF NOT EXISTS field_outlook_calendar_events (
        calendar_event_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_type VARCHAR(24) NOT NULL,
        source_id BIGINT NOT NULL,
        title VARCHAR(255) NOT NULL,
        starts_at DATETIME NOT NULL,
        ends_at DATETIME NOT NULL,
        location VARCHAR(500) NULL,
        description TEXT NULL,
        graph_event_id VARCHAR(255) NULL,
        transaction_id CHAR(36) NOT NULL,
        payload_hash CHAR(64) NULL,
        sync_status VARCHAR(24) NOT NULL DEFAULT 'PENDING',
        last_error VARCHAR(1000) NULL,
        last_synced_at DATETIME NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_outlook_calendar_source (source_type, source_id),
        UNIQUE KEY uq_outlook_calendar_transaction (transaction_id),
        INDEX idx_outlook_calendar_status (sync_status, updated_at),
        INDEX idx_outlook_calendar_start (starts_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $ready = true;
}

function outlook_calendar_scope_granted(?array $tokenData = null): bool
{
    $tokenData ??= onedrive_load_token_data();
    if (!is_array($tokenData)) {
        return false;
    }
    $scopes = array_map('strtolower', onedrive_normalize_scopes((array)($tokenData['scopes'] ?? [])));
    return in_array(strtolower(OUTLOOK_CALENDAR_REQUIRED_SCOPE), $scopes, true);
}

function outlook_calendar_connection_status(): array
{
    $status = onedrive_connection_status();
    $tokenData = onedrive_load_token_data();
    $status['calendar_permission'] = outlook_calendar_scope_granted($tokenData);
    $status['calendar_ready'] = !empty($status['configured'])
        && !empty($status['connected'])
        && $status['calendar_permission'];
    return $status;
}

function outlook_calendar_uuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
        . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-'
        . substr($hex, 20);
}

function outlook_calendar_datetime(string $value): ?DateTimeImmutable
{
    $value = trim(str_replace('T', ' ', $value));
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return null;
    }
    $format = strlen($value) === 16 ? '!Y-m-d H:i' : '!Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone(date_default_timezone_get()));
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        return null;
    }
    return $date;
}

function outlook_calendar_graph_datetime(string $value): ?array
{
    $date = outlook_calendar_datetime($value);
    if (!$date) {
        return null;
    }
    return [
        'dateTime' => $date->format('Y-m-d\TH:i:s'),
        'timeZone' => OUTLOOK_CALENDAR_GRAPH_TIMEZONE,
    ];
}

function outlook_calendar_build_payload(array $event): array
{
    $start = outlook_calendar_graph_datetime((string)($event['starts_at'] ?? ''));
    $end = outlook_calendar_graph_datetime((string)($event['ends_at'] ?? ''));
    if (!$start || !$end) {
        throw new InvalidArgumentException('A valid start and end time are required.');
    }
    $startAt = outlook_calendar_datetime((string)$event['starts_at']);
    $endAt = outlook_calendar_datetime((string)$event['ends_at']);
    if (!$startAt || !$endAt || $endAt <= $startAt) {
        throw new InvalidArgumentException('The end time must be later than the start time.');
    }

    $payload = [
        'subject' => mb_substr(trim((string)($event['title'] ?? 'Work block')), 0, 255),
        'start' => $start,
        'end' => $end,
        'showAs' => 'busy',
        'sensitivity' => 'private',
        'isReminderOn' => true,
        'reminderMinutesBeforeStart' => 30,
        'body' => [
            'contentType' => 'HTML',
            'content' => nl2br(htmlspecialchars((string)($event['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
        ],
    ];
    $location = trim((string)($event['location'] ?? ''));
    if ($location !== '') {
        $payload['location'] = ['displayName' => mb_substr($location, 0, 500)];
    }
    if (trim((string)($event['transaction_id'] ?? '')) !== '') {
        $payload['transactionId'] = (string)$event['transaction_id'];
    }
    return $payload;
}

function outlook_calendar_event_row(string $sourceType, int $sourceId): ?array
{
    outlook_calendar_ensure_schema();
    $statement = db()->prepare('SELECT * FROM field_outlook_calendar_events WHERE source_type = ? AND source_id = ? LIMIT 1');
    $statement->execute([$sourceType, $sourceId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function outlook_calendar_save_event(string $sourceType, int $sourceId, array $event, int $userId = 0): array
{
    outlook_calendar_ensure_schema();
    $existing = outlook_calendar_event_row($sourceType, $sourceId);
    $transactionId = is_array($existing) ? (string)$existing['transaction_id'] : outlook_calendar_uuid();
    if (is_array($existing) && in_array((string)$existing['sync_status'], ['DELETED', 'CANCELLED'], true)) {
        $transactionId = outlook_calendar_uuid();
    }

    $params = [
        $sourceType,
        $sourceId,
        mb_substr(trim((string)$event['title']), 0, 255),
        $event['starts_at'],
        $event['ends_at'],
        trim((string)($event['location'] ?? '')) ?: null,
        trim((string)($event['description'] ?? '')) ?: null,
        $transactionId,
        $userId > 0 ? $userId : null,
    ];
    db()->prepare("INSERT INTO field_outlook_calendar_events
        (source_type, source_id, title, starts_at, ends_at, location, description, transaction_id, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          title = VALUES(title), starts_at = VALUES(starts_at), ends_at = VALUES(ends_at),
          location = VALUES(location), description = VALUES(description),
          transaction_id = IF(sync_status IN ('DELETED', 'CANCELLED'), VALUES(transaction_id), transaction_id),
          graph_event_id = IF(sync_status IN ('DELETED', 'CANCELLED'), NULL, graph_event_id),
          payload_hash = IF(sync_status IN ('DELETED', 'CANCELLED'), NULL, payload_hash),
          sync_status = 'PENDING', last_error = NULL, created_by = COALESCE(created_by, VALUES(created_by)), updated_at = NOW()")->execute($params);
    return outlook_calendar_event_row($sourceType, $sourceId) ?? [];
}

function outlook_calendar_mark_result(int $eventId, string $status, ?string $error = null, ?string $graphEventId = null, ?string $payloadHash = null): void
{
    outlook_calendar_ensure_schema();
    db()->prepare("UPDATE field_outlook_calendar_events
        SET sync_status = ?, last_error = ?,
            graph_event_id = COALESCE(?, graph_event_id),
            payload_hash = COALESCE(?, payload_hash),
            last_synced_at = IF(? = 'SYNCED', NOW(), last_synced_at),
            updated_at = NOW()
        WHERE calendar_event_id = ?
        LIMIT 1")->execute([$status, $error !== null ? mb_substr($error, 0, 1000) : null, $graphEventId, $payloadHash, $status, $eventId]);
}

function outlook_calendar_graph_request(string $method, string $url, string $accessToken, ?array $payload = null): array
{
    return onedrive_api_json($method, $url, $accessToken, $payload);
}

function outlook_calendar_sync_saved_event(array $event): array
{
    $eventId = (int)($event['calendar_event_id'] ?? 0);
    if ($eventId <= 0) {
        return ['ok' => false, 'error' => 'Calendar event record is missing.'];
    }
    if (!outlook_calendar_scope_granted()) {
        $message = 'Connect Outlook Calendar from the Calendar page first.';
        outlook_calendar_mark_result($eventId, 'ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }
    $token = onedrive_get_valid_access_token();
    if (empty($token['ok'])) {
        $message = (string)($token['error'] ?? 'Reconnect Microsoft 365 from the Calendar page.');
        outlook_calendar_mark_result($eventId, 'ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }

    try {
        $payload = outlook_calendar_build_payload($event);
    } catch (Throwable $e) {
        outlook_calendar_mark_result($eventId, 'ERROR', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    unset($payload['transactionId']);
    $payloadHash = hash('sha256', (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    if ((string)($event['sync_status'] ?? '') === 'SYNCED'
        && (string)($event['payload_hash'] ?? '') === $payloadHash
        && trim((string)($event['graph_event_id'] ?? '')) !== '') {
        return ['ok' => true, 'unchanged' => true];
    }

    $accessToken = (string)$token['access_token'];
    $graphId = trim((string)($event['graph_event_id'] ?? ''));
    if ($graphId !== '') {
        $response = outlook_calendar_graph_request(
            'PATCH',
            'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($graphId),
            $accessToken,
            $payload
        );
        if (!empty($response['ok'])) {
            outlook_calendar_mark_result($eventId, 'SYNCED', null, null, $payloadHash);
            return ['ok' => true, 'updated' => true];
        }
        if ((int)($response['status'] ?? 0) !== 404) {
            $message = 'Outlook update failed: ' . (string)($response['error'] ?? 'Unknown Microsoft Graph error.');
            outlook_calendar_mark_result($eventId, 'ERROR', $message);
            return ['ok' => false, 'error' => $message];
        }
        // The linked Outlook event was removed outside OPS. Create its replacement.
        db()->prepare("UPDATE field_outlook_calendar_events SET graph_event_id = NULL, transaction_id = ?, payload_hash = NULL, sync_status = 'PENDING' WHERE calendar_event_id = ? LIMIT 1")
            ->execute([outlook_calendar_uuid(), $eventId]);
        $event['transaction_id'] = outlook_calendar_event_row((string)$event['source_type'], (int)$event['source_id'])['transaction_id'];
        $payload['transactionId'] = $event['transaction_id'];
    } else {
        $payload['transactionId'] = (string)$event['transaction_id'];
    }

    $response = outlook_calendar_graph_request('POST', 'https://graph.microsoft.com/v1.0/me/events', $accessToken, $payload);
    $createdId = trim((string)($response['json']['id'] ?? ''));
    if (empty($response['ok']) || $createdId === '') {
        $message = 'Outlook create failed: ' . (string)($response['error'] ?? 'Microsoft Graph did not return an event ID.');
        outlook_calendar_mark_result($eventId, 'ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }
    outlook_calendar_mark_result($eventId, 'SYNCED', null, $createdId, $payloadHash);
    return ['ok' => true, 'created' => true];
}

function outlook_calendar_delete_saved_event(array $event, string $finalStatus = 'DELETED'): array
{
    $eventId = (int)($event['calendar_event_id'] ?? 0);
    if ($eventId <= 0) {
        return ['ok' => true, 'unchanged' => true];
    }
    $graphId = trim((string)($event['graph_event_id'] ?? ''));
    if ($graphId === '') {
        outlook_calendar_mark_result($eventId, $finalStatus, null);
        return ['ok' => true, 'deleted' => true];
    }
    if (!outlook_calendar_scope_granted()) {
        $message = 'Connect Outlook Calendar to remove the linked event.';
        outlook_calendar_mark_result($eventId, 'DELETE_ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }
    $token = onedrive_get_valid_access_token();
    if (empty($token['ok'])) {
        $message = (string)($token['error'] ?? 'Reconnect Microsoft 365 from the Calendar page.');
        outlook_calendar_mark_result($eventId, 'DELETE_ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }
    $response = outlook_calendar_graph_request(
        'DELETE',
        'https://graph.microsoft.com/v1.0/me/events/' . rawurlencode($graphId),
        (string)$token['access_token']
    );
    if (empty($response['ok']) && !in_array((int)($response['status'] ?? 0), [404, 410], true)) {
        $message = 'Outlook delete failed: ' . (string)($response['error'] ?? 'Unknown Microsoft Graph error.');
        outlook_calendar_mark_result($eventId, 'DELETE_ERROR', $message);
        return ['ok' => false, 'error' => $message];
    }
    outlook_calendar_mark_result($eventId, $finalStatus, null);
    return ['ok' => true, 'deleted' => true];
}

function outlook_calendar_work_order(int $workOrderId): ?array
{
    field_ops_ensure_schema();
    $statement = db()->prepare("SELECT wo.*, b.buyer_name
        FROM field_work_orders wo
        LEFT JOIN field_buyers b ON b.buyer_id = wo.buyer_id
        WHERE wo.work_order_id = ? LIMIT 1");
    $statement->execute([$workOrderId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function outlook_calendar_sync_work_order(int $workOrderId, int $userId = 0): array
{
    $wo = outlook_calendar_work_order($workOrderId);
    if (!$wo) {
        return ['ok' => false, 'error' => 'Work order not found.'];
    }
    $event = outlook_calendar_event_row('FIELDNATION', $workOrderId);
    $status = strtoupper((string)($wo['status'] ?? ''));
    $deleted = !empty($wo['deleted_at']);
    $canceled = $deleted || in_array($status, ['CANCELLED', 'DECLINED'], true);
    if ($canceled) {
        return outlook_calendar_delete_saved_event($event ?? [], $deleted ? 'DELETED' : 'CANCELLED');
    }

    $platform = trim((string)($wo['platform'] ?? ''));
    if (strcasecmp($platform, 'FieldNation') !== 0
        || !in_array($status, ['ASSIGNED', 'SCHEDULED', 'CHECKED_IN', 'IN_PROGRESS', 'RELEASED_BY_LEAD', 'CHECKED_OUT', 'SUBMITTED', 'APPROVED', 'PAID'], true)
        || trim((string)($wo['scheduled_start_at'] ?? '')) === ''
        || trim((string)($wo['scheduled_end_at'] ?? '')) === '') {
        if ($event) {
            if (trim((string)($event['graph_event_id'] ?? '')) !== '') {
                return outlook_calendar_delete_saved_event($event, 'NOT_READY');
            }
            outlook_calendar_mark_result((int)$event['calendar_event_id'], 'NOT_READY', null);
        }
        return ['ok' => true, 'not_ready' => true];
    }

    $site = trim(implode(', ', array_filter([
        trim((string)($wo['site_name'] ?? '')),
        trim((string)($wo['site_address'] ?? '')),
        trim((string)($wo['city'] ?? '')),
        trim((string)($wo['state'] ?? '')),
    ], static fn(string $part): bool => $part !== '')));
    $number = trim((string)($wo['external_work_order_number'] ?? ''));
    $title = 'FieldNation' . ($number !== '' ? ' W/O ' . $number : ' work order') . ' · ' . trim((string)$wo['title']);
    $description = "OPS work order #{$workOrderId}\n"
        . 'Buyer: ' . trim((string)($wo['buyer_name'] ?? '')) . "\n"
        . 'Status: ' . $status . "\n"
        . 'Scheduled window: ' . (string)$wo['scheduled_start_at'] . ' to ' . (string)$wo['scheduled_end_at'] . "\n"
        . 'Open in OPS: ' . BASE_URL . '/admin/field_work_order.php?id=' . $workOrderId;
    $saved = outlook_calendar_save_event('FIELDNATION', $workOrderId, [
        'title' => $title,
        'starts_at' => (string)$wo['scheduled_start_at'],
        'ends_at' => (string)$wo['scheduled_end_at'],
        'location' => $site,
        'description' => $description,
    ], $userId);
    return outlook_calendar_sync_saved_event($saved);
}

function outlook_calendar_add_flex_block(array $input, int $userId = 0): array
{
    $title = trim((string)($input['title'] ?? 'Amazon Flex block'));
    if ($title === '') {
        $title = 'Amazon Flex block';
    }
    $start = outlook_calendar_datetime((string)($input['starts_at'] ?? ''));
    $end = outlook_calendar_datetime((string)($input['ends_at'] ?? ''));
    if (!$start || !$end || $end <= $start) {
        return ['ok' => false, 'errors' => ['Enter a valid start and end; the end must be later than the start.']];
    }
    if ($end->getTimestamp() - $start->getTimestamp() > 16 * 3600) {
        return ['ok' => false, 'errors' => ['A Flex block cannot be longer than 16 hours.']];
    }
    $pay = trim((string)($input['expected_pay'] ?? ''));
    if ($pay !== '' && (!is_numeric(str_replace(',', '', $pay)) || (float)str_replace(',', '', $pay) < 0 || (float)str_replace(',', '', $pay) > 10000)) {
        return ['ok' => false, 'errors' => ['Expected pay must be a non-negative amount.']];
    }
    // The random positive key avoids collisions if a block is submitted twice in parallel.
    $manualId = random_int(1, 9_000_000_000_000_000);
    $description = "Amazon Flex block added in OPS.\n"
        . ($pay !== '' ? 'Expected pay: $' . number_format((float)str_replace(',', '', $pay), 2) . "\n" : '')
        . trim((string)($input['notes'] ?? ''));
    $event = outlook_calendar_save_event('FLEX', $manualId, [
        'title' => $title,
        'starts_at' => $start->format('Y-m-d H:i:s'),
        'ends_at' => $end->format('Y-m-d H:i:s'),
        'location' => 'Amazon Flex',
        'description' => trim($description),
    ], $userId);
    $sync = outlook_calendar_sync_saved_event($event);
    return [
        'ok' => !empty($sync['ok']),
        'calendar_event_id' => (int)($event['calendar_event_id'] ?? 0),
        'errors' => empty($sync['ok']) ? [(string)($sync['error'] ?? 'The block is saved in OPS but could not be sent to Outlook yet.')] : [],
    ];
}

function outlook_calendar_retry_event(int $calendarEventId): array
{
    outlook_calendar_ensure_schema();
    $statement = db()->prepare('SELECT * FROM field_outlook_calendar_events WHERE calendar_event_id = ? LIMIT 1');
    $statement->execute([$calendarEventId]);
    $event = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Calendar event record not found.'];
    }
    if ($event['source_type'] === 'FIELDNATION') {
        return outlook_calendar_sync_work_order((int)$event['source_id']);
    }
    if ($event['source_type'] === 'FLEX' && (string)$event['sync_status'] === 'DELETE_ERROR') {
        return outlook_calendar_delete_saved_event($event, 'CANCELLED');
    }
    if ($event['source_type'] === 'FLEX' && !in_array((string)$event['sync_status'], ['CANCELLED', 'DELETED'], true)) {
        return outlook_calendar_sync_saved_event($event);
    }
    return ['ok' => false, 'error' => 'This calendar item cannot be retried.'];
}

function outlook_calendar_cancel_flex_block(int $calendarEventId): array
{
    outlook_calendar_ensure_schema();
    $statement = db()->prepare("SELECT * FROM field_outlook_calendar_events WHERE calendar_event_id = ? AND source_type = 'FLEX' LIMIT 1");
    $statement->execute([$calendarEventId]);
    $event = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($event)) {
        return ['ok' => false, 'error' => 'Flex block not found.'];
    }
    return outlook_calendar_delete_saved_event($event, 'CANCELLED');
}

function outlook_calendar_pending_events(int $limit = 50): array
{
    outlook_calendar_ensure_schema();
    $limit = max(1, min(200, $limit));
    $statement = db()->query("SELECT * FROM field_outlook_calendar_events
        ORDER BY CASE sync_status WHEN 'ERROR' THEN 0 WHEN 'PENDING' THEN 1 ELSE 2 END,
                 starts_at DESC, calendar_event_id DESC LIMIT {$limit}");
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function outlook_calendar_unlinked_work_orders(int $limit = 50): array
{
    field_ops_ensure_schema();
    outlook_calendar_ensure_schema();
    $limit = max(1, min(100, $limit));
    $statement = db()->query("SELECT wo.work_order_id, wo.external_work_order_number, wo.title, wo.status,
          wo.scheduled_start_at, wo.scheduled_end_at, wo.city, wo.state, b.buyer_name
        FROM field_work_orders wo LEFT JOIN field_buyers b ON b.buyer_id = wo.buyer_id
        LEFT JOIN field_outlook_calendar_events ce ON ce.source_type = 'FIELDNATION' AND ce.source_id = wo.work_order_id
        WHERE wo.platform = 'FieldNation' AND wo.deleted_at IS NULL
          AND wo.status IN ('ASSIGNED','SCHEDULED','CHECKED_IN','IN_PROGRESS','RELEASED_BY_LEAD','CHECKED_OUT','SUBMITTED','APPROVED','PAID')
          AND wo.scheduled_start_at IS NOT NULL AND wo.scheduled_end_at IS NOT NULL
          AND (ce.calendar_event_id IS NULL OR ce.sync_status IN ('ERROR','PENDING','NOT_READY'))
        ORDER BY wo.scheduled_start_at ASC, wo.work_order_id DESC LIMIT {$limit}");
    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
