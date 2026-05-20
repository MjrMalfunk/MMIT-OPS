<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$recurringId = (int)($_GET['id'] ?? 0);
$item = $recurringId > 0 ? accounting_get_recurring_item($recurringId) : null;
if (!$item) {
    http_response_code(404);
    page_header('Recurring Item Not Found', 'accounting');
    echo '<div class="card" style="padding:16px;">Recurring item not found.</div>';
    page_footer();
    exit;
}
$message = null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'save') {
        $result = accounting_update_recurring_item($recurringId, $_POST, $userId);
    } elseif ($action === 'pause') {
        $result = accounting_change_recurring_status($recurringId, false, $userId, trim((string)($_POST['reason'] ?? 'Paused from recurring detail')));
    } else {
        $result = accounting_change_recurring_status($recurringId, true, $userId, trim((string)($_POST['reason'] ?? 'Reactivated from recurring detail')));
    }
    if (!empty($result['ok'])) {
        $message = (string)$result['message'];
        $item = accounting_get_recurring_item($recurringId);
    } else {
        $errors = $result['errors'] ?? ['Unable to update recurring item.'];
    }
}
$sourceInvoices = accounting_list_source_invoices('RECURRING', (string)$recurringId);
page_header('Recurring Item', 'accounting');
accounting_subnav('recurring');
?>
<div class="stack-md">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
    <div>
      <div class="kicker">Recurring billing item</div>
      <h1 class="section-title" style="font-size:24px;"><?= accounting_h((string)($item['item_name'] ?: $item['description'])) ?></h1>
      <div class="section-subtitle"><?= accounting_h((string)($item['dba_name'] ?: $item['legal_name'])) ?> · <?= accounting_recurring_status_badge_html(!empty($item['active'])) ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php if (!empty($item['client_service_id'])): ?><a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/clients/service_view.php?id=<?= (int)$item['client_service_id'] ?>">Open client service</a><?php endif; ?>
      <a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/accounting/recurring.php">Back to recurring</a>
    </div>
  </div>
  <?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

  <div class="grid-4">
    <div class="card stat-card"><div class="label">Recurring value</div><div class="value">$<?= number_format((float)$item['quantity'] * (float)$item['unit_price'], 2) ?></div></div>
    <div class="card stat-card"><div class="label">Next bill date</div><div class="value" style="font-size:20px;"><?= accounting_h((string)$item['next_bill_date']) ?></div></div>
    <div class="card stat-card"><div class="label">Last billed</div><div class="value" style="font-size:20px;"><?= accounting_h((string)($item['last_billed_date'] ?: 'Never')) ?></div></div>
    <div class="card stat-card"><div class="label">Invoices generated</div><div class="value"><?= count($sourceInvoices) ?></div></div>
  </div>

  <div class="grid-main-sidebar">
    <div class="card" style="padding:16px;">
      <h2 class="section-title">Edit recurring item</h2>
      <div class="section-subtitle">Use this to change future draft generation without touching past invoice history.</div>
      <form method="post" class="portal-form" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div><label>Description</label><input type="text" name="description" value="<?= accounting_h((string)$item['description']) ?>"></div>
        <div class="split-3">
          <div><label>Quantity</label><input type="number" step="0.01" min="0.01" name="quantity" value="<?= accounting_h((string)$item['quantity']) ?>"></div>
          <div><label>Unit price</label><input type="number" step="0.01" min="0" name="unit_price" value="<?= accounting_h((string)$item['unit_price']) ?>"></div>
          <div><label>Billing cycle</label><select name="billing_cycle"><?php foreach (accounting_get_recurring_cycles() as $cycle): ?><option value="<?= accounting_h($cycle) ?>" <?= ((string)$item['billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="split-3">
          <div><label>Start date</label><input type="date" name="start_date" value="<?= accounting_h((string)$item['start_date']) ?>"></div>
          <div><label>Next bill date</label><input type="date" name="next_bill_date" value="<?= accounting_h((string)$item['next_bill_date']) ?>"></div>
          <div><label>End date</label><input type="date" name="end_date" value="<?= accounting_h((string)$item['end_date']) ?>"></div>
        </div>
        <div class="split-2">
          <div><label>Term</label><select name="term_months"><?php foreach (accounting_get_term_options() as $months => $label): ?><option value="<?= (int)$months ?>" <?= ((int)$item['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option><?php endforeach; ?></select></div>
          <div class="checks" style="padding-top:24px;">
            <label><input type="checkbox" name="auto_renew" value="1" <?= !empty($item['auto_renew']) ? 'checked' : '' ?>> Auto renew</label>
            <label><input type="checkbox" name="taxable" value="1" <?= !empty($item['taxable']) ? 'checked' : '' ?>> Taxable</label>
          </div>
        </div>
        <div><label>Notes</label><textarea name="notes" rows="4"><?= accounting_h((string)$item['notes']) ?></textarea></div>
        <div><button class="btn btn-secondary" type="submit">Save changes</button></div>
      </form>
    </div>

    <div class="stack-md">
      <div class="card" style="padding:16px;">
        <h2 class="section-title">Lifecycle controls</h2>
        <form method="post" class="portal-form" style="margin-top:12px;">
          <?= csrf_field() ?>
          <div><label>Reason / note</label><textarea name="reason" rows="3" placeholder="Optional note for this status change."></textarea></div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if (!empty($item['active'])): ?><button class="btn btn-secondary" type="submit" name="action" value="pause">Pause recurring</button><?php endif; ?>
            <?php if (empty($item['active'])): ?><button class="btn btn-secondary" type="submit" name="action" value="resume">Resume recurring</button><?php endif; ?>
          </div>
        </form>
      </div>
      <div class="card" style="padding:16px;overflow:auto;">
        <h2 class="section-title">Generated invoices</h2>
        <table class="table-shell" style="margin-top:10px;">
          <thead><tr><th>Invoice</th><th class="date">Date</th><th class="status">Status</th><th class="money">Amount</th></tr></thead>
          <tbody>
          <?php if (!$sourceInvoices): ?><tr><td colspan="4" class="empty-state">No invoices have been generated yet.</td></tr><?php else: foreach ($sourceInvoices as $invoice): ?><tr>
            <td><?= accounting_invoice_link_html((int)$invoice['invoice_id'], (string)$invoice['invoice_number']) ?></td>
            <td class="date"><?= accounting_h((string)$invoice['invoice_date']) ?></td>
            <td class="status"><?= accounting_invoice_status_badge_html($invoice) ?></td>
            <td class="money">$<?= number_format((float)$invoice['total_amount'], 2) ?></td>
          </tr><?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>
