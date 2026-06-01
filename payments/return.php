<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';

$gateway = strtoupper(trim((string)($_GET['gateway'] ?? '')));
$message = null;
$errors = [];
$invoice = null;
$paymentId = null;
$statusLabel = 'Pending';
$currentUser = current_user();
$canViewPaymentRecord = is_array($currentUser) && strtoupper((string)($currentUser['user_type'] ?? '')) === 'INTERNAL';

try {
    if ($gateway === 'STRIPE') {
        $sessionId = trim((string)($_GET['session_id'] ?? ''));
        if ($sessionId === '') {
            throw new RuntimeException('Missing Stripe session id.');
        }
        $session = payment_gateway_stripe_session($sessionId);
        $invoiceId = (int)($session['metadata']['invoice_id'] ?? $session['payment_intent']['metadata']['invoice_id'] ?? 0);
        if ($invoiceId <= 0) {
            throw new RuntimeException('Unable to match the Stripe session back to an invoice.');
        }
        $invoice = accounting_get_invoice($invoiceId);
        if (!$invoice) {
            throw new RuntimeException('The linked invoice could not be found.');
        }
        $result = payment_gateway_record_stripe_invoice_payment($invoice, $session);
        if (!empty($result['ok'])) {
            $message = 'Thank you. This invoice has been paid in full.';
            $paymentId = (int)($result['payment_id'] ?? 0);
            $payment = $paymentId > 0 ? accounting_get_payment($paymentId) : null;
            $statusLabel = strtoupper(trim((string)($payment['payment_status'] ?? ''))) === 'PENDING' ? 'Processing' : 'Paid';
            $invoice = accounting_get_invoice((int)$invoice['invoice_id']) ?: $invoice;
        } else {
            $errors = $result['errors'] ?? ['Stripe returned a non-final payment state.'];
            $statusLabel = 'Processing';
        }
    } else {
        throw new RuntimeException('Unknown payment gateway return.');
    }
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

page_header('Invoice payment complete', '', false);
?>
<div class="card" style="max-width:780px;margin:0 auto;padding:24px;">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 8px;font-size:28px;">Invoice payment complete</h1>
      <div style="opacity:.78;">Thank you for your payment.</div>
    </div>
    <div style="padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.04);font-weight:700;">
      <?= accounting_h($statusLabel) ?>
    </div>
  </div>

  <?php if ($message): ?><div class="flash-success" style="margin-top:16px;"><?= accounting_h($message) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="flash-error" style="margin-top:16px;"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

  <?php if ($invoice): ?>
  <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px;">
    <div class="card" style="padding:14px;"><div style="font-size:13px;opacity:.72;">Invoice</div><div style="font-weight:700;"><?= accounting_h((string)$invoice['invoice_number']) ?></div></div>
    <div class="card" style="padding:14px;"><div style="font-size:13px;opacity:.72;">Remaining balance</div><div style="font-weight:700;">$<?= number_format((float)$invoice['balance_due'], 2) ?></div></div>
    <div class="card" style="padding:14px;"><div style="font-size:13px;opacity:.72;">Client</div><div style="font-weight:700;"><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'Client')) ?></div></div>
    <div class="card" style="padding:14px;"><div style="font-size:13px;opacity:.72;">Invoice status</div><div style="font-weight:700;"><?= accounting_h((string)$invoice['status']) ?></div></div>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px;">
    <?php if ($invoice): ?>
      <a href="<?= accounting_h(BASE_URL) ?>/payments/pay.php?invoice=<?= rawurlencode((string)$invoice['invoice_number']) ?><?= isset($_GET['method']) ? '&method=' . rawurlencode((string)$_GET['method']) : '' ?>" class="btn btn-secondary" style="text-decoration:none;">Return to invoice</a>
    <?php endif; ?>
    <?php if ($invoice && trim((string)($invoice['stripe_invoice_pdf_url'] ?? '')) !== ''): ?>
      <a href="<?= accounting_h((string)$invoice['stripe_invoice_pdf_url']) ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="text-decoration:none;">Download invoice PDF</a>
    <?php endif; ?>
    <?php if ($paymentId && $canViewPaymentRecord): ?>
      <a href="<?= accounting_h(BASE_URL) ?>/payments/view.php?id=<?= (int)$paymentId ?>" class="btn btn-secondary" style="text-decoration:none;">View internal payment record</a>
    <?php endif; ?>
  </div>
</div>
<?php page_footer();
