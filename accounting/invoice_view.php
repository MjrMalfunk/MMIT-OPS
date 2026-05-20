<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';
require_login(); accounting_require_ready(); csrf_check();

$invoiceId = (int)($_GET['id'] ?? 0);
if ($invoiceId <= 0) {
    http_response_code(404); exit('Invoice not found');
}
$invoice = accounting_get_invoice($invoiceId);
if (!$invoice) { http_response_code(404); exit('Invoice not found'); }
$message = !empty($_GET['updated']) ? 'Invoice updated.' : null; $errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'issue_invoice') {
        $result = accounting_issue_invoice($invoiceId, $userId);
    } elseif ($action === 'issue_and_send_invoice') {
        $result = accounting_issue_and_send_invoice($invoiceId, $_POST, $userId);
    } elseif ($action === 'record_payment') {
        $result = accounting_record_invoice_payment($invoiceId, $_POST, $userId);
    } elseif ($action === 'send_invoice') {
        $result = accounting_send_invoice_email($invoiceId, $_POST, $userId);
    } elseif ($action === 'void_invoice') {
        $result = accounting_void_invoice($invoiceId, (string)($_POST['void_reason'] ?? ''), $userId);
    } elseif ($action === 'stripe_sync_invoice') {
        $result = payment_gateway_stripe_sync_local_invoice($invoiceId);
    } elseif ($action === 'mark_paid_full') {
        $result = accounting_record_invoice_payment($invoiceId, [
            'payment_date' => (string)($_POST['payment_date'] ?? date('Y-m-d')),
            'payment_method' => (string)($_POST['payment_method'] ?? 'ACH'),
            'deposit_account_id' => (int)($_POST['deposit_account_id'] ?? 0),
            'gross_amount' => (float)$invoice['balance_due'],
            'fee_amount' => 0,
            'reference_number' => trim((string)($_POST['reference_number'] ?? 'PAID-IN-FULL')),
            'memo' => trim((string)($_POST['memo'] ?? 'Invoice marked paid in full from invoice screen')),
        ], $userId);
    } else {
        $result = ['ok' => false, 'errors' => ['Unknown action.']];
    }
    if (!empty($result['ok'])) $message = (string)$result['message'];
    else $errors = $result['errors'] ?? ['Unable to process invoice action.'];
    $invoice = accounting_get_invoice($invoiceId);
    if (!$invoice) { http_response_code(404); exit('Invoice not found'); }
}
$lines = accounting_invoice_lines($invoiceId);
$includedGroups = !empty($invoice['contract_id']) ? accounting_contract_included_services_summary((int)$invoice['contract_id']) : [];
$payments = accounting_invoice_payments($invoiceId);
$canEditInvoice = accounting_can_edit_invoice($invoice);
$deliveryLog = accounting_invoice_delivery_log($invoiceId);
$hasDeliveryLog = !empty($deliveryLog);
$sendPanelTitle = $hasDeliveryLog ? 'Resend invoice' : 'Send invoice';
$sendButtonLabel = $hasDeliveryLog ? 'Resend invoice email' : 'Send invoice email';
$emailDefaults = accounting_invoice_email_defaults($invoice);
$emailTo = (string)($_POST['email_to'] ?? $emailDefaults['to']);
$emailSubject = (string)($_POST['email_subject'] ?? $emailDefaults['subject']);
$emailBody = (string)($_POST['email_body'] ?? $emailDefaults['body']);
$canEmailInvoice = filter_var(trim((string)($invoice['client_email'] ?? '')), FILTER_VALIDATE_EMAIL);
$depositAccounts = accounting_payment_account_options();
$defaultDepositAccountId = accounting_default_cash_account_id();
$feeExpenseAccounts = accounting_list_fee_expense_accounts();
$defaultFeeExpenseAccountId = accounting_find_default_fee_expense_account_id();
$totalApplied = 0.0;
$lastPaymentDate = null;
foreach ($payments as $payment) {
    if (strtoupper((string)$payment['payment_status']) !== 'VOID') {
        $totalApplied += (float)$payment['amount_applied'];
        if ($lastPaymentDate === null || (string)$payment['payment_date'] > $lastPaymentDate) {
            $lastPaymentDate = (string)$payment['payment_date'];
        }
    }
}
$paymentSnapshot = accounting_invoice_payment_snapshot($invoice, $payments);
$canReceivePayment = accounting_invoice_can_receive_payment($invoice);
$canVoidInvoice = accounting_invoice_can_void($invoice);
$receivePaymentHref = accounting_h(BASE_URL) . '/accounting/receive_payment.php?invoice_id=' . (int)$invoice['invoice_id'];
$stripeConfigured = payment_gateway_stripe_enabled();
$stripeHostedUrl = accounting_invoice_stripe_payment_url($invoice);
$stripeSyncStatus = strtoupper(trim((string)($invoice['stripe_sync_status'] ?? '')));
$stripeSyncError = trim((string)($invoice['stripe_last_error'] ?? ''));
page_header('Invoice ' . (string)$invoice['invoice_number'], 'accounting'); accounting_subnav('invoices');
?>
<style>
  .invoice-watermark-card { position:relative; overflow:hidden; isolation:isolate; }
  .invoice-watermark-card .invoice-watermark-shell {
    position:absolute; inset:-2% -4%; pointer-events:none; z-index:0;
    display:flex; align-items:center; justify-content:center; transform:rotate(-21deg);
  }
  .invoice-watermark-card .invoice-watermark-layer {
    position:absolute; left:0; right:0; text-align:center; font-weight:900; line-height:1;
  }
  .invoice-watermark-card .invoice-watermark-layer.outline {
    font-size:96px; letter-spacing:10px; color:rgba(34,197,94,.07);
  }
  .invoice-watermark-card .invoice-watermark-layer.main {
    top:6px; font-size:88px; letter-spacing:11px; color:rgba(34,197,94,.14);
  }
  .invoice-watermark-card .invoice-watermark-date {
    position:absolute; top:62%; left:0; right:0; text-align:center; font-weight:800; line-height:1;
  }
  .invoice-watermark-card .invoice-watermark-date.outline {
    font-size:22px; letter-spacing:3px; color:rgba(34,197,94,.07);
  }
  .invoice-watermark-card .invoice-watermark-date.main {
    top:calc(62% + 2px); font-size:18px; letter-spacing:4px; color:rgba(34,197,94,.16);
  }
  .invoice-watermark-card > .invoice-watermark-body { position:relative; z-index:1; }
</style>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:minmax(0,1.7fr) 380px;gap:18px;align-items:start;">
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card invoice-watermark-card" style="padding:18px;">
      <?php if (!empty($paymentSnapshot['show_paid_watermark'])): ?>
      <div class="invoice-watermark-shell" aria-hidden="true">
        <div class="invoice-watermark-layer outline">PAID</div>
        <div class="invoice-watermark-layer main">PAID</div>
        <?php if (!empty($paymentSnapshot['last_payment_date'])): ?>
          <div class="invoice-watermark-date outline">PAID <?= accounting_h((string)$paymentSnapshot['last_payment_date']) ?></div>
          <div class="invoice-watermark-date main">PAID <?= accounting_h((string)$paymentSnapshot['last_payment_date']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="invoice-watermark-body">
      <div style="display:grid;grid-template-columns:minmax(0,1fr) 160px;gap:18px;align-items:start;">
        <div style="min-width:0;">
          <h2 style="margin:0;font-size:22px;"><?= accounting_h((string)$invoice['invoice_number']) ?></h2>
          <div style="opacity:.75;margin-top:4px;"><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'])) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:13px;opacity:.75;">Status</div>
          <div><?= accounting_invoice_status_badge_html($invoice) ?></div>
          <div style="margin-top:10px;display:flex;justify-content:flex-end;"><?= accounting_invoice_lifecycle_stage_html($invoice) ?></div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:16px;">
        <div><div style="font-size:13px;opacity:.75;">Invoice date</div><div><?= accounting_h((string)$invoice['invoice_date']) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Due date</div><div><?= accounting_h((string)($invoice['due_date'] ?: '—')) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">A/R account</div><div><?= accounting_h((string)$invoice['ar_account_code']) ?> · <?= accounting_h((string)$invoice['ar_account_name']) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Balance</div><div>$<?= number_format((float)$invoice['balance_due'],2) ?></div></div>
        <?php if (!empty($paymentSnapshot['last_payment_date'])): ?><div><div style="font-size:13px;opacity:.75;">Last payment</div><div><?= accounting_h((string)$paymentSnapshot['last_payment_date']) ?><?= !empty($paymentSnapshot['last_payment_method']) ? ' · ' . accounting_h((string)$paymentSnapshot['last_payment_method']) : '' ?></div></div><?php endif; ?>
      </div>
      <?php if (!empty($invoice['contract_number']) || !empty($invoice['memo'])): ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;">
        <div><div style="font-size:13px;opacity:.75;">Contract</div><div><?= !empty($invoice['contract_number']) ? accounting_h((string)$invoice['contract_number'] . ' · ' . $invoice['contract_name']) : '—' ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Internal memo</div><div><?= $invoice['memo'] !== null ? nl2br(accounting_h((string)$invoice['memo'])) : '—' ?></div></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($paymentSnapshot['show_paid_watermark']) || !empty($paymentSnapshot['has_payments'])): ?>
      <div style="margin-top:14px;padding:10px 12px;border-radius:12px;border:1px solid <?= !empty($paymentSnapshot['show_paid_watermark']) ? 'rgba(34,197,94,.24)' : 'rgba(245,158,11,.24)' ?>;background:<?= !empty($paymentSnapshot['show_paid_watermark']) ? 'rgba(34,197,94,.10)' : 'rgba(245,158,11,.10)' ?>;font-size:13px;line-height:1.45;">
        <?= accounting_h((string)$paymentSnapshot['detail_text']) ?>
      </div>
      <?php endif; ?>
      </div>
    </div>

    <div class="card" style="padding:18px;overflow:auto;">
      <h2 style="margin:0 0 12px;font-size:18px;">Billed items</h2>
      <table style="width:100%;border-collapse:collapse;table-layout:fixed;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
            <th style="padding:10px 8px;width:36%;">Description</th>
            <th style="padding:10px 8px;width:26%;">Revenue</th>
            <th style="padding:10px 8px;text-align:right;width:10%;">Qty</th>
            <th style="padding:10px 8px;text-align:right;width:14%;">Unit</th>
            <th style="padding:10px 8px;text-align:right;width:14%;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$lines): ?>
            <tr><td colspan="5" style="padding:14px 8px;opacity:.75;">No invoice lines found.</td></tr>
          <?php else: ?>
            <?php foreach ($lines as $line): ?>
              <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
                <td style="padding:10px 8px;word-break:break-word;"><?= accounting_h((string)$line['description']) ?><?php if (!empty($line['service_code'])): ?><div style="font-size:12px;opacity:.65;"><?= accounting_h((string)$line['service_code']) ?></div><?php endif; ?></td>
                <td style="padding:10px 8px;word-break:break-word;"><?= accounting_h((string)($line['revenue_account_code'] ?: '')) ?><?= !empty($line['revenue_account_name']) ? ' · ' . accounting_h((string)$line['revenue_account_name']) : '' ?></td>
                <td style="padding:10px 8px;text-align:right;"><?= number_format((float)$line['quantity'],2) ?></td>
                <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$line['unit_price'],2) ?></td>
                <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$line['line_total'],2) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4" style="padding:12px 8px;text-align:right;font-weight:700;">Total</td>
            <td style="padding:12px 8px;text-align:right;font-weight:700;white-space:nowrap;">$<?= number_format((float)$invoice['total_amount'],2) ?></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if ($includedGroups): ?>
    <div class="card" style="padding:18px;overflow:auto;">
      <h2 style="margin:0 0 12px;font-size:18px;">Included in plan</h2>
      <div style="display:grid;gap:14px;">
        <?php foreach ($includedGroups as $group): ?>
          <div style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);">
            <div style="font-weight:800;margin-bottom:8px;"><?= accounting_h((string)$group['bundle_name']) ?></div>
            <?php if (!empty($group['included'])): ?>
              <?php foreach ($group['included'] as $svc): ?>
                <div style="padding:8px 10px;border-radius:10px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.16);margin-bottom:8px;display:flex;justify-content:space-between;gap:10px;">
                  <span><?= accounting_h((string)$svc['service_name']) ?></span>
                  <span style="opacity:.72;">Included</span>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div style="opacity:.65;">No included services listed.</div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Actions</h2>
      <div style="display:grid;gap:10px;">
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_pdf.php?id=<?= (int)$invoice['invoice_id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Download PDF</a>
        <?php if ($canReceivePayment): ?>
        <a href="<?= $receivePaymentHref ?>" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Receive payment</a>
        <?php endif; ?>
        <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Open payment register</a>
        <?php if ($canReceivePayment): ?>
        <div class="card" style="padding:12px;background:rgba(255,255,255,.02);">
          <div style="font-weight:700;margin-bottom:10px;">Client payment links</div>
          <?php if ($stripeHostedUrl !== ''): ?>
            <div style="display:grid;gap:10px;">
              <a class="btn btn-secondary" href="<?= accounting_h($stripeHostedUrl) ?>" target="_blank" rel="noopener" style="text-decoration:none;text-align:left;">Open Stripe payment page</a>
              <?php if (!empty($invoice['stripe_invoice_pdf_url'])): ?>
                <a class="btn btn-secondary" href="<?= accounting_h((string)$invoice['stripe_invoice_pdf_url']) ?>" target="_blank" rel="noopener" style="text-decoration:none;text-align:left;">Open Stripe invoice PDF</a>
              <?php endif; ?>
            </div>
            <div style="font-size:12px;opacity:.72;line-height:1.45;margin-top:10px;">Customers pay on Stripe’s hosted invoice page and can download the receipt there after payment.</div>
          <?php else: ?>
            <div style="display:grid;gap:10px;">
              <?= accounting_invoice_payment_link_html($invoice, 'ACH') ?>
              <?= accounting_invoice_payment_link_html($invoice, 'CARD') ?>
            </div>
            <div style="font-size:12px;opacity:.72;line-height:1.45;margin-top:10px;">Portal checkout links remain available as a fallback until Stripe sync is ready.</div>
            <?php if ($stripeConfigured): ?>
              <form method="post" style="margin-top:12px;display:grid;gap:10px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="stripe_sync_invoice">
                <button type="submit" class="btn btn-secondary" style="text-align:left;">Build Stripe hosted payment page</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($stripeConfigured && $stripeSyncStatus !== ''): ?>
            <div style="font-size:12px;opacity:.72;line-height:1.45;margin-top:10px;">Stripe sync status: <strong><?= accounting_h($stripeSyncStatus) ?></strong><?= $stripeSyncError !== '' ? ' · ' . accounting_h($stripeSyncError) : '' ?></div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!in_array((string)$invoice['status'], ['DRAFT', 'VOID'], true)): ?>
        <details class="card" style="padding:12px;background:rgba(255,255,255,.02);">
          <summary style="cursor:pointer;font-weight:700;list-style:none;"><?= accounting_h($sendPanelTitle) ?></summary>
          <form method="post" style="margin-top:12px;display:grid;gap:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_invoice">
            <div><label>Send to</label><br><input type="email" name="email_to" value="<?= accounting_h($emailTo) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
            <?php if (accounting_mail_sandbox_enabled()): ?>
            <div style="font-size:12px;opacity:.78;line-height:1.45;">Test mail mode is active. All invoice email is sent from <strong><?= accounting_h(accounting_mail_from_email()) ?></strong> and redirected to <strong><?= accounting_h(accounting_mail_sandbox_to()) ?></strong> until sandbox mode is turned off.</div>
            <?php endif; ?>
            <div><label>Subject</label><br><input type="text" name="email_subject" value="<?= accounting_h($emailSubject) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
            <div><label>Message</label><br><textarea name="email_body" rows="9" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h($emailBody) ?></textarea></div>
            <div style="font-size:12px;opacity:.72;line-height:1.45;">A PDF copy of this invoice will be attached automatically.</div>
            <?php if ($hasDeliveryLog): ?><div style="font-size:12px;opacity:.72;line-height:1.45;">This will send the invoice again and append another delivery-history entry.</div><?php endif; ?>
            <div><button type="submit" class="btn btn-secondary" style="text-align:left;"><?= accounting_h($sendButtonLabel) ?></button></div>
          </form>
        </details>
        <?php endif; ?>
        <?php if ((string)$invoice['status'] === 'DRAFT'): ?>
        <details class="card" style="padding:12px;background:rgba(255,255,255,.02);">
          <summary style="cursor:pointer;font-weight:700;list-style:none;">Issue &amp; send invoice</summary>
          <form method="post" style="margin-top:12px;display:grid;gap:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="issue_and_send_invoice">
            <div><label>Send to</label><br><input type="email" name="email_to" value="<?= accounting_h($emailTo) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
            <?php if (accounting_mail_sandbox_enabled()): ?>
            <div style="font-size:12px;opacity:.78;line-height:1.45;">Test mail mode is active. All invoice email is sent from <strong><?= accounting_h(accounting_mail_from_email()) ?></strong> and redirected to <strong><?= accounting_h(accounting_mail_sandbox_to()) ?></strong> until sandbox mode is turned off.</div>
            <?php endif; ?>
            <div><label>Subject</label><br><input type="text" name="email_subject" value="<?= accounting_h($emailSubject) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
            <div><label>Message</label><br><textarea name="email_body" rows="8" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h($emailBody) ?></textarea></div>
            <div style="font-size:12px;opacity:.72;line-height:1.45;">This issues the invoice, posts it to the GL, and emails the PDF in one step.</div>
            <div><button type="submit" class="btn btn-secondary" style="text-align:left;" <?= $canEmailInvoice ? '' : 'disabled' ?>>Issue and send invoice</button></div>
            <?php if (!$canEmailInvoice): ?><div style="font-size:12px;color:#fca5a5;">Add a valid client email before using Issue &amp; send.</div><?php endif; ?>
          </form>
        </details>
        <form method="post" style="margin:0;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="issue_invoice">
          <button type="submit" class="btn btn-secondary" style="text-align:left;">Issue (do not send)</button>
        </form>
        <div style="font-size:12px;opacity:.72;line-height:1.45;">Use this when you want to issue the invoice now and send it later from the issued invoice screen.</div>
        <?php endif; ?>
        <?php if (in_array((string)$invoice['status'], ['ISSUED', 'PARTIALLY_PAID'], true) && (float)$invoice['balance_due'] > 0): ?>
        <details id="mark-paid-panel" style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.02);">
          <summary style="cursor:pointer;font-weight:700;">Mark paid in full</summary>
          <form method="post" style="margin-top:12px;display:grid;gap:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_paid_full">
            <div><label>Payment date</label><br><input type="date" name="payment_date" value="<?= accounting_h((string)date('Y-m-d')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
            <div><label>Payment method</label><br><select name="payment_method" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach (accounting_get_payment_methods() as $method): ?><option value="<?= accounting_h($method) ?>" <?= $method === 'ACH' ? 'selected' : '' ?>><?= accounting_h($method) ?></option><?php endforeach; ?></select></div>
            <div><label>Deposit account</label><br><select name="deposit_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select account</option><?php foreach ($depositAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === (int)$defaultDepositAccountId ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
            <div style="font-size:12px;opacity:.72;line-height:1.45;">This posts a zero-fee payment for the remaining balance of <strong>$<?= number_format((float)$invoice['balance_due'], 2) ?></strong>.</div>
            <button type="submit" class="btn btn-secondary" style="text-align:left;">Mark invoice paid in full</button>
          </form>
        </details>
        <form id="record-payment-panel" method="post" style="margin:0;display:grid;gap:10px;">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="record_payment">
          <div><label>Payment date</label><br><input type="date" name="payment_date" value="<?= accounting_h((string)date('Y-m-d')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Payment method</label><br><select name="payment_method" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach (accounting_get_payment_methods() as $method): ?><option value="<?= accounting_h($method) ?>"><?= accounting_h($method) ?></option><?php endforeach; ?></select></div>
          <div><label>Deposit account</label><br><select name="deposit_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select account</option><?php foreach ($depositAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === (int)$defaultDepositAccountId ? 'selected' : '' ?>><?= accounting_h(accounting_account_option_label($account)) ?></option><?php endforeach; ?></select></div>
          <div><label>Gross amount</label><br><input type="number" step="1" min="0.01" max="<?= accounting_h((string)$invoice['balance_due']) ?>" name="gross_amount" value="<?= accounting_h((string)$invoice['balance_due']) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Fee amount</label><br><input type="number" step="1" min="0" name="fee_amount" value="0.00" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Fee expense account</label><br><select name="fee_expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select fee account</option><?php foreach ($feeExpenseAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= (int)$account['account_id'] === (int)$defaultFeeExpenseAccountId ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Reference</label><br><input type="text" name="reference_number" value="" style="width:100%;padding:10px;box-sizing:border-box;"></div>
          <div><label>Memo</label><br><textarea name="memo" rows="3" style="width:100%;padding:10px;box-sizing:border-box;resize:vertical;"></textarea></div>
          <button type="submit" class="btn btn-secondary" style="text-align:left;">Record payment</button>
        </form>
        <?php endif; ?>
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoices.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Back to invoice register</a>
      </div>
    </div>
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Delivery history</h2>
      <?php if (!$deliveryLog): ?>
        <div style="opacity:.72;font-size:13px;">No invoice emails logged yet.</div>
      <?php else: ?>
        <div style="display:grid;gap:10px;">
          <?php foreach ($deliveryLog as $delivery): ?>
            <div style="border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:10px 12px;background:rgba(255,255,255,.02);">
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                <div>
                  <div style="font-weight:600;word-break:break-word;"><?= accounting_h((string)$delivery['recipient_email']) ?></div>
                  <div style="font-size:12px;opacity:.68;"><?= accounting_h((string)$delivery['sent_at']) ?><?= !empty($delivery['sent_by_name']) ? ' · ' . accounting_h((string)$delivery['sent_by_name']) : '' ?></div>
                </div>
                <div><?= accounting_delivery_status_badge_html((string)$delivery['delivery_status']) ?></div>
              </div>
              <div style="font-size:13px;opacity:.82;margin-top:8px;word-break:break-word;"><?= accounting_h((string)$delivery['subject_line']) ?></div>
              <?php if (!empty($delivery['error_message'])): ?><div style="font-size:12px;color:#fecaca;margin-top:6px;"><?= accounting_h((string)$delivery['error_message']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($canVoidInvoice): ?>
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Void invoice</h2>
      <p style="margin:0 0 12px;opacity:.78;line-height:1.5;">Void an unpaid invoice to preserve the paper trail, stop future collection activity, and reverse the A/R journal if the invoice was already issued. Any open Stripe hosted invoice page is voided too.</p>
      <form method="post" style="display:grid;gap:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="void_invoice">
        <div>
          <label>Reason</label><br>
          <textarea name="void_reason" rows="4" style="width:100%;padding:10px;box-sizing:border-box;resize:vertical;" placeholder="Explain why this invoice is being voided."></textarea>
        </div>
        <button type="submit" class="btn btn-danger" style="text-align:left;">Void invoice</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Summary</h2>
      <div style="display:grid;gap:10px;">
        <div><div style="font-size:13px;opacity:.75;">Invoice total</div><div>$<?= number_format((float)$invoice['total_amount'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Payments applied</div><div>$<?= number_format($totalApplied, 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Remaining balance</div><div>$<?= number_format((float)$invoice['balance_due'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Last payment date</div><div><?= accounting_h($lastPaymentDate ?: '—') ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">GL posting</div><div><?= !empty($invoice['has_journal']) ? 'Posted to general ledger' : 'Not posted yet' ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Client email</div><div style="word-break:break-word;"><?= accounting_h((string)($invoice['client_email'] ?: '—')) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Revenue header</div><div><?= accounting_h((string)$invoice['revenue_account_code']) ?> · <?= accounting_h((string)$invoice['revenue_account_name']) ?></div></div>
      </div>
    </div>
    <div class="card" style="padding:16px;overflow:auto;">
      <h2 style="margin:0 0 12px;font-size:18px;">Applied payments</h2>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
            <th style="padding:10px 8px;">Date</th>
            <th style="padding:10px 8px;">Method</th>
            <th class="status-cell" style="padding:10px 8px;">Status</th>
            <th style="padding:10px 8px;text-align:right;">Gross</th>
            <th style="padding:10px 8px;text-align:right;">Fee</th>
            <th style="padding:10px 8px;text-align:right;">Net</th>
            <th style="padding:10px 8px;text-align:right;">Applied</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="7" style="padding:12px 8px;opacity:.75;">No payments have been applied yet.</td></tr>
        <?php else: ?>
          <?php foreach ($payments as $payment): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/payments/view.php?id=<?= (int)$payment['payment_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;"><?= accounting_h((string)$payment['payment_date']) ?></a><div style="font-size:12px;opacity:.65;"><?= accounting_h((string)($payment['reference_number'] ?: '—')) ?></div></td>
            <td class="status-cell" style="padding:10px 8px;"><?= accounting_payment_method_badge_html((string)$payment['payment_method']) ?></td>
            <td class="status-cell" style="padding:10px 8px;"><?= accounting_payment_status_badge_html((string)$payment['payment_status']) ?><div style="font-size:12px;opacity:.65;margin-top:4px;"><?= accounting_h((string)($payment['created_by_name'] ?: 'System')) ?></div></td>
            <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$payment['gross_amount'], 2) ?></td>
            <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$payment['fee_amount'], 2) ?></td>
            <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$payment['net_amount'], 2) ?></td>
            <td style="padding:10px 8px;text-align:right;white-space:nowrap;">$<?= number_format((float)$payment['amount_applied'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php page_footer(); ?>
