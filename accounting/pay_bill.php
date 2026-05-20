<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login(); accounting_require_ready(); csrf_check();
$expenseId = (int)($_GET['expense_id'] ?? $_POST['expense_id'] ?? 0);
$bill = $expenseId > 0 ? accounting_get_expense($expenseId) : null;
$vendor = null;
if ($bill && !empty($bill['vendor_id'])) { $vendor = accounting_get_vendor((int)$bill['vendor_id']); }
if (!$bill) { http_response_code(404); echo 'Bill not found.'; exit; }
$message = null; $errors = [];
$form = [
  'payment_date' => (string)date('Y-m-d'),
  'reference_number' => (string)($bill['reference_number'] ?? ''),
  'payment_account_id' => (string)((int)($bill['payment_account_id'] ?? 0) ?: accounting_default_cash_account_id()),
  'memo' => '',
];
$canPay = ((string)($bill['status'] ?? '') === 'APPROVED') && !accounting_has_expense_payment_journal($expenseId);
if (!$canPay) {
  if (accounting_has_expense_payment_journal($expenseId) || (string)($bill['status'] ?? '') === 'PAID') {
    $errors[] = 'This bill has already been paid.';
  } else {
    $errors[] = 'Only approved bills can be paid.';
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = array_merge($form, $_POST);
  $result = accounting_pay_bill(
    $expenseId,
    (int)($_POST['payment_account_id'] ?? 0),
    trim((string)($_POST['payment_date'] ?? '')),
    (int)(current_user()['user_id'] ?? 0)
  );
  if (!empty($result['ok'])) {
    $message = (string)$result['message'];
    $bill = accounting_get_expense($expenseId);
    $canPay = false;
  } else {
    $errors = array_merge($errors, $result['errors'] ?? ['Unable to pay bill.']);
  }
}
page_header('Pay Bill', 'accounting'); accounting_subnav('bills');
$paymentAccounts = accounting_payment_account_options();
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div style="max-width:760px;margin:0 auto;display:grid;gap:16px;">
<div class="card" style="padding:16px;"><h2 style="margin:0 0 8px;font-size:18px;">Bill summary</h2><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;"><div><div style="opacity:.7;font-size:13px;">Vendor</div><div><?= accounting_h((string)($vendor['vendor_name'] ?? '—')) ?></div></div><div><div style="opacity:.7;font-size:13px;">Amount</div><div>$<?= number_format((float)$bill['total_amount'],2) ?></div></div><div><div style="opacity:.7;font-size:13px;">Status</div><div><?= accounting_h((string)$bill['status']) ?></div></div><div><div style="opacity:.7;font-size:13px;">Due date</div><div><?= accounting_h((string)($bill['due_date'] ?? '—')) ?></div></div></div></div>
<div class="card" style="padding:16px;"><h2 style="margin:0 0 12px;font-size:18px;">Apply payment</h2><form method="post" style="display:grid;gap:12px;"><?= csrf_field() ?><input type="hidden" name="expense_id" value="<?= (int)$expenseId ?>"><div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Payment date</label><br><input type="date" name="payment_date" value="<?= accounting_h((string)($form['payment_date'] ?? date('Y-m-d'))) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)($form['reference_number'] ?? ($bill['reference_number'] ?? ''))) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div><div><label>Payment account</label><br><select name="payment_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select payment account</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payment_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div><div><label>Memo</label><br><textarea name="memo" rows="3" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($form['memo'] ?? '')) ?></textarea></div><div><button type="submit" <?= $canPay ? '' : 'disabled' ?> style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:<?= $canPay ? 'pointer' : 'not-allowed' ?>;opacity:<?= $canPay ? '1' : '.6' ?>;">Pay bill</button></div></form></div></div>
<?php page_footer(); ?>
