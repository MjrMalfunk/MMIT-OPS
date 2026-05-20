<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$userId = (int)(current_user()['user_id'] ?? 0);
$ready = accounting_bank_reconciliation_ready();
$accountOptions = accounting_bank_reconciliation_account_options();
$defaultAccountId = accounting_default_reconciliation_account_id();
$selectedAccountId = (int)($_POST['account_id'] ?? $_GET['account_id'] ?? $defaultAccountId);
if ($selectedAccountId <= 0 && $accountOptions) {
    $selectedAccountId = (int)($accountOptions[0]['account_id'] ?? 0);
}
$statementEndingDate = trim((string)($_POST['statement_ending_date'] ?? $_GET['statement_ending_date'] ?? date('Y-m-d')));
if ($statementEndingDate === '') {
    $statementEndingDate = date('Y-m-d');
}

$message = null;
$messageType = 'ok';
if (!empty($_GET['saved'])) {
    $message = !empty($_GET['completed']) ? 'Reconciliation completed.' : 'Reconciliation saved.';
    $messageType = !empty($_GET['warning']) ? 'warn' : 'ok';
    if (!empty($_GET['warning'])) {
        $message = 'Reconciliation saved, but the difference must be $0.00 before it can be completed.';
    }
}
$errors = [];

$reconciliation = $ready ? accounting_get_bank_reconciliation_by_statement($selectedAccountId, $statementEndingDate) : null;
$statementEndingBalance = isset($_POST['statement_ending_balance']) ? (float)$_POST['statement_ending_balance'] : (float)($reconciliation['statement_ending_balance'] ?? 0);
$notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : trim((string)($reconciliation['notes'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_reconciliation') {
    if (!$ready) {
        $errors[] = 'Run the bank reconciliation SQL patch first.';
    } else {
        $complete = ((string)($_POST['submit_mode'] ?? 'save') === 'complete');
        $result = accounting_save_bank_reconciliation(
            $selectedAccountId,
            $statementEndingDate,
            (float)($_POST['statement_ending_balance'] ?? 0),
            $_POST['selected_lines'] ?? [],
            $userId,
            (string)($_POST['notes'] ?? ''),
            $complete
        );
        if (!empty($result['ok'])) {
            $query = http_build_query([
                'account_id' => $selectedAccountId,
                'statement_ending_date' => $statementEndingDate,
                'saved' => 1,
                'completed' => !empty($result['completed']) ? 1 : 0,
                'warning' => !empty($result['warning']) ? 1 : 0,
            ]);
            header('Location: ' . BASE_URL . '/accounting/reconcile.php?' . $query, true, 302);
            exit;
        }
        $errors = array_merge($errors, $result['errors'] ?? ['Unable to save reconciliation.']);
    }
}

$selectedAccount = $selectedAccountId > 0 ? accounting_get_account($selectedAccountId) : null;
$reconciliation = $ready ? accounting_get_bank_reconciliation_by_statement($selectedAccountId, $statementEndingDate) : null;
$reconciliationId = (int)($reconciliation['reconciliation_id'] ?? 0);
$previousReconciliation = $ready ? accounting_previous_bank_reconciliation($selectedAccountId, $statementEndingDate) : null;
if (!isset($_POST['statement_ending_balance']) && $ready) {
    if ($reconciliation) {
        $statementEndingBalance = (float)$reconciliation['statement_ending_balance'];
    } else {
        $statementEndingBalance = accounting_bank_ledger_balance($selectedAccountId, $statementEndingDate);
    }
}
if (!isset($_POST['notes']) && $reconciliation) {
    $notes = trim((string)($reconciliation['notes'] ?? ''));
}

$activity = $ready ? accounting_bank_reconciliation_activity($selectedAccountId, $statementEndingDate, $reconciliationId > 0 ? $reconciliationId : null) : [];
$selectedLineIds = [];
foreach ($activity as $row) {
    if ($reconciliationId > 0 && (int)($row['selected_reconciliation_id'] ?? 0) === $reconciliationId) {
        $selectedLineIds[] = (int)$row['journal_line_id'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_lines']) && is_array($_POST['selected_lines'])) {
    $selectedLineIds = array_values(array_unique(array_filter(array_map('intval', $_POST['selected_lines']), static fn($id) => $id > 0)));
}
$selectedLineMap = array_fill_keys($selectedLineIds, true);

$manualSelectedTotal = 0.0;
foreach ($activity as $row) {
    if (isset($selectedLineMap[(int)$row['journal_line_id']])) {
        $manualSelectedTotal += (float)$row['signed_amount'];
    }
}
$priorClearedBalance = $ready ? accounting_bank_reconciliation_prior_cleared_balance($selectedAccountId, $statementEndingDate, $reconciliationId > 0 ? $reconciliationId : null) : 0.0;
$ledgerBalance = $ready ? accounting_bank_ledger_balance($selectedAccountId, $statementEndingDate) : 0.0;
$clearedBalance = round($priorClearedBalance + $manualSelectedTotal, 2);
$difference = round((float)$statementEndingBalance - $clearedBalance, 2);
$outstandingBalance = round($ledgerBalance - $clearedBalance, 2);
$outstandingCount = 0;
foreach ($activity as $row) {
    if (!isset($selectedLineMap[(int)$row['journal_line_id']])) {
        $outstandingCount++;
    }
}
$recentReconciliations = $ready ? accounting_list_recent_bank_reconciliations($selectedAccountId > 0 ? $selectedAccountId : null, 8) : [];
$previousStatementDate = trim((string)($previousReconciliation['statement_ending_date'] ?? ''));

page_header('Bank Reconciliation', 'accounting');
accounting_subnav('reconcile');
?>
<style>
.recon-page { display:grid; gap:16px; }
.recon-top { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:16px; align-items:start; }
.recon-main { display:grid; gap:16px; }
.recon-sidebar { display:grid; gap:16px; }
.recon-filter-form, .recon-setup-form { display:grid; gap:12px; }
.recon-filter-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; align-items:end; }
.recon-setup-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.recon-filter-form input, .recon-filter-form select, .recon-setup-form input, .recon-setup-form select, .recon-setup-form textarea { width:100%; padding:10px 12px; box-sizing:border-box; }
.recon-summary-grid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:12px; }
.recon-balance-good { color:#bbf7d0; }
.recon-balance-warn { color:#fde68a; }
.recon-balance-bad { color:#fecaca; }
.recon-table-wrap { overflow:auto; }
.recon-table { width:100%; border-collapse:collapse; }
.recon-table th, .recon-table td { padding:10px 8px; border-bottom:1px solid rgba(255,255,255,.06); vertical-align:top; }
.recon-table .num { text-align:right; white-space:nowrap; }
.recon-row-selected { background:rgba(34,197,94,.07); }
.recon-row-outstanding-old { background:rgba(245,158,11,.06); }
.recon-meta { font-size:12px; opacity:.72; }
.recon-badge { display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px; font-size:12px; font-weight:700; }
.recon-badge-open { background:rgba(59,130,246,.18); border:1px solid rgba(59,130,246,.28); color:#bfdbfe; }
.recon-badge-completed { background:rgba(34,197,94,.18); border:1px solid rgba(34,197,94,.28); color:#bbf7d0; }
@media (max-width: 1180px) { .recon-top { grid-template-columns:1fr; } .recon-summary-grid { grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (max-width: 760px) { .recon-filter-grid, .recon-setup-grid, .recon-summary-grid { grid-template-columns:1fr; } }
</style>

<?php if ($message): ?>
  <div class="card" style="padding:14px 16px;border-color:<?= $messageType === 'ok' ? 'rgba(34,197,94,.35)' : 'rgba(250,204,21,.35)' ?>;background:<?= $messageType === 'ok' ? 'rgba(34,197,94,.10)' : 'rgba(245,158,11,.12)' ?>;margin-bottom:16px;"><?= accounting_h($message) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="card" style="padding:14px 16px;border-color:rgba(248,113,113,.32);background:rgba(127,29,29,.30);margin-bottom:16px;">
    <?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="recon-page">
  <div class="card" style="padding:18px;">
    <form method="get" class="recon-filter-form">
      <div class="recon-filter-grid">
        <div>
          <label>Bank account</label>
          <select name="account_id">
            <?php foreach ($accountOptions as $account): ?>
              <option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === $selectedAccountId ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Statement ending date</label>
          <input type="date" name="statement_ending_date" value="<?= accounting_h($statementEndingDate) ?>">
        </div>
        <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
          <button type="submit" class="btn btn-secondary">Load statement</button>
          <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="text-decoration:none;">Open payment register</a>
        </div>
      </div>
    </form>
    <?php if ($selectedAccount): ?>
      <div class="recon-meta" style="margin-top:10px;">
        Reconciling <?= accounting_h(accounting_account_option_label($selectedAccount)) ?> through <?= accounting_h($statementEndingDate) ?>.
        <?php if ($previousReconciliation): ?>
          Previous statement ended <?= accounting_h((string)$previousReconciliation['statement_ending_date']) ?>.
        <?php else: ?>
          No previous reconciliations found for this bank account yet.
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$ready): ?>
    <div class="card" style="padding:18px;">
      <h2 style="margin:0 0 10px;font-size:20px;">Bank reconciliation tables are not installed yet</h2>
      <div style="opacity:.8;line-height:1.55;">Run <code>sql/2026_03_27_bank_reconciliation_first_pass.sql</code>, then reload this page. Once that patch is in, you will be able to save open reconciliations, tick cleared cash activity, and complete a statement when the difference reaches zero.</div>
    </div>
  <?php else: ?>
    <div class="recon-summary-grid">
      <div class="card metric-card"><div class="metric-label">Ledger balance</div><div class="metric-value">$<?= number_format($ledgerBalance, 2) ?></div></div>
      <div class="card metric-card"><div class="metric-label">Previously reconciled</div><div class="metric-value">$<?= number_format($priorClearedBalance, 2) ?></div></div>
      <div class="card metric-card"><div class="metric-label">Selected this statement</div><div class="metric-value">$<?= number_format($manualSelectedTotal, 2) ?></div></div>
      <div class="card metric-card"><div class="metric-label">Cleared balance</div><div class="metric-value">$<?= number_format($clearedBalance, 2) ?></div></div>
      <div class="card metric-card"><div class="metric-label">Outstanding</div><div class="metric-value">$<?= number_format($outstandingBalance, 2) ?></div></div>
      <div class="card metric-card"><div class="metric-label">Difference</div><div class="metric-value <?= abs($difference) <= 0.009 ? 'recon-balance-good' : (abs($difference) < 25 ? 'recon-balance-warn' : 'recon-balance-bad') ?>">$<?= number_format($difference, 2) ?></div></div>
    </div>

    <div class="recon-top">
      <div class="recon-main">
        <form method="post" class="card recon-setup-form" style="padding:18px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_reconciliation">
          <input type="hidden" name="account_id" value="<?= (int)$selectedAccountId ?>">
          <input type="hidden" name="statement_ending_date" value="<?= accounting_h($statementEndingDate) ?>">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
            <div>
              <h2 style="margin:0 0 4px;font-size:20px;">Statement setup</h2>
              <div class="recon-meta">Save work as you go, then complete the statement once the difference hits zero.</div>
            </div>
            <?php if ($reconciliation): ?>
              <div class="recon-badge <?= strtoupper((string)$reconciliation['status']) === 'COMPLETED' ? 'recon-badge-completed' : 'recon-badge-open' ?>"><?= accounting_h((string)$reconciliation['status']) ?></div>
            <?php endif; ?>
          </div>
          <div class="recon-setup-grid">
            <div>
              <label>Statement ending balance</label>
              <input type="number" step="0.01" name="statement_ending_balance" value="<?= accounting_h(number_format((float)$statementEndingBalance, 2, '.', '')) ?>">
            </div>
            <div>
              <label>Statement ending date</label>
              <input type="date" value="<?= accounting_h($statementEndingDate) ?>" disabled>
            </div>
          </div>
          <div>
            <label>Notes</label>
            <textarea name="notes" rows="3" style="resize:vertical;"><?= accounting_h($notes) ?></textarea>
          </div>
          <div class="recon-table-wrap">
            <table class="recon-table">
              <thead>
                <tr>
                  <th style="width:44px;">Clear</th>
                  <th>Date</th>
                  <th>Source</th>
                  <th>Party / memo</th>
                  <th class="num">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$activity): ?>
                  <tr><td colspan="5" class="empty-state">No posted bank activity matched this statement yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($activity as $row): ?>
                    <?php
                      $lineId = (int)$row['journal_line_id'];
                      $isSelected = isset($selectedLineMap[$lineId]);
                      $isPriorOutstanding = $previousStatementDate !== '' && (string)$row['journal_date'] <= $previousStatementDate && !$isSelected;
                      $party = trim((string)($row['vendor_name'] ?: ($row['dba_name'] ?: $row['legal_name'])));
                      $sourceLabel = accounting_bank_reconciliation_source_label($row);
                      $sourceHref = accounting_bank_reconciliation_source_href($row);
                    ?>
                    <tr class="<?= $isSelected ? 'recon-row-selected' : ($isPriorOutstanding ? 'recon-row-outstanding-old' : '') ?>">
                      <td>
                        <input type="checkbox" name="selected_lines[]" value="<?= $lineId ?>" <?= $isSelected ? 'checked' : '' ?>>
                      </td>
                      <td>
                        <div><?= accounting_h((string)$row['journal_date']) ?></div>
                        <?php if ($isPriorOutstanding): ?><div class="recon-meta">Prior outstanding item</div><?php endif; ?>
                      </td>
                      <td>
                        <?php if ($sourceHref): ?>
                          <a href="<?= accounting_h($sourceHref) ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;"><?= accounting_h($sourceLabel) ?></a>
                        <?php else: ?>
                          <div><?= accounting_h($sourceLabel) ?></div>
                        <?php endif; ?>
                        <div class="recon-meta">Journal #<?= (int)$row['journal_id'] ?> · line <?= (int)$row['line_number'] ?></div>
                      </td>
                      <td>
                        <div><?= $party !== '' ? accounting_h($party) : '—' ?></div>
                        <div class="recon-meta"><?= accounting_h((string)($row['line_memo'] ?: $row['journal_memo'] ?: '—')) ?></div>
                      </td>
                      <td class="num"><?= ((float)$row['signed_amount'] >= 0 ? '' : '-') ?>$<?= number_format(abs((float)$row['signed_amount']), 2) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-start;">
            <button type="submit" name="submit_mode" value="save" class="btn btn-secondary">Save work</button>
            <button type="submit" name="submit_mode" value="complete" class="btn btn-primary" <?= abs($difference) > 0.009 ? '' : '' ?>>Complete reconciliation</button>
          </div>
        </form>
      </div>

      <div class="recon-sidebar">
        <div class="card" style="padding:18px;">
          <h2 style="margin:0 0 12px;font-size:18px;">Statement snapshot</h2>
          <div style="display:grid;gap:10px;">
            <div><div class="recon-meta">Selected items</div><div style="font-weight:700;"><?= count($selectedLineIds) ?></div></div>
            <div><div class="recon-meta">Outstanding items</div><div style="font-weight:700;"><?= (int)$outstandingCount ?></div></div>
            <div><div class="recon-meta">Previous statement</div><div><?= $previousStatementDate !== '' ? accounting_h($previousStatementDate) : '—' ?></div></div>
            <?php if ($reconciliation): ?>
              <div><div class="recon-meta">Saved by</div><div><?= accounting_h((string)($reconciliation['created_by_name'] ?: 'System')) ?></div></div>
              <div><div class="recon-meta">Last updated</div><div><?= accounting_h((string)($reconciliation['updated_at'] ?? '—')) ?></div></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="padding:18px;">
          <h2 style="margin:0 0 12px;font-size:18px;">Recent reconciliations</h2>
          <div style="display:grid;gap:10px;">
            <?php if (!$recentReconciliations): ?>
              <div class="recon-meta">No reconciliations saved yet.</div>
            <?php else: ?>
              <?php foreach ($recentReconciliations as $item): ?>
                <a href="<?= accounting_h(BASE_URL) ?>/accounting/reconcile.php?account_id=<?= (int)$item['account_id'] ?>&statement_ending_date=<?= accounting_h((string)$item['statement_ending_date']) ?>" style="display:block;padding:12px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.22);background:rgba(59,130,246,.10);color:#dbeafe;text-decoration:none;">
                  <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                    <div style="font-weight:700;"><?= accounting_h((string)$item['statement_ending_date']) ?></div>
                    <span class="recon-badge <?= strtoupper((string)$item['status']) === 'COMPLETED' ? 'recon-badge-completed' : 'recon-badge-open' ?>"><?= accounting_h((string)$item['status']) ?></span>
                  </div>
                  <div class="recon-meta" style="margin-top:6px;"><?= accounting_h((string)$item['account_code']) ?> · <?= accounting_h((string)$item['account_name']) ?></div>
                  <div style="margin-top:6px;">$<?= number_format((float)$item['statement_ending_balance'], 2) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="card" style="padding:18px;">
          <h2 style="margin:0 0 12px;font-size:18px;">Shortcuts</h2>
          <div style="display:grid;gap:10px;">
            <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open payment register</a>
            <a href="<?= accounting_h(BASE_URL) ?>/accounting/bills.php" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open bills</a>
            <a href="<?= accounting_h(BASE_URL) ?>/accounting/capital.php" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open capital</a>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
