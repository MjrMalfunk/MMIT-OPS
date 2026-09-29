<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/bank_import.php';

require_login();
accounting_require_ready();
csrf_check();

$userId = (int)(current_user()['user_id'] ?? 0);
$ready = accounting_bank_import_ready();
$errors = [];
$message = trim((string)($_GET['message'] ?? ''));
$batchId = (int)($_GET['batch_id'] ?? 0);
$accountOptions = accounting_bank_reconciliation_account_options();
$reviewAccountOptions =
    accounting_bank_import_review_account_options();
$defaultAccountId = accounting_default_reconciliation_account_id();
$selectedAccountId = (int)(
    $_POST['account_id'] ?? $defaultAccountId
);

if ($selectedAccountId <= 0 && $accountOptions) {
    $selectedAccountId = (int)$accountOptions[0]['account_id'];
}

$statementType = strtoupper(trim((string)(
    $_POST['statement_type'] ?? 'CLOSED'
)));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'discard_preview_batch'
) {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $result = accounting_bank_import_discard_preview_batch($batchId);

    if (empty($result['ok'])) {
        $errors = array_merge(
            $errors,
            $result['errors'] ?? ['Unable to discard preview batch.']
        );
    } else {
        audit_event(
            $userId,
            'BANK_IMPORT_BATCH_DISCARDED',
            [
                'batch_id' => $batchId,
                'transaction_count' => (int)$result['transaction_count'],
            ]
        );

        header(
            'Location: '
            . BASE_URL
            . '/accounting/bank_import.php?'
            . http_build_query([
                'message' => sprintf(
                    'Preview batch #%d discarded. %d draft transactions removed; nothing was posted.',
                    $batchId,
                    (int)$result['transaction_count']
                ),
            ]),
            true,
            302
        );
        exit;
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '')
        === 'update_transaction_review'
) {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $transactionId =
        (int)($_POST['bank_transaction_id'] ?? 0);
    $selectedReviewAccountRaw = trim((string)(
        $_POST['selected_account_id'] ?? ''
    ));
    $selectedReviewAccountId =
        $selectedReviewAccountRaw === ''
            ? null
            : (int)$selectedReviewAccountRaw;
    $reviewSplitAllocations = is_array(
        $_POST['split_allocations'] ?? null
    )
        ? $_POST['split_allocations']
        : [];

    $result = accounting_bank_import_save_review(
        $transactionId,
        $batchId,
        (string)($_POST['classification'] ?? ''),
        (string)($_POST['review_status'] ?? ''),
        (string)($_POST['settlement_status'] ?? ''),
        $selectedReviewAccountId,
        (string)($_POST['notes'] ?? ''),
        $reviewSplitAllocations
    );

    if (empty($result['ok'])) {
        $errors = array_merge(
            $errors,
            $result['errors']
                ?? ['Unable to update transaction review.']
        );
    } else {
        audit_event(
            $userId,
            'BANK_IMPORT_TRANSACTION_REVIEWED',
            [
                'batch_id' => $batchId,
                'bank_transaction_id' => $transactionId,
                'classification' =>
                    (string)($result['classification'] ?? ''),
                'selected_account_id' =>
                    $result['selected_account_id'] ?? null,
                'split_lines' => $result['split_lines'] ?? [],
                'review_status' =>
                    (string)($_POST['review_status'] ?? ''),
                'settlement_status' =>
                    (string)($_POST['settlement_status'] ?? ''),
            ]
        );

        header(
            'Location: '
            . BASE_URL
            . '/accounting/bank_import.php?'
            . http_build_query([
                'batch_id' => $batchId,
                'message' =>
                    'Transaction review saved. '
                    . 'Nothing was posted to the ledger.',
            ]),
            true,
            302
        );
        exit;
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'approve_batch'
) {
    $batchId = (int)($_POST['batch_id'] ?? 0);

    if ((string)($_POST['approval_confirm'] ?? '') !== '1') {
        $errors[] = 'Confirm that the batch review is complete.';
    } else {
        $result = accounting_bank_import_approve_batch(
            $batchId,
            $userId
        );

        if (empty($result['ok'])) {
            $errors = array_merge(
                $errors,
                $result['errors'] ?? ['Unable to approve batch.']
            );
        } else {
            audit_event(
                $userId,
                'BANK_IMPORT_BATCH_APPROVED',
                [
                    'batch_id' => $batchId,
                    'ready_count' => (int)$result['ready_count'],
                    'ignored_count' => (int)$result['ignored_count'],
                ]
            );

            header(
                'Location: '
                . BASE_URL
                . '/accounting/bank_import.php?'
                . http_build_query([
                    'batch_id' => $batchId,
                    'message' =>
                        'Batch approved and review editing locked. '
                        . 'Nothing has been posted yet.',
                ]),
                true,
                302
            );
            exit;
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'post_batch'
) {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $expectedConfirmation = 'POST BATCH #' . $batchId;
    $confirmation = strtoupper(trim((string)(
        $_POST['posting_confirmation'] ?? ''
    )));

    if ($confirmation !== $expectedConfirmation) {
        $errors[] = 'Enter ' . $expectedConfirmation . ' exactly to post.';
    } else {
        $result = accounting_bank_import_post_batch(
            $batchId,
            $userId
        );

        if (empty($result['ok'])) {
            $errors = array_merge(
                $errors,
                $result['errors'] ?? ['Unable to post batch.']
            );
        } else {
            audit_event(
                $userId,
                'BANK_IMPORT_BATCH_POSTED',
                [
                    'batch_id' => $batchId,
                    'journal_count' => (int)$result['journal_count'],
                    'journal_ids' => $result['journal_ids'],
                    'ignored_count' => (int)$result['ignored_count'],
                ]
            );

            header(
                'Location: '
                . BASE_URL
                . '/accounting/bank_import.php?'
                . http_build_query([
                    'batch_id' => $batchId,
                    'message' => sprintf(
                        'Batch posted successfully. %d balanced journals created.',
                        (int)$result['journal_count']
                    ),
                ]),
                true,
                302
            );
            exit;
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string)($_POST['action'] ?? '') === 'upload_pnc_csv'
) {
    $file = $_FILES['statement_csv'] ?? null;
    $openingRaw = trim((string)(
        $_POST['opening_balance'] ?? ''
    ));
    $endingRaw = trim((string)(
        $_POST['ending_balance'] ?? ''
    ));

    $statementEndingDateRaw = trim((string)(
        $_POST['statement_ending_date'] ?? ''
    ));
    $statementEndingDate =
        accounting_bank_import_parse_date(
            $statementEndingDateRaw
        );

    if (!$ready) {
        $errors[] =
            'Bank import tables or the transaction-split migration are not installed.';
    } elseif ($selectedAccountId <= 0) {
        $errors[] = 'Choose a bank account.';
    } elseif (
        !is_array($file)
        || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE)
            !== UPLOAD_ERR_OK
    ) {
        $errors[] = 'Choose a PNC CSV file to upload.';
    } elseif (
        strtolower(pathinfo(
            (string)($file['name'] ?? ''),
            PATHINFO_EXTENSION
        )) !== 'csv'
    ) {
        $errors[] = 'The statement export must be a CSV file.';
    } elseif ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $errors[] = 'The CSV file must be 5 MB or smaller.';
    } elseif (
        !in_array(
            $statementType,
            ['CLOSED', 'CURRENT'],
            true
        )
    ) {
        $errors[] =
            'Choose Closed statement or Current activity.';
    } elseif (
        $statementEndingDate === null
        || $statementEndingDate !== $statementEndingDateRaw
    ) {
        $errors[] =
            'Enter a valid statement ending/as-of date.';
    } elseif ($openingRaw === '') {
        $errors[] =
            'Statement opening balance is required.';
    } elseif (
        $statementType === 'CLOSED'
        && $endingRaw === ''
    ) {
        $errors[] =
            'Closed statements require an ending balance.';
    } elseif (
        !is_numeric($openingRaw)
        || (
            $endingRaw !== ''
            && !is_numeric($endingRaw)
        )
    ) {
        $errors[] = 'Statement balances must be valid numbers.';
    } else {
        $tmpPath = (string)($file['tmp_name'] ?? '');
        $preview = accounting_bank_import_parse_pnc_csv(
            $tmpPath,
            $selectedAccountId
        );

        if (empty($preview['ok'])) {
            $errors = array_merge(
                $errors,
                $preview['errors'] ?? ['Unable to parse CSV.']
            );
        } elseif ($statementType === 'CLOSED') {
            $expectedEnding = round(
                (float)$openingRaw
                    + (float)$preview['net_total'],
                2
            );

            if (
                abs($expectedEnding - (float)$endingRaw)
                > 0.009
            ) {
                $errors[] = sprintf(
                    'Statement does not balance: $%.2f opening '
                    . '+ $%.2f activity = $%.2f, not $%.2f.',
                    (float)$openingRaw,
                    (float)$preview['net_total'],
                    $expectedEnding,
                    (float)$endingRaw
                );
            }
        }

        if (!$errors) {
            $result = accounting_bank_import_store_pnc_csv(
                $tmpPath,
                (string)($file['name'] ?? 'statement.csv'),
                $selectedAccountId,
                $userId,
                (string)($_POST['account_last4'] ?? '8179'),
                $statementType,
                $statementEndingDate,
                round((float)$openingRaw, 2),
                $endingRaw !== ''
                    ? round((float)$endingRaw, 2)
                    : null
            );

            if (empty($result['ok'])) {
                $errors = array_merge(
                    $errors,
                    $result['errors']
                        ?? ['Unable to import statement.']
                );
                $batchId = (int)($result['batch_id'] ?? 0);
            } else {
                $batchId = (int)$result['batch_id'];

                audit_event(
                    $userId,
                    'BANK_STATEMENT_IMPORTED',
                    [
                        'batch_id' => $batchId,
                        'account_id' => $selectedAccountId,
                        'transaction_count' =>
                            $preview['transaction_count'],
                    ]
                );

                header(
                    'Location: '
                    . BASE_URL
                    . '/accounting/bank_import.php?'
                    . http_build_query([
                        'batch_id' => $batchId,
                        'message' =>
                            'Statement imported for review. '
                            . 'Nothing was posted to the ledger.',
                    ]),
                    true,
                    302
                );
                exit;
            }
        }
    }
}

$batch = $batchId > 0
    ? accounting_bank_import_get_batch($batchId)
    : null;
$transactions = $batch
    ? accounting_bank_import_transactions($batchId)
    : [];
$batches = accounting_bank_import_list_batches(20);
$batchPreflight = null;

if ($batch && in_array((string)$batch['status'], ['PREVIEW', 'READY'], true)) {
    $batchPreflight = accounting_bank_import_preflight(
        $batchId,
        (string)$batch['status']
    );
}

$treatmentOptions = [
    'UNCLASSIFIED' => 'Unclassified',
    'SOFTWARE' => 'Software expense',
    'INTERNET' => 'Internet expense',
    'VOICE_COMMUNICATION' => 'Voice & communication',
    'WEB_SECURITY' => 'Web security',
    'BANK_FEE' => 'Bank fee',
    'BUSINESS_EXPENSE' => 'Other business expense',
    'OWNER_CONTRIBUTION' => 'Owner contribution',
    'OWNER_DRAW' => 'Owner draw',
    'FIELD_SERVICE_REVENUE' => 'Field service revenue',
    'MANAGED_SERVICE_REVENUE' => 'Managed service revenue',
    'TRANSFER' => 'Transfer',
    'REFUND' => 'Refund / expense reduction',
    'STRIPE_RECEIPT' => 'Stripe receipt',
    'MATCH_INVOICE' => 'Match invoice or bill',
    'SPLIT_REQUIRED' => 'Split required',
];

$mathMatches = null;
if (
    $batch
    && $batch['opening_balance'] !== null
    && $batch['ending_balance'] !== null
) {
    $calculatedEnding = round(
        (float)$batch['opening_balance']
            + (float)$batch['net_total'],
        2
    );
    $mathMatches =
        abs($calculatedEnding - (float)$batch['ending_balance'])
        <= 0.009;
}

page_header('Bank Statement Import', 'accounting');
accounting_subnav('bank_import');
?>
<style>
.bank-import-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(280px,.5fr);gap:16px;align-items:start}
.bank-import-form{display:grid;gap:12px}
.bank-import-form input,.bank-import-form select{width:100%;padding:10px 12px;box-sizing:border-box}
.bank-import-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.bank-import-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.bank-import-table-wrap{overflow:auto}
.bank-import-table{width:100%;border-collapse:collapse}
.bank-import-table th,.bank-import-table td{padding:10px 8px;border-bottom:1px solid rgba(255,255,255,.07);vertical-align:top;text-align:left}
.bank-import-table .num{text-align:right;white-space:nowrap}
.bank-import-meta{font-size:12px;opacity:.72;line-height:1.45}
.bank-import-credit{color:#bbf7d0}
.bank-import-debit{color:#fecaca}
.bank-import-review{display:grid;gap:7px;min-width:190px}
.bank-import-review select,.bank-import-review textarea,
.bank-import-account-select{width:100%;padding:8px 10px;box-sizing:border-box}
.bank-import-review textarea{min-height:64px;resize:vertical}
.bank-import-status{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800;letter-spacing:.03em}
.bank-import-status-posted{background:rgba(34,197,94,.13);color:#bbf7d0}
.bank-import-status-pending{background:rgba(245,158,11,.14);color:#fde68a}
.bank-import-workflow{display:grid;gap:12px;padding:16px;margin-bottom:14px;border:1px solid rgba(59,130,246,.28);border-radius:14px;background:rgba(59,130,246,.07)}
.bank-import-workflow-errors{display:grid;gap:5px;color:#fecaca}
.bank-import-confirm{display:flex;align-items:flex-start;gap:9px}
.bank-import-confirm input{margin-top:3px}
.bank-import-post-confirm{display:grid;grid-template-columns:minmax(220px,360px) auto;gap:10px;align-items:end}
.bank-import-post-confirm input{width:100%;padding:10px 12px;box-sizing:border-box}

/* Transaction review cards */
.bank-import-transactions{
  display:grid;
  gap:14px;
}
.bank-import-transaction{
  overflow:hidden;
  border:1px solid rgba(148,163,184,.18);
  border-radius:14px;
  background:rgba(15,23,42,.42);
}
.bank-import-transaction-head{
  display:grid;
  grid-template-columns:minmax(0,1fr) auto;
  gap:16px;
  align-items:start;
  padding:15px 16px;
  border-bottom:1px solid rgba(148,163,184,.14);
  background:rgba(30,41,59,.3);
}
.bank-import-transaction-meta{
  display:flex;
  flex-wrap:wrap;
  gap:6px 12px;
  align-items:center;
  margin-bottom:5px;
}
.bank-import-transaction-date{
  color:#cbd5e1;
  font-size:12px;
  font-weight:700;
  white-space:nowrap;
}
.bank-import-transaction-description{
  color:#f8fafc;
  font-size:14px;
  line-height:1.45;
  overflow-wrap:anywhere;
}
.bank-import-transaction-amount{
  font-size:17px;
  font-weight:800;
  line-height:1.2;
  text-align:right;
  white-space:nowrap;
}
.bank-import-review{
  display:grid;
  grid-template-columns:
    minmax(170px,1.15fr)
    minmax(240px,1.65fr)
    minmax(145px,.85fr)
    minmax(145px,.85fr);
  gap:12px;
  padding:16px;
  min-width:0;
}
.bank-import-review label{
  display:grid;
  align-content:start;
  gap:6px;
  min-width:0;
  color:#cbd5e1;
  font-size:12px;
  font-weight:700;
}
.bank-import-review select,
.bank-import-review textarea{
  width:100%;
  min-width:0;
  box-sizing:border-box;
  padding:9px 10px;
}
.bank-import-review textarea{
  min-height:72px;
  resize:vertical;
}
.bank-import-review-notes{
  grid-column:1 / -2;
}
.bank-import-review-submit{
  display:flex;
  align-items:end;
}
.bank-import-review-submit .btn{
  width:100%;
  min-height:40px;
}
.bank-import-review-foot{
  grid-column:1 / -1;
  display:flex;
  flex-wrap:wrap;
  justify-content:space-between;
  gap:5px 14px;
  padding-top:2px;
}
.bank-import-review-splits{
  grid-column:1 / -1;
  display:grid;
  gap:10px;
  padding:13px;
  border:1px solid rgba(96,165,250,.28);
  border-radius:12px;
  background:rgba(30,41,59,.34);
}
.bank-import-review-splits[hidden]{display:none!important}
.bank-import-split-rows{display:grid;gap:9px}
.bank-import-split-row{
  display:grid;
  grid-template-columns:minmax(200px,1.5fr) minmax(120px,.7fr) minmax(170px,1.2fr) auto;
  gap:9px;
  align-items:end;
}
.bank-import-split-row label{display:grid;gap:5px;min-width:0;color:#cbd5e1;font-size:12px;font-weight:700}
.bank-import-split-row input,.bank-import-split-row select{width:100%;min-width:0;box-sizing:border-box;padding:9px 10px}
.bank-import-split-remove{min-height:38px}
.bank-import-split-total{display:flex;flex-wrap:wrap;gap:5px 16px;align-items:center;font-size:13px}
.bank-import-split-error{color:#fecaca;font-weight:700}
.bank-import-locked-splits{display:grid;gap:5px;padding-top:4px}
.bank-import-locked-split{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline}
.bank-import-review-locked{
  display:grid;
  gap:7px;
  padding:16px;
}
@media (max-width:1100px){
  .bank-import-review{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
  .bank-import-review-notes,
  .bank-import-review-splits{
    grid-column:1 / -1;
  }
  .bank-import-split-row{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bank-import-review-submit{
    grid-column:1 / -1;
  }
}
@media (max-width:650px){
  .bank-import-transaction-head{
    grid-template-columns:1fr;
    gap:9px;
  }
  .bank-import-transaction-amount{
    text-align:left;
  }
  .bank-import-review{
    grid-template-columns:1fr;
    padding:13px;
  }
  .bank-import-review-notes,
  .bank-import-review-splits,
  .bank-import-review-submit,
  .bank-import-review-foot{
    grid-column:1;
  }
  .bank-import-split-row{grid-template-columns:1fr}
}
@media(max-width:1000px){.bank-import-grid{grid-template-columns:1fr}.bank-import-summary{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.bank-import-fields,.bank-import-summary{grid-template-columns:1fr}}
@media(max-width:650px){.bank-import-post-confirm{grid-template-columns:1fr}}
</style>

<?php if ($message !== ''): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:16px;border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10);"><?= accounting_h($message) ?></div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="card" style="padding:14px 16px;margin-bottom:16px;border-color:rgba(248,113,113,.35);background:rgba(127,29,29,.28);">
    <?php foreach ($errors as $error): ?>
      <div><?= accounting_h((string)$error) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card" style="padding:16px;margin-bottom:16px;border-color:rgba(59,130,246,.30);background:rgba(59,130,246,.08);">
  <strong><?= accounting_h(accounting_bank_import_legal_business_name()) ?></strong>
  · Two-stage bank workflow. Uploading and reviewing do not change the ledger. Approval locks the review; only the separate, typed posting confirmation creates journals.
</div>

<div class="bank-import-grid">
  <div style="display:grid;gap:16px;">
    <form method="post" enctype="multipart/form-data" class="card bank-import-form" style="padding:18px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_pnc_csv">
      <h2 style="margin:0;font-size:20px;">Upload PNC statement activity</h2>
      <div class="bank-import-fields">
        <div>
          <label>Bank account</label>
          <select name="account_id">
            <?php foreach ($accountOptions as $account): ?>
              <option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === $selectedAccountId ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Account last four</label>
          <input name="account_last4" value="8179" maxlength="4">
        </div>
        <div>
          <label>Import type</label>
          <select name="statement_type">
            <option value="CLOSED" <?= $statementType === 'CLOSED' ? 'selected' : '' ?>>Closed statement</option>
            <option value="CURRENT" <?= $statementType === 'CURRENT' ? 'selected' : '' ?>>Current / open activity</option>
          </select>
        </div>
        <div>
          <label>Statement ending / activity as-of date</label>
          <input type="date" name="statement_ending_date" required value="<?= accounting_h((string)($_POST['statement_ending_date'] ?? '')) ?>">
        </div>
        <div>
          <label>Statement opening balance</label>
          <input type="number" step="0.01" name="opening_balance" required placeholder="3647.96" value="<?= accounting_h((string)($_POST['opening_balance'] ?? '')) ?>">
        </div>
        <div>
          <label>Statement ending balance</label>
          <input type="number" step="0.01" name="ending_balance" placeholder="468.09" value="<?= accounting_h((string)($_POST['ending_balance'] ?? '')) ?>">
        </div>
      </div>
      <div>
        <label>PNC account activity CSV</label>
        <input type="file" name="statement_csv" accept=".csv,text/csv" required>
      </div>
      <div class="bank-import-meta">The expected columns are Transaction Date, Transaction Description, and Amount. Closed statements require both balances and must balance before saving. Current activity requires the opening balance; its ending balance may remain blank until the statement closes.</div>
      <div><button class="btn btn-primary" type="submit">Upload and preview</button></div>
    </form>

    <?php if ($batch): ?>
      <div class="bank-import-summary">
        <div class="card metric-card"><div class="metric-label">Transactions</div><div class="metric-value"><?= (int)$batch['transaction_count'] ?></div></div>
        <div class="card metric-card"><div class="metric-label">Credits</div><div class="metric-value bank-import-credit">$<?= number_format((float)$batch['credit_total'], 2) ?></div></div>
        <div class="card metric-card"><div class="metric-label">Debits</div><div class="metric-value bank-import-debit">$<?= number_format((float)$batch['debit_total'], 2) ?></div></div>
        <div class="card metric-card"><div class="metric-label">Net activity</div><div class="metric-value">$<?= number_format((float)$batch['net_total'], 2) ?></div></div>
      </div>

      <div class="card" style="padding:18px;">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
          <div>
            <h2 style="margin:0 0 4px;font-size:20px;">Batch #<?= (int)$batch['batch_id'] ?></h2>
            <div class="bank-import-meta">
              <?= accounting_h((string)$batch['original_filename']) ?>
              · Activity <?= accounting_h((string)$batch['period_start_date']) ?>
              through <?= accounting_h((string)$batch['period_end_date']) ?>
              · Statement ending <?= accounting_h((string)($batch['statement_ending_date'] ?? '—')) ?>
            </div>
          </div>
          <div style="font-weight:700;">
            <?= accounting_h((string)$batch['statement_type']) ?>
            ·
            <?= accounting_h((string)$batch['status']) ?>
            <?php if ((string)$batch['status'] === 'PREVIEW'): ?>
              <form
                method="post"
                style="margin-top:10px;"
                onsubmit="return confirm('Discard Batch #<?= (int)$batch['batch_id'] ?> and remove its draft transactions? This cannot be undone. No ledger entries have been posted.');"
              >
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard_preview_batch">
                <input type="hidden" name="batch_id" value="<?= (int)$batch['batch_id'] ?>">
                <button class="btn" type="submit">Discard preview batch</button>
              </form>
              <div class="bank-import-meta" style="max-width:260px;margin-top:5px;">
                Available only before approval. Posted or matched transactions are protected.
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($mathMatches !== null): ?>
          <div style="padding:12px;margin-bottom:12px;border-radius:12px;background:<?= $mathMatches ? 'rgba(34,197,94,.10)' : 'rgba(127,29,29,.28)' ?>;">
            <?= $mathMatches ? 'Statement math verified.' : 'Statement math does not match.' ?>
            $<?= number_format((float)$batch['opening_balance'], 2) ?>
            + $<?= number_format((float)$batch['net_total'], 2) ?>
            = $<?= number_format((float)$batch['ending_balance'], 2) ?>
          </div>
        <?php endif; ?>

        <?php if ((string)$batch['status'] === 'PREVIEW'): ?>
          <div class="bank-import-workflow">
            <div>
              <strong>Step 1 · Approve reviewed batch</strong>
              <div class="bank-import-meta">
                Approval locks every transaction decision. It does not create ledger entries.
              </div>
            </div>

            <?php if ($batchPreflight && empty($batchPreflight['ok'])): ?>
              <div class="bank-import-workflow-errors">
                <?php foreach ($batchPreflight['errors'] as $preflightError): ?>
                  <div><?= accounting_h((string)$preflightError) ?></div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($batchPreflight): ?>
              <div class="bank-import-meta">
                Preflight passed:
                <?= (int)$batchPreflight['ready_count'] ?> ready,
                <?= (int)$batchPreflight['ignored_count'] ?> ignored.
              </div>
              <form method="post" style="display:grid;gap:10px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="approve_batch">
                <input type="hidden" name="batch_id" value="<?= (int)$batch['batch_id'] ?>">
                <label class="bank-import-confirm">
                  <input type="checkbox" name="approval_confirm" value="1" required>
                  <span>I confirm that every transaction treatment and offsetting account is correct.</span>
                </label>
                <div>
                  <button class="btn btn-primary" type="submit">Approve and lock batch</button>
                </div>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif ((string)$batch['status'] === 'READY'): ?>
          <div class="bank-import-workflow" style="border-color:rgba(245,158,11,.40);background:rgba(245,158,11,.08);">
            <div>
              <strong>Step 2 · Post approved batch</strong>
              <div class="bank-import-meta">
                Review editing is locked. Posting creates one balanced journal for each Ready transaction and cannot be undone here.
              </div>
            </div>

            <?php if ($batchPreflight && empty($batchPreflight['ok'])): ?>
              <div class="bank-import-workflow-errors">
                <?php foreach ($batchPreflight['errors'] as $preflightError): ?>
                  <div><?= accounting_h((string)$preflightError) ?></div>
                <?php endforeach; ?>
              </div>
            <?php elseif ($batchPreflight): ?>
              <div class="bank-import-meta">
                Posting will create <?= (int)$batchPreflight['ready_count'] ?> journals;
                <?= (int)$batchPreflight['ignored_count'] ?> ignored transaction(s) will remain unposted.
              </div>
              <form method="post" class="bank-import-post-confirm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="post_batch">
                <input type="hidden" name="batch_id" value="<?= (int)$batch['batch_id'] ?>">
                <label>
                  Type <strong>POST BATCH #<?= (int)$batch['batch_id'] ?></strong>
                  <input
                    name="posting_confirmation"
                    autocomplete="off"
                    spellcheck="false"
                    required
                    placeholder="POST BATCH #<?= (int)$batch['batch_id'] ?>"
                  >
                </label>
                <button class="btn btn-primary" type="submit">Post batch to ledger</button>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif ((string)$batch['status'] === 'POSTED'): ?>
          <div class="bank-import-workflow" style="border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08);">
            <strong>Posted to ledger</strong>
            <div class="bank-import-meta">
              This batch and all transaction decisions are permanently locked.
            </div>
          </div>
        <?php endif; ?>

        <div class="bank-import-transactions">
          <?php foreach ($transactions as $transaction): ?>
            <?php
            $reviewFormId =
                'review-'
                . (int)$transaction['bank_transaction_id'];

            $reviewLocked =
                (string)$batch['status'] !== 'PREVIEW'
                ||
                in_array(
                    (string)$transaction['review_status'],
                    ['MATCHED', 'POSTED'],
                    true
                )
                || !empty($transaction['posted_journal_id']);

            $rowTreatmentOptions = $treatmentOptions;
            $currentTreatment =
                (string)$transaction['classification'];

            if (
                $currentTreatment !== ''
                && !isset(
                    $rowTreatmentOptions[$currentTreatment]
                )
            ) {
                $rowTreatmentOptions =
                    [
                        $currentTreatment =>
                            ucwords(strtolower(str_replace(
                                '_',
                                ' ',
                                $currentTreatment
                            ))),
                    ]
                    + $rowTreatmentOptions;
            }

            $effectiveAccountId =
                !empty($transaction['selected_account_id'])
                    ? (int)$transaction['selected_account_id']
                    : (int)(
                        $transaction['suggested_account_id']
                        ?? 0
                    );

            $signedAmount =
                (float)$transaction['signed_amount'];
            $existingSplitAllocations =
                $transaction['split_allocations'] ?? [];
            $splitMode =
                $currentTreatment === 'SPLIT_REQUIRED'
                || !empty($existingSplitAllocations);
            $splitRowsForForm = $existingSplitAllocations;

            if ($splitMode && !$splitRowsForForm) {
                $splitRowsForForm = [[], []];
            }
            ?>

            <article class="bank-import-transaction">
              <header class="bank-import-transaction-head">
                <div>
                  <div class="bank-import-transaction-meta">
                    <span class="bank-import-transaction-date">
                      <?= accounting_h((string)$transaction['transaction_date']) ?>
                    </span>

                    <?php if ((int)$transaction['occurrence_ordinal'] > 1): ?>
                      <span class="bank-import-meta">
                        Occurrence
                        <?= (int)$transaction['occurrence_ordinal'] ?>
                        of an identical transaction
                      </span>
                    <?php endif; ?>
                  </div>

                  <div class="bank-import-transaction-description">
                    <?= accounting_h((string)$transaction['description_raw']) ?>
                  </div>
                </div>

                <div class="bank-import-transaction-amount <?= $signedAmount >= 0 ? 'bank-import-credit' : 'bank-import-debit' ?>">
                  <?= $signedAmount >= 0 ? '+' : '-' ?>$<?= number_format(abs($signedAmount), 2) ?>
                </div>
              </header>

              <?php if ($reviewLocked): ?>
                <div class="bank-import-review-locked">
                  <strong>
                    <?= accounting_h($currentTreatment) ?>
                  </strong>

                  <div class="bank-import-meta">
                    Locked:
                    <?= accounting_h((string)$transaction['review_status']) ?>
                  </div>

                  <?php if ($existingSplitAllocations): ?>
                    <div class="bank-import-locked-splits">
                      <strong>Split allocation</strong>
                      <?php foreach ($existingSplitAllocations as $split): ?>
                        <div class="bank-import-locked-split">
                          <span>$<?= number_format((float)$split['split_amount'], 2) ?></span>
                          <span><?= accounting_h((string)$split['account_code']) ?> · <?= accounting_h((string)$split['account_name']) ?></span>
                          <?php if (!empty($split['split_note'])): ?>
                            <span class="bank-import-meta">· <?= accounting_h((string)$split['split_note']) ?></span>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php elseif (!empty($transaction['selected_account_code'])): ?>
                    <div>
                      <?= accounting_h((string)$transaction['selected_account_code']) ?>
                      ·
                      <?= accounting_h((string)$transaction['selected_account_name']) ?>
                    </div>
                  <?php else: ?>
                    <div>Manual account decision</div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <form
                  id="<?= accounting_h($reviewFormId) ?>"
                  method="post"
                  class="bank-import-review"
                >
                  <?= csrf_field() ?>

                  <input
                    type="hidden"
                    name="action"
                    value="update_transaction_review"
                  >

                  <input
                    type="hidden"
                    name="batch_id"
                    value="<?= (int)$batch['batch_id'] ?>"
                  >

                  <input
                    type="hidden"
                    name="bank_transaction_id"
                    value="<?= (int)$transaction['bank_transaction_id'] ?>"
                  >

                  <label>
                    Treatment
                    <select name="classification">
                      <?php foreach ($rowTreatmentOptions as $value => $label): ?>
                        <option
                          value="<?= accounting_h($value) ?>"
                          <?= $value === $currentTreatment ? 'selected' : '' ?>
                        >
                          <?= accounting_h($label) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>

                  <label>
                    Account
                    <select
                      name="selected_account_id"
                      data-split-single-account
                      <?= $splitMode ? 'disabled' : '' ?>
                    >
                      <option value="">Choose account</option>

                      <?php foreach ($reviewAccountOptions as $account): ?>
                        <option
                          value="<?= (int)$account['account_id'] ?>"
                          <?= (int)$account['account_id'] === $effectiveAccountId ? 'selected' : '' ?>
                        >
                          <?= accounting_h((string)$account['account_code']) ?>
                          ·
                          <?= accounting_h((string)$account['account_name']) ?>
                          (<?= accounting_h((string)$account['account_type']) ?>)
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>

                  <div
                    class="bank-import-review-splits"
                    data-split-panel
                    data-expected-cents="<?= abs((int)round($signedAmount * 100)) ?>"
                    <?= $splitMode ? '' : 'hidden' ?>
                  >
                    <div>
                      <strong>Split this transaction across accounts</strong>
                      <div class="bank-import-meta">
                        Enter positive amounts that add up to exactly
                        $<?= number_format(abs($signedAmount), 2) ?>.
                        Each line posts to its selected account.
                      </div>
                    </div>

                    <div class="bank-import-split-rows" data-split-rows>
                      <?php foreach ($splitRowsForForm as $splitIndex => $split): ?>
                        <div class="bank-import-split-row" data-split-row>
                          <label>
                            Account
                            <select
                              class="bank-import-review-split-control"
                              name="split_allocations[<?= (int)$splitIndex ?>][account_id]"
                              required
                              <?= $splitMode ? '' : 'disabled' ?>
                            >
                              <option value="">Choose account</option>
                              <?php foreach ($reviewAccountOptions as $account): ?>
                                <option
                                  value="<?= (int)$account['account_id'] ?>"
                                  <?= (int)($split['account_id'] ?? 0) === (int)$account['account_id'] ? 'selected' : '' ?>
                                >
                                  <?= accounting_h((string)$account['account_code']) ?>
                                  · <?= accounting_h((string)$account['account_name']) ?>
                                  (<?= accounting_h((string)$account['account_type']) ?>)
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </label>
                          <label>
                            Amount
                            <input
                              class="bank-import-review-split-control"
                              type="number"
                              min="0.01"
                              step="0.01"
                              inputmode="decimal"
                              name="split_allocations[<?= (int)$splitIndex ?>][amount]"
                              value="<?= accounting_h((string)($split['split_amount'] ?? '')) ?>"
                              required
                              <?= $splitMode ? '' : 'disabled' ?>
                            >
                          </label>
                          <label>
                            Line note
                            <input
                              class="bank-import-review-split-control"
                              type="text"
                              maxlength="255"
                              name="split_allocations[<?= (int)$splitIndex ?>][note]"
                              value="<?= accounting_h((string)($split['split_note'] ?? '')) ?>"
                              placeholder="Equipment, supplies, or category note"
                              <?= $splitMode ? '' : 'disabled' ?>
                            >
                          </label>
                          <button
                            class="btn bank-import-split-remove"
                            type="button"
                            data-remove-split
                          >Remove</button>
                        </div>
                      <?php endforeach; ?>
                    </div>

                    <template data-split-template>
                      <div class="bank-import-split-row" data-split-row>
                        <label>
                          Account
                          <select
                            class="bank-import-review-split-control"
                            data-name-template="split_allocations[__INDEX__][account_id]"
                            required
                          >
                            <option value="">Choose account</option>
                            <?php foreach ($reviewAccountOptions as $account): ?>
                              <option value="<?= (int)$account['account_id'] ?>">
                                <?= accounting_h((string)$account['account_code']) ?>
                                · <?= accounting_h((string)$account['account_name']) ?>
                                (<?= accounting_h((string)$account['account_type']) ?>)
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </label>
                        <label>
                          Amount
                          <input
                            class="bank-import-review-split-control"
                            type="number"
                            min="0.01"
                            step="0.01"
                            inputmode="decimal"
                            data-name-template="split_allocations[__INDEX__][amount]"
                            required
                          >
                        </label>
                        <label>
                          Line note
                          <input
                            class="bank-import-review-split-control"
                            type="text"
                            maxlength="255"
                            data-name-template="split_allocations[__INDEX__][note]"
                            placeholder="Equipment, supplies, or category note"
                          >
                        </label>
                        <button
                          class="btn bank-import-split-remove"
                          type="button"
                          data-remove-split
                        >Remove</button>
                      </div>
                    </template>

                    <div class="bank-import-split-total">
                      <strong data-split-total-text></strong>
                      <span class="bank-import-meta" data-split-remaining></span>
                    </div>
                    <div class="bank-import-split-error" data-split-error aria-live="polite"></div>
                    <div>
                      <button class="btn" type="button" data-add-split>
                        Add split line
                      </button>
                    </div>
                  </div>

                  <label>
                    Review
                    <select name="review_status">
                      <?php foreach ([
                          'UNREVIEWED' => 'Unreviewed',
                          'READY' => 'Ready',
                          'IGNORED' => 'Ignore',
                      ] as $value => $label): ?>
                        <option
                          value="<?= accounting_h($value) ?>"
                          <?= $value === (string)$transaction['review_status'] ? 'selected' : '' ?>
                        >
                          <?= accounting_h($label) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>

                  <label>
                    Bank status
                    <select name="settlement_status">
                      <option
                        value="POSTED"
                        <?= (string)$transaction['settlement_status'] === 'POSTED' ? 'selected' : '' ?>
                      >
                        Posted by bank
                      </option>
                      <option
                        value="PENDING"
                        <?= (string)$transaction['settlement_status'] === 'PENDING' ? 'selected' : '' ?>
                      >
                        Pending
                      </option>
                    </select>
                  </label>

                  <label class="bank-import-review-notes">
                    Notes / business purpose
                    <textarea
                      name="notes"
                      placeholder="What was purchased, transfer source, job, or review note"
                    ><?= accounting_h((string)($transaction['notes'] ?? '')) ?></textarea>
                  </label>

                  <div class="bank-import-review-submit">
                    <button class="btn" type="submit">
                      Save review
                    </button>
                  </div>

                  <div class="bank-import-review-foot">
                    <span class="bank-import-meta">
                      <?= accounting_h((string)$transaction['suggestion_reason']) ?>
                    </span>

                    <?php if (!empty($transaction['suggested_account_code'])): ?>
                      <span class="bank-import-meta">
                        Suggested:
                        <?= accounting_h((string)$transaction['suggested_account_code']) ?>
                        ·
                        <?= accounting_h((string)$transaction['suggested_account_name']) ?>
                        · Selection remains unposted
                      </span>
                    <?php else: ?>
                      <span class="bank-import-meta">
                        Selection remains unposted
                      </span>
                    <?php endif; ?>
                  </div>
                </form>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Recent import batches</h2>
    <div style="display:grid;gap:10px;">
      <?php if (!$batches): ?>
        <div class="bank-import-meta">No statements imported yet.</div>
      <?php else: ?>
        <?php foreach ($batches as $item): ?>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/bank_import.php?batch_id=<?= (int)$item['batch_id'] ?>" style="padding:12px;border-radius:12px;border:1px solid rgba(59,130,246,.22);background:rgba(59,130,246,.08);color:#dbeafe;text-decoration:none;">
            <div style="display:flex;justify-content:space-between;gap:8px;">
              <strong>Batch #<?= (int)$item['batch_id'] ?></strong>
              <span><?= accounting_h((string)$item['status']) ?></span>
            </div>
            <div class="bank-import-meta" style="margin-top:5px;"><?= accounting_h((string)$item['account_code']) ?> · <?= accounting_h((string)$item['period_end_date']) ?> · <?= (int)$item['transaction_count'] ?> items</div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(() => {
  const formatMoney = cents => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(cents / 100);

  const parseCents = value => {
    const raw = String(value || '').trim();
    if (!/^\d+(?:\.\d{1,2})?$/.test(raw)) return null;
    const [whole, fraction = ''] = raw.split('.');
    return (Number(whole) * 100) + Number((fraction + '00').slice(0, 2));
  };

  document.querySelectorAll('form.bank-import-review').forEach(form => {
    const treatment = form.querySelector('[name="classification"]');
    const reviewStatus = form.querySelector('[name="review_status"]');
    const settlementStatus = form.querySelector('[name="settlement_status"]');
    const singleAccount = form.querySelector('[data-split-single-account]');
    const panel = form.querySelector('[data-split-panel]');
    const rows = form.querySelector('[data-split-rows]');
    const template = form.querySelector('template[data-split-template]');
    const totalText = form.querySelector('[data-split-total-text]');
    const remainingText = form.querySelector('[data-split-remaining]');
    const errorText = form.querySelector('[data-split-error]');
    const expectedCents = Number(panel?.dataset.expectedCents || 0);
    let nextIndex = rows.querySelectorAll('[data-split-row]').length;

    const isSplitMode = () =>
      treatment.value === 'SPLIT_REQUIRED'
      && reviewStatus.value !== 'IGNORED'
      && settlementStatus.value === 'POSTED';

    const addRow = () => {
      if (rows.querySelectorAll('[data-split-row]').length >= 12) {
        errorText.textContent = 'A transaction can have at most 12 split lines.';
        return;
      }

      const fragment = template.content.cloneNode(true);
      const index = nextIndex++;
      fragment.querySelectorAll('[data-name-template]').forEach(field => {
        field.name = field.dataset.nameTemplate.replace('__INDEX__', index);
      });
      rows.appendChild(fragment);
      updateMode();
    };

    const updateTotals = () => {
      const splitRows = [...rows.querySelectorAll('[data-split-row]')];
      let sum = 0;
      splitRows.forEach(row => {
        const amount = row.querySelector('[name$="[amount]"]');
        const cents = parseCents(amount?.value);
        if (cents !== null) sum += cents;
      });

      const remaining = expectedCents - sum;
      totalText.textContent = `Allocated ${formatMoney(sum)} of ${formatMoney(expectedCents)}`;
      if (remaining > 0) {
        remainingText.textContent = `${formatMoney(remaining)} remaining`;
      } else if (remaining < 0) {
        remainingText.textContent = `${formatMoney(Math.abs(remaining))} over`;
      } else {
        remainingText.textContent = 'Exact total';
      }
    };

    const updateMode = () => {
      const enabled = isSplitMode();
      panel.hidden = !enabled;
      singleAccount.disabled = enabled;
      panel.querySelectorAll('.bank-import-review-split-control').forEach(field => {
        field.disabled = !enabled;
      });
      errorText.textContent = '';
      updateTotals();
    };

    treatment.addEventListener('change', () => {
      if (
        isSplitMode()
        && rows.querySelectorAll('[data-split-row]').length === 0
      ) {
        addRow();
        addRow();
      }
      updateMode();
    });
    reviewStatus.addEventListener('change', updateMode);
    settlementStatus.addEventListener('change', updateMode);
    rows.addEventListener('input', updateTotals);
    rows.addEventListener('change', updateTotals);
    rows.addEventListener('click', event => {
      const removeButton = event.target.closest('[data-remove-split]');
      if (removeButton) {
        removeButton.closest('[data-split-row]').remove();
        updateTotals();
      }
    });
    form.querySelector('[data-add-split]').addEventListener('click', addRow);
    form.addEventListener('submit', event => {
      if (!isSplitMode()) return;

      const splitRows = [...rows.querySelectorAll('[data-split-row]')];
      const allLinesComplete = splitRows.every(row => {
        const account = row.querySelector('select');
        const amount = row.querySelector('[name$="[amount]"]');
        const cents = parseCents(amount?.value);
        return account?.value && cents !== null && cents > 0;
      });
      const sum = splitRows.reduce((total, row) => {
        const cents = parseCents(row.querySelector('[name$="[amount]"]')?.value);
        return total + (cents ?? 0);
      }, 0);

      if (
        reviewStatus.value !== 'READY'
        || settlementStatus.value !== 'POSTED'
        || splitRows.length < 2
        || !allLinesComplete
        || sum !== expectedCents
      ) {
        event.preventDefault();
        errorText.textContent = reviewStatus.value !== 'READY'
          ? 'Set Review to Ready and Bank status to Posted before saving split lines.'
          : 'Choose at least two accounts and enter positive amounts that total exactly to the bank transaction.';
      }
    });

    updateMode();
  });
})();
</script>

<?php page_footer(); ?>
