<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

$message = null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
$defaults = [
  'client_id' => '0',
  'contract_id' => '0',
  'item_id' => '0',
  'item_type' => 'SERVICE',
  'description' => '',
  'billing_type' => 'FIXED',
  'billing_cycle' => 'MONTHLY',
  'quantity' => '1.00',
  'unit_price' => '0.00',
  'term_months' => '0',
  'next_bill_date' => date('Y-m-d'),
  'start_date' => date('Y-m-d'),
  'end_date' => '',
  'auto_renew' => '1',
  'taxable' => '0',
  'notes' => '',
];
$form = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (($_POST['action'] ?? '') === 'generate_now') {
    $gen = accounting_generate_recurring_invoices(trim((string)($_POST['as_of_date'] ?? '')) ?: null, $userId);
    if ($gen['created']) $message = count($gen['created']) . ' draft invoice(s) generated from recurring items.';
    if (!empty($gen['skipped'])) {
        $message = trim(($message ? $message . ' ' : '') . count($gen['skipped']) . ' already existed and were skipped.');
    }
    if ($gen['errors']) $errors = array_merge($errors, $gen['errors']);
    if (!$gen['created'] && empty($gen['skipped']) && !$gen['errors']) $message = 'No recurring invoices were due for the selected date.';
  } else {
    $form = array_merge($form, $_POST);
    $result = accounting_create_recurring_item($_POST);
    if (!empty($result['ok'])) {
      $message = (string)$result['message'];
      $form = $defaults;
    } else {
      $errors = $result['errors'] ?? ['Unable to save recurring item.'];
    }
  }
}

$clients = accounting_list_clients();
$catalogItems = accounting_list_catalog_items(null, true, 'RECURRING');
$contractsByClient = [];
foreach ($clients as $client) {
  $contractsByClient[(int)$client['client_id']] = accounting_list_contracts_for_client((int)$client['client_id']);
}
$summary = accounting_recurring_summary();
$rows = accounting_list_recurring_items(200);
page_header('Recurring Billing', 'accounting');
accounting_subnav('recurring');
?>
<?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid-4" style="margin-bottom:16px;">
  <div class="card stat-card"><div class="label">Active recurring items</div><div class="value"><?= (int)$summary['active_count'] ?></div></div>
  <div class="card stat-card"><div class="label">Due today</div><div class="value"><?= (int)$summary['due_today_count'] ?></div></div>
  <div class="card stat-card"><div class="label">Monthly recurring value</div><div class="value">$<?= number_format((float)$summary['monthly_value'], 2) ?></div></div>
  <div class="card stat-card"><div class="label">Annual recurring value</div><div class="value">$<?= number_format((float)$summary['annual_value'], 2) ?></div></div>
</div>

<div class="grid-main-sidebar">
  <div class="stack-md">
    <div class="card" style="padding:16px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
        <div>
          <h2 class="section-title">Add recurring item</h2>
          <div class="section-subtitle">Use this for manual recurring items, or jump here after assigning a client service.</div>
        </div>
        <a href="<?= accounting_h(BASE_URL) ?>/clients/services.php" class="link-row"><span class="icon">↗</span><span>Assign from client</span></a>
      </div>

      <div class="value-preview" style="margin-bottom:12px;">
        <div class="kicker">Estimated recurring value</div>
        <div class="value" id="recurring-estimated-value">$0.00</div>
        <div class="section-subtitle" id="recurring-impact-copy">Pick a catalog item to preload pricing and billing defaults.</div>
      </div>

      <form method="post" class="portal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_recurring">
        <div><label>Client</label><select name="client_id" id="recurring-client"><option value="0">Select client</option><?php foreach ($clients as $client): ?><option value="<?= (int)$client['client_id'] ?>" <?= ((int)$form['client_id'] === (int)$client['client_id']) ? 'selected' : '' ?>><?= accounting_h((string)($client['dba_name'] ?: $client['legal_name'])) ?></option><?php endforeach; ?></select></div>
        <div class="split-2">
          <div><label>Contract</label><select name="contract_id" id="recurring-contract"><option value="0">No contract</option></select></div>
          <div><label>Catalog item</label><select name="item_id" id="recurring-item"><option value="0">Manual / no catalog item</option><?php foreach ($catalogItems as $item): ?><option value="<?= (int)$item['item_id'] ?>" data-type="<?= accounting_h((string)$item['item_type']) ?>" data-name="<?= accounting_h((string)$item['item_name']) ?>" data-price="<?= accounting_h((string)$item['default_unit_price']) ?>" data-cycle="<?= accounting_h((string)$item['default_billing_cycle']) ?>" data-term="<?= (int)($item['term_months'] ?? 0) ?>" <?= ((int)$form['item_id'] === (int)$item['item_id']) ? 'selected' : '' ?>><?= accounting_h((string)($item['item_code'] ?: '—')) ?> · <?= accounting_h((string)$item['item_name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="split-2">
          <div><label>Item type</label><select name="item_type" id="recurring-item-type"><?php foreach (accounting_get_catalog_item_types() as $type): ?><option value="<?= accounting_h($type) ?>" <?= ((string)$form['item_type'] === $type) ? 'selected' : '' ?>><?= accounting_h($type) ?></option><?php endforeach; ?></select></div>
          <div><label>Billing cycle</label><select name="billing_cycle" id="recurring-cycle"><?php foreach (accounting_get_recurring_cycles() as $cycle): ?><option value="<?= accounting_h($cycle) ?>" <?= ((string)$form['billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option><?php endforeach; ?></select></div>
        </div>
        <div><label>Description</label><input type="text" name="description" id="recurring-description" value="<?= accounting_h((string)$form['description']) ?>"><div class="form-hint">This becomes the recurring line item description on future invoice drafts.</div></div>
        <div class="split-3">
          <div><label>Quantity</label><input type="number" step="1" min="0.01" name="quantity" id="recurring-quantity" value="<?= accounting_h((string)$form['quantity']) ?>"></div>
          <div><label>Unit price</label><input type="number" step="1" min="0" name="unit_price" id="recurring-price" value="<?= accounting_h((string)$form['unit_price']) ?>"></div>
          <div><label>Term</label><select name="term_months" id="recurring-term"><?php foreach (accounting_get_term_options() as $months => $label): ?><option value="<?= (int)$months ?>" <?= ((int)$form['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="split-3">
          <div><label>Start date</label><input type="date" name="start_date" id="recurring-start-date" value="<?= accounting_h((string)$form['start_date']) ?>"></div>
          <div><label>Next bill date</label><input type="date" name="next_bill_date" id="recurring-next-bill-date" value="<?= accounting_h((string)$form['next_bill_date']) ?>"></div>
          <div><label>End date</label><input type="date" name="end_date" value="<?= accounting_h((string)$form['end_date']) ?>"></div>
        </div>
        <div class="checks">
          <label><input type="checkbox" name="auto_renew" value="1" <?= !empty($form['auto_renew']) ? 'checked' : '' ?>> Auto renew</label>
          <label><input type="checkbox" name="taxable" value="1" <?= !empty($form['taxable']) ? 'checked' : '' ?>> Taxable</label>
        </div>
        <div><label>Notes</label><textarea name="notes" rows="3"><?= accounting_h((string)$form['notes']) ?></textarea></div>
        <div><button type="submit" class="btn btn-secondary">Save recurring item</button></div>
      </form>
    </div>

    <div class="card" style="padding:16px;">
      <h2 class="section-title" style="margin-bottom:12px;">Generate draft invoices</h2>
      <form method="post" class="portal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate_now">
        <div><label>As of date</label><input type="date" name="as_of_date" value="<?= accounting_h((string)date('Y-m-d')) ?>"></div>
        <div class="soft-note">Recurring items due as of the selected date become draft invoices only. Nothing is sent automatically.</div>
        <div><button type="submit" class="btn btn-secondary">Generate drafts now</button></div>
      </form>
    </div>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div>
        <h2 class="section-title">Recurring register</h2>
        <div class="section-subtitle">Services and licenses that can create draft invoices on their next bill date.</div>
      </div>
      <a href="<?= accounting_h(BASE_URL) ?>/products/index.php" style="white-space:nowrap;">Manage catalog →</a>
    </div>
    <table class="table-shell">
      <thead><tr><th>Client / item</th><th>Cycle</th><th class="date">Next bill</th><th class="money">Value</th><th class="status">Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="empty-state">No recurring items yet. Assign a service to a client or add one manually to start generating draft invoices.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <?php $isActive = !empty($row['active']); ?>
        <tr>
          <td><strong><?= accounting_h((string)($row['dba_name'] ?: $row['legal_name'])) ?></strong><div class="muted-note"><?= accounting_h((string)($row['item_name'] ?: $row['description'])) ?></div></td>
          <td><?= accounting_h((string)$row['billing_cycle']) ?><div class="muted-note"><?= accounting_h((string)$row['item_type']) ?><?= !empty($row['term_months']) ? ' · ' . (int)$row['term_months'] . ' mo term' : '' ?></div></td>
          <td class="date"><?= accounting_h((string)$row['next_bill_date']) ?><div class="muted-note"><?= accounting_h((string)($row['last_billed_date'] ?: 'Never billed')) ?></div></td>
          <td class="money">$<?= number_format((float)$row['quantity'] * (float)$row['unit_price'], 2) ?><div class="muted-note"><?= number_format((float)$row['quantity'], 2) ?> × $<?= number_format((float)$row['unit_price'], 2) ?></div></td>
          <td class="status"><?= accounting_recurring_status_badge_html(!empty($row['active'])) ?><div class="muted-note"><?= !empty($row['auto_renew']) ? 'Auto renew' : 'Manual renew' ?></div></td>
          <td><a class="link-row" href="<?= accounting_h(BASE_URL) ?>/accounting/recurring_view.php?id=<?= (int)$row['recurring_service_id'] ?>"><span class="icon">↗</span><span>Open</span></a><?php if (!empty($row['contract_id'])): ?><div class="muted-note"><?= accounting_h((string)($row['contract_number'] ?: '')) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
const recurringContracts = <?= json_encode($contractsByClient, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const clientSelect = document.getElementById('recurring-client');
const contractSelect = document.getElementById('recurring-contract');
const itemSelect = document.getElementById('recurring-item');
const descInput = document.getElementById('recurring-description');
const priceInput = document.getElementById('recurring-price');
const qtyInput = document.getElementById('recurring-quantity');
const cycleSelect = document.getElementById('recurring-cycle');
const typeSelect = document.getElementById('recurring-item-type');
const termSelect = document.getElementById('recurring-term');
const startDateInput = document.getElementById('recurring-start-date');
const nextBillInput = document.getElementById('recurring-next-bill-date');
const estimateEl = document.getElementById('recurring-estimated-value');
const impactEl = document.getElementById('recurring-impact-copy');
const selectedContractId = <?= (int)$form['contract_id'] ?>;

function money(n) {
  const value = Number.parseFloat(n || '0');
  return `$${value.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
}
function cycleLabel(cycle) {
  return (cycle || 'MONTHLY').replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase());
}
function addMonthsToDate(dateStr, months) {
  const base = new Date(dateStr + 'T00:00:00');
  if (Number.isNaN(base.getTime())) return '';
  const day = base.getDate();
  base.setMonth(base.getMonth() + months);
  if (base.getDate() < day) base.setDate(0);
  return base.toISOString().slice(0, 10);
}
function suggestNextBill() {
  if (nextBillInput.value) return;
  const cycle = cycleSelect.value;
  const startDate = startDateInput.value;
  if (!startDate) return;
  const map = {MONTHLY: 1, QUARTERLY: 3, SEMI_ANNUAL: 6, ANNUAL: 12};
  if (map[cycle]) nextBillInput.value = addMonthsToDate(startDate, map[cycle]);
}
function updateRecurringPreview() {
  const total = (parseFloat(qtyInput.value || '0') || 0) * (parseFloat(priceInput.value || '0') || 0);
  estimateEl.textContent = money(total);
  impactEl.textContent = total > 0
    ? `This recurring item will generate draft invoices at ${cycleLabel(cycleSelect.value).toLowerCase()} value ${money(total)}.`
    : 'Pick a catalog item to preload pricing and billing defaults.';
}
function loadContracts() {
  const clientId = clientSelect.value;
  const rows = recurringContracts[clientId] || [];
  contractSelect.innerHTML = '<option value="0">No contract</option>';
  rows.forEach((row) => {
    const opt = document.createElement('option');
    opt.value = row.contract_id;
    opt.textContent = `${row.contract_number} · ${row.contract_name}`;
    if (parseInt(row.contract_id, 10) === selectedContractId) opt.selected = true;
    contractSelect.appendChild(opt);
  });
}
function applyItemDefaults() {
  const selected = itemSelect.options[itemSelect.selectedIndex];
  if (!selected || !selected.value || selected.value === '0') {
    updateRecurringPreview();
    return;
  }
  if (!descInput.value) descInput.value = selected.dataset.name || '';
  if (!(parseFloat(priceInput.value || '0') > 0)) priceInput.value = selected.dataset.price || '0.00';
  if (selected.dataset.cycle) cycleSelect.value = selected.dataset.cycle;
  if (selected.dataset.type) typeSelect.value = selected.dataset.type;
  if (selected.dataset.term) termSelect.value = selected.dataset.term;
  suggestNextBill();
  updateRecurringPreview();
}
[clientSelect, itemSelect, qtyInput, priceInput, cycleSelect, startDateInput, nextBillInput].forEach((el) => {
  el.addEventListener('change', () => {
    if (el === clientSelect) loadContracts();
    if (el === itemSelect) applyItemDefaults();
    if (el === startDateInput || el === cycleSelect) suggestNextBill();
    updateRecurringPreview();
  });
});
loadContracts();
applyItemDefaults();
updateRecurringPreview();
</script>
<?php page_footer(); ?>
