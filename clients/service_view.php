<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$serviceId = (int)($_GET['id'] ?? 0);
$service = $serviceId > 0 ? accounting_get_client_service($serviceId) : null;
if (!$service) {
    http_response_code(404);
    page_header('Client Service Not Found', 'clients');
    echo '<div class="card" style="padding:16px;">Client service not found.</div>';
    page_footer();
    exit;
}
$message = null;
$errors = [];
$prorationPreview = null;
$userId = (int)(current_user()['user_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'save') {
        $result = accounting_update_client_service($serviceId, $_POST, $userId);
    } elseif ($action === 'preview_proration') {
        $changes = [
            'description' => (string)($_POST['description'] ?? $service['description']),
            'quantity' => (float)($_POST['quantity'] ?? $service['quantity']),
            'unit_price' => (float)($_POST['unit_price'] ?? $service['unit_price']),
        ];
        $prorationPreview = accounting_service_proration_preview($service, $changes, (string)($_POST['effective_date'] ?? date('Y-m-d')));
        $result = ['ok' => true, 'message' => 'Proration preview refreshed.'];
    } elseif ($action === 'save_and_prorate') {
        $changes = [
            'description' => (string)($_POST['description'] ?? $service['description']),
            'quantity' => (float)($_POST['quantity'] ?? $service['quantity']),
            'unit_price' => (float)($_POST['unit_price'] ?? $service['unit_price']),
        ];
        $prorationPreview = accounting_service_proration_preview($service, $changes, (string)($_POST['effective_date'] ?? date('Y-m-d')));
        $result = accounting_update_client_service($serviceId, $_POST, $userId);
        if (!empty($result['ok'])) {
            $prorationResult = accounting_create_service_proration_invoice($serviceId, $changes, (string)($_POST['effective_date'] ?? date('Y-m-d')), $userId);
            if (!empty($prorationResult['ok'])) {
                $result['message'] = (string)$result['message'] . ' Draft proration invoice #' . (int)$prorationResult['invoice_id'] . ' created.';
            } else {
                $result['message'] = (string)$result['message'] . ' ' . implode(' ', array_map('strval', (array)($prorationResult['errors'] ?? ['Proration draft was not created.'])));
            }
        }
    } elseif ($action === 'pause') {
        $result = accounting_change_client_service_status($serviceId, 'PAUSED', $userId, trim((string)($_POST['reason'] ?? 'Paused from client service page')));
    } elseif ($action === 'resume') {
        $result = accounting_change_client_service_status($serviceId, 'ACTIVE', $userId, trim((string)($_POST['reason'] ?? 'Resumed from client service page')));
    } else {
        $result = accounting_change_client_service_status($serviceId, 'ENDED', $userId, trim((string)($_POST['reason'] ?? 'Ended from client service page')));
    }
    if (!empty($result['ok'])) {
        $message = (string)$result['message'];
        $service = accounting_get_client_service($serviceId);
    } else {
        $errors = $result['errors'] ?? ['Unable to update client service.'];
    }
}
$revenueAccounts = accounting_list_accounts('INCOME', true);
$sourceInvoices = !empty($service['recurring_service_id']) ? accounting_list_source_invoices('RECURRING', (string)(int)$service['recurring_service_id']) : [];
page_header('Client Service', 'clients');
?>
<div class="stack-md">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;">
    <div>
      <div class="kicker">Client service</div>
      <h1 class="section-title" style="font-size:24px;"><?= accounting_h((string)($service['item_name'] ?: $service['description'])) ?></h1>
      <div class="section-subtitle"><?= accounting_h((string)($service['dba_name'] ?: $service['legal_name'])) ?> · <?= accounting_client_service_status_badge_html((string)$service['status']) ?></div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/clients/view.php?client_id=<?= (int)$service['client_id'] ?>">Open client</a>
      <?php if (!empty($service['recurring_service_id'])): ?><a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/accounting/recurring_view.php?id=<?= (int)$service['recurring_service_id'] ?>">Open recurring item</a><?php endif; ?>
      <a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/clients/services.php?client_id=<?= (int)$service['client_id'] ?>">Back to services</a>
    </div>
  </div>

  <?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

  <div class="grid-4">
    <div class="card stat-card"><div class="label">Recurring value</div><div class="value">$<?= number_format((float)$service['quantity'] * (float)$service['unit_price'], 2) ?></div></div>
    <div class="card stat-card"><div class="label">Next bill date</div><div class="value" style="font-size:20px;"><?= accounting_h((string)$service['next_bill_date']) ?></div></div>
    <div class="card stat-card"><div class="label">Pricing model</div><div class="value" style="font-size:20px;"><?= accounting_h((string)$service['pricing_model']) ?></div></div>
    <div class="card stat-card"><div class="label">Source</div><div class="value" style="font-size:20px;"><?= !empty($service['recurring_service_id']) ? 'Recurring linked' : 'Manual only' ?></div></div>
  </div>

  <div class="grid-main-sidebar">
    <div class="card" style="padding:16px;">
      <h2 class="section-title">Edit client service</h2>
      <div class="section-subtitle">Updates here flow through to the linked recurring billing item automatically.</div>
      <form method="post" class="portal-form" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <div><label>Description</label><input type="text" name="description" value="<?= accounting_h((string)$service['description']) ?>"></div>
        <div class="split-3">
          <div><label>Quantity</label><input type="number" step="0.01" min="0.01" name="quantity" value="<?= accounting_h((string)$service['quantity']) ?>"></div>
          <div><label>Unit price</label><input type="number" step="0.01" min="0" name="unit_price" value="<?= accounting_h((string)$service['unit_price']) ?>"></div>
          <div><label>Billing cycle</label><select name="billing_cycle"><?php foreach (accounting_get_recurring_cycles() as $cycle): ?><option value="<?= accounting_h($cycle) ?>" <?= ((string)$service['billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="split-3">
          <div><label>Start date</label><input type="date" name="start_date" value="<?= accounting_h((string)$service['start_date']) ?>"></div>
          <div><label>Next bill date</label><input type="date" name="next_bill_date" value="<?= accounting_h((string)$service['next_bill_date']) ?>"></div>
          <div><label>End date</label><input type="date" name="end_date" value="<?= accounting_h((string)$service['end_date']) ?>"></div>
        </div>
        <div class="split-2">
          <div><label>Term</label><select name="term_months"><?php foreach (accounting_get_term_options() as $months => $label): ?><option value="<?= (int)$months ?>" <?= ((int)$service['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option><?php endforeach; ?></select></div>
          <div><label>Revenue account</label><select name="revenue_account_id"><?php foreach ($revenueAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$service['revenue_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="checks">
          <label><input type="checkbox" name="auto_renew" value="1" <?= !empty($service['auto_renew']) ? 'checked' : '' ?>> Auto renew</label>
          <label><input type="checkbox" name="taxable" value="1" <?= !empty($service['taxable']) ? 'checked' : '' ?>> Taxable</label>
        </div>
        <div><label>Notes</label><textarea name="notes" rows="4"><?= accounting_h((string)$service['notes']) ?></textarea></div>
        <div><button class="btn btn-secondary" type="submit">Save changes</button></div>
      </form>
    </div>

    <div class="card" style="padding:16px;">
      <h2 class="section-title">Mid-cycle change + proration</h2>
      <div class="section-subtitle">Use this when seats or pricing change before the next bill date. It updates the service and can create a draft proration invoice for the increase.</div>
      <form method="post" class="portal-form" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="description" value="<?= accounting_h((string)$service['description']) ?>">
        <input type="hidden" name="billing_cycle" value="<?= accounting_h((string)$service['billing_cycle']) ?>">
        <input type="hidden" name="start_date" value="<?= accounting_h((string)$service['start_date']) ?>">
        <input type="hidden" name="next_bill_date" value="<?= accounting_h((string)$service['next_bill_date']) ?>">
        <input type="hidden" name="end_date" value="<?= accounting_h((string)$service['end_date']) ?>">
        <input type="hidden" name="term_months" value="<?= accounting_h((string)$service['term_months']) ?>">
        <input type="hidden" name="revenue_account_id" value="<?= (int)$service['revenue_account_id'] ?>">
        <input type="hidden" name="auto_renew" value="<?= !empty($service['auto_renew']) ? '1' : '0' ?>">
        <input type="hidden" name="taxable" value="<?= !empty($service['taxable']) ? '1' : '0' ?>">
        <input type="hidden" name="notes" value="<?= accounting_h((string)$service['notes']) ?>">
        <div class="split-3">
          <div><label>Effective date</label><input type="date" name="effective_date" value="<?= accounting_h((string)($_POST['effective_date'] ?? date('Y-m-d'))) ?>"></div>
          <div><label>New quantity</label><input type="number" step="0.01" min="0.01" name="quantity" value="<?= accounting_h((string)($_POST['quantity'] ?? $service['quantity'])) ?>"></div>
          <div><label>New unit price</label><input type="number" step="0.01" min="0" name="unit_price" value="<?= accounting_h((string)($_POST['unit_price'] ?? $service['unit_price'])) ?>"></div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <button class="btn btn-secondary" type="submit" name="action" value="preview_proration">Preview proration</button>
          <button class="btn btn-secondary" type="submit" name="action" value="save_and_prorate">Save change + create draft proration</button>
        </div>
      </form>
      <?php if ($prorationPreview): ?>
        <div style="margin-top:14px;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);display:grid;gap:8px;">
          <div style="font-weight:700;">Proration preview</div>
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
            <div><div style="font-size:12px;opacity:.72;">Cycle window</div><div><?= accounting_h((string)$prorationPreview['period_start']) ?> → <?= accounting_h((string)$prorationPreview['period_end']) ?></div></div>
            <div><div style="font-size:12px;opacity:.72;">Effective date</div><div><?= accounting_h((string)$prorationPreview['effective_date']) ?></div></div>
            <div><div style="font-size:12px;opacity:.72;">Old recurring amount</div><div>$<?= number_format((float)$prorationPreview['old_amount'], 2) ?></div></div>
            <div><div style="font-size:12px;opacity:.72;">New recurring amount</div><div>$<?= number_format((float)$prorationPreview['new_amount'], 2) ?></div></div>
            <div><div style="font-size:12px;opacity:.72;">Days remaining</div><div><?= (int)$prorationPreview['remaining_days'] ?> of <?= (int)$prorationPreview['period_days'] ?></div></div>
            <div><div style="font-size:12px;opacity:.72;">Draft proration amount</div><div style="font-weight:800;"><?= ((float)$prorationPreview['proration_amount'] > 0 ? '$' : '') . number_format((float)$prorationPreview['proration_amount'], 2) ?></div></div>
          </div>
          <?php if ((float)$prorationPreview['proration_amount'] <= 0): ?><div style="font-size:12px;color:#fde68a;line-height:1.45;">This preview is zero or negative. The current helper only creates draft proration invoices for increases. Decreases can still be handled as a manual credit or next-cycle adjustment.</div><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="stack-md">
      <div class="card" style="padding:16px;">
        <h2 class="section-title">Lifecycle controls</h2>
        <div class="section-subtitle">Pause stops draft generation. End preserves history and turns billing off.</div>
        <form method="post" class="portal-form" style="margin-top:12px;">
          <?= csrf_field() ?>
          <div><label>Reason / note</label><textarea name="reason" rows="3" placeholder="Optional note for this status change."></textarea></div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php if ((string)$service['status'] !== 'PAUSED'): ?><button class="btn btn-secondary" type="submit" name="action" value="pause">Pause service</button><?php endif; ?>
            <?php if ((string)$service['status'] !== 'ACTIVE'): ?><button class="btn btn-secondary" type="submit" name="action" value="resume">Resume service</button><?php endif; ?>
            <?php if ((string)$service['status'] !== 'ENDED'): ?><button class="btn btn-danger" type="submit" name="action" value="end">End service</button><?php endif; ?>
          </div>
        </form>
      </div>

      <div class="card" style="padding:16px;overflow:auto;">
        <h2 class="section-title">Generated invoices</h2>
        <div class="section-subtitle">Drafts and invoices created from the linked recurring item.</div>
        <table class="table-shell" style="margin-top:10px;">
          <thead><tr><th>Invoice</th><th class="date">Date</th><th class="status">Status</th><th class="money">Amount</th></tr></thead>
          <tbody>
            <?php if (!$sourceInvoices): ?><tr><td colspan="4" class="empty-state">No invoices have been generated from this service yet.</td></tr><?php else: foreach ($sourceInvoices as $invoice): ?><tr>
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
