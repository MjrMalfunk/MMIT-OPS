<?php
declare(strict_types=1);

/**
 * Convert a two-decimal monetary value into integer cents.
 */
function accounting_source_amount_to_cents(mixed $value): int
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Journal amount must be numeric.');
    }

    return (int)round((float)$value * 100);
}

/**
 * Convert nonnegative integer cents into a database decimal string.
 */
function accounting_source_cents_to_decimal(int $cents): string
{
    if ($cents < 0) {
        throw new InvalidArgumentException(
            'Journal amounts cannot be negative.'
        );
    }

    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

/**
 * Insert one balanced, source-linked journal.
 *
 * The caller must:
 * - Begin the database transaction.
 * - Lock the source row with SELECT ... FOR UPDATE.
 * - Update the source row's accounting_journal_id before committing.
 *
 * A retry returns the existing source journal instead of inserting another.
 *
 * @param array<string,mixed> $journal
 * @param array<int,array<string,mixed>> $lines
 */
function accounting_post_source_journal(
    PDO $pdo,
    array $journal,
    array $lines
): int {
    if (!$pdo->inTransaction()) {
        throw new LogicException(
            'Source journal posting requires an active transaction.'
        );
    }

    $sourceType = strtoupper(trim((string)($journal['source_type'] ?? '')));
    $sourceId = (int)($journal['source_id'] ?? 0);
    $journalDate = trim((string)($journal['journal_date'] ?? ''));

    if (
        !in_array(
            $sourceType,
            ['RIDESHARE_SHIFT', 'FIELD_WORK_ORDER'],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Unsupported source journal type.'
        );
    }

    $businessLineCode = match ($sourceType) {
        'FIELD_WORK_ORDER' => 'FIELD_NATION',
        'RIDESHARE_SHIFT' => 'LYFT',
    };

    $businessLineStatement = $pdo->prepare(
        'SELECT business_line_id
         FROM business_line
         WHERE business_line_code = ?
           AND is_active = 1
         LIMIT 1'
    );
    $businessLineStatement->execute([$businessLineCode]);

    $businessLineId = (int)$businessLineStatement->fetchColumn();

    if ($businessLineId <= 0) {
        throw new RuntimeException(
            "Active business line {$businessLineCode} is missing."
        );
    }

    if ($sourceId <= 0) {
        throw new InvalidArgumentException(
            'A positive source record ID is required.'
        );
    }

    $parsedDate = DateTimeImmutable::createFromFormat(
        '!Y-m-d',
        $journalDate
    );

    if (
        !$parsedDate
        || $parsedDate->format('Y-m-d') !== $journalDate
    ) {
        throw new InvalidArgumentException(
            'Journal date must use YYYY-MM-DD.'
        );
    }

    if (count($lines) < 2) {
        throw new InvalidArgumentException(
            'A journal requires at least two lines.'
        );
    }

    $normalizedLines = [];
    $totalDebitCents = 0;
    $totalCreditCents = 0;

    foreach ($lines as $index => $line) {
        $accountId = (int)($line['account_id'] ?? 0);
        $debitCents = accounting_source_amount_to_cents(
            $line['debit_amount'] ?? 0
        );
        $creditCents = accounting_source_amount_to_cents(
            $line['credit_amount'] ?? 0
        );

        if ($accountId <= 0) {
            throw new InvalidArgumentException(
                'Every journal line requires an account.'
            );
        }

        if ($debitCents < 0 || $creditCents < 0) {
            throw new InvalidArgumentException(
                'Journal line amounts cannot be negative.'
            );
        }

        if (($debitCents > 0) === ($creditCents > 0)) {
            throw new InvalidArgumentException(
                'Each journal line must contain either a debit or a credit.'
            );
        }

        $totalDebitCents += $debitCents;
        $totalCreditCents += $creditCents;

        $normalizedLines[] = [
            'line_number' => $index + 1,
            'account_id' => $accountId,
            'client_id' => (int)($line['client_id'] ?? 0) ?: null,
            'vendor_id' => (int)($line['vendor_id'] ?? 0) ?: null,
            'debit_amount' => accounting_source_cents_to_decimal(
                $debitCents
            ),
            'credit_amount' => accounting_source_cents_to_decimal(
                $creditCents
            ),
            'line_memo' => trim((string)($line['line_memo'] ?? ''))
                ?: null,
        ];
    }

    if ($totalDebitCents !== $totalCreditCents) {
        throw new InvalidArgumentException(
            'Journal debits and credits must balance.'
        );
    }

    if ($totalDebitCents <= 0) {
        throw new InvalidArgumentException(
            'Journal total must be greater than zero.'
        );
    }

    $existing = $pdo->prepare(
        'SELECT journal_id
         FROM gl_journal
         WHERE source_type = ?
           AND source_id = ?
         ORDER BY journal_id
         LIMIT 1'
    );
    $existing->execute([$sourceType, $sourceId]);

    $existingJournalId = (int)$existing->fetchColumn();

    if ($existingJournalId > 0) {
        return $existingJournalId;
    }

    $insertJournal = $pdo->prepare(
        "INSERT INTO gl_journal (
            journal_date,
            status,
            source_type,
            source_id,
            reference_number,
            memo,
            posted_by
         ) VALUES (?, 'POSTED', ?, ?, ?, ?, ?)"
    );
    $insertJournal->execute([
        $journalDate,
        $sourceType,
        $sourceId,
        trim((string)($journal['reference_number'] ?? '')) ?: null,
        trim((string)($journal['memo'] ?? '')) ?: null,
        (int)($journal['posted_by'] ?? 0) ?: null,
    ]);

    $journalId = (int)$pdo->lastInsertId();

    $insertLine = $pdo->prepare(
        'INSERT INTO gl_journal_line (
            journal_id,
            line_number,
            account_id,
            business_line_id,
            client_id,
            vendor_id,
            debit_amount,
            credit_amount,
            line_memo
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($normalizedLines as $line) {
        $insertLine->execute([
            $journalId,
            $line['line_number'],
            $line['account_id'],
            $businessLineId,
            $line['client_id'],
            $line['vendor_id'],
            $line['debit_amount'],
            $line['credit_amount'],
            $line['line_memo'],
        ]);
    }

    return $journalId;
}
