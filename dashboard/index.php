<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
$user = current_user();
accounting_require_ready();
$summary = accounting_summary();
$receivables = accounting_receivables_summary();
$recurring = accounting_recurring_summary();
$payments = accounting_payment_totals();
$recentInvoices = accounting_recent_invoices(5);
$recentPayments = accounting_list_payments([], 5);
$clientCount = count(accounting_list_clients());
page_header('Dashboard', 'dashboard');
?>
<div class="dashboard-hero">
  <div class="card" style="padding:18px;">
    <div class="kicker">Welcome back</div>
    <h2 class="section-title" style="font-size:26px;margin-top:4px;"><?= !empty($user['display_name']) ? htmlspecialchars((string)$user['display_name']) : 'Operator' ?></h2>
    <div class="section-subtitle">Keep an eye on receivables, recurring revenue, and the items that need action today.</div>
    <div class="metric-grid" style="margin-top:16px;">
      <div class="card stat-card"><div class="label">Open A/R</div><div class="value">$<?= number_format((float)$summary['open_receivable_total'], 2) ?></div></div>
      <div class="card stat-card"><div class="label">Draft invoices</div><div class="value"><?= (int)$summary['draft_invoice_count'] ?></div></div>
      <div class="card stat-card"><div class="label">Monthly recurring value</div><div class="value">$<?= number_format((float)$recurring['monthly_value'], 2) ?></div></div>
      <div class="card stat-card"><div class="label">Payments logged</div><div class="value"><?= (int)$payments['count'] ?></div></div>
    </div>
  </div>
  <div class="card" style="padding:18px;">
    <h2 class="section-title" style="margin-bottom:10px;">Quick actions</h2>
    <div class="quick-links">
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/clients/index.php">Manage clients</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/clients/services.php">Assign client services</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/accounting/recurring.php">Open recurring billing</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/accounting/invoice_new.php">Create one-off invoice</a>
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/payments/index.php">Review payments</a>
    </div>
  </div>
</div>

<div class="grid-4" style="margin:16px 0;">
  <div class="card stat-card"><div class="label">Active clients</div><div class="value"><?= $clientCount ?></div><div class="muted-note">Tracked through your client register.</div></div>
  <div class="card stat-card"><div class="label">Catalog items</div><div class="value"><?= (int)$summary['catalog_item_count'] ?></div><div class="muted-note">Service and license templates ready to bill.</div></div>
  <div class="card stat-card"><div class="label">Recurring items</div><div class="value"><?= (int)$summary['recurring_item_count'] ?></div><div class="muted-note">Items feeding draft invoice generation.</div></div>
  <div class="card stat-card"><div class="label">Due today</div><div class="value"><?= (int)$recurring['due_today_count'] ?></div><div class="muted-note">Recurring items ready for draft invoices.</div></div>
</div>

<div class="grid-2">
  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div>
        <h2 class="section-title">Recent invoices</h2>
        <div class="section-subtitle">Drafts, issued invoices, and paid work in one view.</div>
      </div>
      <a href="<?= htmlspecialchars(BASE_URL) ?>/accounting/invoices.php">Open invoices →</a>
    </div>
    <table class="table-shell">
      <thead><tr><th>Invoice #</th><th>Client</th><th class="date">Date</th><th class="status">Status</th><th class="money">Amount</th></tr></thead>
      <tbody>
      <?php if (!$recentInvoices): ?>
        <tr><td colspan="5" class="empty-state">No invoices yet. Create one-off work or generate drafts from recurring billing.</td></tr>
      <?php else: foreach ($recentInvoices as $invoice): ?>
        <tr>
          <td><?= accounting_invoice_link_html((int)$invoice['invoice_id'], (string)$invoice['invoice_number']) ?></td>
          <td><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'])) ?></td>
          <td class="date"><?= accounting_h((string)$invoice['invoice_date']) ?></td>
          <td class="status"><?= accounting_invoice_status_badge_html($invoice) ?></td>
          <td class="money">$<?= number_format((float)$invoice['total_amount'], 2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div>
        <h2 class="section-title">Recent payments</h2>
        <div class="section-subtitle">Track what has been collected and what still needs attention.</div>
      </div>
      <a href="<?= htmlspecialchars(BASE_URL) ?>/payments/index.php">Open payments →</a>
    </div>
    <table class="table-shell">
      <thead><tr><th class="date">Date</th><th>Client</th><th class="status">Method</th><th class="status">Status</th><th class="money">Net</th></tr></thead>
      <tbody>
      <?php if (!$recentPayments): ?>
        <tr><td colspan="5" class="empty-state">No payments yet. Sent invoices and Stripe later will populate this area quickly.</td></tr>
      <?php else: foreach ($recentPayments as $payment): ?>
        <tr>
          <td class="date"><?= accounting_h((string)$payment['payment_date']) ?></td>
          <td><?= accounting_h((string)($payment['dba_name'] ?: $payment['legal_name'])) ?><div class="muted-note"><?= accounting_h((string)($payment['reference_number'] ?: '—')) ?></div></td>
          <td class="status"><?= accounting_payment_method_badge_html((string)$payment['payment_method']) ?></td>
          <td class="status"><?= accounting_payment_status_badge_html((string)$payment['payment_status']) ?></td>
          <td class="money">$<?= number_format((float)$payment['net_amount'], 2) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
