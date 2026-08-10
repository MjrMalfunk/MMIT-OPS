<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$expectedHost = trim((string)getenv('FIELD_VEHICLE_HOST'));

if ($expectedHost === '') {
    throw new RuntimeException(
        'Host guard failed: FIELD_VEHICLE_HOST is required.'
    );
}

$_SERVER['HTTP_HOST'] = $expectedHost;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
ob_end_clean();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$expectedDatabase = trim((string)getenv('FIELD_VEHICLE_DATABASE'));
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

if ($expectedDatabase === '' || !hash_equals($expectedDatabase, $database)) {
    throw new RuntimeException(
        "Database guard failed: expected {$expectedDatabase}; connected {$database}."
    );
}

echo "Database: {$database}\n";

$columnExists = static function (
    PDO $pdo,
    string $table,
    string $column
): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?'
    );
    $st->execute([$table, $column]);

    return (int)$st->fetchColumn() > 0;
};

$indexExists = static function (
    PDO $pdo,
    string $table,
    string $index
): bool {
    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.statistics
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND index_name = ?'
    );
    $st->execute([$table, $index]);

    return (int)$st->fetchColumn() > 0;
};

echo "\n=== JOURNAL SOURCE TYPES ===\n";

$sourceColumn = $pdo->query(
    "SELECT column_type
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'gl_journal'
       AND column_name = 'source_type'
     LIMIT 1"
)->fetchColumn();

if (!is_string($sourceColumn) || $sourceColumn === '') {
    throw new RuntimeException('gl_journal.source_type was not found.');
}

if (
    !str_contains($sourceColumn, "'RIDESHARE_SHIFT'")
    || !str_contains($sourceColumn, "'FIELD_WORK_ORDER'")
) {
    $pdo->exec(
        "ALTER TABLE gl_journal
         MODIFY source_type ENUM(
             'EXPENSE',
             'INVOICE',
             'PAYMENT',
             'MANUAL',
             'ADJUSTMENT',
             'INVOICE_VOID',
             'PAYMENT_VOID',
             'PAYMENT_REFUND',
             'BANK_IMPORT',
             'RIDESHARE_SHIFT',
             'FIELD_WORK_ORDER'
         ) NOT NULL"
    );

    echo "Extended gl_journal.source_type.\n";
} else {
    echo "Journal source types already current.\n";
}

echo "\n=== SOURCE LINKS ===\n";

if (!$columnExists($pdo, 'field_rideshare_shifts', 'accounting_journal_id')) {
    $pdo->exec(
        "ALTER TABLE field_rideshare_shifts
         ADD COLUMN accounting_journal_id BIGINT UNSIGNED NULL
             AFTER payout_destination"
    );
    echo "Added field_rideshare_shifts.accounting_journal_id.\n";
} else {
    echo "Rideshare journal column already exists.\n";
}

if (
    !$indexExists(
        $pdo,
        'field_rideshare_shifts',
        'uq_rideshare_accounting_journal'
    )
) {
    $pdo->exec(
        "ALTER TABLE field_rideshare_shifts
         ADD UNIQUE KEY uq_rideshare_accounting_journal
             (accounting_journal_id)"
    );
    echo "Added rideshare journal uniqueness.\n";
}

if (!$columnExists($pdo, 'field_work_orders', 'accounting_journal_id')) {
    $pdo->exec(
        "ALTER TABLE field_work_orders
         ADD COLUMN accounting_journal_id BIGINT UNSIGNED NULL
             AFTER invoice_created_at"
    );
    echo "Added field_work_orders.accounting_journal_id.\n";
} else {
    echo "Field Ops journal column already exists.\n";
}

if (
    !$indexExists(
        $pdo,
        'field_work_orders',
        'uq_field_work_order_accounting_journal'
    )
) {
    $pdo->exec(
        "ALTER TABLE field_work_orders
         ADD UNIQUE KEY uq_field_work_order_accounting_journal
             (accounting_journal_id)"
    );
    echo "Added Field Ops journal uniqueness.\n";
}

echo "\n=== JOURNAL SOURCE UNIQUENESS ===\n";

if (!$indexExists($pdo, 'gl_journal', 'uq_gl_journal_source')) {
    $duplicate = $pdo->query(
        "SELECT source_type, source_id, COUNT(*) AS journal_count
         FROM gl_journal
         WHERE source_id IS NOT NULL
         GROUP BY source_type, source_id
         HAVING COUNT(*) > 1
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if ($duplicate) {
        throw new RuntimeException(
            'Cannot add journal-source uniqueness: duplicate '
            . (string)$duplicate['source_type']
            . ' source '
            . (string)$duplicate['source_id']
            . ' exists.'
        );
    }

    $pdo->exec(
        "ALTER TABLE gl_journal
         ADD UNIQUE KEY uq_gl_journal_source (
             source_type,
             source_id
         )"
    );

    echo "Added journal-source uniqueness.\n";
} else {
    echo "Journal-source uniqueness already present.\n";
}

echo "\n=== ACCOUNTS ===\n";

$accounts = [
    [
        '1020',
        'Lyft Direct',
        'ASSET',
        'Cash and cash equivalents',
        'Lyft Direct business debit-card balance.',
    ],
    [
        '1110',
        'FieldNation Receivable',
        'ASSET',
        'Accounts receivable',
        'Approved FieldNation earnings awaiting settlement.',
    ],
    [
        '4100',
        'Field Service Income',
        'INCOME',
        'Service income',
        'FieldNation and other field-service revenue.',
    ],
    [
        '4110',
        'Rideshare Income',
        'INCOME',
        'Service income',
        'Lyft and other rideshare revenue.',
    ],
];

$findByCode = $pdo->prepare(
    'SELECT account_id, account_name, account_type
     FROM gl_account
     WHERE account_code = ?
     LIMIT 1'
);

$findByName = $pdo->prepare(
    'SELECT account_id, account_code
     FROM gl_account
     WHERE account_name = ?
     LIMIT 1'
);

$insertAccount = $pdo->prepare(
    'INSERT INTO gl_account (
        account_code,
        account_name,
        account_type,
        detail_type,
        description,
        is_system,
        is_active
     ) VALUES (?, ?, ?, ?, ?, 1, 1)'
);

foreach ($accounts as [$code, $name, $type, $detail, $description]) {
    $findByCode->execute([$code]);
    $existing = $findByCode->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if (
            (string)$existing['account_name'] !== $name
            || (string)$existing['account_type'] !== $type
        ) {
            throw new RuntimeException(
                "Account {$code} exists with an unexpected name or type."
            );
        }

        echo "{$code} {$name}: already present.\n";
        continue;
    }

    $findByName->execute([$name]);
    $nameConflict = $findByName->fetch(PDO::FETCH_ASSOC);

    if ($nameConflict) {
        throw new RuntimeException(
            "{$name} already exists under account code "
            . (string)$nameConflict['account_code']
            . '.'
        );
    }

    $insertAccount->execute([
        $code,
        $name,
        $type,
        $detail,
        $description,
    ]);

    echo "{$code} {$name}: created.\n";
}

echo "\nACCOUNTING CONVERGENCE MIGRATION PASSED\n";
