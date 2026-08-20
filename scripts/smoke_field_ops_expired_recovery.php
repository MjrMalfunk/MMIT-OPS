<?php
declare(strict_types=1);

$targetHost = trim((string)getenv('FIELD_OPS_HOST'));
$expectedDatabase = trim((string)getenv('FIELD_OPS_DATABASE'));

if ($targetHost === '' || $expectedDatabase === '') {
    throw new RuntimeException(
        'FIELD_OPS_HOST and FIELD_OPS_DATABASE are required'
    );
}

$_SERVER['HTTP_HOST'] = $targetHost;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';
ob_end_clean();

$pdo = db();
$actualDatabase = (string)$pdo
    ->query('SELECT DATABASE()')
    ->fetchColumn();

if (!hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException(
        "Database guard failed: expected {$expectedDatabase}, connected {$actualDatabase}"
    );
}

echo "Database: {$actualDatabase}\n";

field_ops_ensure_opportunity_schema();
field_ops_ensure_fn_packet_schema();

$failures = 0;
$assert = static function (
    bool $condition,
    string $message
) use (&$failures): void {
    echo $condition ? 'PASS: ' : 'FAIL: ', $message, PHP_EOL;

    if (!$condition) {
        $failures++;
    }
};

$prefix = 'SMOKE-REC-' . bin2hex(random_bytes(5));
$expiredNumber = $prefix . '-EXPIRED';
$availableNumber = $prefix . '-AVAILABLE';
$expiredAt = '2026-08-20 00:00:42';

$cleanup = static function () use (
    $pdo,
    $prefix
): void {
    $like = $prefix . '-%';

    $stmt = $pdo->prepare("
        DELETE FROM field_fn_work_order_packets
        WHERE external_work_order_number LIKE ?
    ");
    $stmt->execute([$like]);

    $stmt = $pdo->prepare("
        DELETE FROM field_opportunities
        WHERE external_work_order_number LIKE ?
    ");
    $stmt->execute([$like]);

    $stmt = $pdo->prepare("
        DELETE FROM field_work_orders
        WHERE external_work_order_number LIKE ?
    ");
    $stmt->execute([$like]);
};

$cleanup();

try {
    $insert = $pdo->prepare("
        INSERT INTO field_opportunities (
            platform,
            external_work_order_number,
            title,
            scheduled_start_at,
            status,
            score,
            recommendation,
            estimated_gross,
            expired_at
        ) VALUES (
            'FieldNation',
            ?,
            ?,
            ?,
            ?,
            50,
            'Review',
            60.00,
            ?
        )
    ");

    $insert->execute([
        $expiredNumber,
        'Expired recovery smoke',
        '2026-08-19 09:00:00',
        'EXPIRED',
        $expiredAt,
    ]);
    $expiredId = (int)$pdo->lastInsertId();

    $insert->execute([
        $availableNumber,
        'Available recovery guard smoke',
        '2099-08-19 09:00:00',
        'AVAILABLE',
        null,
    ]);
    $availableId = (int)$pdo->lastInsertId();

    $normal = field_ops_promote_opportunity_to_work_order(
        $expiredId
    );

    $assert(
        empty($normal['ok']),
        'ordinary promotion still rejects an expired opportunity'
    );

    $wrongStatus = field_ops_promote_opportunity_to_work_order(
        $availableId,
        true
    );

    $assert(
        empty($wrongStatus['ok']),
        'manual recovery rejects a non-expired opportunity'
    );

    $recovered = field_ops_promote_opportunity_to_work_order(
        $expiredId,
        true
    );

    $workOrderId = (int)($recovered['work_order_id'] ?? 0);

    $assert(
        !empty($recovered['ok']) && $workOrderId > 0,
        'manual recovery creates a W/O'
    );

    $stmt = $pdo->prepare("
        SELECT
            status,
            gross_pay,
            notes
        FROM field_work_orders
        WHERE work_order_id = ?
        LIMIT 1
    ");
    $stmt->execute([$workOrderId]);
    $workOrder = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert(
        strtoupper((string)($workOrder['status'] ?? '')) ===
            'REQUESTED',
        'recovered W/O begins as REQUESTED'
    );

    $assert(
        abs((float)($workOrder['gross_pay'] ?? 0) - 60.00) < .001,
        'recovered W/O preserves projected gross'
    );

    $assert(
        str_contains(
            (string)($workOrder['notes'] ?? ''),
            'Manually recovered after automatic expiration.'
        ),
        'recovered W/O records an audit note'
    );

    $stmt = $pdo->prepare("
        SELECT
            status,
            promoted_work_order_id,
            expired_at
        FROM field_opportunities
        WHERE opportunity_id = ?
        LIMIT 1
    ");
    $stmt->execute([$expiredId]);
    $opportunity = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $assert(
        strtoupper((string)($opportunity['status'] ?? '')) ===
            'REQUESTED',
        'recovered opportunity changes to REQUESTED'
    );

    $assert(
        (int)($opportunity['promoted_work_order_id'] ?? 0) ===
            $workOrderId,
        'opportunity links to the recovered W/O'
    );

    $assert(
        (string)($opportunity['expired_at'] ?? '') === $expiredAt,
        'original expiration timestamp is preserved'
    );

    $second = field_ops_promote_opportunity_to_work_order(
        $expiredId,
        true
    );

    $assert(
        !empty($second['ok'])
        && (int)($second['work_order_id'] ?? 0) === $workOrderId,
        'repeated recovery returns the existing W/O'
    );

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM field_work_orders
        WHERE external_work_order_number = ?
    ");
    $stmt->execute([$expiredNumber]);

    $assert(
        (int)$stmt->fetchColumn() === 1,
        'repeated recovery does not create a duplicate W/O'
    );

    $packet = field_ops_find_fn_packet(
        'FieldNation',
        $expiredNumber
    );

    $assert(
        is_array($packet)
        && (int)($packet['work_order_id'] ?? 0) === $workOrderId,
        'recovery creates and links the FN packet record'
    );
} finally {
    $cleanup();
}

foreach ([
    'field_opportunities',
    'field_work_orders',
    'field_fn_work_order_packets',
] as $table) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM {$table}
        WHERE external_work_order_number LIKE ?
    ");
    $stmt->execute([$prefix . '-%']);

    $assert(
        (int)$stmt->fetchColumn() === 0,
        "cleanup removed synthetic rows from {$table}"
    );
}

if ($failures > 0) {
    fwrite(
        STDERR,
        "FIELD OPS EXPIRED RECOVERY SMOKE FAILED: {$failures} assertion(s).\n"
    );
    exit(1);
}

echo PHP_EOL;
echo "FIELD OPS EXPIRED RECOVERY SMOKE PASSED\n";
