<?php
declare(strict_types=1);

/** Parse DECIMAL(12,2) without rounding, exponents, separators, or floats. */
function accounting_bank_import_cents(string $value): int
{
    if (PHP_INT_SIZE < 8 || !preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
        throw new InvalidArgumentException('Amounts must be decimal numbers with at most two fractional digits.');
    }
    $cents = (int)$parts[2] * 100 + (int)str_pad($parts[3] ?? '', 2, '0');
    return ($parts[1] === '-' ? -1 : 1) * $cents;
}

function accounting_bank_import_decimal(int $cents): string
{
    return ($cents < 0 ? '-' : '') . intdiv(abs($cents), 100) . '.' . str_pad((string)(abs($cents) % 100), 2, '0', STR_PAD_LEFT);
}

/** Split allocations are positive magnitudes; direction comes from the bank row. */
function accounting_bank_import_validate_splits(array $rows, string $signedAmount, int $bankAccountId): array
{
    if (count($rows) < 2 || count($rows) > 50) {
        throw new InvalidArgumentException('A split requires between 2 and 50 allocations.');
    }
    $total = 0;
    $normalized = [];
    foreach (array_values($rows) as $index => $row) {
        if (!is_array($row) || !is_scalar($row['account_id'] ?? null)
            || !preg_match('/^[1-9][0-9]*$/D', (string)$row['account_id'])
            || !is_string($row['split_amount'] ?? null) || !is_string($row['memo'] ?? '')) {
            throw new InvalidArgumentException('Each split needs an account, amount, and valid memo.');
        }
        $accountId = filter_var($row['account_id'], FILTER_VALIDATE_INT);
        $cents = accounting_bank_import_cents(trim($row['split_amount']));
        $memo = trim($row['memo'] ?? '');
        if ($accountId === false || $accountId === $bankAccountId || $cents <= 0) {
            throw new InvalidArgumentException('Split amounts must be positive and use offsetting accounts.');
        }
        if (!preg_match('//u', $memo) || (function_exists('mb_strlen') ? mb_strlen($memo, 'UTF-8') : preg_match_all('/./us', $memo)) > 255) {
            throw new InvalidArgumentException('Split memos must be valid text of at most 255 characters.');
        }
        $total += $cents;
        $normalized[] = ['account_id' => $accountId, 'split_amount' => accounting_bank_import_decimal($cents), 'memo' => $memo, 'sort_order' => $index + 1];
    }
    if ($total !== abs(accounting_bank_import_cents($signedAmount))) {
        throw new InvalidArgumentException('Split total must equal the bank transaction exactly to the cent.');
    }
    return $normalized;
}

function accounting_bank_import_splits_ready(): bool
{
    return db_table_exists('bank_import_transaction_split');
}

/** One batch query avoids querying once for every transaction card. */
function accounting_bank_import_split_map(PDO $pdo, int $batchId, bool $lock = false): array
{
    if (!accounting_bank_import_splits_ready()) {
        return [];
    }
    $statement = $pdo->prepare("SELECT s.*, a.account_code, a.account_name, a.is_active
        FROM bank_import_transaction_split s
        INNER JOIN bank_import_transaction t ON t.bank_transaction_id = s.bank_transaction_id
        INNER JOIN gl_account a ON a.account_id = s.account_id
        WHERE t.batch_id = ? ORDER BY s.bank_transaction_id, s.sort_order" . ($lock ? ' FOR UPDATE' : ''));
    $statement->execute([$batchId]);
    $map = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[(int)$row['bank_transaction_id']][] = $row;
    }
    return $map;
}

/** Pure journal construction shared by posting and the smoke test. */
function accounting_bank_import_allocation_lines(array $transaction, int $bankAccountId, string $memo): array
{
    $signed = accounting_bank_import_cents((string)$transaction['signed_amount']);
    if ($signed === 0) {
        throw new InvalidArgumentException('A zero amount cannot be posted.');
    }
    $rows = $transaction['splits'] ?? [];
    if ($rows) {
        $rows = accounting_bank_import_validate_splits($rows, (string)$transaction['signed_amount'], $bankAccountId);
    } else {
        $rows = [['account_id' => (int)$transaction['selected_account_id'], 'split_amount' => accounting_bank_import_decimal(abs($signed)), 'memo' => $memo]];
    }
    $lines = [];
    foreach ($rows as $row) {
        $lines[] = [(int)$row['account_id'], $signed < 0 ? $row['split_amount'] : '0.00', $signed > 0 ? $row['split_amount'] : '0.00', $row['memo'] !== '' ? $row['memo'] : $memo];
    }
    $bankLine = [$bankAccountId, $signed > 0 ? accounting_bank_import_decimal($signed) : '0.00', $signed < 0 ? accounting_bank_import_decimal(-$signed) : '0.00', $signed > 0 ? 'Bank deposit' : 'Bank withdrawal'];
    // Keep the existing deposit-first / withdrawal-last ordering.
    if ($signed > 0) {
        array_unshift($lines, $bankLine);
    } else {
        $lines[] = $bankLine;
    }
    return $lines;
}

/** Serialize review saves with approval/posting by locking the batch first. */
function accounting_bank_import_save_review(
    int $transactionId, int $batchId, string $classification, string $reviewStatus,
    string $settlementStatus, ?int $selectedAccountId, string $notes,
    array $splits = [], string $allocationMode = 'SINGLE'
): array {
    $pdo = db();
    try {
        if (!in_array($allocationMode, ['SINGLE', 'SPLIT'], true)) {
            throw new InvalidArgumentException('Choose a valid allocation mode.');
        }
        $pdo->beginTransaction();
        $batch = $pdo->prepare('SELECT status FROM bank_import_batch WHERE batch_id = ? FOR UPDATE');
        $batch->execute([$batchId]);
        if ($batch->fetchColumn() !== 'PREVIEW') {
            throw new InvalidArgumentException('Transaction reviews are locked after batch approval.');
        }
        $statement = $pdo->prepare('SELECT * FROM bank_import_transaction WHERE bank_transaction_id = ? AND batch_id = ? FOR UPDATE');
        $statement->execute([$transactionId, $batchId]);
        $transaction = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$transaction || !empty($transaction['posted_journal_id']) || in_array($transaction['review_status'], ['MATCHED', 'POSTED'], true)) {
            throw new InvalidArgumentException('This transaction is missing, matched, or posted and cannot be edited.');
        }
        $normalized = [];
        if ($allocationMode === 'SPLIT') {
            if (!accounting_bank_import_splits_ready()) {
                throw new InvalidArgumentException('Apply the staging split migration before saving splits.');
            }
            $normalized = accounting_bank_import_validate_splits($splits, (string)$transaction['signed_amount'], (int)$transaction['account_id']);
            $account = $pdo->prepare('SELECT is_active FROM gl_account WHERE account_id = ? FOR UPDATE');
            foreach ($normalized as $row) {
                $account->execute([$row['account_id']]);
                if ((int)$account->fetchColumn() !== 1) {
                    throw new InvalidArgumentException('Every split must use an active accounting account.');
                }
            }
            $selectedAccountId = null;
        }
        $result = accounting_bank_import_save_review_single($transactionId, $batchId, $classification, $reviewStatus, $settlementStatus, $selectedAccountId, $notes, $allocationMode === 'SPLIT');
        if (empty($result['ok'])) {
            $pdo->rollBack();
            return $result;
        }
        if (accounting_bank_import_splits_ready()) {
            $delete = $pdo->prepare('DELETE FROM bank_import_transaction_split WHERE bank_transaction_id = ?');
            $delete->execute([$transactionId]);
            $insert = $pdo->prepare('INSERT INTO bank_import_transaction_split (bank_transaction_id, account_id, split_amount, memo, sort_order) VALUES (?, ?, ?, ?, ?)');
            foreach ($normalized as $row) {
                $insert->execute([$transactionId, $row['account_id'], $row['split_amount'], $row['memo'] !== '' ? $row['memo'] : null, $row['sort_order']]);
            }
        }
        $pdo->commit();
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'errors' => [$e->getMessage()]];
    }
}
