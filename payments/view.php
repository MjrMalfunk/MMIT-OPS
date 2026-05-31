<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$paymentId = (int)($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(404);
    exit('Payment not found');
}

$message = !empty($_GET['created']) ? 'Payment recorded.' : null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'void_payment') {
        $result = accounting_void_payment($paymentId, (string)($_POST['void_reason'] ?? ''), $userId);
    } elseif ($action === 'refund_payment') {
        $result = accounting_refund_payment($paymentId, (string)($_POST['refund_reason'] ?? ''), $userId);
    } else {
        $result = ['ok' => false, 'errors' => ['Unknown action.']];
    }
    if (!empty($result['ok'])) {
        $message = (string)($result['message'] ?? 'Saved.');
    } else {
        $errors = $result['errors'] ?? ['Unable to process payment action.'];
    }
}

$payment = accounting_get_payment($paymentId);
if (!$payment) {
    http_response_code(404);
    exit('Payment not found');
}
$applications = accounting_payment_applications($paymentId);
$journals = accounting_payment_journals($paymentId);
$canRefundPayment = accounting_payment_can_refund($payment);

$journalGroups = [];
foreach ($journals as $row) {
    $journalId = (int)$row['journal_id'];
    if (!isset($journalGroups[$journalId])) {
        $journalGroups[$journalId] = [
            'journal_id' => $journalId,
            'journal_date' => $row['journal_date'],
            'status' => $row['status'],
            'source_type' => $row['source_type'],
            'reference_number' => $row['reference_number'],
            'memo' => $row['memo'],
            'lines' => [],
        ];
    }
    $journalGroups[$journalId]['lines'][] = $row;
}

$totalApplied = 0.0;
foreach ($applications as $application) {
    $totalApplied += (float)$application['amount_applied'];
}

page_header('Payment #' . $paymentId, 'accounting');
accounting_subnav('invoices');
?>
<?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<style>
.payment-view-grid{display:grid;grid-template-columns:minmax(0,1.7fr) 380px;gap:18px;align-items:start;}
.payment-view-grid .card{padding:18px;}
.payment-summary-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:18px;}
.payment-summary-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px;}
@media (max-width:960px){.payment-view-grid,.payment-summary-metrics,.payment-summary-meta{grid-template-columns:1fr;}}
</style>

<div class="payment-view-grid">
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0 0 6px;font-size:22px;">Payment #<?= (int)$payment['payment_id'] ?></h2>
          <div style="opacity:.78;"><?= accounting_h((string)($payment['dba_name'] ?: $payment['legal_name'])) ?></div>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
            <?= accounting_payment_method_badge_html((string)$payment['payment_method']) ?>
            <?= accounting_payment_status_badge_html((string)$payment['payment_status']) ?>
          </div>
        </div>
        <div style="text-align:right;min-width:220px;">
          <div style="font-size:13px;opacity:.75;">Payment date</div>
          <div style="font-size:18px;font-weight:700;"><?= accounting_h((string)$payment['payment_date']) ?></div>
          <div style="margin-top:10px;font-size:13px;opacity:.75;">Reference</div>
          <div><?= accounting_h((string)($payment['reference_number'] ?: '—')) ?></div>
        </div>
      </div>
      <div class="payment-summary-metrics">
        <div><div style="font-size:13px;opacity:.75;">Gross</div><div style="font-size:24px;font-weight:700;">$<?= number_format((float)$payment['gross_amount'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Fee</div><div style="font-size:24px;font-weight:700;">$<?= number_format((float)$payment['fee_amount'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Net</div><div style="font-size:24px;font-weight:700;">$<?= number_format((float)$payment['net_amount'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Applied</div><div style="font-size:24px;font-weight:700;">$<?= number_format($totalApplied, 2) ?></div></div>
      </div>
      <div class="payment-summary-meta">
        <div><div style="font-size:13px;opacity:.75;">Deposit account</div><div><?= accounting_h((string)($payment['deposit_account_code'] ?: '')) ?><?= !empty($payment['deposit_account_name']) ? ' · ' . accounting_h((string)$payment['deposit_account_name']) : '' ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">A/R account</div><div><?= accounting_h((string)($payment['ar_account_code'] ?: '')) ?><?= !empty($payment['ar_account_name']) ? ' · ' . accounting_h((string)$payment['ar_account_name']) : '' ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Fee expense</div><div><?= !empty($payment['fee_account_code']) ? accounting_h((string)$payment['fee_account_code']) . ' · ' . accounting_h((string)$payment['fee_account_name']) : '—' ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Recorded by</div><div><?= accounting_h((string)($payment['created_by_name'] ?: 'System')) ?></div></div>
      </div>
      <div style="margin-top:18px;">
        <div style="font-size:13px;opacity:.75;">Memo</div>
        <div><?= $payment['memo'] !== null && trim((string)$payment['memo']) !== '' ? nl2br(accounting_h((string)$payment['memo'])) : '—' ?></div>
      </div>
    </div>

    <div class="card table-shell">
      <h2 style="margin:0 0 12px;font-size:18px;">Invoice applications</h2>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
            <th style="padding:10px 8px;">Invoice</th>
            <th style="padding:10px 8px;">Client</th>
            <th class="status-cell" style="padding:10px 8px;">Status</th>
            <th style="padding:10px 8px;text-align:right;">Applied</th>
            <th style="padding:10px 8px;text-align:right;">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$applications): ?>
            <tr><td colspan="5" style="padding:12px 8px;opacity:.75;">No invoices linked to this payment.</td></tr>
          <?php else: ?>
            <?php foreach ($applications as $application): ?>
              <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
                <td style="padding:10px 8px;"><?= accounting_invoice_link_html((int)$application['invoice_id'], (string)$application['invoice_number']) ?><div style="font-size:12px;opacity:.65;"><?= accounting_h((string)$application['invoice_date']) ?></div></td>
                <td style="padding:10px 8px;"><?= accounting_h((string)($application['dba_name'] ?: $application['legal_name'])) ?></td>
                <td class="status-cell" style="padding:10px 8px;"><?= accounting_invoice_status_badge_html(['status' => (string)$application['status']]) ?></td>
                <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$application['amount_applied'], 2) ?></td>
                <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$application['balance_due'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card table-shell">
      <h2 style="margin:0 0 12px;font-size:18px;">General ledger activity</h2>
      <?php if (!$journalGroups): ?>
        <div style="opacity:.75;">No journal entries found for this payment yet.</div>
      <?php else: ?>
        <?php foreach ($journalGroups as $journal): ?>
          <div style="padding:14px 0;border-bottom:1px solid rgba(255,255,255,.08);">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;flex-wrap:wrap;">
              <div>
                <div style="font-weight:700;"><?= accounting_h((string)$journal['source_type']) ?> · <?= accounting_h((string)$journal['journal_date']) ?></div>
                <div style="font-size:12px;opacity:.7;"><?= accounting_h((string)($journal['reference_number'] ?: '—')) ?></div>
              </div>
              <div><?= accounting_payment_status_badge_html((string)$journal['status']) ?></div>
            </div>
            <div style="margin-top:8px;opacity:.8;"><?= accounting_h((string)($journal['memo'] ?: '—')) ?></div>
            <table style="width:100%;border-collapse:collapse;margin-top:10px;">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.08)">
                  <th style="padding:8px 6px;">Account</th>
                  <th style="padding:8px 6px;text-align:right;">Debit</th>
                  <th style="padding:8px 6px;text-align:right;">Credit</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($journal['lines'] as $line): ?>
                  <tr>
                    <td style="padding:8px 6px;"><?= accounting_h((string)($line['account_code'] ?: '')) ?><?= !empty($line['account_name']) ? ' · ' . accounting_h((string)$line['account_name']) : '' ?><div style="font-size:12px;opacity:.65;"><?= accounting_h((string)($line['line_memo'] ?: '')) ?></div></td>
                    <td style="padding:8px 6px;text-align:right;">$<?= number_format((float)$line['debit_amount'], 2) ?></td>
                    <td style="padding:8px 6px;text-align:right;">$<?= number_format((float)$line['credit_amount'], 2) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Actions</h2>
      <div style="display:grid;gap:10px;">
        <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="justify-content:flex-start;">Back to payment register</a>
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php" class="btn btn-secondary" style="justify-content:flex-start;">Receive another payment</a>
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/reconcile.php?account_id=<?= (int)$payment['deposit_account_id'] ?>" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Reconcile this bank account</a>
        <?php if (!empty($applications[0]['invoice_id'])): ?>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$applications[0]['invoice_id'] ?>" class="btn btn-secondary" style="justify-content:flex-start;">Open linked invoice</a>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_pdf.php?id=<?= (int)$applications[0]['invoice_id'] ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open invoice PDF</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Payment summary</h2>
      <div style="display:grid;gap:10px;">
        <div><div style="font-size:13px;opacity:.75;">Client code</div><div><?= accounting_h((string)($payment['client_code'] ?: '—')) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Created at</div><div><?= accounting_h((string)($payment['created_at'] ?: '—')) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Unapplied</div><div>$<?= number_format((float)$payment['unapplied_amount'], 2) ?></div></div>
        <div><div style="font-size:13px;opacity:.75;">Gateway</div><div><?= accounting_h((string)($payment['processor_name'] ?: 'Manual / none')) ?></div></div>
        <?php if (!empty($payment['processor_txn_id'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Processor transaction</div><div style="word-break:break-all;"><?= accounting_h((string)$payment['processor_txn_id']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_payment_intent_id'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Payment intent</div><div style="word-break:break-all;"><?= accounting_h((string)$payment['processor_payment_intent_id']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_charge_id'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Charge id</div><div style="word-break:break-all;"><?= accounting_h((string)$payment['processor_charge_id']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_checkout_session_id'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Checkout session</div><div style="word-break:break-all;"><?= accounting_h((string)$payment['processor_checkout_session_id']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_payment_method_label'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Payment method detail</div><div><?= accounting_h((string)$payment['processor_payment_method_label']) ?></div></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_receipt_url'])): ?>
          <div><a href="<?= accounting_h((string)$payment['processor_receipt_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open Stripe receipt</a></div>
        <?php endif; ?>
        <?php if (!empty($payment['processor_name'])): ?>
          <div><a href="<?= accounting_h(BASE_URL) ?>/payments/gateway_health.php" class="btn btn-secondary" style="justify-content:flex-start;text-decoration:none;">Open gateway health</a></div>
        <?php endif; ?>
        <?php if (!empty($payment['void_reason'])): ?>
          <div><div style="font-size:13px;opacity:.75;">Void / refund note</div><div><?= nl2br(accounting_h((string)$payment['void_reason'])) ?></div></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($canRefundPayment): ?>
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Refund Stripe payment</h2>
      <p style="margin:0 0 12px;opacity:.78;">This creates a full refund in Stripe, restores the invoice balance locally, and posts a refund reversal journal. Processor fees already incurred are left in place.</p>
      <form method="post" style="display:grid;gap:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="refund_payment">
        <div>
          <label>Reason</label><br>
          <textarea name="refund_reason" rows="4" style="width:100%;padding:10px;box-sizing:border-box;resize:vertical;" placeholder="Explain why this payment is being refunded."></textarea>
        </div>
        <button type="submit" class="btn btn-danger" style="justify-content:flex-start;">Refund in Stripe &amp; reverse locally</button>
      </form>
    </div>
    <?php elseif (strtoupper((string)$payment['payment_status']) === 'POSTED'): ?>
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Void payment</h2>
      <p style="margin:0 0 12px;opacity:.78;">This will reverse the GL entry, restore invoice balances, and mark the payment void.</p>
      <form method="post" style="display:grid;gap:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="void_payment">
        <div>
          <label>Reason</label><br>
          <textarea name="void_reason" rows="4" style="width:100%;padding:10px;box-sizing:border-box;resize:vertical;" placeholder="Explain why this payment is being voided."></textarea>
        </div>
        <button type="submit" class="btn btn-danger" style="justify-content:flex-start;">Void payment</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_footer(); ?>
