<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$message = !empty($_GET['message']) ? (string)$_GET['message'] : (!empty($_GET['approved']) ? 'Bill approved.' : (!empty($_GET['updated']) ? 'Bill updated.' : null)); $errors = []; if (!empty($_GET['error'])) { $errors[] = (string)$_GET['error']; }
$user = current_user();
$defaults = [
  'expense_date' => date('Y-m-d'), 'posting_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime('+15 days')),
  'status' => 'DRAFT', 'subtotal_amount' => '', 'tax_amount' => '0.00',
  'payable_account_id' => (string)(accounting_find_account_id_by_code('2000') ?? 0),
];
$form = $defaults;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $form = array_merge($form, $_POST);
  $result = accounting_create_expense($_POST, (int)($user['user_id'] ?? 0));
  if (!empty($result['ok'])) { $message = (string)$result['message']; $form = $defaults; } else { $errors = $result['errors'] ?? ['Unable to save bill.']; }
}
$vendors = accounting_list_vendors();
$expenseAccounts = accounting_account_options(['EXPENSE']);
$liabilityAccounts = accounting_account_options(['LIABILITY']);
$bills = accounting_list_expenses(100);
page_header('Bills', 'accounting'); accounting_subnav('bills');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:1.15fr 1.85fr;gap:16px;align-items:start;">
<div class="card" style="padding:16px;">
<h2 style="margin:0 0 6px;font-size:18px;">Enter vendor bill</h2><div style="opacity:.74;font-size:13px;margin-bottom:12px;">New bills can start as Draft or Submitted. Submitted bills wait for approval before they can be paid.</div>
<form method="post" style="display:grid;gap:12px;"><?= csrf_field() ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Bill date</label><br><input type="date" name="expense_date" value="<?= accounting_h((string)$form['expense_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Posting date</label><br><input type="date" name="posting_date" value="<?= accounting_h((string)$form['posting_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
<div><label>Due date</label><br><input type="date" name="due_date" value="<?= accounting_h((string)$form['due_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
<div><label>Vendor</label><br><select name="vendor_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select vendor</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int)$vendor['vendor_id'] ?>" <?= ((int)($form['vendor_id'] ?? 0) === (int)$vendor['vendor_id']) ? 'selected' : '' ?>><?= accounting_h((string)$vendor['vendor_name']) ?></option><?php endforeach; ?></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Subtotal</label><br><input type="number" step="0.01" min="0" name="subtotal_amount" value="<?= accounting_h((string)$form['subtotal_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Tax</label><br><input type="number" step="0.01" min="0" name="tax_amount" value="<?= accounting_h((string)$form['tax_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
<div><label>Status</label><br><select name="status" style="width:100%;padding:10px;box-sizing:border-box;"><option value="DRAFT" <?= (($form['status'] ?? '') === 'DRAFT') ? 'selected' : '' ?>>DRAFT</option><option value="SUBMITTED" <?= (($form['status'] ?? '') === 'SUBMITTED') ? 'selected' : '' ?>>SUBMITTED</option></select></div>
<div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)($form['reference_number'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
<div><label>Expense category account</label><br><select name="expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select category</option><?php foreach ($expenseAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['expense_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Accounts payable account</label><br><select name="payable_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($liabilityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payable_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Preferred payment account (optional)</label><br><select name="payment_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select payment account later</option><?php foreach (accounting_account_options(['ASSET','LIABILITY','EQUITY']) as $account): ?><option value="<?= (int)$account['account_id'] ?>"><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Memo</label><br><textarea name="memo" rows="4" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($form['memo'] ?? '')) ?></textarea></div>
<div style="opacity:.72;font-size:13px;line-height:1.45;">Draft stays editable. Submitted bills post to accounts payable and wait for approval. Approved bills can be paid from the bill payment screen.</div>
<div><button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;">Save bill</button></div>
</form></div>
<div class="card" style="padding:16px;overflow:auto;"><h2 style="margin:0 0 12px;font-size:18px;">Bill register</h2><table style="width:100%;border-collapse:collapse;"><thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Vendor</th><th style="padding:10px 8px;">Due</th><th style="padding:10px 8px;">Status</th><th style="padding:10px 8px;">Posting</th><th style="padding:10px 8px;text-align:right;">Total</th><th style="padding:10px 8px;">Receipts</th><th style="padding:10px 8px;">Action</th></tr></thead><tbody>
<?php if (!$bills): ?><tr><td colspan="7" style="padding:18px 8px;opacity:.75;">No bills entered yet.</td></tr><?php else: foreach ($bills as $bill): ?><?php
$receiptCount = accounting_count_expense_attachments((int)($bill['expense_id'] ?? 0));
?><?php
$status = strtoupper(trim((string)($bill['status'] ?? '')));
$isPosted = !empty($bill['has_journal']);
$hasPayment = accounting_has_expense_payment_journal((int)($bill['expense_id'] ?? 0));
$canEditBill = ($status === 'DRAFT') && !$isPosted && !$hasPayment;
$canApproveBill = ($status === 'SUBMITTED') && !$hasPayment;
$canPayBill = ($status === 'APPROVED') && !$hasPayment;
?><tr style="border-bottom:1px solid rgba(255,255,255,.06)"><td style="padding:10px 8px;"><?= accounting_h((string)($bill['vendor_name'] ?? '—')) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$bill['expense_account_code']) ?> · <?= accounting_h((string)$bill['expense_account_name']) ?></div></td><td style="padding:10px 8px;"><?= accounting_h((string)($bill['due_date'] ?? '—')) ?></td><td style="padding:10px 8px;"><?= accounting_h((string)$bill['status']) ?></td><td style="padding:10px 8px;"><?= $isPosted ? 'Posted' : 'Not posted' ?></td><td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$bill['total_amount'],2) ?></td><td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/expense_receipts.php?expense_id=<?= (int)$bill['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Receipts<?= $receiptCount > 0 ? ' (' . $receiptCount . ')' : '' ?></a></td><td style="padding:10px 8px;"><?php if ($canEditBill): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/bill_edit.php?id=<?= (int)$bill['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Edit draft</a><?php elseif ($canApproveBill): ?><form method="post" action="<?= accounting_h(BASE_URL) ?>/accounting/approve_bill.php" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="expense_id" value="<?= (int)$bill['expense_id'] ?>"><input type="hidden" name="return_to" value="bills"><button type="submit" class="btn btn-secondary" style="width:auto;">Approve</button></form><?php elseif ($canPayBill): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/pay_bill.php?expense_id=<?= (int)$bill['expense_id'] ?>" style="color:#dbeafe;font-weight:600;">Pay bill</a><?php elseif ($status === 'DRAFT'): ?><span style="opacity:.72;">Draft saved</span><?php else: ?><span style="opacity:.6;">Posted</span><?php endif; ?></td></tr><?php endforeach; endif; ?>
</tbody></table></div></div>
<?php page_footer(); ?>
