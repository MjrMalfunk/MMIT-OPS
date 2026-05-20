<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();
csrf_check();

if (!accounting_client_service_ready()) {
    page_header('Client Services', 'clients');
    echo '<div class="card" style="padding:16px;">Run the client service SQL migration first.</div>';
    page_footer();
    exit;
}

$message = null;
$errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
$clientFilter = (int)($_GET['client_id'] ?? 0);
$defaults = [
    'client_id' => $clientFilter > 0 ? (string)$clientFilter : '0',
    'contract_id' => '0',
    'item_id' => '0',
    'pricing_model' => 'FIXED',
    'description' => '',
    'quantity' => '1.00',
    'unit_price' => '0.00',
    'billing_cycle' => 'MONTHLY',
    'term_months' => '0',
    'start_date' => date('Y-m-d'),
    'next_bill_date' => date('Y-m-d'),
    'end_date' => '',
    'revenue_account_id' => '0',
    'status' => 'ACTIVE',
    'auto_renew' => '1',
    'taxable' => '0',
    'notes' => '',
];
$form = $defaults;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, $_POST);
    $result = accounting_create_client_service_assignment($_POST, $userId);
    if (!empty($result['ok'])) {
        $message = (string)$result['message'];
        $clientFilter = (int)($_POST['client_id'] ?? 0);
        $defaults['client_id'] = $clientFilter > 0 ? (string)$clientFilter : '0';
        $form = $defaults;
    } else {
        $errors = $result['errors'] ?? ['Unable to save client service assignment.'];
    }
}

$summary = accounting_client_service_summary();
$clients = accounting_list_clients();
$catalogItems = accounting_list_catalog_items(null, true);
$contractsByClient = [];
foreach ($clients as $client) {
    $contractsByClient[(int)$client['client_id']] = accounting_list_contracts_for_client((int)$client['client_id']);
}
$revenueAccounts = accounting_list_accounts('INCOME', true);
$rows = accounting_list_client_services(250, $clientFilter > 0 ? $clientFilter : null);
page_header('Client Services', 'clients');
?>
<div class="inline-pills"><div>
  <a href="<?= accounting_h(BASE_URL) ?>/clients/index.php" class="inline-pill">Clients</a>
  <a href="<?= accounting_h(BASE_URL) ?>/clients/services.php" class="inline-pill is-active">Client Services</a>
  <a href="<?= accounting_h(BASE_URL) ?>/products/index.php" class="inline-pill">Products &amp; Services</a>
  <a href="<?= accounting_h(BASE_URL) ?>/accounting/recurring.php" class="inline-pill">Recurring Billing</a>
</div></div>

<?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="grid-4" style="margin-bottom:16px;">
  <div class="card stat-card"><div class="label">Active client services</div><div class="value"><?= (int)$summary['active_count'] ?></div></div>
  <div class="card stat-card"><div class="label">Due today</div><div class="value"><?= (int)$summary['due_today_count'] ?></div></div>
  <div class="card stat-card"><div class="label">Estimated MRR</div><div class="value">$<?= number_format((float)$summary['mrr_value'], 2) ?></div></div>
  <div class="card stat-card"><div class="label">Estimated ARR</div><div class="value">$<?= number_format((float)$summary['arr_value'], 2) ?></div></div>
</div>

<div class="grid-main-sidebar">
  <div class="card" style="padding:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
      <div>
        <h2 class="section-title">Assign service to client</h2>
        <div class="section-subtitle">Assign once, then let recurring billing generate invoice drafts automatically.</div>
      </div>
      <?php if ($clientFilter > 0): ?>
        <a href="<?= accounting_h(BASE_URL) ?>/clients/view.php?id=<?= $clientFilter ?>" class="link-row"><span class="icon">←</span><span>Back to client</span></a>
      <?php endif; ?>
    </div>

    <div class="value-preview" style="margin-bottom:12px;">
      <div class="kicker">Estimated recurring value</div>
      <div class="value" id="svc-estimated-value">$0.00</div>
      <div class="section-subtitle" id="svc-impact-copy">Select a catalog item to preload pricing and billing details.</div>
    </div>

    <div class="helper-grid" style="margin-bottom:14px;">
      <div class="helper-chip"><div class="label">Workflow</div><div class="value">Assign → Recurring → Draft Invoice</div></div>
      <div class="helper-chip"><div class="label">Billing impact</div><div class="value" id="svc-cycle-preview">Monthly</div></div>
      <div class="helper-chip"><div class="label">Next bill preview</div><div class="value" id="svc-next-preview"><?= accounting_h((string)$form['next_bill_date']) ?></div></div>
    </div>

    <form method="post" class="portal-form">
      <?= csrf_field() ?>
      <div><label>Client</label><select name="client_id" id="svc-client"><option value="0">Select client</option><?php foreach ($clients as $client): ?><option value="<?= (int)$client['client_id'] ?>" <?= ((int)$form['client_id'] === (int)$client['client_id']) ? 'selected' : '' ?>><?= accounting_h((string)($client['dba_name'] ?: $client['legal_name'])) ?></option><?php endforeach; ?></select></div>
      <div class="split-2">
        <div><label>Contract</label><select name="contract_id" id="svc-contract"><option value="0">No contract</option></select></div>
        <div><label>Catalog item</label><select name="item_id" id="svc-item"><option value="0">Select product/service</option><?php foreach ($catalogItems as $item): ?><option value="<?= (int)$item['item_id'] ?>" data-name="<?= accounting_h((string)$item['item_name']) ?>" data-price="<?= accounting_h((string)$item['default_unit_price']) ?>" data-cycle="<?= accounting_h((string)$item['default_billing_cycle']) ?>" data-term="<?= (int)($item['term_months'] ?? 0) ?>" data-type="<?= accounting_h((string)$item['item_type']) ?>" data-revenue="<?= (int)($item['revenue_account_id'] ?? 0) ?>" data-taxable="<?= !empty($item['is_taxable']) ? '1' : '0' ?>" <?= ((int)$form['item_id'] === (int)$item['item_id']) ? 'selected' : '' ?>><?= accounting_h((string)($item['item_code'] ?: '—')) ?> · <?= accounting_h((string)$item['item_name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label>Description</label><input type="text" name="description" id="svc-description" value="<?= accounting_h((string)$form['description']) ?>"><div class="form-hint">Good descriptions flow through to recurring billing and invoice lines.</div></div>
      <div class="split-2">
        <div><label>Pricing model</label><select name="pricing_model" id="svc-pricing-model"><?php foreach (accounting_get_pricing_models() as $model): ?><option value="<?= accounting_h($model) ?>" <?= ((string)$form['pricing_model'] === $model) ? 'selected' : '' ?>><?= accounting_h($model) ?></option><?php endforeach; ?></select></div>
        <div><label>Billing cycle</label><select name="billing_cycle" id="svc-cycle"><?php foreach (accounting_get_recurring_cycles() as $cycle): ?><option value="<?= accounting_h($cycle) ?>" <?= ((string)$form['billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="split-3">
        <div><label>Quantity</label><input type="number" step="0.01" min="0.01" name="quantity" id="svc-quantity" value="<?= accounting_h((string)$form['quantity']) ?>"></div>
        <div><label>Unit price</label><input type="number" step="0.01" min="0" name="unit_price" id="svc-price" value="<?= accounting_h((string)$form['unit_price']) ?>"></div>
        <div><label>Term</label><select name="term_months" id="svc-term"><?php foreach (accounting_get_term_options() as $months => $label): ?><option value="<?= (int)$months ?>" <?= ((int)$form['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="split-2">
        <div><label>Start date</label><input type="date" name="start_date" id="svc-start-date" value="<?= accounting_h((string)$form['start_date']) ?>"></div>
        <div><label>Next bill date</label><input type="date" name="next_bill_date" id="svc-next-bill-date" value="<?= accounting_h((string)$form['next_bill_date']) ?>"></div>
      </div>
      <div class="split-2">
        <div><label>End date</label><input type="date" name="end_date" value="<?= accounting_h((string)$form['end_date']) ?>"></div>
        <div><label>Revenue account</label><select name="revenue_account_id" id="svc-revenue"><option value="0">Select income account</option><?php foreach ($revenueAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$form['revenue_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="checks">
        <label><input type="checkbox" name="auto_renew" value="1" <?= !empty($form['auto_renew']) ? 'checked' : '' ?>> Auto renew</label>
        <label><input type="checkbox" name="taxable" id="svc-taxable" value="1" <?= !empty($form['taxable']) ? 'checked' : '' ?>> Taxable</label>
      </div>
      <div><label>Notes</label><textarea name="notes" rows="3"><?= accounting_h((string)$form['notes']) ?></textarea><div class="form-hint">Use notes for contract-specific billing details or exceptions.</div></div>
      <div><button type="submit" class="btn btn-secondary">Save client service</button></div>
    </form>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div>
        <h2 class="section-title">Assigned services register</h2>
        <div class="section-subtitle">Each assignment creates the recurring billing record the draft invoice engine uses.</div>
      </div>
      <a href="<?= accounting_h(BASE_URL) ?>/accounting/recurring.php" style="white-space:nowrap;">Open recurring register →</a>
    </div>
    <table class="table-shell">
      <thead>
        <tr>
          <th>Client / item</th>
          <th>Model</th>
          <th class="date">Next bill</th>
          <th class="money">Value</th>
          <th class="status">Status</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="empty-state">No assigned services yet. Add one on the left to create recurring billing for this client.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <?php $isActive = strtoupper((string)($row['status'] ?? 'ACTIVE')) === 'ACTIVE'; ?>
        <tr>
          <td><strong><?= accounting_h((string)($row['dba_name'] ?: $row['legal_name'])) ?></strong><div class="muted-note"><?= accounting_h((string)($row['item_name'] ?: $row['description'])) ?></div></td>
          <td><?= accounting_h((string)$row['pricing_model']) ?><div class="muted-note"><?= accounting_h((string)$row['billing_cycle']) ?><?= !empty($row['term_months']) ? ' · ' . (int)$row['term_months'] . ' mo term' : '' ?></div></td>
          <td class="date"><?= accounting_h((string)$row['next_bill_date']) ?><div class="muted-note"><?= accounting_h((string)($row['recurring_last_billed_date'] ?: 'Never billed')) ?></div></td>
          <td class="money">$<?= number_format((float)$row['quantity'] * (float)$row['unit_price'], 2) ?><div class="muted-note"><?= number_format((float)$row['quantity'], 2) ?> × $<?= number_format((float)$row['unit_price'], 2) ?></div></td>
          <td class="status"><span class="badge <?= $isActive ? 'badge-active' : 'badge-inactive' ?>"><?= accounting_h((string)$row['status']) ?></span><div class="muted-note"><?= !empty($row['auto_renew']) ? 'Auto renew' : 'Manual renew' ?></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
const svcContracts = <?= json_encode($contractsByClient, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const svcClient = document.getElementById('svc-client');
const svcContract = document.getElementById('svc-contract');
const svcItem = document.getElementById('svc-item');
const svcDesc = document.getElementById('svc-description');
const svcPrice = document.getElementById('svc-price');
const svcQty = document.getElementById('svc-quantity');
const svcCycle = document.getElementById('svc-cycle');
const svcTerm = document.getElementById('svc-term');
const svcRevenue = document.getElementById('svc-revenue');
const svcTaxable = document.getElementById('svc-taxable');
const svcNextBill = document.getElementById('svc-next-bill-date');
const svcStartDate = document.getElementById('svc-start-date');
const svcEstimatedValue = document.getElementById('svc-estimated-value');
const svcImpactCopy = document.getElementById('svc-impact-copy');
const svcCyclePreview = document.getElementById('svc-cycle-preview');
const svcNextPreview = document.getElementById('svc-next-preview');
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
  if (svcNextBill.value) return;
  const cycle = svcCycle.value;
  const startDate = svcStartDate.value;
  if (!startDate) return;
  const map = {MONTHLY: 1, QUARTERLY: 3, SEMIANNUAL: 6, ANNUAL: 12};
  if (map[cycle]) {
    svcNextBill.value = addMonthsToDate(startDate, map[cycle]);
  }
}
function updatePreview() {
  const total = (parseFloat(svcQty.value || '0') || 0) * (parseFloat(svcPrice.value || '0') || 0);
  svcEstimatedValue.textContent = money(total);
  svcCyclePreview.textContent = cycleLabel(svcCycle.value);
  svcNextPreview.textContent = svcNextBill.value || 'Not set';
  svcImpactCopy.textContent = total > 0
    ? `This assignment will feed recurring billing automatically at ${cycleLabel(svcCycle.value).toLowerCase()} value ${money(total)}.`
    : 'Select a catalog item to preload pricing and billing details.';
}
function loadSvcContracts() {
  const rows = svcContracts[svcClient.value] || [];
  svcContract.innerHTML = '<option value="0">No contract</option>';
  rows.forEach((row) => {
    const opt = document.createElement('option');
    opt.value = row.contract_id;
    opt.textContent = `${row.contract_number} · ${row.contract_name}`;
    if (parseInt(row.contract_id, 10) === selectedContractId) opt.selected = true;
    svcContract.appendChild(opt);
  });
}
function applySvcDefaults() {
  const selected = svcItem.options[svcItem.selectedIndex];
  if (!selected || !selected.value || selected.value === '0') {
    updatePreview();
    return;
  }
  if (!svcDesc.value) svcDesc.value = selected.dataset.name || '';
  if (!(parseFloat(svcPrice.value || '0') > 0)) svcPrice.value = selected.dataset.price || '0.00';
  if (selected.dataset.cycle) svcCycle.value = selected.dataset.cycle;
  if (selected.dataset.term) svcTerm.value = selected.dataset.term;
  if (selected.dataset.revenue && svcRevenue.value === '0') svcRevenue.value = selected.dataset.revenue;
  if (selected.dataset.taxable === '1') svcTaxable.checked = true;
  suggestNextBill();
  updatePreview();
}
[svcClient, svcItem, svcQty, svcPrice, svcCycle, svcNextBill, svcStartDate].forEach((el) => {
  el.addEventListener('change', () => {
    if (el === svcStartDate || el === svcCycle) suggestNextBill();
    if (el === svcItem) applySvcDefaults();
    updatePreview();
  });
});
loadSvcContracts();
applySvcDefaults();
updatePreview();
</script>
<?php page_footer(); ?>
