<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login(); accounting_require_ready(); csrf_check();

$message = !empty($_GET['updated']) ? 'Invoice updated.' : null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'generate_recurring')) {
    $gen = accounting_generate_recurring_invoices(trim((string)($_POST['as_of_date'] ?? '')) ?: null, $userId);
    if ($gen['created']) {
        $message = count($gen['created']) . ' draft recurring invoice(s) generated.';
    }
    if (!empty($gen['skipped'])) {
        $message = trim(($message ? $message . ' ' : '') . count($gen['skipped']) . ' already existed and were skipped.');
    }
    if ($gen['errors']) {
        $errors = array_merge($errors, $gen['errors']);
    }
    if (!$gen['created'] && empty($gen['skipped']) && !$gen['errors']) {
        $message = 'No invoices were due for the selected date.';
    }
}

$invoices = accounting_list_invoices(100);
$draftCount = 0; $overdueCount = 0; $openAr = 0.0; $currentBucket = 0.0;
$today = date('Y-m-d');
foreach ($invoices as $invoice) {
    $status = (string)$invoice['status'];
    $balance = (float)$invoice['balance_due'];
    if ($status === 'DRAFT') $draftCount++;
    if (in_array($status, ['ISSUED', 'PARTIALLY_PAID'], true) && $balance > 0) {
        $openAr += $balance;
        if (!empty($invoice['due_date']) && $invoice['due_date'] < $today) $overdueCount++;
        else $currentBucket += $balance;
    }
}
page_header('Invoices', 'accounting'); accounting_subnav('invoices');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div style="display:grid;grid-template-columns:1.75fr .95fr;gap:18px;align-items:start;">
  <div style="display:grid;gap:18px;">
    <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;">
      <?php foreach ([['Draft invoices',$draftCount],['Overdue invoices',$overdueCount],['Open A/R','$'.number_format($openAr,2)],['Current bucket','$'.number_format($currentBucket,2)]] as $stat): ?>
        <div class="card" style="padding:16px;min-height:86px;display:flex;flex-direction:column;justify-content:space-between;">
          <div style="font-size:13px;opacity:.78;min-height:34px;"><?= accounting_h((string)$stat[0]) ?></div>
          <div style="font-size:22px;font-weight:700;"><?= accounting_h((string)$stat[1]) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card" style="padding:16px;overflow:auto;">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0;font-size:18px;">Invoice register</h2>
          <div style="opacity:.75;">Portal-owned invoices created manually or from recurring billing.</div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_new.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;white-space:nowrap;">Create one-off / project invoice</a>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;white-space:nowrap;">Receive customer payment</a>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/receivables.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;white-space:nowrap;">Review A/R aging</a>
        </div>
      </div>
      <table style="width:100%;border-collapse:collapse;">
        <thead>
          <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
            <th style="padding:10px 8px;">Invoice #</th>
            <th style="padding:10px 8px;">Client</th>
            <th class="status-cell" style="padding:10px 8px;">Status</th>
            <th style="padding:10px 8px;">Accounts</th>
            <th style="padding:10px 8px;text-align:right;">Balance</th>
            <th style="padding:10px 8px;text-align:right;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invoices): ?>
            <tr><td colspan="6" style="padding:18px 8px;opacity:.75;">No invoices created yet. Use <strong>Create one-off / project invoice</strong> to create your first invoice.</td></tr>
          <?php else: foreach ($invoices as $invoice): ?>
            <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
              <td style="padding:10px 8px;"><?= accounting_invoice_link_html((int)$invoice['invoice_id'], (string)$invoice['invoice_number']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$invoice['invoice_date']) ?></div></td>
              <td style="padding:10px 8px;"><?= accounting_h((string)($invoice['dba_name'] ?: $invoice['legal_name'])) ?></td>
              <td class="status-cell" style="padding:10px 8px;"><?= accounting_invoice_status_badge_html($invoice) ?><div style="opacity:.65;font-size:12px;margin-top:4px;"><?= !empty($invoice['has_journal']) ? 'Posted to GL' : 'Draft only' ?></div></td>
              <td style="padding:10px 8px;"><?= accounting_h((string)$invoice['ar_account_code']) ?> / <?= accounting_h((string)$invoice['revenue_account_code']) ?></td>
              <td style="padding:10px 8px;text-align:right;">$<?= number_format((float)$invoice['balance_due'],2) ?></td>
              <td style="padding:10px 8px;text-align:right;white-space:nowrap;">
                <?php if (accounting_can_edit_invoice($invoice)): ?>
                  <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_edit.php?id=<?= (int)$invoice['invoice_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;">Edit draft</a><span style="opacity:.45;"> · </span>
                <?php endif; ?>
                <?php if (accounting_invoice_can_receive_payment($invoice)): ?>
                  <a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php?invoice_id=<?= (int)$invoice['invoice_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;">Receive payment</a><span style="opacity:.45;"> · </span>
                <?php endif; ?>
                <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$invoice['invoice_id'] ?>" style="color:#9bd0ff;text-decoration:none;font-weight:600;"><?php if ((string)$invoice['status'] === 'DRAFT'): ?>Review / issue<?php else: ?>Open<?php endif; ?></a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:grid;gap:16px;">
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Actions</h2>
      <div style="display:grid;gap:10px;">
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/receive_payment.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Receive customer payment</a>
        <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Open payment register</a>
        <a href="<?= accounting_h(BASE_URL) ?>/products/index.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Manage products &amp; services</a>
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/recurring.php" class="btn btn-secondary" style="text-decoration:none;text-align:left;">Open recurring billing</a>
      </div>
    </div>
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:18px;">Generate recurring drafts</h2>
      <form method="post" style="display:grid;gap:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_recurring">
        <div><label>As of date</label><br><input type="date" name="as_of_date" value="<?= accounting_h((string)date('Y-m-d')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
        <div style="opacity:.72;font-size:13px;line-height:1.45;">This creates draft invoices only. Nothing is sent to clients automatically. Running it again for the same date skips duplicates.</div>
        <div><button type="submit" class="btn btn-secondary" style="width:auto;">Generate draft invoices</button></div>
      </form>
    </div>
  </div>
</div>
<?php page_footer(); ?>
