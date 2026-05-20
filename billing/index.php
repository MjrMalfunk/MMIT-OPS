<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/billing_portal.php';

$flashSuccess = null;
$flashError = null;

if (isset($_GET['logout'])) {
    billing_portal_session_clear();
    header('Location: ' . BASE_URL . '/billing/index.php', true, 302);
    exit;
}

$accessToken = trim((string) ($_GET['access'] ?? ''));
if ($accessToken !== '') {
    $verified = billing_portal_verify_access_token($accessToken);
    if ($verified && billing_portal_has_email_match((string) $verified['email'])) {
        billing_portal_session_set((string) $verified['email']);
        audit_event(null, 'BILLING_LINK_USED', ['email' => (string) $verified['email']]);
        header('Location: ' . BASE_URL . '/billing/index.php?welcome=1', true, 302);
        exit;
    }
    $flashError = 'That billing link is no longer valid. Request a fresh one below.';
}

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($requestMethod === 'POST') {
    $email = billing_portal_normalize_email((string) ($_POST['email'] ?? ''));
    $lastSentAt = (int) ($_SESSION['billing_portal_last_sent_at'] ?? 0);
    if ($lastSentAt > 0 && (time() - $lastSentAt) < 30) {
        $flashError = 'Give it about 30 seconds before requesting another secure link.';
    } else {
        $result = billing_portal_send_access_link($email);
        if (!empty($result['ok'])) {
            $_SESSION['billing_portal_last_sent_at'] = time();
            $flashSuccess = 'If that email matches a billing contact, a secure sign-in link is on the way.';
        } else {
            $flashError = (string) ($result['error'] ?? 'Secure billing access could not be started right now.');
        }
    }
}

if (!empty($_GET['welcome'])) {
    $flashSuccess = 'Secure billing access is live. You are inside the Midwest Managed IT billing center now.';
}

$sessionEmail = billing_portal_session_email();
$clientMatches = $sessionEmail ? billing_portal_client_matches_for_email($sessionEmail) : [];
$clientCount = count($clientMatches);
$invoices = $sessionEmail ? billing_portal_list_invoices_for_email($sessionEmail, 100) : [];
$summary = billing_portal_summary($invoices);
$selectedInvoiceId = (int) ($_GET['invoice'] ?? 0);
$selectedInvoice = $sessionEmail && $selectedInvoiceId > 0 ? billing_portal_invoice_for_email($sessionEmail, $selectedInvoiceId) : null;
$selectedInvoiceLines = $selectedInvoice ? accounting_invoice_lines((int) $selectedInvoice['invoice_id']) : [];
$selectedInvoicePayments = $selectedInvoice ? accounting_invoice_payments((int) $selectedInvoice['invoice_id']) : [];

$pageTitle = 'Billing Center';
$publicSiteUrl = billing_portal_public_site_url();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?> | <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="icon" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon.ico">
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-64.png">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-512.png">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=7">
  <style>
    .billing-wrap { max-width: 1180px; margin: 0 auto; padding: 18px; }
    .billing-topbar { display:flex; align-items:center; justify-content:space-between; gap:12px; margin:6px 0 14px; }
    .billing-actions { display:flex; flex-wrap:wrap; gap:10px; }
    .billing-chip { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:999px; border:1px solid rgba(148,163,184,.2); background:rgba(255,255,255,.04); color:var(--fg); text-decoration:none; }
    .billing-shell { display:grid; gap:18px; }
    .billing-hero { display:grid; grid-template-columns:minmax(0,1.4fr) minmax(280px,.9fr); gap:18px; }
    .billing-card { padding:22px; }
    .billing-card h1 { margin:10px 0 12px; font-size:clamp(2rem,4vw,3rem); line-height:1.05; letter-spacing:-.03em; }
    .billing-card h2 { margin:0 0 12px; font-size:1.2rem; }
    .billing-card h3 { margin:0 0 10px; font-size:1.05rem; }
    .billing-card p { margin:0 0 14px; color:var(--muted); }
    .eyebrow { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; background:rgba(140,200,255,.10); border:1px solid rgba(140,200,255,.16); color:#d7ecff; font-size:.78rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
    .billing-login-grid { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(260px,.8fr); gap:18px; }
    .billing-form { display:grid; gap:12px; }
    .billing-form .btn { width:auto; min-width:170px; }
    .summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
    .summary-tile { padding:16px; }
    .summary-tile .label { font-size:13px; opacity:.72; }
    .summary-tile .value { font-size:1.7rem; font-weight:800; margin-top:6px; }
    .billing-grid { display:grid; grid-template-columns:minmax(0,1.25fr) minmax(320px,.85fr); gap:18px; }
    .table-shell { overflow:auto; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:12px 10px; vertical-align:top; border-bottom:1px solid rgba(148,163,184,.12); }
    th { text-align:left; color:var(--muted-strong); font-size:13px; }
    td .muted { color:var(--muted); font-size:13px; }
    .num { text-align:right; white-space:nowrap; }
    .invoice-actions { display:flex; flex-wrap:wrap; gap:10px; }
    .invoice-actions .btn { width:auto; min-width:0; padding:10px 14px; min-height:40px; }
    .detail-grid { display:grid; gap:14px; }
    .detail-meta { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .meta-box { padding:14px; }
    .meta-box .label { font-size:12px; opacity:.72; margin-bottom:6px; text-transform:uppercase; letter-spacing:.05em; }
    .meta-box .value { font-weight:700; }
    .line-table th, .line-table td, .payment-table th, .payment-table td { padding:10px 8px; }
    .empty-state { color:var(--muted); }
    .subtle { color:var(--muted); font-size:14px; }
    @media (max-width: 980px) {
      .billing-hero, .billing-login-grid, .billing-grid, .summary-grid, .detail-meta { grid-template-columns:1fr; }
    }
  </style>
</head>
<body>
  <div class="billing-wrap">
    <div class="billing-topbar">
      <a class="portal-brand" href="<?= htmlspecialchars($publicSiteUrl) ?>" aria-label="Midwest Managed IT">
        <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" class="portal-logo billing-brand-logo" data-dark-logo="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" data-light-logo="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-dark.svg" alt="Midwest Managed IT">
      </a>
      <div class="billing-actions">
        <button class="billing-chip theme-toggle" type="button" id="themeToggle" aria-pressed="false">
          <span class="theme-toggle-icon" aria-hidden="true"></span>
          <span class="theme-toggle-label">Light mode</span>
        </button>
        <a class="billing-chip" href="<?= htmlspecialchars($publicSiteUrl) ?>">Back to Midwest Managed IT</a>
        <?php if ($sessionEmail): ?>
          <a class="billing-chip" href="<?= htmlspecialchars(BASE_URL) ?>/billing/index.php?logout=1">Use different email</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($flashSuccess): ?><div class="flash-success"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

    <div class="billing-shell">
      <?php if (!$sessionEmail): ?>
        <section class="billing-hero">
          <div class="glass billing-card">
            <div class="eyebrow">Billing Center</div>
            <h1>Secure billing access, without the swivel chair routine.</h1>
            <p>Start here with the same easy email-link motion you used in the client portal. Once the link lands, clients can review invoices, balances, and payment status in one branded billing workspace.</p>
            <p class="subtle">This lane is for invoices and payment visibility. Support and asset visibility stay in their own lanes.</p>
          </div>
          <aside class="glass billing-card">
            <div class="eyebrow">What happens next</div>
            <h2>Magic-link billing flow</h2>
            <p>Enter the billing email tied to the account, then open the secure link we send out. That link opens the Midwest Managed IT billing center with invoice history and payment actions already lined up.</p>
            <ul class="subtle" style="margin:0;padding-left:18px;line-height:1.65;">
              <li>No password maze</li>
              <li>Short-lived secure access link</li>
              <li>Hosted payment pages stay with Stripe when payment is due</li>
            </ul>
          </aside>
        </section>

        <section class="glass billing-card">
          <div class="billing-login-grid">
            <div class="card billing-card">
              <div class="eyebrow">Email Link Login</div>
              <h2>Send secure billing access</h2>
              <p>Use the billing contact email for the client account you want to review.</p>
              <form method="post" class="billing-form">
                <div>
                  <label for="billing-email">Billing email</label>
                  <input id="billing-email" name="email" type="email" placeholder="you@company.com" required autocomplete="email">
                </div>
                <button type="submit" class="btn btn-primary">Send Billing Link</button>
              </form>
            </div>
            <div class="card billing-card">
              <div class="eyebrow">Beta Notes</div>
              <h2>For tonight’s outside-eye test</h2>
              <p>Make sure the email Doc uses exists on the target client as either the client email or a contact email. Once that is in place, this flow is ready for a clean outsider pass.</p>
              <p class="subtle">If an email matches multiple organizations, the billing center shows all linked invoice activity together.</p>
            </div>
          </div>
        </section>
      <?php else: ?>
        <section class="billing-hero">
          <div class="glass billing-card">
            <div class="eyebrow">Signed In</div>
            <h1>Billing center for <?= htmlspecialchars($sessionEmail) ?></h1>
            <p>You are inside the Midwest Managed IT billing lane now. Review invoice status, balances, payment history, and jump into hosted payment pages when payment is due.</p>
          </div>
          <aside class="glass billing-card">
            <div class="eyebrow">Account Snapshot</div>
            <h2><?= $clientCount ?> linked <?= $clientCount === 1 ? 'organization' : 'organizations' ?></h2>
            <p><?= $clientCount > 0 ? 'Secure access matched the email against the current client roster and contact list.' : 'No linked billing accounts were found for this session.' ?></p>
            <?php if ($clientMatches): ?>
              <ul class="subtle" style="margin:0;padding-left:18px;line-height:1.65;">
                <?php foreach ($clientMatches as $match): ?>
                  <li><?= htmlspecialchars((string) ($match['dba_name'] ?: $match['legal_name'])) ?></li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </aside>
        </section>

        <section class="summary-grid">
          <div class="glass summary-tile">
            <div class="label">Invoices</div>
            <div class="value"><?= (int) $summary['invoice_count'] ?></div>
          </div>
          <div class="glass summary-tile">
            <div class="label">Open invoices</div>
            <div class="value"><?= (int) $summary['open_count'] ?></div>
          </div>
          <div class="glass summary-tile">
            <div class="label">Open balance</div>
            <div class="value">$<?= number_format((float) $summary['open_balance'], 2) ?></div>
          </div>
          <div class="glass summary-tile">
            <div class="label">Overdue</div>
            <div class="value"><?= (int) $summary['overdue_count'] ?></div>
          </div>
        </section>

        <section class="billing-grid">
          <div class="glass billing-card table-shell">
            <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:12px;">
              <div>
                <div class="eyebrow">Invoice Register</div>
                <h2 style="margin-top:10px;">Invoices and balances</h2>
              </div>
              <div class="subtle">Newest invoices first</div>
            </div>
            <table>
              <thead>
                <tr>
                  <th>Invoice</th>
                  <th>Client</th>
                  <th>Status</th>
                  <th class="num">Total</th>
                  <th class="num">Balance</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$invoices): ?>
                  <tr><td colspan="6" class="empty-state">No invoices are available for this billing email yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($invoices as $invoice): ?>
                    <?php $payUrl = billing_portal_invoice_pay_url($invoice); ?>
                    <tr>
                      <td>
                        <div><strong><?= htmlspecialchars((string) $invoice['invoice_number']) ?></strong></div>
                        <div class="muted"><?= htmlspecialchars((string) $invoice['invoice_date']) ?><?= !empty($invoice['due_date']) ? ' · Due ' . htmlspecialchars((string) $invoice['due_date']) : '' ?></div>
                      </td>
                      <td>
                        <div><?= htmlspecialchars((string) ($invoice['dba_name'] ?: $invoice['legal_name'])) ?></div>
                        <div class="muted"><?= htmlspecialchars((string) ($invoice['contract_number'] ?: $invoice['client_code'])) ?></div>
                      </td>
                      <td><?= accounting_invoice_status_badge_html($invoice) ?></td>
                      <td class="num">$<?= number_format((float) $invoice['total_amount'], 2) ?></td>
                      <td class="num">$<?= number_format((float) $invoice['balance_due'], 2) ?></td>
                      <td>
                        <div class="invoice-actions">
                          <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/billing/index.php?invoice=<?= (int) $invoice['invoice_id'] ?>#invoice-detail">Details</a>
                          <?php if ($payUrl !== ''): ?>
                            <a class="btn btn-primary" href="<?= htmlspecialchars($payUrl) ?>" target="_blank" rel="noopener noreferrer">Pay / Open</a>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <aside id="invoice-detail" class="glass billing-card">
            <div class="eyebrow">Invoice Detail</div>
            <?php if ($selectedInvoice): ?>
              <div class="detail-grid" style="margin-top:12px;">
                <div>
                  <h2 style="margin:0 0 8px;"><?= htmlspecialchars((string) $selectedInvoice['invoice_number']) ?></h2>
                  <p><?= htmlspecialchars((string) ($selectedInvoice['dba_name'] ?: $selectedInvoice['legal_name'])) ?></p>
                  <div><?= accounting_invoice_status_badge_html($selectedInvoice) ?></div>
                </div>
                <div class="detail-meta">
                  <div class="card meta-box">
                    <div class="label">Invoice total</div>
                    <div class="value">$<?= number_format((float) $selectedInvoice['total_amount'], 2) ?></div>
                  </div>
                  <div class="card meta-box">
                    <div class="label">Balance due</div>
                    <div class="value">$<?= number_format((float) $selectedInvoice['balance_due'], 2) ?></div>
                  </div>
                  <div class="card meta-box">
                    <div class="label">Invoice date</div>
                    <div class="value"><?= htmlspecialchars((string) $selectedInvoice['invoice_date']) ?></div>
                  </div>
                  <div class="card meta-box">
                    <div class="label">Due date</div>
                    <div class="value"><?= htmlspecialchars((string) ($selectedInvoice['due_date'] ?: '—')) ?></div>
                  </div>
                </div>
                <div class="invoice-actions">
                  <?php $selectedPayUrl = billing_portal_invoice_pay_url($selectedInvoice); ?>
                  <?php if ($selectedPayUrl !== ''): ?>
                    <a class="btn btn-primary" href="<?= htmlspecialchars($selectedPayUrl) ?>" target="_blank" rel="noopener noreferrer">Open payment page</a>
                  <?php endif; ?>
                  <?php if (!empty($selectedInvoice['stripe_invoice_pdf_url'])): ?>
                    <a class="btn btn-secondary" href="<?= htmlspecialchars((string) $selectedInvoice['stripe_invoice_pdf_url']) ?>" target="_blank" rel="noopener noreferrer">Open invoice PDF</a>
                  <?php endif; ?>
                </div>

                <div class="card billing-card">
                  <h3>Line items</h3>
                  <table class="line-table">
                    <thead>
                      <tr>
                        <th>Description</th>
                        <th class="num">Qty</th>
                        <th class="num">Unit</th>
                        <th class="num">Line total</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($selectedInvoiceLines as $line): ?>
                        <tr>
                          <td>
                            <div><?= htmlspecialchars((string) $line['description']) ?></div>
                            <?php if (!empty($line['item_code'])): ?><div class="muted"><?= htmlspecialchars((string) $line['item_code']) ?></div><?php endif; ?>
                          </td>
                          <td class="num"><?= number_format((float) $line['quantity'], 2) ?></td>
                          <td class="num">$<?= number_format((float) $line['unit_price'], 2) ?></td>
                          <td class="num">$<?= number_format((float) $line['line_total'], 2) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="card billing-card">
                  <h3>Payment history</h3>
                  <?php if (!$selectedInvoicePayments): ?>
                    <div class="empty-state">No payments have been applied to this invoice yet.</div>
                  <?php else: ?>
                    <table class="payment-table">
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>Method</th>
                          <th>Status</th>
                          <th class="num">Applied</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($selectedInvoicePayments as $payment): ?>
                          <tr>
                            <td><?= htmlspecialchars((string) $payment['payment_date']) ?></td>
                            <td><?= htmlspecialchars((string) $payment['payment_method']) ?></td>
                            <td><?= accounting_payment_status_badge_html((string) $payment['payment_status']) ?></td>
                            <td class="num">$<?= number_format((float) $payment['amount_applied'], 2) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  <?php endif; ?>
                </div>
              </div>
            <?php else: ?>
              <h2 style="margin-top:12px;">Select an invoice</h2>
              <p>Choose any invoice from the register to load detail, line items, and payment history here.</p>
            <?php endif; ?>
          </aside>
        </section>
      <?php endif; ?>
    </div>
  </div>
<script>
(function(){
  const storageKey = 'mmit-billing-theme';
  const body = document.body;
  const toggle = document.getElementById('themeToggle');
  const label = toggle ? toggle.querySelector('.theme-toggle-label') : null;
  const logo = document.querySelector('.billing-brand-logo');
  function applyTheme(theme){
    const light = theme === 'light';
    body.classList.toggle('light-mode', light);
    if(toggle){ toggle.setAttribute('aria-pressed', light ? 'true' : 'false'); }
    if(label){ label.textContent = light ? 'Dark mode' : 'Light mode'; }
    if(logo){ logo.src = light ? logo.getAttribute('data-light-logo') : logo.getAttribute('data-dark-logo'); }
  }
  const saved = localStorage.getItem(storageKey) || 'dark';
  applyTheme(saved);
  if(toggle){ toggle.addEventListener('click', function(){ const next = body.classList.contains('light-mode') ? 'dark' : 'light'; localStorage.setItem(storageKey, next); applyTheme(next); }); }
})();
</script>
</body>
</html>
