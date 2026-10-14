<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/outlook_calendar.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

date_default_timezone_set('America/Indiana/Indianapolis');

$scopes = onedrive_normalize_scopes(['User.Read Files.ReadWrite', 'user.read Calendars.ReadWrite']);
$assert(count($scopes) === 3, 'scope normalization removes duplicates');
$assert(outlook_calendar_scope_granted(['scopes' => $scopes]), 'calendar permission is detected');
$assert(!outlook_calendar_scope_granted(['scopes' => ['User.Read', 'Files.ReadWrite']]), 'calendar permission remains opt-in');

$start = outlook_calendar_graph_datetime('2026-10-16 08:30:00');
$assert(($start['dateTime'] ?? '') === '2026-10-16T08:30:00', 'date-time is preserved in local wall-clock time');
$assert(($start['timeZone'] ?? '') === 'Eastern Standard Time', 'Graph receives the Windows Eastern timezone');

$payload = outlook_calendar_build_payload([
    'title' => 'FieldNation W/O 123 · Switch install',
    'starts_at' => '2026-10-16 08:30:00',
    'ends_at' => '2026-10-16 14:00:00',
    'location' => 'Blue Bell · 100 Main St, Fort Wayne, IN',
    'description' => "OPS W/O #12\nBuyer: A & B",
    'transaction_id' => 'test-transaction-id',
]);
$assert($payload['showAs'] === 'busy', 'calendar events block availability');
$assert($payload['sensitivity'] === 'private', 'calendar events are private');
$assert($payload['reminderMinutesBeforeStart'] === 30, 'events include a 30-minute reminder');
$assert(($payload['location']['displayName'] ?? '') === 'Blue Bell · 100 Main St, Fort Wayne, IN', 'location is included');
$assert(str_contains($payload['body']['content'], 'A &amp; B'), 'description is HTML escaped');
$assert(($payload['transactionId'] ?? '') === 'test-transaction-id', 'create payload has an idempotency transaction ID');

$invalidWindowRejected = false;
try {
    outlook_calendar_build_payload([
        'title' => 'Bad window',
        'starts_at' => '2026-10-16 14:00',
        'ends_at' => '2026-10-16 08:30',
    ]);
} catch (InvalidArgumentException) {
    $invalidWindowRejected = true;
}
$assert($invalidWindowRejected, 'end-before-start is rejected');
$assert(outlook_calendar_datetime('2026-02-30 08:30') === null, 'invalid dates are rejected');

echo "PASS: {$checks} Outlook calendar mapping, permission, privacy, and schedule checks.\n";
