<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();

$filters = [
    'method' => strtoupper(trim((string)($_GET['method'] ?? ''))),
    'status' => strtoupper(trim((string)($_GET['status'] ?? ''))),
    'client' => trim((string)($_GET['client'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];
$payments = accounting_list_payments($filters, 250);
$totals = accounting_payment_totals($filters);

page_header('Payments', 'accounting');
accounting_subnav('payments');
?>
<style>
.payments-filter-card, .payments-register-card { padding: 18px; }
.payments-filter-form { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; align-items:end; }
.payments-filter-form .date-to-row { display:flex; gap:8px; }
.payments-filter-form input,
.payments-filter-form select,
.payments-filter-form textarea { width:100%; padding:10px 12px; }
.payments-filter-form .btn { width:auto; min-width:104px; }
.payments-register-card h2 { margin:0 0 12px; font-size:18px; }
@media (max-width: 1100px) { .payments-filter-form { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width: 700px) { .payments-filter-form { grid-template-columns:1fr; } .payments-filter-form .date-to-row { flex-direction:column; } }
</style>

<div class="card payments-filter-card" style="margin-bottom:16px;">
  <form method="get" class="payments-filter-form">
    <div>
      <label>Method</label>
      <select name="method">
        <option value="">All methods</option>
        <?php foreach (accounting_get_payment_methods() as $method): ?>
          <option value="<?= accounting_h($method) ?>" <?= $filters['method'] === $method ? 'selected' : '' ?>><?= accounting_h($method) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach (accounting_payment_statuses() as $status): ?>
          <option value="<?= accounting_h($status) ?>" <?= $filters['status'] === $status ? 'selected' : '' ?>><?= accounting_h($status) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Client / reference</label>
      <input type="text" name="client" value="<?= accounting_h($filters['client']) ?>">
    </div>
    <div>
      <label>Date from</label>
      <input type="date" name="date_from" value="<?= accounting_h($filters['date_from']) ?>">
    </div>
    <div>
      <label>Date to</label>
      <div class="date-to-row">
        <input type="date" name="date_to" value="<?= accounting_h($filters['date_to']) ?>">
        <button type="submit" class="btn btn-secondary">Filter</button>
      </div>
    </div>
  </form>
</div>

<div class="metric-grid" style="margin-bottom:16px;">
  <div class="card metric-card"><div class="metric-label">Payments</div><div class="metric-value"><?= (int)$totals['count'] ?></div></div>
  <div class="card metric-card"><div class="metric-label">Gross total</div><div class="metric-value">$<?= number_format((float)$totals['gross_total'], 2) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Fees</div><div class="metric-value">$<?= number_format((float)$totals['fee_total'], 2) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Net deposits</div><div class="metric-value">$<?= number_format((float)$totals['net_total'], 2) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Applied</div><div class="metric-value">$<?= number_format((float)$totals['applied_total'], 2) ?></div></div>
</div>

<div class="card payments-register-card table-shell click-table">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
    <h2 style="margin:0;">Payment register</h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php" class="btn btn-secondary" style="text-decoration:none;">Record manual payment</a>
      <a href="<?= accounting_h(BASE_URL) ?>/accounting/reconcile.php" class="btn btn-secondary" style="text-decoration:none;">Bank reconcile</a>
      <a href="<?= accounting_h(BASE_URL) ?>/payments/gateway_health.php" class="btn btn-secondary" style="text-decoration:none;">Gateway health</a>
    </div>
  </div>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Client</th>
        <th>Method</th>
        <th class="status-cell">Status</th>
        <th class="num">Gross</th>
        <th class="num">Fee</th>
        <th class="num">Net</th>
        <th class="num">Applied</th>
        <th>Reference</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$payments): ?>
        <tr><td colspan="9" class="empty-state">No payments found for the current filter set.</td></tr>
      <?php else: ?>
        <?php foreach ($payments as $payment): ?>
          <tr>
            <td><a href="<?= accounting_h(BASE_URL) ?>/payments/view.php?id=<?= (int)$payment['payment_id'] ?>"><?= accounting_h((string)$payment['payment_date']) ?></a><div class="muted-sm"><?= accounting_h((string)($payment['created_by_name'] ?: 'System')) ?></div></td>
            <td><div><?= accounting_h((string)($payment['dba_name'] ?: $payment['legal_name'])) ?></div><div class="muted-sm"><?= accounting_h((string)$payment['client_code']) ?></div></td>
            <td class="status-cell"><?= accounting_payment_method_badge_html((string)$payment['payment_method']) ?></td>
            <td class="status-cell"><?= accounting_payment_status_badge_html((string)$payment['payment_status']) ?></td>
            <td class="num">$<?= number_format((float)$payment['gross_amount'], 2) ?></td>
            <td class="num">$<?= number_format((float)$payment['fee_amount'], 2) ?></td>
            <td class="num">$<?= number_format((float)$payment['net_amount'], 2) ?></td>
            <td class="num">$<?= number_format((float)$payment['applied_amount'], 2) ?></td>
            <td>
              <div><?= accounting_h((string)($payment['reference_number'] ?: '—')) ?></div>
              <?php if (!empty($payment['invoice_id'])): ?>
                <div class="muted-sm"><?= accounting_invoice_link_html((int)$payment['invoice_id'], (string)$payment['invoice_number']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
    <?php if ($payments): ?>
      <tfoot>
        <tr style="border-top:1px solid rgba(255,255,255,.10);font-weight:700;">
          <td colspan="4" class="num">Totals</td>
          <td class="num">$<?= number_format((float)$totals['gross_total'], 2) ?></td>
          <td class="num">$<?= number_format((float)$totals['fee_total'], 2) ?></td>
          <td class="num">$<?= number_format((float)$totals['net_total'], 2) ?></td>
          <td class="num">$<?= number_format((float)$totals['applied_total'], 2) ?></td>
          <td>&nbsp;</td>
        </tr>
      </tfoot>
    <?php endif; ?>
  </table>
</div>
<?php page_footer(); ?>
