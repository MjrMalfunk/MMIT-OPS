<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../inc/bank_import.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$sumLines = static function (array $lines, string $field): int {
    $total = 0;

    foreach ($lines as $line) {
        $cents = accounting_bank_import_amount_cents(
            (string)$line[$field]
        );

        if ($cents === null) {
            throw new RuntimeException('A generated journal line has invalid money.');
        }

        $total += $cents;
    }

    return $total;
};

try {
    $receiptSplits = accounting_bank_import_normalize_split_allocations(
        [
            ['account_id' => '1001', 'amount' => '120.06', 'note' => 'Lyft car equipment'],
            ['account_id' => '1002', 'amount' => '5.77', 'note' => 'Cardstock'],
        ],
        12583
    );

    $expect(!empty($receiptSplits['ok']), 'The August receipt allocation should total $125.83.');
    $expect(count($receiptSplits['allocations']) === 2, 'The receipt should create two allocation lines.');

    $badTotal = accounting_bank_import_normalize_split_allocations(
        [
            ['account_id' => '1001', 'amount' => '120.06', 'note' => 'Equipment'],
            ['account_id' => '1002', 'amount' => '5.76', 'note' => 'Supplies'],
        ],
        12583
    );
    $expect(empty($badTotal['ok']), 'A one-cent short split must be rejected.');

    $oneLine = accounting_bank_import_normalize_split_allocations(
        [
            ['account_id' => '1001', 'amount' => '125.83', 'note' => 'Single line'],
        ],
        12583
    );
    $expect(empty($oneLine['ok']), 'A split must contain at least two lines.');

    $depositLines = accounting_bank_import_build_journal_lines(
        [
            'signed_amount' => '125.83',
            'description_raw' => 'AMAZON MARKETPLACE',
            'split_allocations' => $receiptSplits['allocations'],
        ],
        1000
    );
    $expect(count($depositLines) === 3, 'A split deposit should post one bank line and two offset lines.');
    $expect($sumLines($depositLines, 'debit_amount') === 12583, 'The split deposit debit total must be $125.83.');
    $expect($sumLines($depositLines, 'credit_amount') === 12583, 'The split deposit credit total must be $125.83.');
    $expect($depositLines[0]['account_id'] === 1000, 'The bank deposit line must debit the bank account.');
    $expect($depositLines[1]['credit_amount'] === '120.06', 'The equipment allocation must remain $120.06.');
    $expect($depositLines[2]['credit_amount'] === '5.77', 'The supplies allocation must remain $5.77.');

    $withdrawalLines = accounting_bank_import_build_journal_lines(
        [
            'signed_amount' => '-125.83',
            'description_raw' => 'AMAZON MARKETPLACE',
            'split_allocations' => $receiptSplits['allocations'],
        ],
        1000
    );
    $expect(count($withdrawalLines) === 3, 'A split withdrawal should post two offset lines and one bank line.');
    $expect($sumLines($withdrawalLines, 'debit_amount') === 12583, 'The split withdrawal debit total must be $125.83.');
    $expect($sumLines($withdrawalLines, 'credit_amount') === 12583, 'The split withdrawal credit total must be $125.83.');
    $expect($withdrawalLines[2]['account_id'] === 1000, 'The bank withdrawal line must credit the bank account.');

    $singleLines = accounting_bank_import_build_journal_lines(
        [
            'signed_amount' => '-12.34',
            'description_raw' => 'TEST SINGLE ACCOUNT',
            'selected_account_id' => 1001,
        ],
        1000
    );
    $expect(count($singleLines) === 2, 'An unsplit transaction must keep its existing two-line posting.');
    $expect($sumLines($singleLines, 'debit_amount') === 1234, 'The unsplit debit total must be $12.34.');
    $expect($sumLines($singleLines, 'credit_amount') === 1234, 'The unsplit credit total must be $12.34.');

    echo "Exact split totals: PASS\n";
    echo "Deposit posting lines: PASS\n";
    echo "Withdrawal posting lines: PASS\n";
    echo "Existing single-account posting: PASS\n";
    echo "BANK IMPORT SPLIT SMOKE PASSED\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'SMOKE FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
