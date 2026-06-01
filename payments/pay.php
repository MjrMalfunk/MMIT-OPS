<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';

function public_pay_format_date(?string $date): string {
    $date = trim((string)$date);
    if ($date === '' || $date === '0000-00-00') {
        return 'Not specified';
    }
    $timestamp = strtotime($date);
    return $timestamp !== false ? date('M j, Y', $timestamp) : $date;
}

function public_pay_status_label(?string $status): string {
    $status = strtoupper(trim((string)$status));
    if ($status === '') {
        return 'Not available';
    }
    return ucwords(strtolower(str_replace('_', ' ', $status)));
}

function public_pay_method_label(?string $method): string {
    $method = strtoupper(trim((string)$method));
    if ($method === 'ACH') {
        return 'Bank / ACH';
    }
    if ($method === 'CARD') {
        return 'Card';
    }
    return $method !== '' ? $method : 'Payment';
}

$invoiceNumber = trim((string)($_GET['invoice'] ?? $_POST['invoice'] ?? ''));
$invoice = $invoiceNumber !== '' ? accounting_invoice_lookup_by_number($invoiceNumber) : null;
$requestedMethod = strtoupper(trim((string)($_GET['method'] ?? $_POST['method'] ?? '')));
$method = payment_gateway_resolve_requested_method($invoice, $requestedMethod);
$errors = [];
$selectedGateway = payment_gateway_pick($method, (string)($_POST['gateway'] ?? $_GET['gateway'] ?? ''));
$availableGateways = payment_gateway_available_for_method($method);
$cancelled = isset($_GET['cancelled']);
$invoicePayments = $invoice ? accounting_invoice_payments((int)$invoice['invoice_id']) : [];
$paymentSnapshot = $invoice ? accounting_invoice_payment_snapshot($invoice, $invoicePayments) : null;
$isPaid = !empty($paymentSnapshot['is_paid']);
$latestPayment = null;
foreach ($invoicePayments as $payment) {
    $paymentStatus = strtoupper(trim((string)($payment['payment_status'] ?? 'POSTED')));
    if ($paymentStatus === 'VOID' || $paymentStatus === 'FAILED') {
        continue;
    }
    $latestPayment = $payment;
    break;
}
$clientName = $invoice ? trim((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'Client')) : 'Client';
$invoicePdfUrl = $invoice ? trim((string)($invoice['stripe_invoice_pdf_url'] ?? '')) : '';
$paidDate = $paymentSnapshot ? trim((string)($paymentSnapshot['last_payment_date'] ?? '')) : '';
$paidMethod = '';
if ($latestPayment !== null && trim((string)($latestPayment['processor_payment_method_label'] ?? '')) !== '') {
    $paidMethod = trim((string)$latestPayment['processor_payment_method_label']);
} elseif ($paymentSnapshot !== null && trim((string)($paymentSnapshot['last_payment_method'] ?? '')) !== '') {
    $paidMethod = public_pay_method_label((string)$paymentSnapshot['last_payment_method']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($invoice['invoice_id'] ?? 0) > 0 && !$isPaid) {
    try {
        if ($selectedGateway === 'STRIPE') {
            $session = payment_gateway_stripe_checkout($invoice, $method);
            header('Location: ' . (string)$session['url'], true, 303);
            exit;
        }
        $errors[] = 'Secure checkout is temporarily unavailable. Please try again later or contact billing for help.';
    } catch (Throwable $e) {
        error_log('public invoice checkout failure for invoice ' . (string)($invoice['invoice_number'] ?? $invoiceNumber) . ': ' . $e->getMessage());
        $errors[] = 'Secure checkout is temporarily unavailable. Please try again later or contact billing for help.';
    }
}

page_header('Secure invoice payment', '', false);
?>
<style>
.page-title{display:none;}
.glass{max-width:1120px;margin:0 auto;padding:0;background:transparent;border:0;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none;}
.public-pay-shell{max-width:1040px;margin:0 auto;display:grid;gap:18px;}
.public-pay-hero{padding:28px;border-radius:28px;background:linear-gradient(135deg,rgba(15,23,42,.94),rgba(15,23,42,.78));border:1px solid rgba(148,163,184,.22);box-shadow:0 24px 70px rgba(0,0,0,.34);overflow:hidden;position:relative;}
.public-pay-hero::after{content:'';position:absolute;right:-100px;top:-120px;width:320px;height:320px;border-radius:999px;background:radial-gradient(circle,rgba(47,108,255,.22),transparent 68%);pointer-events:none;}
.public-pay-brand{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:28px;position:relative;z-index:1;}
.public-pay-brand img{height:42px;width:auto;max-width:260px;}
.public-pay-secure-pill,.public-pay-badge,.public-pay-status-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:8px 12px;border:1px solid rgba(148,163,184,.24);background:rgba(255,255,255,.06);color:rgba(233,238,247,.9);font-size:13px;font-weight:700;white-space:nowrap;}
.public-pay-badge{border-color:rgba(52,211,153,.32);background:rgba(16,185,129,.12);color:#d1fae5;}
.public-pay-status-pill.paid{border-color:rgba(52,211,153,.36);background:rgba(16,185,129,.14);color:#d1fae5;}
.public-pay-status-pill.open{border-color:rgba(96,165,250,.34);background:rgba(59,130,246,.12);color:#dbeafe;}
.public-pay-grid{display:grid;grid-template-columns:minmax(0,1.35fr) 360px;gap:18px;align-items:start;position:relative;z-index:1;}
.public-pay-title{margin:0 0 10px;font-size:clamp(30px,5vw,46px);line-height:1.04;letter-spacing:-.04em;}
.public-pay-lede{margin:0;color:rgba(233,238,247,.78);font-size:16px;line-height:1.65;max-width:640px;}
.public-pay-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:24px;}
.public-pay-field,.public-pay-panel{border:1px solid rgba(148,163,184,.18);border-radius:18px;background:rgba(2,6,23,.32);padding:14px;}
.public-pay-field.full{grid-column:1/-1;}
.public-pay-label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:rgba(203,213,225,.66);font-weight:800;margin-bottom:7px;}
.public-pay-value{font-size:16px;font-weight:800;color:#f8fafc;overflow-wrap:anywhere;}
.public-pay-amount{font-size:30px;letter-spacing:-.03em;}
.public-pay-panel{padding:18px;display:grid;gap:12px;}
.public-pay-panel h2{margin:0;font-size:18px;letter-spacing:-.02em;}
.public-pay-panel p{margin:0;color:rgba(233,238,247,.78);line-height:1.6;}
.public-pay-panel ul{margin:0;padding-left:20px;color:rgba(233,238,247,.78);line-height:1.58;display:grid;gap:8px;}
.public-pay-action-card{border-radius:24px;background:rgba(248,250,252,.97);border:1px solid rgba(255,255,255,.72);color:#13233f;box-shadow:0 18px 48px rgba(2,6,23,.28);padding:20px;display:grid;gap:14px;}
.public-pay-action-card h2{margin:0;font-size:20px;color:#0f172a;}
.public-pay-action-card p{margin:0;color:#475569;line-height:1.55;}
.public-pay-action-card .public-pay-badge{background:#dcfce7;border-color:#bbf7d0;color:#166534;width:max-content;max-width:100%;}
.public-pay-action-card .gateway-row{border-color:#dbe3ef;background:#f8fafc;color:#334155;}
.public-pay-action-card .gateway-row strong{color:#0f172a;}
.public-pay-action-card .btn-primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8;box-shadow:0 12px 24px rgba(29,78,216,.24);}
.public-pay-action-card .btn-secondary{background:#eef4ff;border-color:#d6e4ff;color:#1e3a8a;}
.gateway-row{display:grid;gap:8px;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:14px;background:rgba(255,255,255,.03);}
.public-pay-help{padding:18px;text-align:center;color:rgba(233,238,247,.78);line-height:1.6;}
.public-pay-help strong{color:#f8fafc;}
.public-pay-alert{border-radius:16px;padding:14px 16px;line-height:1.55;}
.public-pay-alert.warning{border:1px solid rgba(245,158,11,.28);background:rgba(245,158,11,.10);color:#ffedd5;}
@media (max-width:900px){.public-pay-grid{grid-template-columns:1fr;}.public-pay-brand{align-items:flex-start;flex-direction:column;}.public-pay-action-card{order:-1;}.public-pay-summary{grid-template-columns:1fr;}.public-pay-amount{font-size:26px;}}
@media (max-width:560px){.page-wrap{padding:12px;}.public-pay-hero{padding:20px;border-radius:22px;}.public-pay-brand img{height:34px;max-width:220px;}.public-pay-secure-pill,.public-pay-badge,.public-pay-status-pill{white-space:normal;}.public-pay-action-card{padding:16px;border-radius:20px;}}
</style>
<div class="public-pay-shell">
  <?php if ($errors): ?><div class="flash-error public-pay-alert"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php if ($cancelled): ?><div class="public-pay-alert warning">Checkout was cancelled before payment completed. No payment was recorded. You can continue to secure checkout when you are ready.</div><?php endif; ?>

  <div class="public-pay-hero">
    <div class="public-pay-brand">
      <img src="<?= accounting_h(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" alt="Midwest Managed IT">
      <div class="public-pay-secure-pill" aria-label="Secure payment page">🔒 Secure payment</div>
    </div>

    <div class="public-pay-grid">
      <main style="min-width:0;display:grid;gap:18px;">
        <section>
          <h1 class="public-pay-title">Secure invoice payment</h1>
          <p class="public-pay-lede">Review your Midwest Managed IT invoice details below, then continue to Stripe Checkout to complete payment securely.</p>
        </section>

        <?php if ($invoice): ?>
          <section class="public-pay-summary" aria-label="Invoice summary">
            <div class="public-pay-field">
              <div class="public-pay-label">Invoice number</div>
              <div class="public-pay-value"><?= accounting_h((string)$invoice['invoice_number']) ?></div>
            </div>
            <div class="public-pay-field">
              <div class="public-pay-label">Status</div>
              <div><span class="public-pay-status-pill <?= $isPaid ? 'paid' : 'open' ?>"><?= $isPaid ? 'Paid' : accounting_h(public_pay_status_label((string)$invoice['status'])) ?></span></div>
            </div>
            <div class="public-pay-field full">
              <div class="public-pay-label">Client</div>
              <div class="public-pay-value"><?= accounting_h($clientName) ?></div>
            </div>
            <div class="public-pay-field">
              <div class="public-pay-label"><?= $isPaid ? 'Amount paid' : 'Amount due' ?></div>
              <div class="public-pay-value public-pay-amount">$<?= number_format($isPaid ? (float)($paymentSnapshot['payments_received'] ?? $invoice['total_amount'] ?? 0) : (float)$invoice['balance_due'], 2) ?></div>
            </div>
            <div class="public-pay-field">
              <div class="public-pay-label">Due date</div>
              <div class="public-pay-value"><?= accounting_h(public_pay_format_date((string)($invoice['due_date'] ?? ''))) ?></div>
            </div>
            <?php if ($isPaid && $paidDate !== ''): ?>
              <div class="public-pay-field">
                <div class="public-pay-label">Payment date</div>
                <div class="public-pay-value"><?= accounting_h(public_pay_format_date($paidDate)) ?></div>
              </div>
            <?php endif; ?>
            <?php if ($isPaid && $paidMethod !== ''): ?>
              <div class="public-pay-field">
                <div class="public-pay-label">Payment method</div>
                <div class="public-pay-value"><?= accounting_h($paidMethod) ?></div>
              </div>
            <?php endif; ?>
          </section>
        <?php else: ?>
          <section class="public-pay-alert warning">
            <strong>Invalid invoice link.</strong><br>
            We could not find an invoice for this payment link. Please check the link from your invoice email or contact billing for help.
          </section>
        <?php endif; ?>

        <section class="public-pay-panel">
          <h2>Trust &amp; security</h2>
          <ul>
            <li>Payments are processed securely by Stripe.</li>
            <li>Midwest Managed IT does not store your card or bank credentials.</li>
            <li>You will be redirected to Stripe Checkout to complete payment.</li>
          </ul>
        </section>
      </main>

      <aside style="display:grid;gap:18px;min-width:0;">
        <section class="public-pay-action-card" aria-label="Payment action">
          <?php if ($isPaid): ?>
            <span class="public-pay-badge">Paid in full</span>
            <h2>Thank you — this invoice is paid.</h2>
            <p>We have recorded payment for this invoice. No additional payment action is needed.</p>
            <?php if ($invoicePdfUrl !== ''): ?>
              <a href="<?= accounting_h($invoicePdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Download invoice PDF</a>
            <?php endif; ?>
          <?php else: ?>
            <?php if ($method === 'ACH'): ?>
              <span class="public-pay-badge">Preferred: Bank / ACH</span>
              <h2>Continue with secure checkout</h2>
              <p>Bank/ACH is preferred and usually has lower processing fees. Card payment remains available in secure checkout.</p>
            <?php elseif ($method === 'CARD'): ?>
              <span class="public-pay-badge">Card payment</span>
              <h2>Continue with card checkout</h2>
              <p>This link is set for card payment. Payment is completed through Stripe Checkout.</p>
            <?php else: ?>
              <h2>Continue with secure checkout</h2>
              <p>Payment is completed through Stripe Checkout.</p>
            <?php endif; ?>

            <?php if (!$invoice): ?>
              <p>A valid invoice link is required before checkout can start.</p>
            <?php elseif ((float)$invoice['balance_due'] <= 0): ?>
              <p>This invoice does not have a remaining balance.</p>
            <?php elseif ($availableGateways === []): ?>
              <p>Secure checkout is temporarily unavailable. Please try again later or contact billing for help.</p>
            <?php else: ?>
              <form method="post" class="public-pay-actions" style="display:grid;gap:12px;">
                <input type="hidden" name="invoice" value="<?= accounting_h($invoiceNumber) ?>">
                <input type="hidden" name="method" value="<?= accounting_h($method) ?>">
                <?php if (count($availableGateways) > 1): ?>
                  <div>
                    <label style="display:block;margin-bottom:8px;color:#334155;">Choose checkout provider</label>
                    <div style="display:grid;gap:10px;">
                      <?php foreach ($availableGateways as $gateway): ?>
                        <label class="gateway-row" style="cursor:pointer;">
                          <span style="display:flex;gap:10px;align-items:flex-start;">
                            <input type="radio" name="gateway" value="<?= payment_gateway_h((string)$gateway['code']) ?>" <?= $selectedGateway === $gateway['code'] ? 'checked' : '' ?>>
                            <span>
                              <strong><?= payment_gateway_h((string)$gateway['label']) ?></strong>
                              <span style="display:block;opacity:.78;font-size:13px;line-height:1.45;margin-top:4px;"><?= payment_gateway_h((string)$gateway['note']) ?></span>
                            </span>
                          </span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php else: ?>
                  <input type="hidden" name="gateway" value="<?= payment_gateway_h((string)$selectedGateway) ?>">
                  <div class="gateway-row">
                    <strong><?= payment_gateway_h($method === 'ACH' ? 'Stripe Checkout' : (string)($availableGateways[$selectedGateway]['label'] ?? $selectedGateway)) ?></strong>
                    <span style="opacity:.78;font-size:13px;line-height:1.45;">Payment is completed through Stripe. Midwest Managed IT does not store card or bank login credentials.</span>
                  </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Continue to secure checkout</button>
                <?php if ($invoicePdfUrl !== ''): ?>
                  <a href="<?= accounting_h($invoicePdfUrl) ?>" target="_blank" rel="noopener" class="btn btn-secondary">Download invoice PDF</a>
                <?php endif; ?>
              </form>
            <?php endif; ?>
          <?php endif; ?>
        </section>

        <section class="public-pay-panel">
          <h2>What happens next</h2>
          <ul>
            <li>For ACH, payment may show pending until bank confirmation.</li>
            <li>For card, payment normally confirms immediately.</li>
            <li>Receipt and payment status will update after Stripe confirms payment.</li>
          </ul>
        </section>
      </aside>
    </div>
  </div>

  <footer class="public-pay-help">
    <strong>Need help?</strong><br>
    Questions about this invoice? Contact <a href="mailto:billing@midwestmanagedit.com">billing@midwestmanagedit.com</a><?php if ($invoice): ?> and include invoice number <strong><?= accounting_h((string)$invoice['invoice_number']) ?></strong><?php else: ?> and include the invoice number from your email<?php endif; ?>.
  </footer>
</div>
<?php page_footer();
