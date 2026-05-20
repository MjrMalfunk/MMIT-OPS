<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$expenseId = (int)($_GET['id'] ?? $_POST['expense_id'] ?? 0);
$bill = $expenseId > 0 ? accounting_get_expense($expenseId) : null;
if (!$bill) {
    http_response_code(404);
    exit('Bill not found.');
}
if (!accounting_can_edit_expense($bill)) {
    http_response_code(400);
    exit('Only unposted draft bills can be edited.');
}

$message = null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
$form = [
    'vendor_id' => (string)($bill['vendor_id'] ?? '0'),
    'expense_date' => (string)($bill['expense_date'] ?? date('Y-m-d')),
    'posting_date' => (string)($bill['posting_date'] ?? date('Y-m-d')),
    'due_date' => (string)($bill['due_date'] ?? ''),
    'status' => (string)($bill['status'] ?? 'DRAFT'),
    'subtotal_amount' => (string)($bill['subtotal_amount'] ?? '0.00'),
    'tax_amount' => (string)($bill['tax_amount'] ?? '0.00'),
    'reference_number' => (string)($bill['reference_number'] ?? ''),
    'expense_account_id' => (string)($bill['expense_account_id'] ?? '0'),
    'payable_account_id' => (string)($bill['payable_account_id'] ?? (accounting_find_account_id_by_code('2000') ?? 0)),
    'payment_account_id' => (string)($bill['payment_account_id'] ?? '0'),
    'memo' => (string)($bill['memo'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, $_POST);
    $result = accounting_update_expense($expenseId, $_POST, $userId);
    if (!empty($result['ok'])) {
        header('Location: ' . BASE_URL . '/accounting/bills.php?updated=1');
        exit;
    }
    $errors = $result['errors'] ?? ['Unable to update bill.'];
}

$vendors = accounting_list_vendors();
$expenseAccounts = accounting_account_options(['EXPENSE']);
$liabilityAccounts = accounting_account_options(['LIABILITY']);
$paymentAccounts = accounting_account_options(['ASSET','LIABILITY','EQUITY']);
$receiptCount = accounting_count_expense_attachments($expenseId);

page_header('Edit Bill', 'accounting');
accounting_subnav('bills');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card" style="padding:16px;max-width:900px;">
<h2 style="margin:0 0 12px;font-size:18px;">Edit draft bill</h2>
<form method="post" style="display:grid;gap:12px;">
<?= csrf_field() ?>
<input type="hidden" name="expense_id" value="<?= (int)$expenseId ?>">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Bill date</label><br><input type="date" name="expense_date" value="<?= accounting_h((string)$form['expense_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Posting date</label><br><input type="date" name="posting_date" value="<?= accounting_h((string)$form['posting_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
<div><label>Due date</label><br><input type="date" name="due_date" value="<?= accounting_h((string)$form['due_date']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
<div><label>Vendor</label><br><select name="vendor_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select vendor</option><?php foreach ($vendors as $vendor): ?><option value="<?= (int)$vendor['vendor_id'] ?>" <?= ((int)($form['vendor_id'] ?? 0) === (int)$vendor['vendor_id']) ? 'selected' : '' ?>><?= accounting_h((string)$vendor['vendor_name']) ?></option><?php endforeach; ?></select></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;"><div><label>Subtotal</label><br><input type="number" step="0.01" min="0" name="subtotal_amount" value="<?= accounting_h((string)$form['subtotal_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div><div><label>Tax</label><br><input type="number" step="0.01" min="0" name="tax_amount" value="<?= accounting_h((string)$form['tax_amount']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div></div>
<div><label>Status</label><br><select name="status" style="width:100%;padding:10px;box-sizing:border-box;"><option value="DRAFT" <?= (($form['status'] ?? '') === 'DRAFT') ? 'selected' : '' ?>>DRAFT</option><option value="SUBMITTED" <?= (($form['status'] ?? '') === 'SUBMITTED') ? 'selected' : '' ?>>SUBMITTED</option></select></div>
<div><label>Reference number</label><br><input type="text" name="reference_number" value="<?= accounting_h((string)($form['reference_number'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
<div><label>Expense category account</label><br><select name="expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select category</option><?php foreach ($expenseAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['expense_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Accounts payable account</label><br><select name="payable_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($liabilityAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payable_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Payment account</label><br><select name="payment_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select payment account</option><?php foreach ($paymentAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['payment_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
<div><label>Memo</label><br><textarea name="memo" rows="4" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($form['memo'] ?? '')) ?></textarea></div>
<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><button type="submit" class="btn btn-secondary" style="width:auto;">Save bill changes</button><a href="<?= accounting_h(BASE_URL) ?>/accounting/expense_receipts.php?expense_id=<?= (int)$expenseId ?>" style="color:#dbeafe;">Receipts<?= $receiptCount > 0 ? ' (' . $receiptCount . ')' : '' ?></a><a href="<?= accounting_h(BASE_URL) ?>/accounting/bills.php" style="color:#dbeafe;">Cancel</a></div>
</form>
</div>
<?php page_footer(); ?>
