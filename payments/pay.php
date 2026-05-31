<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';

$invoiceNumber = trim((string)($_GET['invoice'] ?? $_POST['invoice'] ?? ''));
$invoice = $invoiceNumber !== '' ? accounting_invoice_lookup_by_number($invoiceNumber) : null;
$requestedMethod = strtoupper(trim((string)($_GET['method'] ?? $_POST['method'] ?? '')));
$method = payment_gateway_resolve_requested_method($invoice, $requestedMethod);
$errors = [];
$selectedGateway = payment_gateway_pick($method, (string)($_POST['gateway'] ?? $_GET['gateway'] ?? ''));
$availableGateways = payment_gateway_available_for_method($method);
$cancelled = isset($_GET['cancelled']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($invoice['invoice_id'] ?? 0) > 0) {
    try {
        if ($selectedGateway === 'STRIPE') {
            $session = payment_gateway_stripe_checkout($invoice, $method);
            header('Location: ' . (string)$session['url'], true, 303);
            exit;
        }
        $errors[] = 'No configured payment gateway is available for this payment method yet.';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

page_header('Pay invoice', '', false);
?>
<style>
.public-pay-grid{display:grid;grid-template-columns:minmax(0,1.25fr) 340px;gap:18px;align-items:start;}
.public-pay-grid .card{padding:18px;}
.public-pay-actions{display:grid;gap:12px;}
@media (max-width:900px){.public-pay-grid{grid-template-columns:1fr;}}
.gateway-row{display:grid;gap:8px;padding:12px;border:1px solid rgba(255,255,255,.08);border-radius:12px;background:rgba(255,255,255,.02);}
</style>
<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($cancelled): ?><div class="flash-error">Checkout was cancelled before payment completed.</div><?php endif; ?>
<div class="public-pay-grid">
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <h1 style="margin:0 0 8px;font-size:28px;">Invoice payment</h1>
          <div style="opacity:.78;">Secure online payment for Midwest Managed IT invoices.</div>
        </div>
        <div style="text-align:right;min-width:220px;">
          <div style="font-size:13px;opacity:.72;">Preferred method</div>
          <div style="font-size:20px;font-weight:700;"><?= $method === 'ACH' ? 'Bank / ACH' : accounting_h($method) ?></div>
        </div>
      </div>
      <?php if ($invoice): ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px;">
        <div class="gateway-row"><div style="font-size:13px;opacity:.72;">Invoice</div><div style="font-weight:700;"><?= accounting_h((string)$invoice['invoice_number']) ?></div></div>
        <div class="gateway-row"><div style="font-size:13px;opacity:.72;">Balance due</div><div style="font-weight:700;">$<?= number_format((float)$invoice['balance_due'], 2) ?></div></div>
        <div class="gateway-row"><div style="font-size:13px;opacity:.72;">Client</div><div style="font-weight:700;"><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'] ?: 'Client')) ?></div></div>
        <div class="gateway-row"><div style="font-size:13px;opacity:.72;">Status</div><div style="font-weight:700;"><?= accounting_h((string)$invoice['status']) ?></div></div>
      </div>
      <?php else: ?>
      <div class="card" style="padding:14px;margin-top:18px;background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.18);">Invoice lookup was not provided or could not be matched.</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Gateway status</h2>
      <div style="display:grid;gap:10px;">
        <div class="gateway-row"><strong>Stripe</strong><div style="opacity:.78;"><?= payment_gateway_stripe_enabled() ? 'Configured and ready.' : 'Not configured yet.' ?></div></div>
      </div>
    </div>
  </div>

  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Pay now</h2>
      <?php if ($method === 'ACH'): ?>
        <div style="opacity:.82;line-height:1.55;margin-bottom:12px;">Bank/ACH payment is preferred for this invoice. Secure Stripe Checkout will present bank payment first, and card remains available as a fallback in checkout.</div>
      <?php elseif ($method === 'CARD'): ?>
        <div style="opacity:.82;line-height:1.55;margin-bottom:12px;">This link was opened with a card-only payment request. Remove the method override from the URL to use the preferred bank/ACH flow.</div>
      <?php endif; ?>
      <?php if (!$invoice): ?>
        <div style="opacity:.78;">A valid invoice is required before checkout can start.</div>
      <?php elseif ((float)$invoice['balance_due'] <= 0): ?>
        <div style="opacity:.78;">This invoice is already paid.</div>
      <?php elseif ($availableGateways === []): ?>
        <div style="opacity:.78;line-height:1.55;">No payment gateway is configured for <?= accounting_h($method) ?> yet. Add your Stripe keys to <code>inc/config.php</code>, then reopen this link.</div>
      <?php else: ?>
        <form method="post" class="public-pay-actions">
          <input type="hidden" name="invoice" value="<?= accounting_h($invoiceNumber) ?>">
          <input type="hidden" name="method" value="<?= accounting_h($method) ?>">
          <?php if (count($availableGateways) > 1): ?>
            <div>
              <label style="display:block;margin-bottom:8px;">Choose gateway</label>
              <div style="display:grid;gap:10px;">
                <?php foreach ($availableGateways as $gateway): ?>
                  <label class="gateway-row" style="cursor:pointer;">
                    <span style="display:flex;gap:10px;align-items:flex-start;">
                      <input type="radio" name="gateway" value="<?= payment_gateway_h((string)$gateway['code']) ?>" <?= $selectedGateway === $gateway['code'] ? 'checked' : '' ?>>
                      <span>
                        <strong><?= payment_gateway_h((string)$gateway['label']) ?></strong>
                        <div style="opacity:.76;font-size:13px;line-height:1.45;margin-top:4px;"><?= payment_gateway_h((string)$gateway['note']) ?></div>
                      </span>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <input type="hidden" name="gateway" value="<?= payment_gateway_h((string)$selectedGateway) ?>">
            <div class="gateway-row">
              <strong><?= payment_gateway_h((string)($availableGateways[$selectedGateway]['label'] ?? $selectedGateway)) ?></strong>
              <div style="opacity:.76;font-size:13px;line-height:1.45;"><?= payment_gateway_h((string)($availableGateways[$selectedGateway]['note'] ?? '')) ?></div>
            </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-secondary" style="justify-content:flex-start;">Continue to secure checkout</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">What happens next</h2>
      <div style="display:grid;gap:10px;opacity:.82;line-height:1.55;">
        <div>Bank/ACH is the preferred payment option and appears first when available; card payments remain available inside secure Stripe Checkout unless this link was explicitly set to card-only.</div>
        <div>Card payments through Stripe return immediately and can be posted back to the invoice automatically.</div>
        <div>ACH/bank payments may show as pending while Stripe waits for bank settlement; invoices are marked paid only after Stripe confirms success.</div>
        <div>Keep this invoice number handy: <strong><?= $invoice ? accounting_h((string)$invoice['invoice_number']) : '—' ?></strong></div>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
