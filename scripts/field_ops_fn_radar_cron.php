<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST']
    ?? 'ops-test.midwestmanagedit.com';

$_SERVER['HTTPS'] = $_SERVER['HTTPS']
    ?? 'on';

$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI']
    ?? '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/field_ops.php';
ob_end_clean();

$importLimit = 100;
$applyLimit = 100;

foreach ($argv ?? [] as $argument) {
    if (preg_match('/^--import-limit=(\d+)$/', $argument, $match)) {
        $importLimit = max(
            1,
            min(100, (int)$match[1])
        );
    }

    if (preg_match('/^--apply-limit=(\d+)$/', $argument, $match)) {
        $applyLimit = max(
            1,
            min(500, (int)$match[1])
        );
    }
}

echo 'Field Ops FN radar automation starting.', PHP_EOL;

$import = field_ops_import_fieldnation_mailbox([
    'limit' => $importLimit,
]);

if (empty($import['ok'])) {
    echo 'IMPORT FAILED: ', implode(
        ' ',
        (array)($import['errors'] ?? ['Unknown import failure.'])
    ), PHP_EOL;

    exit(1);
}

echo sprintf(
    'Import: found %d, checked %d, imported %d, duplicates %d, skipped %d.',
    (int)($import['found'] ?? 0),
    (int)($import['checked'] ?? 0),
    (int)($import['imported'] ?? 0),
    (int)($import['duplicates'] ?? 0),
    (int)($import['skipped'] ?? 0)
), PHP_EOL;

$st = db()->prepare("
    SELECT email_event_id
    FROM field_email_events
    WHERE applied_at IS NULL
      AND ignored_at IS NULL
      AND parsed_status IN ('AVAILABLE', 'ROUTED')
    ORDER BY
        COALESCE(received_at, created_at) ASC,
        email_event_id ASC
    LIMIT {$applyLimit}
");

$st->execute();

$eventIds = array_map(
    'intval',
    $st->fetchAll(PDO::FETCH_COLUMN) ?: []
);

$stats = [
    'queued' => count($eventIds),
    'applied' => 0,
    'failed' => 0,
];

foreach ($eventIds as $eventId) {
    $result = field_ops_apply_email_event_to_opportunity(
        $eventId
    );

    if (!empty($result['ok'])) {
        $stats['applied']++;

        echo sprintf(
            'Event #%d applied to opportunity #%d.',
            $eventId,
            (int)($result['opportunity_id'] ?? 0)
        ), PHP_EOL;

        continue;
    }

    $stats['failed']++;

    echo sprintf(
        'Event #%d failed: %s',
        $eventId,
        implode(
            ' ',
            (array)($result['errors'] ?? ['Unknown apply failure.'])
        )
    ), PHP_EOL;
}

echo sprintf(
    'Radar: queued %d, applied %d, failed %d.',
    $stats['queued'],
    $stats['applied'],
    $stats['failed']
), PHP_EOL;

echo 'Field Ops FN radar automation complete.', PHP_EOL;

exit($stats['failed'] > 0 ? 1 : 0);
