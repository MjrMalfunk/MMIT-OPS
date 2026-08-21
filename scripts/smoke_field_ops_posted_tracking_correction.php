<?php
declare(strict_types=1);

$host = trim((string)getenv('FIELD_OPS_HOST'));
$database = trim((string)getenv('FIELD_OPS_DATABASE'));

if ($host === '' || $database === '') {
    throw new RuntimeException(
        'FIELD_OPS_HOST and FIELD_OPS_DATABASE are required.'
    );
}

$_SERVER['HTTP_HOST'] = $host;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';
ob_end_clean();

$pdo = db();

echo 'Database: ',
    (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    PHP_EOL;

field_ops_ensure_schema();

function smoke_assert(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException("FAIL: {$message}");
    }

    echo "PASS: {$message}\n";
}

$pdo->beginTransaction();

try {
    $account = $pdo->prepare("
        SELECT account_id
        FROM gl_account
        WHERE account_code = ?
        LIMIT 1
    ");

    $account->execute(['1110']);
    $receivableId = (int)$account->fetchColumn();

    $account->execute(['4100']);
    $incomeId = (int)$account->fetchColumn();

    smoke_assert(
        $receivableId > 0 && $incomeId > 0,
        'required Field Ops ledger accounts exist'
    );

    $pdo->prepare("
        INSERT INTO gl_journal (
            journal_date,
            status,
            source_type,
            source_id,
            reference_number,
            memo,
            posted_at,
            created_at,
            updated_at
        ) VALUES (
            CURDATE(),
            'POSTED',
            'FIELD_WORK_ORDER',
            0,
            'SMOKE-TRACKING-CORRECTION',
            'Synthetic posted tracking-correction smoke journal.',
            NOW(),
            NOW(),
            NOW()
        )
    ")->execute();

    $journalId = (int)$pdo->lastInsertId();

    $line = $pdo->prepare("
        INSERT INTO gl_journal_line (
            journal_id,
            line_number,
            account_id,
            debit_amount,
            credit_amount,
            line_memo,
            business_line_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $line->execute([
        $journalId,
        1,
        $receivableId,
        '123.45',
        '0.00',
        'Synthetic FieldNation receivable.',
        2,
    ]);

    $line->execute([
        $journalId,
        2,
        $incomeId,
        '0.00',
        '123.45',
        'Synthetic field service income.',
        2,
    ]);

    $pdo->prepare("
        INSERT INTO field_work_orders (
            platform,
            external_work_order_number,
            title,
            work_type,
            status,
            payment_status,
            gross_pay,
            platform_fee,
            insurance_fee,
            oai_fee,
            bonus_pay,
            reimbursement_amount,
            mileage,
            mileage_rate,
            drive_minutes,
            onsite_minutes,
            admin_minutes,
            accounting_journal_id,
            created_at,
            updated_at
        ) VALUES (
            'FieldNation',
            'SMOKE-POSTED-TRACKING',
            'Synthetic posted tracking correction',
            'Smoke test',
            'PAID',
            'PAID',
            123.45,
            12.35,
            2.41,
            0.62,
            0.00,
            0.00,
            10.00,
            0.6700,
            20,
            60,
            5,
            ?,
            NOW(),
            NOW()
        )
    ")->execute([$journalId]);

    $workOrderId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE gl_journal
        SET source_id = ?
        WHERE journal_id = ?
    ")->execute([$workOrderId, $journalId]);

    $journalBefore = $pdo->prepare("
        SELECT *
        FROM gl_journal
        WHERE journal_id = ?
    ");
    $journalBefore->execute([$journalId]);
    $journalBefore = $journalBefore->fetch(PDO::FETCH_ASSOC);

    $linesBefore = $pdo->prepare("
        SELECT *
        FROM gl_journal_line
        WHERE journal_id = ?
        ORDER BY journal_line_id
    ");
    $linesBefore->execute([$journalId]);
    $linesBefore = $linesBefore->fetchAll(PDO::FETCH_ASSOC);

    $result = field_ops_update_work_order_state([
        'work_order_id' => $workOrderId,
        'gross_pay' => '124.45',
    ], 999);

    smoke_assert(
        empty($result['ok']),
        'posted gross-pay change is rejected'
    );

    $row = field_ops_find_work_order($workOrderId);

    smoke_assert(
        abs((float)$row['gross_pay'] - 123.45) < 0.004,
        'rejected monetary change leaves gross unchanged'
    );

    $result = field_ops_update_work_order_state([
        'work_order_id' => $workOrderId,
        'mileage' => '20.00',
    ], 999);

    smoke_assert(
        empty($result['ok']),
        'posted mileage correction without a reason is rejected'
    );

    $row = field_ops_find_work_order($workOrderId);

    smoke_assert(
        abs((float)$row['mileage'] - 10.00) < 0.004,
        'reasonless correction leaves mileage unchanged'
    );

    $result = field_ops_update_work_order_state([
        'work_order_id' => $workOrderId,
        'mileage' => '20.00',
        'drive_minutes' => '40',
        'tracking_correction_reason' =>
            'Smoke test: original route was entered one way.',
    ], 999);

    smoke_assert(
        !empty($result['ok']),
        'posted tracking correction with a reason succeeds'
    );

    $row = field_ops_find_work_order($workOrderId);

    smoke_assert(
        abs((float)$row['mileage'] - 20.00) < 0.004,
        'mileage is corrected'
    );

    smoke_assert(
        (int)$row['drive_minutes'] === 40,
        'drive minutes are corrected'
    );

    $audit = $pdo->prepare("
        SELECT *
        FROM field_work_order_tracking_corrections
        WHERE work_order_id = ?
        ORDER BY correction_id
    ");
    $audit->execute([$workOrderId]);
    $auditRows = $audit->fetchAll(PDO::FETCH_ASSOC);

    smoke_assert(
        count($auditRows) === 1,
        'exactly one correction audit row is created'
    );

    $oldValues = json_decode(
        (string)$auditRows[0]['old_values'],
        true
    );
    $newValues = json_decode(
        (string)$auditRows[0]['new_values'],
        true
    );

    smoke_assert(
        abs((float)($oldValues['mileage'] ?? 0) - 10.00) < 0.004
        && abs((float)($newValues['mileage'] ?? 0) - 20.00) < 0.004,
        'audit records old and new mileage'
    );

    smoke_assert(
        (int)($oldValues['drive_minutes'] ?? 0) === 20
        && (int)($newValues['drive_minutes'] ?? 0) === 40,
        'audit records old and new drive minutes'
    );

    smoke_assert(
        (int)$auditRows[0]['corrected_by'] === 999,
        'audit records the correcting user'
    );

    smoke_assert(
        str_contains(
            (string)$auditRows[0]['reason'],
            'one way'
        ),
        'audit records the correction reason'
    );

    $journalAfter = $pdo->prepare("
        SELECT *
        FROM gl_journal
        WHERE journal_id = ?
    ");
    $journalAfter->execute([$journalId]);
    $journalAfter = $journalAfter->fetch(PDO::FETCH_ASSOC);

    $linesAfter = $pdo->prepare("
        SELECT *
        FROM gl_journal_line
        WHERE journal_id = ?
        ORDER BY journal_line_id
    ");
    $linesAfter->execute([$journalId]);
    $linesAfter = $linesAfter->fetchAll(PDO::FETCH_ASSOC);

    smoke_assert(
        $journalBefore === $journalAfter,
        'tracking correction leaves the journal unchanged'
    );

    smoke_assert(
        $linesBefore === $linesAfter,
        'tracking correction leaves every journal line unchanged'
    );

    $pdo->rollBack();

    echo "\nFIELD OPS POSTED TRACKING CORRECTION SMOKE PASSED\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    throw $exception;
}
