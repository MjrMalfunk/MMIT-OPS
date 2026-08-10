<?php
declare(strict_types=1);

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
require_once dirname(__DIR__) . '/inc/bootstrap.php';
ob_end_clean();

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$expectedDatabase = trim((string)getenv('FIELD_VEHICLE_DATABASE'));
$actualDatabase = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

if ($expectedDatabase === '' || !hash_equals($expectedDatabase, $actualDatabase)) {
    throw new RuntimeException(
        "Database guard failed: expected {$expectedDatabase}; connected {$actualDatabase}."
    );
}

echo "=== ACCOUNTING BUSINESS-LINE MIGRATION ===\n";
echo "Database: {$actualDatabase}\n";

function accounting_business_line_column_exists(
    PDO $pdo,
    string $table,
    string $column
): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, $column]);

    return (int)$statement->fetchColumn() > 0;
}

function accounting_business_line_index_exists(
    PDO $pdo,
    string $table,
    string $index
): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?'
    );
    $statement->execute([$table, $index]);

    return (int)$statement->fetchColumn() > 0;
}

function accounting_business_line_fk_exists(
    PDO $pdo,
    string $table,
    string $constraint
): bool {
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $statement->execute([$table, $constraint]);

    return (int)$statement->fetchColumn() > 0;
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS business_line (
        business_line_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_line_code VARCHAR(32) NOT NULL,
        business_line_name VARCHAR(100) NOT NULL,
        sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (business_line_id),
        UNIQUE KEY uq_business_line_code (business_line_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "business_line table: READY\n";

$seed = $pdo->prepare(
    'INSERT INTO business_line (
        business_line_code,
        business_line_name,
        sort_order,
        is_active
     ) VALUES (?, ?, ?, 1)
     ON DUPLICATE KEY UPDATE
        business_line_name = VALUES(business_line_name),
        sort_order = VALUES(sort_order),
        is_active = 1'
);

$businessLines = [
    ['MMIT', 'Midwest Managed IT', 10],
    ['FIELD_NATION', 'Field Nation', 20],
    ['LYFT', 'Lyft', 30],
    ['SHARED', 'Shared / General', 40],
];

foreach ($businessLines as [$code, $name, $sortOrder]) {
    $seed->execute([$code, $name, $sortOrder]);
}
echo "business-line seeds: READY\n";

if (!accounting_business_line_column_exists($pdo, 'expense', 'business_line_id')) {
    $pdo->exec(
        'ALTER TABLE expense
         ADD COLUMN business_line_id INT UNSIGNED NULL'
    );
}

if (!accounting_business_line_index_exists(
    $pdo,
    'expense',
    'idx_expense_business_line_id'
)) {
    $pdo->exec(
        'ALTER TABLE expense
         ADD INDEX idx_expense_business_line_id (business_line_id)'
    );
}

if (!accounting_business_line_fk_exists(
    $pdo,
    'expense',
    'fk_expense_business_line'
)) {
    $pdo->exec(
        'ALTER TABLE expense
         ADD CONSTRAINT fk_expense_business_line
         FOREIGN KEY (business_line_id)
         REFERENCES business_line (business_line_id)
         ON UPDATE CASCADE
         ON DELETE RESTRICT'
    );
}
echo "expense.business_line_id: READY\n";

if (!accounting_business_line_column_exists(
    $pdo,
    'gl_journal_line',
    'business_line_id'
)) {
    $pdo->exec(
        'ALTER TABLE gl_journal_line
         ADD COLUMN business_line_id INT UNSIGNED NULL'
    );
}

if (!accounting_business_line_index_exists(
    $pdo,
    'gl_journal_line',
    'idx_gl_journal_line_business_line_id'
)) {
    $pdo->exec(
        'ALTER TABLE gl_journal_line
         ADD INDEX idx_gl_journal_line_business_line_id (business_line_id)'
    );
}

if (!accounting_business_line_fk_exists(
    $pdo,
    'gl_journal_line',
    'fk_gl_journal_line_business_line'
)) {
    $pdo->exec(
        'ALTER TABLE gl_journal_line
         ADD CONSTRAINT fk_gl_journal_line_business_line
         FOREIGN KEY (business_line_id)
         REFERENCES business_line (business_line_id)
         ON UPDATE CASCADE
         ON DELETE RESTRICT'
    );
}
echo "gl_journal_line.business_line_id: READY\n";

$ids = $pdo->query(
    'SELECT business_line_code, business_line_id
     FROM business_line'
)->fetchAll(PDO::FETCH_KEY_PAIR);

foreach (['FIELD_NATION', 'LYFT'] as $requiredCode) {
    if (empty($ids[$requiredCode])) {
        throw new RuntimeException(
            "Seed verification failed for {$requiredCode}."
        );
    }
}

$sourceMap = [
    'FIELD_WORK_ORDER' => (int)$ids['FIELD_NATION'],
    'RIDESHARE_SHIFT' => (int)$ids['LYFT'],
];

$conflictStatement = $pdo->prepare(
    'SELECT COUNT(*)
     FROM gl_journal_line AS line
     INNER JOIN gl_journal AS journal
        ON journal.journal_id = line.journal_id
     WHERE journal.source_type = ?
       AND line.business_line_id IS NOT NULL
       AND line.business_line_id <> ?'
);

$backfillStatement = $pdo->prepare(
    'UPDATE gl_journal_line AS line
     INNER JOIN gl_journal AS journal
        ON journal.journal_id = line.journal_id
     SET line.business_line_id = ?
     WHERE journal.source_type = ?
       AND line.business_line_id IS NULL'
);

foreach ($sourceMap as $sourceType => $businessLineId) {
    $conflictStatement->execute([$sourceType, $businessLineId]);
    $conflicts = (int)$conflictStatement->fetchColumn();

    if ($conflicts !== 0) {
        throw new RuntimeException(
            "Backfill refused: {$sourceType} has {$conflicts} conflicting line(s)."
        );
    }

    $backfillStatement->execute([$businessLineId, $sourceType]);
    echo sprintf(
        "%s backfill: %d line(s) updated\n",
        $sourceType,
        $backfillStatement->rowCount()
    );
}

$verification = $pdo->query(
    "SELECT journal.source_type,
            business.business_line_code,
            COUNT(*) AS line_count,
            SUM(line.debit_amount) AS debits,
            SUM(line.credit_amount) AS credits
     FROM gl_journal AS journal
     INNER JOIN gl_journal_line AS line
        ON line.journal_id = journal.journal_id
     LEFT JOIN business_line AS business
        ON business.business_line_id = line.business_line_id
     WHERE journal.source_type IN ('FIELD_WORK_ORDER', 'RIDESHARE_SHIFT')
     GROUP BY journal.source_type, business.business_line_code
     ORDER BY journal.source_type, business.business_line_code"
)->fetchAll(PDO::FETCH_ASSOC);

echo "=== SOURCE BACKFILL VERIFICATION ===\n";

foreach ($verification as $row) {
    echo implode(' | ', [
        'source=' . (string)$row['source_type'],
        'line=' . ((string)($row['business_line_code'] ?? '') ?: 'NULL'),
        'lines=' . (string)$row['line_count'],
        'debits=' . (string)$row['debits'],
        'credits=' . (string)$row['credits'],
    ]) . "\n";
}

$missing = (int)$pdo->query(
    "SELECT COUNT(*)
     FROM gl_journal_line AS line
     INNER JOIN gl_journal AS journal
        ON journal.journal_id = line.journal_id
     WHERE journal.source_type IN ('FIELD_WORK_ORDER', 'RIDESHARE_SHIFT')
       AND line.business_line_id IS NULL"
)->fetchColumn();

if ($missing !== 0) {
    throw new RuntimeException(
        "Verification failed: {$missing} source-posted line(s) remain unclassified."
    );
}

$classifiedExpenses = (int)$pdo->query(
    'SELECT COUNT(*) FROM expense WHERE business_line_id IS NOT NULL'
)->fetchColumn();

echo "Classified historical expenses: {$classifiedExpenses}\n";
echo "Ambiguous historical expenses were not guessed.\n";
echo "ACCOUNTING BUSINESS-LINE MIGRATION PASSED\n";
