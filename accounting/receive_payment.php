<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$userId = (int)(current_user()['user_id'] ?? 0);
$message = !empty($_GET['updated']) ? 'Payment recorded.' : null;
$errors = [];
$selectedInvoiceId = (int)($_POST['invoice_id'] ?? $_GET['invoice_id'] ?? 0);
$selectedInvoice = null;
if ($selectedInvoiceId > 0) {
    $selectedInvoice = accounting_get_invoice($selectedInvoiceId);
    if (!$selectedInvoice || !accounting_invoice_can_receive_payment($selectedInvoice)) {
        $errors[] = 'Choose an issued invoice with a remaining balance.';
        $selectedInvoice = null;
        $selectedInvoiceId = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'record_payment')) {
    if ($selectedInvoiceId <= 0 || !$selectedInvoice) {
        $errors[] = 'Choose an invoice before recording a payment.';
    } else {
        $result = accounting_record_invoice_payment($selectedInvoiceId, $_POST, $userId);
        if (!empty($result['ok'])) {
            header('Location: ' . BASE_URL . '/payments/view.php?id=' . (int)$result['payment_id'] . '&created=1', true, 302);
            exit;
        }
        $errors = array_merge($errors, $result['errors'] ?? ['Unable to record payment.']);
        $selectedInvoice = accounting_get_invoice($selectedInvoiceId);
    }
}

$summary = accounting_receivables_summary();
$openInvoices = accounting_list_open_invoices_for_payment(250);
$depositAccounts = accounting_payment_account_options();
$defaultDepositAccountId = accounting_default_cash_account_id();
$feeExpenseAccounts = accounting_list_fee_expense_accounts();
$defaultFeeExpenseAccountId = accounting_find_default_fee_expense_account_id();

page_header('Receive Payment', 'accounting');
accounting_subnav('receivables');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px;">
  <?php foreach ([
    ['Open invoices', (string)$summary['open_invoice_count']],
    ['Overdue invoices', (string)$summary['overdue_invoice_count']],
    ['Open A/R', '$' . number_format((float)$summary['total_open_ar'], 2)],
    ['Current bucket', '$' . number_format((float)$summary['current_bucket'], 2)],
  ] as [$label, $value]): ?>
    <div class="card" style="padding:16px;"><div style="opacity:.78;font-size:14px;margin-bottom:6px;"><?= accounting_h($label) ?></div><div style="font-size:22px;font-weight:800;"><?= accounting_h($value) ?></div></div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1.25fr);gap:18px;align-items:start;">
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card" style="padding:18px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Choose invoice</h2>
      <form method="get" style="display:grid;gap:12px;">
        <div>
          <label>Open invoice</label><br>
          <select name="invoice_id" style="width:100%;padding:10px;box-sizing:border-box;">
            <option value="0">Select invoice</option>
            <?php foreach ($openInvoices as $invoice): ?>
              <?php $invoiceLabel = (string)$invoice['invoice_number'] . ' · ' . (string)($invoice['dba_name'] ?: $invoice['legal_name']) . ' · $' . number_format((float)$invoice['balance_due'], 2); ?>
              <option value="<?= (int)$invoice['invoice_id'] ?>" <?= (int)$invoice['invoice_id'] === $selectedInvoiceId ? 'selected' : '' ?>><?= accounting_h($invoiceLabel) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button type="submit" class="btn btn-secondary" style="width:auto;">Load invoice</button>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoices.php" class="btn btn-secondary" style="text-decoration:none;">Open invoice register</a>
          <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="text-decoration:none;">Open payment register</a>
        </div>
      </form>
    </div>

    <?php if ($selectedInvoice): ?>
    <div class="card" style="padding:18px;">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0;font-size:20px;"><?= accounting_h((string)$selectedInvoice['invoice_number']) ?></h2>
          <div style="opacity:.76;margin-top:4px;"><?= accounting_h((string)($selectedInvoice['dba_name'] ?: $selectedInvoice['legal_name'])) ?></div>
        </div>
        <div><?= accounting_invoice_status_badge_html($selectedInvoice) ?></div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:14px;">
        <div><div style="font-size:13px;opacity:.75;">Invoice date</div><div><?= accounting_h((string)$selectedInvoice['invoice_date']) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Due date</div><div><?= accounting_h((string)($selectedInvoice['due_date'] ?: '—')) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Open balance</div><div style="font-size:22px;font-weight:800;">$<?= number_format((float)$selectedInvoice['balance_due'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">A/R account</div><div><?= accounting_h((string)$selectedInvoice['ar_account_code']) ?> · <?= accounting_h((string)$selectedInvoice['ar_account_name']) ?></div></div>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$selectedInvoice['invoice_id'] ?>" class="btn btn-secondary" style="text-decoration:none;">Open invoice</a>
        <?= accounting_invoice_payment_link_html($selectedInvoice, 'ACH') ?>
        <?= accounting_invoice_payment_link_html($selectedInvoice, 'CARD') ?>
      </div>
    </div>

    <div class="card" style="padding:18px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Record manual payment</h2>
      <form method="post" style="display:grid;gap:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="invoice_id" value="<?= (int)$selectedInvoice['invoice_id'] ?>">
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <div><label>Payment date</label><br><input type="date" name="payment_date" value="<?= accounting_h((string)date('Y-m-d')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Payment method</label><br><select name="payment_method" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach (accounting_get_payment_methods() as $method): ?><option value="<?= accounting_h($method) ?>" <?= $method === 'ACH' ? 'selected' : '' ?>><?= accounting_h($method) ?></option><?php endforeach; ?></select></div>
          <div><label>Deposit account</label><br><select name="deposit_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select account</option><?php foreach ($depositAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === (int)$defaultDepositAccountId ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Gross amount</label><br><input type="number" step="0.01" min="0.01" max="<?= accounting_h((string)$selectedInvoice['balance_due']) ?>" name="gross_amount" value="<?= accounting_h((string)$selectedInvoice['balance_due']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Fee amount</label><br><input type="number" step="0.01" min="0" name="fee_amount" value="0.00" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Fee expense account</label><br><select name="fee_expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select fee account</option><?php foreach ($feeExpenseAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === (int)$defaultFeeExpenseAccountId ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Reference</label><br><input type="text" name="reference_number" value="" placeholder="Check #, ACH trace, wire ref" style="width:100%;padding:10px;box-sizing:border-box;"></div>
        </div>
        <div><label>Memo</label><br><textarea name="memo" rows="3" style="width:100%;padding:10px;box-sizing:border-box;resize:vertical;">Payment for <?= accounting_h((string)$selectedInvoice['invoice_number']) ?></textarea></div>
        <div style="font-size:12px;opacity:.72;line-height:1.45;">Record cash, check, ACH, wire, or other manual receipts here. Online card and ACH links above are ready for Stripe hosted checkout once the gateway keys are configured.</div>
        <div><button type="submit" class="btn btn-secondary" style="width:auto;">Record payment</button></div>
      </form>
    </div>
    <?php else: ?>
    <div class="card" style="padding:18px;">
      <h2 style="margin:0 0 10px;font-size:18px;">Ready for payment entry</h2>
      <div style="opacity:.78;line-height:1.55;">Pick an issued invoice with an open balance, then record the money received into your default deposit account. This is the clean manual path while Stripe is still waiting in the wings.</div>
    </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:18px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
      <div>
        <h2 style="margin:0;font-size:18px;">Invoices awaiting payment</h2>
        <div style="opacity:.75;">Open invoices ordered by due date so you can move from A/R to cash receipt without spelunking.</div>
      </div>
      <a href="<?= accounting_h(BASE_URL) ?>/accounting/receivables.php" class="btn btn-secondary" style="text-decoration:none;">Review A/R aging</a>
    </div>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
          <th style="padding:10px 8px;">Invoice</th>
          <th style="padding:10px 8px;">Client</th>
          <th style="padding:10px 8px;">Due</th>
          <th class="status-cell" style="padding:10px 8px;">Status</th>
          <th style="padding:10px 8px;text-align:right;">Balance</th>
          <th style="padding:10px 8px;text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$openInvoices): ?>
        <tr><td colspan="6" style="padding:18px 8px;opacity:.75;">No issued invoices currently need payment.</td></tr>
      <?php else: foreach ($openInvoices as $invoice): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:10px 8px;"><?= accounting_invoice_link_html((int)$invoice['invoice_id'], (string)$invoice['invoice_number']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$invoice['invoice_date']) ?></div></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'])) ?></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)($invoice['due_date'] ?: '—')) ?></td>
          <td class="status-cell" style="padding:10px 8px;"><?= accounting_invoice_status_badge_html($invoice) ?></td>
          <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$invoice['balance_due'], 2) ?></td>
          <td style="padding:10px 8px;text-align:right;white-space:nowrap;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php?invoice_id=<?= (int)$invoice['invoice_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;">Receive</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
