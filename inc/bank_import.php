<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function accounting_bank_import_ready(): bool
{
    return db_table_exists('bank_import_batch')
        && db_table_exists('bank_import_transaction');
}

function accounting_bank_import_normalize_description(
    string $description
): string {
    $description = strtoupper(trim($description));
    $description = preg_replace('/\s+/', ' ', $description) ?? '';
    return substr($description, 0, 255);
}

function accounting_bank_import_parse_amount(string $raw): ?float
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    $negativeParentheses =
        str_starts_with($raw, '(') && str_ends_with($raw, ')');

    $clean = str_replace(
        ['$', ',', ' ', '+', '(', ')'],
        '',
        $raw
    );

    if (
        $clean === ''
        || !preg_match('/^-?\d+(?:\.\d{1,2})?$/', $clean)
    ) {
        return null;
    }

    $amount = round((float)$clean, 2);

    if ($negativeParentheses) {
        $amount = -abs($amount);
    }

    return $amount;
}

function accounting_bank_import_parse_date(string $raw): ?string
{
    $raw = trim($raw);

    foreach (['!m/d/Y', '!n/j/Y', '!Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $raw);

        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function accounting_bank_import_suggestion(
    string $description,
    float $amount
): array {
    $description = accounting_bank_import_normalize_description(
        $description
    );

    $result = [
        'classification' => 'UNCLASSIFIED',
        'account_code' => null,
        'reason' => 'Manual review required.',
        'confidence' => 0.0,
    ];

    if (str_contains($description, 'TRANSFER FROM')) {
        return [
            'classification' => 'TRANSFER_REVIEW',
            'account_code' => null,
            'reason' => 'Bank transfer—not business income.',
            'confidence' => 1.0,
        ];
    }

    if (
        str_contains($description, 'WITHDRAWAL')
        || str_contains($description, 'TRANSFER TO')
    ) {
        return [
            'classification' => 'OUTFLOW_REVIEW',
            'account_code' => '3100',
            'reason' =>
                'Possible owner draw or transfer; confirm before posting.',
            'confidence' => 0.35,
        ];
    }

    if (
        $amount > 0
        && str_contains($description, 'FRASER ENGINE REBUILD')
    ) {
        return [
            'classification' => 'REFUND_REVIEW',
            'account_code' => null,
            'reason' =>
                'Vendor refund; match against the original engine cost.',
            'confidence' => 1.0,
        ];
    }

    if ($amount > 0 && str_contains($description, 'STRIPE')) {
        return [
            'classification' => 'STRIPE_REVIEW',
            'account_code' => '1010',
            'reason' =>
                'Stripe deposit; match against payment/clearing activity.',
            'confidence' => 0.75,
        ];
    }

    if (str_contains($description, 'OVERDRAFT')) {
        return [
            'classification' => 'BANK_FEE',
            'account_code' => '5071',
            'reason' => 'Bank overdraft fee.',
            'confidence' => 0.9,
        ];
    }

    foreach ([
        'SYNCRO',
        'CHATGPT',
        'OPENAI',
        'MICROSOFT',
        'CANVA',
        'N-ABLE',
        'NABLE',
    ] as $vendor) {
        if (str_contains($description, $vendor)) {
            return [
                'classification' => 'SOFTWARE',
                'account_code' => '5010',
                'reason' => 'Recognized software/service vendor.',
                'confidence' => 0.95,
            ];
        }
    }

    if (str_contains($description, 'COMCAST BUSINESS MOBIL')) {
        return [
            'classification' => 'VOICE_COMMUNICATION',
            'account_code' => '5121',
            'reason' => 'Recognized business mobile service.',
            'confidence' => 0.95,
        ];
    }

    if (
        str_contains($description, 'COMCAST')
        || str_contains($description, 'XFINITY')
    ) {
        return [
            'classification' => 'INTERNET',
            'account_code' => '5120',
            'reason' => 'Recognized internet provider.',
            'confidence' => 0.9,
        ];
    }

    if (str_contains($description, 'SCOUTDNS')) {
        return [
            'classification' => 'WEB_SECURITY',
            'account_code' => '5124',
            'reason' => 'Recognized DNS/security provider.',
            'confidence' => 0.95,
        ];
    }

    return $result;
}

function accounting_bank_import_parse_pnc_csv(
    string $path,
    int $accountId
): array {
    if ($accountId <= 0) {
        return [
            'ok' => false,
            'errors' => ['Choose a valid bank account.'],
        ];
    }

    $handle = @fopen($path, 'rb');

    if ($handle === false) {
        return [
            'ok' => false,
            'errors' => ['Unable to read the CSV file.'],
        ];
    }

    try {
        $header = fgetcsv($handle);

        if (!is_array($header)) {
            return [
                'ok' => false,
                'errors' => ['The CSV file is empty.'],
            ];
        }

        $header = array_map(
            static function ($value): string {
                $value = trim((string)$value);
                return preg_replace('/^\xEF\xBB\xBF/', '', $value)
                    ?? $value;
            },
            $header
        );

        $expected = [
            'Transaction Date',
            'Transaction Description',
            'Amount',
        ];

        if ($header !== $expected) {
            return [
                'ok' => false,
                'errors' => [
                    'Unexpected CSV columns. Expected: '
                    . implode(', ', $expected),
                ],
            ];
        }

        $rows = [];
        $occurrences = [];
        $rowNumber = 1;
        $creditTotal = 0.0;
        $debitTotal = 0.0;
        $dates = [];

        while (($columns = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (
                count($columns) === 1
                && trim((string)$columns[0]) === ''
            ) {
                continue;
            }

            if (count($columns) !== 3) {
                return [
                    'ok' => false,
                    'errors' => [
                        "CSV row {$rowNumber} does not have 3 columns.",
                    ],
                ];
            }

            $date = accounting_bank_import_parse_date(
                (string)$columns[0]
            );
            $description = trim((string)$columns[1]);
            $normalized =
                accounting_bank_import_normalize_description(
                    $description
                );
            $amount = accounting_bank_import_parse_amount(
                (string)$columns[2]
            );

            if ($date === null) {
                return [
                    'ok' => false,
                    'errors' => [
                        "CSV row {$rowNumber} has an invalid date.",
                    ],
                ];
            }

            if ($description === '') {
                return [
                    'ok' => false,
                    'errors' => [
                        "CSV row {$rowNumber} has no description.",
                    ],
                ];
            }

            if ($amount === null || abs($amount) < 0.005) {
                return [
                    'ok' => false,
                    'errors' => [
                        "CSV row {$rowNumber} has an invalid amount.",
                    ],
                ];
            }

            $cents = (int)round($amount * 100);
            $occurrenceKey = implode('|', [
                $date,
                $cents,
                $normalized,
            ]);
            $occurrences[$occurrenceKey] =
                ($occurrences[$occurrenceKey] ?? 0) + 1;
            $ordinal = $occurrences[$occurrenceKey];

            $fingerprint = hash('sha256', implode('|', [
                'PNC-CSV-V1',
                $accountId,
                $date,
                $cents,
                $normalized,
                $ordinal,
            ]));

            $suggestion = accounting_bank_import_suggestion(
                $description,
                $amount
            );

            if ($amount > 0) {
                $creditTotal += $amount;
            } else {
                $debitTotal += abs($amount);
            }

            $dates[] = $date;
            $rows[] = [
                'row_number' => $rowNumber,
                'transaction_date' => $date,
                'description_raw' => $description,
                'description_normalized' => $normalized,
                'signed_amount' => round($amount, 2),
                'direction' => $amount > 0 ? 'CREDIT' : 'DEBIT',
                'occurrence_ordinal' => $ordinal,
                'fingerprint' => $fingerprint,
                'classification' =>
                    $suggestion['classification'],
                'suggested_account_code' =>
                    $suggestion['account_code'],
                'suggestion_reason' => $suggestion['reason'],
                'suggestion_confidence' =>
                    $suggestion['confidence'],
            ];
        }

        if ($rows === []) {
            return [
                'ok' => false,
                'errors' => ['The CSV contains no transactions.'],
            ];
        }

        sort($dates);

        return [
            'ok' => true,
            'rows' => $rows,
            'transaction_count' => count($rows),
            'period_start_date' => $dates[0],
            'period_end_date' => $dates[count($dates) - 1],
            'credit_total' => round($creditTotal, 2),
            'debit_total' => round($debitTotal, 2),
            'net_total' => round(
                $creditTotal - $debitTotal,
                2
            ),
        ];
    } finally {
        fclose($handle);
    }
}

function accounting_bank_import_account_id_by_code(
    string $accountCode
): ?int {
    $statement = db()->prepare("
        SELECT account_id
        FROM gl_account
        WHERE account_code = ?
          AND is_active = 1
        LIMIT 1
    ");
    $statement->execute([$accountCode]);
    $value = $statement->fetchColumn();

    return $value === false ? null : (int)$value;
}

function accounting_bank_import_legal_business_name(): string
{
    return 'LnK Consulting LLC dba Midwest Managed IT';
}

function accounting_bank_import_review_account_options(): array
{
    return db()->query("
        SELECT account_id,
               account_code,
               account_name,
               account_type
        FROM gl_account
        WHERE is_active = 1
        ORDER BY FIELD(
            account_type,
            'EXPENSE',
            'INCOME',
            'EQUITY',
            'ASSET',
            'LIABILITY'
        ),
        account_code,
        account_name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

function accounting_bank_import_duplicate_rows(
    int $accountId,
    array $rows
): array {
    if ($accountId <= 0 || !$rows) {
        return [];
    }

    $statement = db()->prepare("
        SELECT t.bank_transaction_id,
               t.batch_id,
               t.transaction_date,
               t.description_raw,
               t.signed_amount,
               b.status AS batch_status
        FROM bank_import_transaction t
        INNER JOIN bank_import_batch b
            ON b.batch_id = t.batch_id
        WHERE t.account_id = ?
          AND t.fingerprint = ?
        LIMIT 1
    ");

    $duplicates = [];

    foreach ($rows as $row) {
        $fingerprint = (string)($row['fingerprint'] ?? '');

        if ($fingerprint === '') {
            continue;
        }

        $statement->execute([
            $accountId,
            $fingerprint,
        ]);
        $match = $statement->fetch(PDO::FETCH_ASSOC);

        if ($match) {
            $duplicates[] = $match;
        }
    }

    return $duplicates;
}

function accounting_bank_import_sequence_errors(
    int $accountId,
    string $statementType,
    string $statementEndingDate,
    float $openingBalance,
    ?float $endingBalance,
    array $parsed
): array {
    $errors = [];
    $statementType = strtoupper(trim($statementType));

    if (!in_array($statementType, ['CLOSED', 'CURRENT'], true)) {
        return ['Choose Closed statement or Current activity.'];
    }

    $parsedDate = accounting_bank_import_parse_date(
        $statementEndingDate
    );

    if (
        $parsedDate === null
        || $parsedDate !== $statementEndingDate
    ) {
        return ['Enter a valid statement ending/as-of date.'];
    }

    if (
        !empty($parsed['period_end_date'])
        && (string)$parsed['period_end_date']
            > $statementEndingDate
    ) {
        $errors[] =
            'The CSV contains activity after the statement '
            . 'ending/as-of date.';
    }

    $sameDate = db()->prepare("
        SELECT batch_id
        FROM bank_import_batch
        WHERE account_id = ?
          AND statement_ending_date = ?
          AND status <> 'VOID'
        LIMIT 1
    ");
    $sameDate->execute([
        $accountId,
        $statementEndingDate,
    ]);
    $sameDateBatch = $sameDate->fetchColumn();

    if ($sameDateBatch !== false) {
        $errors[] =
            'Batch #'
            . (int)$sameDateBatch
            . ' already uses this statement ending/as-of date.';
    }

    if ($statementType === 'CLOSED' && $endingBalance === null) {
        $errors[] =
            'A closed statement requires its ending balance.';
    }

    if ($statementType === 'CURRENT') {
        $current = db()->prepare("
            SELECT batch_id
            FROM bank_import_batch
            WHERE account_id = ?
              AND statement_type = 'CURRENT'
              AND status <> 'VOID'
            LIMIT 1
        ");
        $current->execute([$accountId]);
        $currentBatch = $current->fetchColumn();

        if ($currentBatch !== false) {
            $errors[] =
                'Batch #'
                . (int)$currentBatch
                . ' is already the open/current import for '
                . 'this account.';
        }
    }

    $previous = db()->prepare("
        SELECT batch_id,
               statement_ending_date,
               ending_balance
        FROM bank_import_batch
        WHERE account_id = ?
          AND statement_type = 'CLOSED'
          AND status <> 'VOID'
          AND statement_ending_date < ?
        ORDER BY statement_ending_date DESC,
                 batch_id DESC
        LIMIT 1
    ");
    $previous->execute([
        $accountId,
        $statementEndingDate,
    ]);
    $previousBatch = $previous->fetch(PDO::FETCH_ASSOC);

    if ($previousBatch) {
        $previousDate =
            (string)$previousBatch['statement_ending_date'];
        $distance = (
            strtotime($statementEndingDate)
            - strtotime($previousDate)
        ) / 86400;

        /*
         * Enforce continuity for adjacent monthly statements.
         * A wider gap is allowed while earlier history is being
         * backfilled around the existing June preview.
         */
        if (
            $distance <= 40
            && $previousBatch['ending_balance'] !== null
            && abs(
                (float)$previousBatch['ending_balance']
                - $openingBalance
            ) > 0.009
        ) {
            $errors[] = sprintf(
                'Opening balance $%.2f does not match Batch #%d '
                . 'ending balance $%.2f.',
                $openingBalance,
                (int)$previousBatch['batch_id'],
                (float)$previousBatch['ending_balance']
            );
        }
    }

    if (
        $statementType === 'CLOSED'
        && $endingBalance !== null
    ) {
        $next = db()->prepare("
            SELECT batch_id,
                   statement_ending_date,
                   opening_balance
            FROM bank_import_batch
            WHERE account_id = ?
              AND statement_type = 'CLOSED'
              AND status <> 'VOID'
              AND statement_ending_date > ?
            ORDER BY statement_ending_date,
                     batch_id
            LIMIT 1
        ");
        $next->execute([
            $accountId,
            $statementEndingDate,
        ]);
        $nextBatch = $next->fetch(PDO::FETCH_ASSOC);

        if ($nextBatch) {
            $nextDate =
                (string)$nextBatch['statement_ending_date'];
            $distance = (
                strtotime($nextDate)
                - strtotime($statementEndingDate)
            ) / 86400;

            if (
                $distance <= 40
                && $nextBatch['opening_balance'] !== null
                && abs(
                    (float)$nextBatch['opening_balance']
                    - $endingBalance
                ) > 0.009
            ) {
                $errors[] = sprintf(
                    'Ending balance $%.2f does not match Batch '
                    . '#%d opening balance $%.2f.',
                    $endingBalance,
                    (int)$nextBatch['batch_id'],
                    (float)$nextBatch['opening_balance']
                );
            }
        }
    }

    return $errors;
}

function accounting_bank_import_save_review(
    int $transactionId,
    int $batchId,
    string $classification,
    string $reviewStatus,
    string $settlementStatus,
    ?int $selectedAccountId,
    string $notes
): array {
    $classification = strtoupper(trim($classification));
    $reviewStatus = strtoupper(trim($reviewStatus));
    $settlementStatus = strtoupper(trim($settlementStatus));
    $notes = trim($notes);

    if ($transactionId <= 0 || $batchId <= 0) {
        return [
            'ok' => false,
            'errors' => ['Invalid imported transaction.'],
        ];
    }

    $transaction = db()->prepare("
        SELECT t.account_id,
               b.status AS batch_status
        FROM bank_import_transaction t
        INNER JOIN bank_import_batch b
            ON b.batch_id = t.batch_id
        WHERE t.bank_transaction_id = ?
          AND t.batch_id = ?
        LIMIT 1
    ");
    $transaction->execute([$transactionId, $batchId]);
    $transactionRow = $transaction->fetch(PDO::FETCH_ASSOC);

    if (!$transactionRow) {
        return [
            'ok' => false,
            'errors' => ['Invalid imported transaction.'],
        ];
    }

    if ((string)$transactionRow['batch_status'] !== 'PREVIEW') {
        return [
            'ok' => false,
            'errors' => [
                'Transaction reviews are locked after batch approval.',
            ],
        ];
    }

    if (
        $classification === ''
        || strlen($classification) > 50
        || !preg_match(
            '/^[A-Z][A-Z0-9_]*$/',
            $classification
        )
    ) {
        return [
            'ok' => false,
            'errors' => ['Choose a valid transaction treatment.'],
        ];
    }

    if (
        !in_array(
            $reviewStatus,
            ['UNREVIEWED', 'READY', 'IGNORED'],
            true
        )
    ) {
        return [
            'ok' => false,
            'errors' => ['Choose a valid review status.'],
        ];
    }

    if (
        !in_array(
            $settlementStatus,
            ['POSTED', 'PENDING'],
            true
        )
    ) {
        return [
            'ok' => false,
            'errors' => ['Choose a valid bank settlement status.'],
        ];
    }

    if ($settlementStatus === 'PENDING') {
        $reviewStatus = 'UNREVIEWED';
    }

    if ($selectedAccountId !== null) {
        $account = db()->prepare("
            SELECT account_id
            FROM gl_account
            WHERE account_id = ?
              AND is_active = 1
            LIMIT 1
        ");
        $account->execute([$selectedAccountId]);

        if ($account->fetchColumn() === false) {
            return [
                'ok' => false,
                'errors' => [
                    'The selected accounting account is invalid.',
                ],
            ];
        }

        if ($selectedAccountId === (int)$transactionRow['account_id']) {
            return [
                'ok' => false,
                'errors' => [
                    'Choose the offsetting accounting account, not the '
                    . 'bank account being imported.',
                ],
            ];
        }
    }

    if (
        $reviewStatus === 'READY'
        && $selectedAccountId === null
    ) {
        return [
            'ok' => false,
            'errors' => [
                'Choose an accounting account before marking '
                . 'the transaction Ready.',
            ],
        ];
    }

    $statement = db()->prepare("
        UPDATE bank_import_transaction t
        INNER JOIN bank_import_batch b
            ON b.batch_id = t.batch_id
        SET t.classification = ?,
            t.review_status = ?,
            t.settlement_status = ?,
            t.selected_account_id = ?,
            t.notes = ?
        WHERE t.bank_transaction_id = ?
          AND t.batch_id = ?
          AND b.status = 'PREVIEW'
          AND t.review_status <> 'POSTED'
          AND t.posted_journal_id IS NULL
        LIMIT 1
    ");
    $statement->execute([
        $classification,
        $reviewStatus,
        $settlementStatus,
        $selectedAccountId,
        $notes !== '' ? $notes : null,
        $transactionId,
        $batchId,
    ]);

    if ($statement->rowCount() !== 1) {
        return [
            'ok' => false,
            'errors' => [
                'The transaction was not updated. It may already '
                . 'be posted or may not belong to this batch.',
            ],
        ];
    }

    return ['ok' => true];
}

function accounting_bank_import_preflight(
    int $batchId,
    string $requiredStatus = 'PREVIEW',
    ?PDO $pdo = null,
    bool $lockRows = false
): array {
    $requiredStatus = strtoupper(trim($requiredStatus));

    if (
        $batchId <= 0
        || !in_array($requiredStatus, ['PREVIEW', 'READY'], true)
        || !accounting_bank_import_ready()
    ) {
        return [
            'ok' => false,
            'errors' => ['Invalid bank import batch.'],
        ];
    }

    $pdo = $pdo ?? db();
    $lockSql = $lockRows ? ' FOR UPDATE' : '';
    $batchStatement = $pdo->prepare("
        SELECT b.*,
               a.is_active AS bank_account_active
        FROM bank_import_batch b
        INNER JOIN gl_account a
            ON a.account_id = b.account_id
        WHERE b.batch_id = ?
        LIMIT 1{$lockSql}
    ");
    $batchStatement->execute([$batchId]);
    $batch = $batchStatement->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        return [
            'ok' => false,
            'errors' => ['Bank import batch not found.'],
        ];
    }

    $transactionStatement = $pdo->prepare("
        SELECT t.*,
               selected.is_active AS selected_account_active
        FROM bank_import_transaction t
        LEFT JOIN gl_account selected
            ON selected.account_id = t.selected_account_id
        WHERE t.batch_id = ?
        ORDER BY t.bank_transaction_id{$lockSql}
    ");
    $transactionStatement->execute([$batchId]);
    $transactions = $transactionStatement->fetchAll(PDO::FETCH_ASSOC);
    $errors = [];
    $readyCount = 0;
    $ignoredCount = 0;

    if ((string)$batch['statement_type'] !== 'CLOSED') {
        $errors[] = 'Current/open activity batches cannot be approved or posted.';
    }

    if ((string)$batch['status'] !== $requiredStatus) {
        $errors[] = sprintf(
            'Batch #%d must be %s for this action; it is currently %s.',
            $batchId,
            $requiredStatus,
            (string)$batch['status']
        );
    }

    if ((int)$batch['bank_account_active'] !== 1) {
        $errors[] = 'The imported bank account is inactive.';
    }

    if (
        $batch['opening_balance'] === null
        || $batch['ending_balance'] === null
        || $batch['statement_ending_date'] === null
    ) {
        $errors[] = 'Closed-statement dates and balances are incomplete.';
    } elseif (
        abs(
            round(
                (float)$batch['opening_balance']
                + (float)$batch['net_total'],
                2
            ) - (float)$batch['ending_balance']
        ) > 0.009
    ) {
        $errors[] = 'The statement opening balance, activity, and ending balance do not reconcile.';
    }

    if (count($transactions) !== (int)$batch['transaction_count']) {
        $errors[] = 'The stored transaction count does not match the batch header.';
    }

    foreach ($transactions as $transaction) {
        $transactionId = (int)$transaction['bank_transaction_id'];
        $reviewStatus = (string)$transaction['review_status'];

        if ((int)$transaction['account_id'] !== (int)$batch['account_id']) {
            $errors[] = "Transaction #{$transactionId} belongs to a different bank account.";
        }

        if (!empty($transaction['posted_journal_id'])) {
            $errors[] = "Transaction #{$transactionId} is already linked to a journal.";
        }

        if ((string)$transaction['settlement_status'] !== 'POSTED') {
            $errors[] = "Transaction #{$transactionId} is still pending at the bank.";
        }

        $signedAmount = round((float)$transaction['signed_amount'], 2);
        $expectedDirection = $signedAmount >= 0 ? 'CREDIT' : 'DEBIT';

        if (abs($signedAmount) < 0.01) {
            $errors[] = "Transaction #{$transactionId} has no postable amount.";
        }

        if ((string)$transaction['direction'] !== $expectedDirection) {
            $errors[] = "Transaction #{$transactionId} has an inconsistent amount direction.";
        }

        if ($reviewStatus === 'IGNORED') {
            ++$ignoredCount;
            continue;
        }

        if ($reviewStatus !== 'READY') {
            $errors[] = "Transaction #{$transactionId} is not Ready or Ignored.";
            continue;
        }

        ++$readyCount;

        if (empty($transaction['selected_account_id'])) {
            $errors[] = "Transaction #{$transactionId} has no offsetting account.";
        } elseif (
            (int)$transaction['selected_account_id']
            === (int)$batch['account_id']
        ) {
            $errors[] = "Transaction #{$transactionId} uses the bank account as its own offset.";
        } elseif ((int)$transaction['selected_account_active'] !== 1) {
            $errors[] = "Transaction #{$transactionId} uses an inactive offsetting account.";
        }

    }

    if (!$transactions) {
        $errors[] = 'The batch contains no transactions.';
    }

    return [
        'ok' => !$errors,
        'errors' => array_values(array_unique($errors)),
        'batch' => $batch,
        'transactions' => $transactions,
        'ready_count' => $readyCount,
        'ignored_count' => $ignoredCount,
    ];
}

function accounting_bank_import_approve_batch(
    int $batchId,
    int $userId
): array {
    if ($userId <= 0) {
        return ['ok' => false, 'errors' => ['A valid approving user is required.']];
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();
        $preflight = accounting_bank_import_preflight(
            $batchId,
            'PREVIEW',
            $pdo,
            true
        );

        if (empty($preflight['ok'])) {
            $pdo->rollBack();
            return $preflight;
        }

        $statement = $pdo->prepare("
            UPDATE bank_import_batch
            SET status = 'READY',
                approved_by = ?,
                approved_at = NOW()
            WHERE batch_id = ?
              AND statement_type = 'CLOSED'
              AND status = 'PREVIEW'
            LIMIT 1
        ");
        $statement->execute([$userId, $batchId]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('The batch approval state changed before it could be saved.');
        }

        $pdo->commit();

        return [
            'ok' => true,
            'ready_count' => (int)$preflight['ready_count'],
            'ignored_count' => (int)$preflight['ignored_count'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'errors' => ['Unable to approve the batch: ' . $e->getMessage()],
        ];
    }
}

function accounting_bank_import_post_batch(
    int $batchId,
    int $userId
): array {
    if ($userId <= 0) {
        return ['ok' => false, 'errors' => ['A valid posting user is required.']];
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();
        $preflight = accounting_bank_import_preflight(
            $batchId,
            'READY',
            $pdo,
            true
        );

        if (empty($preflight['ok'])) {
            $pdo->rollBack();
            return $preflight;
        }

        $journal = $pdo->prepare("
            INSERT INTO gl_journal
                (journal_date, status, source_type, source_id,
                 reference_number, memo, posted_by)
            VALUES (?, 'POSTED', 'BANK_IMPORT', ?, ?, ?, ?)
        ");
        $line = $pdo->prepare("
            INSERT INTO gl_journal_line
                (journal_id, line_number, account_id,
                 debit_amount, credit_amount, line_memo)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $link = $pdo->prepare("
            UPDATE bank_import_transaction
            SET posted_journal_id = ?,
                review_status = 'POSTED'
            WHERE bank_transaction_id = ?
              AND batch_id = ?
              AND review_status = 'READY'
              AND posted_journal_id IS NULL
            LIMIT 1
        ");
        $journalIds = [];
        $bankAccountId = (int)$preflight['batch']['account_id'];

        foreach ($preflight['transactions'] as $transaction) {
            if ((string)$transaction['review_status'] !== 'READY') {
                continue;
            }

            $transactionId = (int)$transaction['bank_transaction_id'];
            $signedAmount = round((float)$transaction['signed_amount'], 2);
            $amount = abs($signedAmount);

            if ($amount < 0.01) {
                throw new RuntimeException(
                    "Transaction #{$transactionId} has no postable amount."
                );
            }

            $reference = sprintf('BANK-B%d-T%d', $batchId, $transactionId);
            $memo = trim((string)$transaction['description_raw']);
            $lineMemo = function_exists('mb_substr')
                ? mb_substr($memo, 0, 255)
                : substr($memo, 0, 255);
            $journal->execute([
                (string)$transaction['transaction_date'],
                $transactionId,
                $reference,
                $memo !== '' ? $memo : 'Bank statement transaction',
                $userId,
            ]);
            $journalId = (int)$pdo->lastInsertId();
            $offsetAccountId = (int)$transaction['selected_account_id'];

            if ($signedAmount > 0) {
                $line->execute([$journalId, 1, $bankAccountId, $amount, 0, 'Bank deposit']);
                $line->execute([$journalId, 2, $offsetAccountId, 0, $amount, $lineMemo]);
            } else {
                $line->execute([$journalId, 1, $offsetAccountId, $amount, 0, $lineMemo]);
                $line->execute([$journalId, 2, $bankAccountId, 0, $amount, 'Bank withdrawal']);
            }

            $link->execute([$journalId, $transactionId, $batchId]);

            if ($link->rowCount() !== 1) {
                throw new RuntimeException(
                    "Transaction #{$transactionId} could not be linked to its journal."
                );
            }

            $journalIds[] = $journalId;
        }

        $batchUpdate = $pdo->prepare("
            UPDATE bank_import_batch
            SET status = 'POSTED',
                posted_by = ?,
                posted_at = NOW()
            WHERE batch_id = ?
              AND statement_type = 'CLOSED'
              AND status = 'READY'
            LIMIT 1
        ");
        $batchUpdate->execute([$userId, $batchId]);

        if ($batchUpdate->rowCount() !== 1) {
            throw new RuntimeException('The batch posting state changed before it could be finalized.');
        }

        $pdo->commit();

        return [
            'ok' => true,
            'journal_count' => count($journalIds),
            'journal_ids' => $journalIds,
            'ignored_count' => (int)$preflight['ignored_count'],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return [
            'ok' => false,
            'errors' => ['Unable to post the batch: ' . $e->getMessage()],
        ];
    }
}

function accounting_bank_import_store_pnc_csv(
    string $path,
    string $originalFilename,
    int $accountId,
    int $userId,
    ?string $accountLast4 = null,
    string $statementType = 'CLOSED',
    ?string $statementEndingDate = null,
    ?float $openingBalance = null,
    ?float $endingBalance = null
): array {
    if (!accounting_bank_import_ready()) {
        return [
            'ok' => false,
            'errors' => ['Bank import tables are not installed.'],
        ];
    }

    if (!is_file($path) || !is_readable($path)) {
        return [
            'ok' => false,
            'errors' => ['The uploaded CSV cannot be read.'],
        ];
    }

    $fileHash = hash_file('sha256', $path);

    if (!is_string($fileHash) || $fileHash === '') {
        return [
            'ok' => false,
            'errors' => ['Unable to fingerprint the uploaded CSV.'],
        ];
    }

    $existing = db()->prepare("
        SELECT batch_id
        FROM bank_import_batch
        WHERE account_id = ?
          AND file_sha256 = ?
        LIMIT 1
    ");
    $existing->execute([$accountId, $fileHash]);
    $existingBatchId = $existing->fetchColumn();

    if ($existingBatchId !== false) {
        return [
            'ok' => false,
            'duplicate' => true,
            'batch_id' => (int)$existingBatchId,
            'errors' => [
                'This exact CSV file has already been imported.',
            ],
        ];
    }

    $parsed = accounting_bank_import_parse_pnc_csv(
        $path,
        $accountId
    );

    if (empty($parsed['ok'])) {
        return $parsed;
    }

    $statementType = strtoupper(trim($statementType));

    if ($statementEndingDate !== null) {
        if ($openingBalance === null) {
            return [
                'ok' => false,
                'errors' => [
                    'A statement opening balance is required.',
                ],
            ];
        }

        $sequenceErrors =
            accounting_bank_import_sequence_errors(
                $accountId,
                $statementType,
                $statementEndingDate,
                $openingBalance,
                $endingBalance,
                $parsed
            );

        if ($sequenceErrors) {
            return [
                'ok' => false,
                'errors' => $sequenceErrors,
            ];
        }
    }

    $duplicates = accounting_bank_import_duplicate_rows(
        $accountId,
        $parsed['rows']
    );

    if ($duplicates) {
        $first = $duplicates[0];

        return [
            'ok' => false,
            'duplicate' => true,
            'batch_id' => (int)$first['batch_id'],
            'errors' => [
                count($duplicates)
                . ' transaction(s) already exist, beginning with '
                . 'Batch #'
                . (int)$first['batch_id']
                . ': '
                . (string)$first['transaction_date']
                . ' · '
                . (string)$first['description_raw'],
            ],
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare("
            INSERT INTO bank_import_batch
              (account_id, source_bank, source_account_last4,
               statement_type,
               original_filename, file_sha256,
               period_start_date, period_end_date,
               statement_ending_date,
               opening_balance, ending_balance,
               credit_total, debit_total, net_total,
               transaction_count, status, imported_by)
            VALUES
              (?, 'PNC', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
               'PREVIEW', ?)
        ");
        $statement->execute([
            $accountId,
            $accountLast4 !== null
                ? substr(preg_replace('/\D/', '', $accountLast4), -4)
                : null,
            $statementType,
            substr(basename($originalFilename), 0, 255),
            $fileHash,
            $parsed['period_start_date'],
            $parsed['period_end_date'],
            $statementEndingDate,
            $openingBalance,
            $endingBalance,
            $parsed['credit_total'],
            $parsed['debit_total'],
            $parsed['net_total'],
            $parsed['transaction_count'],
            $userId > 0 ? $userId : null,
        ]);

        $batchId = (int)$pdo->lastInsertId();

        $insert = $pdo->prepare("
            INSERT INTO bank_import_transaction
              (batch_id, account_id, csv_row_number,
               transaction_date, description_raw,
               description_normalized, signed_amount, direction,
               occurrence_ordinal, fingerprint, classification,
               suggested_account_id, suggestion_reason,
               suggestion_confidence)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($parsed['rows'] as $row) {
            $suggestedAccountId = null;
            $suggestedCode =
                $row['suggested_account_code'] ?? null;

            if (is_string($suggestedCode) && $suggestedCode !== '') {
                $suggestedAccountId =
                    accounting_bank_import_account_id_by_code(
                        $suggestedCode
                    );
            }

            $insert->execute([
                $batchId,
                $accountId,
                $row['row_number'],
                $row['transaction_date'],
                $row['description_raw'],
                $row['description_normalized'],
                $row['signed_amount'],
                $row['direction'],
                $row['occurrence_ordinal'],
                $row['fingerprint'],
                $row['classification'],
                $suggestedAccountId,
                $row['suggestion_reason'],
                $row['suggestion_confidence'],
            ]);
        }

        $pdo->commit();

        return [
            'ok' => true,
            'batch_id' => $batchId,
            'parsed' => $parsed,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (
            str_contains(
                strtolower($e->getMessage()),
                'duplicate'
            )
        ) {
            return [
                'ok' => false,
                'duplicate' => true,
                'errors' => [
                    'One or more transactions were already imported.',
                ],
            ];
        }

        return [
            'ok' => false,
            'errors' => [
                'Unable to save the import batch: '
                . $e->getMessage(),
            ],
        ];
    }
}

function accounting_bank_import_get_batch(int $batchId): ?array
{
    if ($batchId <= 0 || !accounting_bank_import_ready()) {
        return null;
    }

    $statement = db()->prepare("
        SELECT b.*,
               a.account_code,
               a.account_name
        FROM bank_import_batch b
        INNER JOIN gl_account a
            ON a.account_id = b.account_id
        WHERE b.batch_id = ?
        LIMIT 1
    ");
    $statement->execute([$batchId]);
    $row = $statement->fetch();

    return $row ?: null;
}

function accounting_bank_import_list_batches(int $limit = 20): array
{
    if (!accounting_bank_import_ready()) {
        return [];
    }

    $limit = max(1, min(100, $limit));

    return db()->query("
        SELECT b.*,
               a.account_code,
               a.account_name
        FROM bank_import_batch b
        INNER JOIN gl_account a
            ON a.account_id = b.account_id
        ORDER BY b.batch_id DESC
        LIMIT {$limit}
    ")->fetchAll();
}

function accounting_bank_import_transactions(int $batchId): array
{
    if ($batchId <= 0 || !accounting_bank_import_ready()) {
        return [];
    }

    $statement = db()->prepare("
        SELECT t.*,
               suggested.account_code
                   AS suggested_account_code,
               suggested.account_name
                   AS suggested_account_name,
               selected.account_code
                   AS selected_account_code,
               selected.account_name
                   AS selected_account_name
        FROM bank_import_transaction t
        LEFT JOIN gl_account suggested
            ON suggested.account_id = t.suggested_account_id
        LEFT JOIN gl_account selected
            ON selected.account_id = t.selected_account_id
        WHERE t.batch_id = ?
        ORDER BY t.csv_row_number
    ");
    $statement->execute([$batchId]);

    return $statement->fetchAll();
}
