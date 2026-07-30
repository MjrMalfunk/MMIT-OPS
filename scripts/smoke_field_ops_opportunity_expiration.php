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

$actualDatabase = (string)db()
    ->query('SELECT DATABASE()')
    ->fetchColumn();

if (!hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException(
        "Database guard failed: expected {$expectedDatabase}, connected {$actualDatabase}"
    );
}

echo 'Database: ', $actualDatabase, PHP_EOL;

field_ops_ensure_opportunity_schema();

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

$now = new DateTimeImmutable('2026-07-30 09:30:00');
$prefix = 'SMOKE-EXP-' . bin2hex(random_bytes(5));
$cases = [
    'available_past_end' => [
        'status' => 'AVAILABLE',
        'start' => '2026-07-29 08:00:00',
        'end' => '2026-07-29 10:00:00',
        'expected' => 'EXPIRED',
    ],
    'routed_previous_day' => [
        'status' => 'ROUTED',
        'start' => '2026-07-29 20:00:00',
        'end' => null,
        'expected' => 'EXPIRED',
    ],
    'watching_past_end' => [
        'status' => 'WATCHING',
        'start' => '2026-07-29 08:00:00',
        'end' => '2026-07-29 10:00:00',
        'expected' => 'WATCHING',
    ],
    'requested_past_end' => [
        'status' => 'REQUESTED',
        'start' => '2026-07-29 08:00:00',
        'end' => '2026-07-29 10:00:00',
        'expected' => 'REQUESTED',
    ],
    'assigned_past_end' => [
        'status' => 'ASSIGNED',
        'start' => '2026-07-29 08:00:00',
        'end' => '2026-07-29 10:00:00',
        'expected' => 'ASSIGNED',
    ],
    'available_today_no_end' => [
        'status' => 'AVAILABLE',
        'start' => '2026-07-30 08:00:00',
        'end' => null,
        'expected' => 'AVAILABLE',
    ],
    'available_future_end' => [
        'status' => 'AVAILABLE',
        'start' => '2026-07-30 10:00:00',
        'end' => '2026-07-30 12:00:00',
        'expected' => 'AVAILABLE',
    ],
    'available_no_schedule' => [
        'status' => 'AVAILABLE',
        'start' => null,
        'end' => null,
        'expected' => 'AVAILABLE',
    ],
];

$pdo = db();

try {
    $insert = $pdo->prepare("
        INSERT INTO field_opportunities (
            platform,
            external_work_order_number,
            title,
            scheduled_start_at,
            scheduled_end_at,
            status,
            score,
            recommendation
        ) VALUES (
            'FieldNation',
            ?,
            ?,
            ?,
            ?,
            ?,
            50,
            'Review'
        )
    ");

    foreach ($cases as $name => $case) {
        $number = $prefix . '-' . $name;
        $insert->execute([
            $number,
            'Expiration smoke ' . $name,
            $case['start'],
            $case['end'],
            $case['status'],
        ]);
        $cases[$name]['number'] = $number;
    }

    $expiredCount = field_ops_expire_stale_opportunities($now);

    $assert(
        $expiredCount >= 2,
        'expiration pass updated at least the two stale smoke opportunities'
    );

    $find = $pdo->prepare("
        SELECT status, expired_at
        FROM field_opportunities
        WHERE external_work_order_number = ?
        LIMIT 1
    ");

    foreach ($cases as $name => $case) {
        $find->execute([$case['number']]);
        $row = $find->fetch(PDO::FETCH_ASSOC) ?: [];

        $assert(
            strtoupper((string)($row['status'] ?? '')) === $case['expected'],
            $name . ' remains/changes to ' . $case['expected']
        );

        $shouldHaveExpiredAt = $case['expected'] === 'EXPIRED';
        $assert(
            $shouldHaveExpiredAt === !empty($row['expired_at']),
            $name . (
                $shouldHaveExpiredAt
                    ? ' records expired_at'
                    : ' does not record expired_at'
            )
        );
    }

    $assert(
        field_ops_expire_stale_opportunities($now) === 0,
        'second expiration pass is idempotent'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $cleanup = $pdo->prepare("
        DELETE FROM field_opportunities
        WHERE external_work_order_number LIKE ?
    ");
    $cleanup->execute([$prefix . '-%']);
}

$placeholders = implode(',', array_fill(0, count($cases), '?'));
$verify = $pdo->prepare("
    SELECT COUNT(*)
    FROM field_opportunities
    WHERE external_work_order_number IN ({$placeholders})
");
$verify->execute(array_column($cases, 'number'));

$assert(
    (int)$verify->fetchColumn() === 0,
    'cleanup removed every synthetic opportunity'
);

if ($failures > 0) {
    fwrite(
        STDERR,
        "FIELD OPS OPPORTUNITY EXPIRATION SMOKE FAILED: {$failures} assertion(s).\n"
    );
    exit(1);
}

echo PHP_EOL, 'FIELD OPS OPPORTUNITY EXPIRATION SMOKE PASSED', PHP_EOL;
