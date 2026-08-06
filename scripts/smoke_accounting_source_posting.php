<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$_SERVER['HTTP_HOST'] = (string)(
    getenv('FIELD_VEHICLE_HOST') ?: 'ops-test.midwestmanagedit.com'
);
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
ob_end_clean();

require_once __DIR__ . '/../inc/accounting_source_posting.php';

$pdo = db();

$getAccountId = static function (PDO $pdo, string $code): int {
    $st = $pdo->prepare(
        'SELECT account_id
         FROM gl_account
         WHERE account_code = ?
           AND is_active = 1
         LIMIT 1'
    );
    $st->execute([$code]);

    $accountId = (int)$st->fetchColumn();

    if ($accountId <= 0) {
        throw new RuntimeException(
            "Required account {$code} was not found."
        );
    }

    return $accountId;
};

$lyftDirectId = $getAccountId($pdo, '1020');
$rideshareIncomeId = $getAccountId($pdo, '4110');
$sourceId = random_int(900000000, 999999999);

$pdo->beginTransaction();

try {
    $journal = [
        'journal_date' => date('Y-m-d'),
        'source_type' => 'RIDESHARE_SHIFT',
        'source_id' => $sourceId,
        'reference_number' => "SMOKE-{$sourceId}",
        'memo' => 'Rollback-only rideshare source posting test.',
        'posted_by' => null,
    ];

    $lines = [
        [
            'account_id' => $lyftDirectId,
            'debit_amount' => '12.34',
            'credit_amount' => '0.00',
            'line_memo' => 'Lyft Direct test debit.',
        ],
        [
            'account_id' => $rideshareIncomeId,
            'debit_amount' => '0.00',
            'credit_amount' => '12.34',
            'line_memo' => 'Rideshare income test credit.',
        ],
    ];

    $firstJournalId = accounting_post_source_journal(
        $pdo,
        $journal,
        $lines
    );

    $retryJournalId = accounting_post_source_journal(
        $pdo,
        $journal,
        $lines
    );

    if ($firstJournalId !== $retryJournalId) {
        throw new RuntimeException(
            'Retry created a different journal.'
        );
    }

    $check = $pdo->prepare(
        'SELECT
            COUNT(*) AS line_count,
            ROUND(SUM(debit_amount), 2) AS debit_total,
            ROUND(SUM(credit_amount), 2) AS credit_total
         FROM gl_journal_line
         WHERE journal_id = ?'
    );
    $check->execute([$firstJournalId]);
    $totals = $check->fetch(PDO::FETCH_ASSOC);

    if (
        (int)($totals['line_count'] ?? 0) !== 2
        || (string)($totals['debit_total'] ?? '') !== '12.34'
        || (string)($totals['credit_total'] ?? '') !== '12.34'
    ) {
        throw new RuntimeException(
            'Balanced journal totals were not preserved.'
        );
    }

    $unbalancedRejected = false;

    try {
        accounting_post_source_journal(
            $pdo,
            [
                'journal_date' => date('Y-m-d'),
                'source_type' => 'RIDESHARE_SHIFT',
                'source_id' => $sourceId + 1,
            ],
            [
                [
                    'account_id' => $lyftDirectId,
                    'debit_amount' => '10.00',
                    'credit_amount' => '0.00',
                ],
                [
                    'account_id' => $rideshareIncomeId,
                    'debit_amount' => '0.00',
                    'credit_amount' => '9.99',
                ],
            ]
        );
    } catch (InvalidArgumentException) {
        $unbalancedRejected = true;
    }

    if (!$unbalancedRejected) {
        throw new RuntimeException(
            'Unbalanced journal was not rejected.'
        );
    }

    $pdo->rollBack();

    $cleanup = $pdo->prepare(
        "SELECT COUNT(*)
         FROM gl_journal
         WHERE source_type = 'RIDESHARE_SHIFT'
           AND source_id IN (?, ?)"
    );
    $cleanup->execute([$sourceId, $sourceId + 1]);

    $remainingJournals = (int)$cleanup->fetchColumn();

    if ($remainingJournals !== 0) {
        throw new RuntimeException(
            "Rollback left {$remainingJournals} test journal(s)."
        );
    }

    echo "Database: "
        . $pdo->query('SELECT DATABASE()')->fetchColumn()
        . "\n";
    echo "Balanced journal: PASS\n";
    echo "Idempotent retry: PASS\n";
    echo "Unbalanced rejection: PASS\n";
    echo "Rollback cleanup: PASS\n";
    echo "ACCOUNTING SOURCE POSTING SMOKE PASSED\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'SMOKE FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
