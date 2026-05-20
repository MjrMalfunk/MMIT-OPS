<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();

$summary = accounting_receivables_summary();
$aging = accounting_receivables_aging(200);
$clients = accounting_receivable_client_balances(200);
page_header('Accounts Receivable', 'accounting');
accounting_subnav('receivables');
?>
<style>
@media (max-width: 1080px) { .receivables-grid, .receivables-top-grid { grid-template-columns:1fr !important; } }
</style>
<div style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px;margin-bottom:18px;">
  <?php $cards = [
    ['Open Invoices', (string)$summary['open_invoice_count']],
    ['Overdue Invoices', (string)$summary['overdue_invoice_count']],
    ['Open A/R', '$' . number_format((float)$summary['total_open_ar'], 2)],
    ['Current', '$' . number_format((float)$summary['current_bucket'], 2)],
    ['1-30 Days', '$' . number_format((float)$summary['bucket_1_30'], 2)],
    ['31+ Days', '$' . number_format((float)($summary['bucket_31_60'] + $summary['bucket_61_90'] + $summary['bucket_90_plus']), 2)],
  ]; foreach ($cards as [$label, $value]): ?>
    <div class="card" style="padding:16px;"><div style="opacity:.78;font-size:14px;margin-bottom:6px;"><?= accounting_h($label) ?></div><div style="font-size:22px;font-weight:800;"><?= accounting_h($value) ?></div></div>
  <?php endforeach; ?>
</div>

<div class="receivables-grid" style="display:grid;grid-template-columns:1fr 1.35fr;gap:16px;align-items:start;">
  <div style="display:grid;gap:16px;">
    <div class="card" style="padding:16px;overflow:auto;">
      <h2 style="margin:0 0 12px;font-size:18px;">Client balances</h2>
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Client</th><th style="padding:10px 8px;">Open invoices</th><th style="padding:10px 8px;">Oldest due</th><th style="padding:10px 8px;text-align:right;">Balance</th></tr></thead>
        <tbody>
        <?php if (!$clients): ?>
          <tr><td colspan="4" style="padding:18px 8px;opacity:.75;">No open receivables right now.</td></tr>
        <?php else: foreach ($clients as $client): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;"><?= accounting_h((string)($client['dba_name'] ?: $client['legal_name'])) ?><?php if (!empty($client['has_overdue'])): ?><div style="opacity:.7;font-size:12px;color:#fca5a5;">Overdue balance present</div><?php endif; ?></td>
            <td style="padding:10px 8px;"><?= (int)$client['open_invoice_count'] ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)($client['oldest_due_date'] ?: '—')) ?></td>
            <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$client['balance_due'], 2) ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 10px;font-size:18px;">Aging buckets</h2>
      <div style="display:grid;gap:10px;">
        <?php $buckets = [
          'Current' => $summary['current_bucket'],
          '1-30 days' => $summary['bucket_1_30'],
          '31-60 days' => $summary['bucket_31_60'],
          '61-90 days' => $summary['bucket_61_90'],
          '90+ days' => $summary['bucket_90_plus'],
        ]; foreach ($buckets as $label => $amount): ?>
          <div style="display:flex;justify-content:space-between;gap:12px;padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);"><span><?= accounting_h($label) ?></span><strong>$<?= number_format((float)$amount, 2) ?></strong></div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:12px;opacity:.75;font-size:13px;line-height:1.5;">Draft invoices do not appear here. This view only tracks issued or partially paid invoices with a remaining balance.</div>
    </div>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;"><div><h2 style="margin:0;font-size:18px;">Open invoice aging detail</h2><div style="opacity:.75;">Issued invoices with a balance due and a quick path into payment posting.</div></div><div style="display:flex;gap:10px;flex-wrap:wrap;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php" class="btn btn-secondary" style="text-decoration:none;">Receive payment</a><a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="text-decoration:none;">Payment register</a></div></div>
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Invoice</th><th style="padding:10px 8px;">Client</th><th style="padding:10px 8px;">Due</th><th style="padding:10px 8px;">Bucket</th><th style="padding:10px 8px;text-align:right;">Balance</th><th style="padding:10px 8px;text-align:right;">Action</th></tr></thead>
      <tbody>
      <?php if (!$aging): ?>
        <tr><td colspan="6" style="padding:18px 8px;opacity:.75;">No open invoices in A/R.</td></tr>
      <?php else: foreach ($aging as $row): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:10px 8px;"><?= accounting_invoice_link_html((int)$row['invoice_id'], (string)$row['invoice_number']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$row['invoice_date']) ?></div></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)($row['dba_name'] ?: $row['legal_name'])) ?></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)($row['due_date'] ?: '—')) ?><div style="opacity:.65;font-size:12px;"><?= (int)$row['days_past_due'] ?> days past due</div></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)$row['aging_bucket']) ?></td>
          <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$row['balance_due'], 2) ?></td>
          <td style="padding:10px 8px;text-align:right;white-space:nowrap;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php?invoice_id=<?= (int)$row['invoice_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;">Receive payment</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
