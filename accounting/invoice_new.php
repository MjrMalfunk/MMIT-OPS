<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login(); accounting_require_ready(); csrf_check();

$message = null; $errors = [];
$userId = (int)(current_user()['user_id'] ?? 0);
$defaults = [
    'invoice_date' => date('Y-m-d'),
    'due_date' => date('Y-m-d', strtotime('+15 days')),
    'status' => 'DRAFT',
    'contract_id' => '0',
    'ar_account_id' => (string)(accounting_find_account_id_by_code('1100') ?? 0),
    'memo' => '', 'client_id' => '0',
    'line_item_id' => ['0'],
    'line_description' => [''],
    'line_quantity' => ['1'],
    'line_unit_price' => ['0.00'],
    'line_revenue_account_id' => ['0'],
];
$form = $defaults;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, $_POST);
    foreach (['line_item_id', 'line_description', 'line_quantity', 'line_unit_price', 'line_revenue_account_id'] as $field) {
        if (!isset($form[$field]) || !is_array($form[$field]) || !$form[$field]) $form[$field] = $defaults[$field];
    }
    $lineCount = max(
        count($form['line_item_id']),
        count($form['line_description']),
        count($form['line_quantity']),
        count($form['line_unit_price']),
        count($form['line_revenue_account_id'])
    );
    for ($i = 0; $i < $lineCount; $i++) {
        $form['line_item_id'][$i] = (string)($form['line_item_id'][$i] ?? '0');
        $form['line_description'][$i] = (string)($form['line_description'][$i] ?? '');
        $form['line_quantity'][$i] = (string)($form['line_quantity'][$i] ?? '1');
        $form['line_unit_price'][$i] = (string)($form['line_unit_price'][$i] ?? '0.00');
        $form['line_revenue_account_id'][$i] = (string)($form['line_revenue_account_id'][$i] ?? '0');
    }
    $result = accounting_create_invoice($_POST, $userId);
    if (!empty($result['ok'])) {
        header('Location: ' . BASE_URL . '/accounting/invoice_view.php?id=' . (int)$result['invoice_id']);
        exit;
    }
    $errors = $result['errors'] ?? ['Unable to create invoice.'];
}
$lineCount = max(1,
    count($form['line_item_id'] ?? []),
    count($form['line_description'] ?? []),
    count($form['line_quantity'] ?? []),
    count($form['line_unit_price'] ?? []),
    count($form['line_revenue_account_id'] ?? [])
);
$clients = accounting_list_clients();
$contractsByClient = [];
foreach ($clients as $client) $contractsByClient[(int)$client['client_id']] = accounting_list_contracts_for_client((int)$client['client_id']);
$revenueAccounts = accounting_account_options(['INCOME']);
$assetAccounts = accounting_account_options(['ASSET']);
$catalogItems = accounting_list_catalog_items(null, true);
page_header('New Invoice', 'accounting'); accounting_subnav('invoices');
?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>
<div class="card" style="padding:18px;">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;margin-bottom:14px;">
    <div>
      <h2 style="margin:0;font-size:18px;">Create one-off / project invoice</h2>
      <div style="opacity:.78;line-height:1.45;margin-top:4px;">Choose a client, add one or more catalog or free-text lines, and save it as draft or issued.</div>
    </div>
    <a href="<?= accounting_h(BASE_URL) ?>/accounting/invoices.php" style="color:#dbeafe;">Back to invoices</a>
  </div>
  <form method="post" style="display:grid;gap:14px;">
    <?= csrf_field() ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div><label>Client</label><br><select name="client_id" id="invoice-client" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select client</option><?php foreach ($clients as $client): ?><option value="<?= (int)$client['client_id'] ?>" <?= ((int)$form['client_id'] === (int)$client['client_id']) ? 'selected' : '' ?>><?= accounting_h((string)($client['dba_name'] ?: $client['legal_name'])) ?></option><?php endforeach; ?></select></div>
      <div><label>Contract</label><br><select name="contract_id" id="invoice-contract" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">No contract</option></select></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
      <div><label>Invoice date</label><br><input type="text" name="invoice_date" value="<?= accounting_h((string)$form['invoice_date']) ?>" inputmode="numeric" placeholder="YYYY-MM-DD" class="invoice-date-input" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Due date</label><br><input type="text" name="due_date" value="<?= accounting_h((string)$form['due_date']) ?>" inputmode="numeric" placeholder="YYYY-MM-DD" class="invoice-date-input" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Status</label><br><select name="status" style="width:100%;padding:10px;box-sizing:border-box;"><option value="DRAFT" <?= ((string)$form['status'] === 'DRAFT') ? 'selected' : '' ?>>DRAFT</option><option value="ISSUED" <?= ((string)$form['status'] === 'ISSUED') ? 'selected' : '' ?>>ISSUED</option></select></div>
    </div>
    <div style="overflow:auto;">
      <table style="width:100%;border-collapse:collapse;">
        <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10);"><th style="padding:8px 6px;min-width:165px;">Catalog / service</th><th style="padding:8px 6px;min-width:210px;">Brief description</th><th style="padding:8px 6px;min-width:75px;">Qty</th><th style="padding:8px 6px;min-width:95px;">Unit price</th><th style="padding:8px 6px;min-width:165px;">Revenue account</th><th style="padding:8px 6px;min-width:90px;">Action</th></tr></thead>
        <tbody id="invoice-lines-body">
        <?php for ($i=0; $i<$lineCount; $i++): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06);">
            <td style="padding:8px 6px;vertical-align:top;"><select name="line_item_id[]" class="invoice-line-item" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Manual / free text</option><?php foreach ($catalogItems as $item): ?><option value="<?= (int)$item['item_id'] ?>" data-name="<?= accounting_h((string)$item['item_name']) ?>" data-code="<?= accounting_h((string)($item['item_code'] ?? '')) ?>" data-price="<?= accounting_h((string)$item['default_unit_price']) ?>" data-revenue-account="<?= (int)($item['revenue_account_id'] ?? 0) ?>" <?= ((int)($form['line_item_id'][$i] ?? 0) === (int)$item['item_id']) ? 'selected' : '' ?>><?= accounting_h((string)($item['item_code'] ?: '—')) ?> · <?= accounting_h((string)$item['item_name']) ?></option><?php endforeach; ?></select></td>
            <td style="padding:8px 6px;vertical-align:top;"><input type="text" name="line_description[]" class="invoice-line-description" value="<?= accounting_h((string)($form['line_description'][$i] ?? '')) ?>" placeholder="Website design, system integration, labor, etc." style="width:100%;padding:10px;box-sizing:border-box;"></td>
            <td style="padding:8px 6px;vertical-align:top;"><input type="number" step="1" min="0" name="line_quantity[]" value="<?= accounting_h((string)($form['line_quantity'][$i] ?? '1')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></td>
            <td style="padding:8px 6px;vertical-align:top;"><input type="number" step="1" min="0" name="line_unit_price[]" class="invoice-line-price" value="<?= accounting_h((string)($form['line_unit_price'][$i] ?? '0.00')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></td>
            <td style="padding:8px 6px;vertical-align:top;"><select name="line_revenue_account_id[]" class="invoice-line-revenue" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select revenue account</option><?php foreach ($revenueAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)($form['line_revenue_account_id'][$i] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></td>
            <td style="padding:8px 6px;vertical-align:top;"><button type="button" class="btn btn-secondary invoice-remove-line" style="width:auto;min-width:76px;">Remove</button></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
    <div><button type="button" id="invoice-add-line" class="btn" style="width:auto;">Add line</button></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div><label>A/R account</label><br><select name="ar_account_id" style="width:100%;padding:10px;box-sizing:border-box;"><?php foreach ($assetAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>" <?= ((int)$form['ar_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Internal memo</label><br><input type="text" name="memo" value="<?= accounting_h((string)($form['memo'] ?? '')) ?>" placeholder="Optional internal note" style="width:100%;padding:10px;box-sizing:border-box;"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;"><button type="submit" class="btn btn-secondary" style="width:auto;">Create invoice</button><a href="<?= accounting_h(BASE_URL) ?>/accounting/invoices.php" style="color:#dbeafe;">Cancel</a></div>
  </form>
</div>
<script>
const invoiceContracts = <?= json_encode($contractsByClient, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const clientSelect = document.getElementById('invoice-client');
const contractSelect = document.getElementById('invoice-contract');
const selectedContractId = <?= (int)($form['contract_id'] ?? 0) ?>;
const invoiceLinesBody = document.getElementById('invoice-lines-body');
const addLineButton = document.getElementById('invoice-add-line');
const catalogOptionsHtml = `<?php foreach ($catalogItems as $item): ?><option value="<?= (int)$item['item_id'] ?>" data-name="<?= accounting_h((string)$item['item_name']) ?>" data-code="<?= accounting_h((string)($item['item_code'] ?? '')) ?>" data-price="<?= accounting_h((string)$item['default_unit_price']) ?>" data-revenue-account="<?= (int)($item['revenue_account_id'] ?? 0) ?>"><?= accounting_h((string)($item['item_code'] ?: '—')) ?> · <?= accounting_h((string)$item['item_name']) ?></option><?php endforeach; ?>`;
const revenueOptionsHtml = `<?php foreach ($revenueAccounts as $account): ?><option value="<?= (int)$account['account_id'] ?>"><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option><?php endforeach; ?>`;
function fillContracts(clientId) {
  if (!contractSelect) return;
  const list = invoiceContracts[clientId] || [];
  contractSelect.innerHTML = '<option value="0">No contract</option>';
  list.forEach((contract) => {
    const option = document.createElement('option');
    option.value = String(contract.contract_id);
    option.textContent = `${contract.contract_number} · ${contract.contract_name}`;
    if (parseInt(contract.contract_id, 10) === selectedContractId) option.selected = true;
    contractSelect.appendChild(option);
  });
}
function bindLineRow(row) {
  if (!row) return;
  const itemSelect = row.querySelector('.invoice-line-item');
  const removeButton = row.querySelector('.invoice-remove-line');
  itemSelect?.addEventListener('change', () => {
    const option = itemSelect.options[itemSelect.selectedIndex];
    const description = row.querySelector('.invoice-line-description');
    const price = row.querySelector('.invoice-line-price');
    const revenue = row.querySelector('.invoice-line-revenue');
    if (description && !description.value.trim() && option.dataset.name) description.value = option.dataset.name;
    if (price && (!price.value || parseFloat(price.value || '0') === 0) && option.dataset.price) price.value = option.dataset.price;
    if (revenue && option.dataset.revenueAccount) revenue.value = option.dataset.revenueAccount;
  });
  removeButton?.addEventListener('click', () => {
    const rows = invoiceLinesBody ? invoiceLinesBody.querySelectorAll('tr') : [];
    if (rows.length <= 1) {
      const description = row.querySelector('.invoice-line-description');
      const quantity = row.querySelector('input[name="line_quantity[]"]');
      const price = row.querySelector('.invoice-line-price');
      const revenue = row.querySelector('.invoice-line-revenue');
      if (itemSelect) itemSelect.value = '0';
      if (description) description.value = '';
      if (quantity) quantity.value = '1.00';
      if (price) price.value = '0.00';
      if (revenue) revenue.value = '0';
      return;
    }
    row.remove();
  });
}
function addLineRow(values = {}) {
  if (!invoiceLinesBody) return;
  const row = document.createElement('tr');
  row.style.borderBottom = '1px solid rgba(255,255,255,.06)';
  row.innerHTML = `
    <td style="padding:8px 6px;vertical-align:top;"><select name="line_item_id[]" class="invoice-line-item" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Manual / free text</option>${catalogOptionsHtml}</select></td>
    <td style="padding:8px 6px;vertical-align:top;"><input type="text" name="line_description[]" class="invoice-line-description" placeholder="Website design, system integration, labor, etc." style="width:100%;padding:10px;box-sizing:border-box;"></td>
    <td style="padding:8px 6px;vertical-align:top;"><input type="number" step="1" min="0" name="line_quantity[]" value="1" style="width:100%;padding:10px;box-sizing:border-box;"></td>
    <td style="padding:8px 6px;vertical-align:top;"><input type="number" step="1" min="0" name="line_unit_price[]" class="invoice-line-price" value="0.00" style="width:100%;padding:10px;box-sizing:border-box;"></td>
    <td style="padding:8px 6px;vertical-align:top;"><select name="line_revenue_account_id[]" class="invoice-line-revenue" style="width:100%;padding:10px;box-sizing:border-box;"><option value="0">Select revenue account</option>${revenueOptionsHtml}</select></td>
    <td style="padding:8px 6px;vertical-align:top;"><button type="button" class="btn btn-secondary invoice-remove-line" style="width:auto;min-width:76px;">Remove</button></td>
  `;
  invoiceLinesBody.appendChild(row);
  const itemSelect = row.querySelector('.invoice-line-item');
  const description = row.querySelector('.invoice-line-description');
  const quantity = row.querySelector('input[name="line_quantity[]"]');
  const price = row.querySelector('.invoice-line-price');
  const revenue = row.querySelector('.invoice-line-revenue');
  if (itemSelect && values.itemId) itemSelect.value = String(values.itemId);
  if (description && typeof values.description !== 'undefined') description.value = values.description;
  if (quantity && typeof values.quantity !== 'undefined') quantity.value = values.quantity;
  if (price && typeof values.unitPrice !== 'undefined') price.value = values.unitPrice;
  if (revenue && values.revenueAccountId) revenue.value = String(values.revenueAccountId);
  bindLineRow(row);
  return row;
}
fillContracts(parseInt(clientSelect?.value || '0', 10));
clientSelect?.addEventListener('change', () => fillContracts(parseInt(clientSelect.value || '0', 10)));
document.querySelectorAll('#invoice-lines-body tr').forEach(bindLineRow);
addLineButton?.addEventListener('click', () => addLineRow());
document.querySelectorAll('.invoice-date-input').forEach((input) => {
  input.addEventListener('focus', () => {
    const value = input.value || '';
    setTimeout(() => input.setSelectionRange(value.length, value.length), 0);
  });
});
</script>
<?php page_footer(); ?>
