<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login(); accounting_require_ready(); csrf_check();

$message = null; $errors = [];
$contributionForm = [
  'journal_date' => date('Y-m-d'),
  'amount' => '0.00',
  'debit_account_id' => (string)accounting_default_cash_account_id(),
  'equity_account_id' => (string)accounting_default_owner_contribution_account_id(),
  'reference_number' => '',
  'memo' => 'Owner contribution',
];
$drawForm = [
  'journal_date' => date('Y-m-d'),
  'amount' => '0.00',
  'asset_account_id' => (string)accounting_default_cash_account_id(),
  'equity_account_id' => (string)accounting_default_owner_draw_account_id(),
  'reference_number' => '',
  'memo' => 'Owner draw',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $capitalAction = strtolower(trim((string)($_POST['capital_action'] ?? 'contribution')));
  if ($capitalAction === 'draw') {
    $drawForm = array_merge($drawForm, $_POST);
    $result = accounting_record_owner_draw($_POST, (int)(current_user()['user_id'] ?? 0));
    if (!empty($result['ok'])) {
      $message = (string)$result['message'];
      $drawForm['amount'] = '0.00';
      $drawForm['reference_number'] = '';
      $drawForm['memo'] = 'Owner draw';
    } else {
      $errors = $result['errors'] ?? ['Unable to post owner draw.'];
    }
  } else {
    $contributionForm = array_merge($contributionForm, $_POST);
    $result = accounting_record_capital_contribution($_POST, (int)(current_user()['user_id'] ?? 0));
    if (!empty($result['ok'])) {
      $message = (string)$result['message'];
      $contributionForm['amount'] = '0.00';
      $contributionForm['reference_number'] = '';
      $contributionForm['memo'] = 'Owner contribution';
    } else {
      $errors = $result['errors'] ?? ['Unable to post contribution.'];
    }
  }
}

$equityAccounts = accounting_account_options(['EQUITY']);
$assetAccounts = accounting_account_options(['ASSET']);
$history = accounting_capital_history(50);
$openBills = accounting_list_expenses(25, ['APPROVED']);
page_header('Capital, Owner Funding & Draws', 'accounting'); accounting_subnav('capital');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:1.35fr 1fr;gap:16px;align-items:start;">
  <div style="display:grid;gap:16px;">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;">
      <div class="card" style="padding:16px;">
        <h2 style="margin:0 0 12px;font-size:18px;">Post owner contribution</h2>
        <form method="post" style="display:grid;gap:12px;"><?= csrf_field() ?><input type="hidden" name="capital_action" value="contribution">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Date</label><br><input type="date" name="journal_date" value="<?= accounting_h((string)$contributionForm['journal_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Amount</label><br><input type="number" step="0.01" min="0.01" name="amount" value="<?= accounting_h((string)$contributionForm['amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
          <div><label>Account receiving funds / asset</label><br><select name="debit_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($assetAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$contributionForm['debit_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Equity account</label><br><select name="equity_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($equityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$contributionForm['equity_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)$contributionForm['reference_number']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Memo</label><br><textarea name="memo" rows="3" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)$contributionForm['memo']) ?></textarea></div>
          <div style="opacity:.74;font-size:13px;line-height:1.5;">Use this when you move personal funds into the business, fund checking, or record startup capital. This increases business cash/assets and increases owner equity.</div>
          <div><button type="submit" class="btn btn-secondary" style="width:auto;">Post contribution</button></div>
        </form>
      </div>

      <div class="card" style="padding:16px;">
        <h2 style="margin:0 0 12px;font-size:18px;">Post owner draw</h2>
        <form method="post" style="display:grid;gap:12px;"><?= csrf_field() ?><input type="hidden" name="capital_action" value="draw">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Date</label><br><input type="date" name="journal_date" value="<?= accounting_h((string)$drawForm['journal_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Amount</label><br><input type="number" step="0.01" min="0.01" name="amount" value="<?= accounting_h((string)$drawForm['amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
          <div><label>Paid from asset / bank account</label><br><select name="asset_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($assetAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$drawForm['asset_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Owner draw / equity account</label><br><select name="equity_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($equityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$drawForm['equity_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)$drawForm['reference_number']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Memo</label><br><textarea name="memo" rows="3" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)$drawForm['memo']) ?></textarea></div>
          <div style="opacity:.74;font-size:13px;line-height:1.5;">Use this when you take money out of the business for yourself. This reduces business cash/assets and records it against owner equity, not expense or payroll.</div>
          <div><button type="submit" class="btn btn-secondary" style="width:auto;">Post owner draw</button></div>
        </form>
      </div>
    </div>

    <div class="card" style="padding:16px;overflow:auto;">
      <h2 style="margin:0 0 10px;font-size:18px;">Approved bills you can fund personally</h2>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Vendor</th><th style="padding:10px 8px;">Status</th><th style="padding:10px 8px;text-align:right;">Amount</th><th style="padding:10px 8px;">Action</th></tr></thead>
        <tbody>
        <?php if (!$openBills): ?><tr><td colspan="4" style="padding:18px 8px;opacity:.75;">No open bills right now.</td></tr><?php else: foreach ($openBills as $bill): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;"><?= accounting_h((string)($bill['vendor_name'] ?? '—')) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$bill['expense_account_code']) ?> · <?= accounting_h((string)$bill['expense_account_name']) ?></div></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)$bill['status']) ?></td>
            <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$bill['total_amount'], 2) ?></td>
            <td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/pay_bill.php?expense_id=<?= (int)$bill['expense_id'] ?>" style="color:#dbeafe;">Pay from owner funds</a></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:18px;">Capital & owner activity</h2>
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Date</th><th style="padding:10px 8px;">Type</th><th style="padding:10px 8px;">Entry</th><th style="padding:10px 8px;">Accounts</th><th style="padding:10px 8px;text-align:right;">Amount</th></tr></thead>
      <tbody>
      <?php if (!$history): ?>
        <tr><td colspan="5" style="padding:18px 8px;opacity:.75;">No owner funding or draw activity posted yet.</td></tr>
      <?php else: foreach ($history as $row): ?>
        <?php $entryKind = strtoupper((string)($row['entry_kind'] ?? 'MANUAL')); $typeLabel = $entryKind === 'DRAW' ? 'Owner draw' : 'Contribution'; $typeStyles = $entryKind === 'DRAW'
          ? 'background:rgba(245,158,11,.18);border:1px solid rgba(245,158,11,.30);color:#fde68a;'
          : 'background:rgba(34,197,94,.18);border:1px solid rgba(34,197,94,.30);color:#bbf7d0;'; ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:10px 8px;"><?= accounting_h((string)$row['journal_date']) ?></td>
          <td style="padding:10px 8px;"><span style="display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;<?= $typeStyles ?>"><?= accounting_h($typeLabel) ?></span></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)($row['memo'] ?: ($entryKind === 'DRAW' ? 'Owner draw' : 'Owner contribution'))) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($row['reference_number'] ?: 'Manual capital entry')) ?></div></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)$row['debit_code']) ?> → <?= accounting_h((string)$row['credit_code']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$row['debit_name']) ?> / <?= accounting_h((string)$row['credit_name']) ?></div></td>
          <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$row['amount'], 2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
