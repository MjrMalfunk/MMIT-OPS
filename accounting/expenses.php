<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();

csrf_check();
$message = !empty($_GET['message']) ? (string)$_GET['message'] : (!empty($_GET['approved']) ? 'Bill approved.' : null);
$errors = [];
if (!empty($_GET['error'])) {
    $errors[] = (string)$_GET['error'];
}
$user = current_user();
$defaults = [
    'expense_date' => date('Y-m-d'),
    'posting_date' => date('Y-m-d'),
    'status' => 'DRAFT',
    'subtotal_amount' => '',
    'tax_amount' => '0.00',
    'payable_account_id' => (string)(accounting_find_account_id_by_code('2000') ?? 0),
    'payment_account_id' => (string)(accounting_find_account_id_by_code(defined('GL_DEFAULT_CASH_ACCT_NO') ? GL_DEFAULT_CASH_ACCT_NO : '1000') ?? 0),
];
$form = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, $_POST);
    $result = accounting_create_expense($_POST, (int)($user['user_id'] ?? 0));
    if (!empty($result['ok'])) {
        $message = (string)$result['message'];
        $form = $defaults;
    } else {
        $errors = $result['errors'] ?? ['Unable to save expense.'];
    }
}

$vendors = accounting_list_vendors();
$businessLines = accounting_business_line_options();
$expenseAccounts = accounting_account_options(['EXPENSE']);
$liabilityAccounts = accounting_account_options(['LIABILITY']);
$assetLiabilityAccounts = accounting_account_options(['ASSET', 'LIABILITY']);
$expenses = accounting_list_expenses(60);

page_header('Expenses', 'accounting');
accounting_subnav('expenses');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1.2fr 2fr;gap:16px;align-items:start;">
  <div class="card" style="padding:16px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Enter expense</h2>
    <form method="post" style="display:grid;gap:12px;">
      <?= csrf_field() ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><label>Expense date</label><br><input type="date" name="expense_date" value="<?= accounting_h((string)$form['expense_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
        <div><label>Posting date</label><br><input type="date" name="posting_date" value="<?= accounting_h((string)$form['posting_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      </div>
      <div><label>Vendor</label><br><select name="vendor_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select vendor</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int)$vendor['vendor_id'] ?>" <?= ((int)($form['vendor_id'] ?? 0) === (int)$vendor['vendor_id']) ? 'selected' : '' ?>><?= accounting_h((string)$vendor['vendor_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Business line</label><br><select name="business_line_id" required style="width:100%;padding:10px;box-sizing:border-box;"><option value="">Choose business line</option><?php foreach ($businessLines as $businessLine): ?><option value="<?= (int)$businessLine['business_line_id'] ?>" <?= ((int)($form['business_line_id'] ?? 0) === (int)$businessLine['business_line_id']) ? 'selected' : '' ?>><?= accounting_h((string)$businessLine['business_line_name']) ?></option><?php endforeach; ?></select></div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div><label>Subtotal</label><br><input type="number" step="0.01" min="0" name="subtotal_amount" value="<?= accounting_h((string)$form['subtotal_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
        <div><label>Tax</label><br><input type="number" step="0.01" min="0" name="tax_amount" value="<?= accounting_h((string)$form['tax_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
        <div><label>Status</label><br><select name="status" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach (['DRAFT', 'SUBMITTED'] as $status): ?><option value="<?= accounting_h($status) ?>" <?= (($form['status'] ?? 'DRAFT') === $status) ? 'selected' : '' ?>><?= accounting_h($status) ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)($form['reference_number'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Expense category account</label><br><select name="expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select category</option><?php foreach ($expenseAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['expense_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div><label>Payable account</label><br><select name="payable_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select liability account</option><?php foreach ($liabilityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payable_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Payment account</label><br><select name="payment_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select cash/card account</option><?php foreach ($assetLiabilityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payment_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label>Memo</label><br><textarea name="memo" rows="4" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($form['memo'] ?? '')) ?></textarea></div>
      <div style="opacity:.72;font-size:13px;line-height:1.45;">Draft keeps the expense saved without GL posting. Submitted posts to accounts payable and waits for approval. Approved bills can be paid from the bill payment screen.</div>
      <div><button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;">Save expense</button></div>
    </form>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:18px;">Expense register</h2>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
      <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
        <th style="padding:10px 8px;">Date</th>
        <th style="padding:10px 8px;">Vendor</th>
        <th style="padding:10px 8px;">Business line</th>
        <th style="padding:10px 8px;">Category</th>
        <th style="padding:10px 8px;">Status</th>
        <th style="padding:10px 8px;">Posting</th>
        <th style="padding:10px 8px;text-align:right;">Total</th>
        <th style="padding:10px 8px;">Receipts</th>
        <th style="padding:10px 8px;">Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$expenses): ?>
        <tr><td colspan="9" style="padding:18px 8px;opacity:.75;">No expenses entered yet.</td></tr>
      <?php else: ?>
        <?php foreach ($expenses as $expense): ?>
          <?php
          $receiptCount = accounting_count_expense_attachments((int)($expense['expense_id'] ?? 0));
          $expenseStatus = strtoupper(trim((string)($expense['status'] ?? '')));
          $hasPayment = accounting_has_expense_payment_journal((int)($expense['expense_id'] ?? 0));
          $canEditExpense = ($expenseStatus === 'DRAFT') && empty($expense['has_journal']) && !$hasPayment;
          $canApproveExpense = ($expenseStatus === 'SUBMITTED') && !$hasPayment;
          $canPayExpense = ($expenseStatus === 'APPROVED') && !$hasPayment;
          ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;"><?= accounting_h((string)$expense['expense_date']) ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)($expense['vendor_name'] ?? '—')) ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)($expense['business_line_name'] ?? 'Unclassified')) ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)$expense['expense_account_code']) ?> · <?= accounting_h((string)$expense['expense_account_name']) ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)$expense['status']) ?></td>
            <td style="padding:10px 8px;"><?= !empty($expense['has_journal']) ? 'Posted' : 'Not posted' ?><div style="opacity:.65;font-size:12px;"><?= !empty($expense['payment_account_code']) ? accounting_h((string)$expense['payment_account_code'] . ' · ' . (string)$expense['payment_account_name']) : accounting_h((string)$expense['payable_account_code'] . ' · ' . (string)$expense['payable_account_name']) ?></div></td>
            <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$expense['total_amount'], 2) ?></td>
            <td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/expense_receipts.php?expense_id=<?= (int)$expense['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Receipts<?= $receiptCount > 0 ? ' (' . $receiptCount . ')' : '' ?></a></td>
            <td style="padding:10px 8px;"><?php if ($canEditExpense): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/bill_edit.php?id=<?= (int)$expense['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Edit draft</a><?php elseif ($canApproveExpense): ?><form method="post" action="<?= accounting_h(BASE_URL) ?>/accounting/approve_bill.php" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="expense_id" value="<?= (int)$expense['expense_id'] ?>"><input type="hidden" name="return_to" value="expenses"><button type="submit" class="btn btn-secondary" style="width:auto;">Approve</button></form><?php elseif ($canPayExpense): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/pay_bill.php?expense_id=<?= (int)$expense['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Pay bill</a><?php else: ?><span style="opacity:.55;">—</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
