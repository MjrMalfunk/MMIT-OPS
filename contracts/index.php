<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/clients.php';
require_login();
accounting_require_ready();
csrf_check();

$summary = accounting_contract_summary();
$packages = accounting_service_packages();
$productivityCatalog = accounting_productivity_catalog();
$clients = accounting_list_clients();
$userId = (int)(current_user()['user_id'] ?? 0);
$errors = [];
$form = [
    'client_id' => '0',
    'legal_name' => '', 'dba_name' => '', 'email' => '', 'phone' => '', 'website' => '',
    'contact_first_name' => '', 'contact_last_name' => '', 'contact_title' => '', 'contact_email' => '', 'contact_phone' => '',
    'location_name' => 'Main Office', 'address1' => '', 'address2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country' => 'US',
    'package_code' => 'ESSENTIAL',
    'productivity_platform' => 'NONE',
    'productivity_license' => 'NONE',
    'productivity_price' => '0.00',
    'contract_name' => '',
    'term_months' => '12',
    'quantity' => '1',
    'covered_users' => '0',
    'covered_devices' => '1',
    'server_count' => '0',
    'unit_price' => (string)number_format((float)($packages['ESSENTIAL']['default_unit_price'] ?? 85), 2, '.', ''),
    'start_date' => date('Y-m-d'),
    'billing_cycle' => 'MONTHLY',
    'auto_renew' => '1',
    'create_draft_invoice' => '1',
    'status' => 'DRAFT',
    'notes' => '',
];

$prefillClientId = (int)($_GET['client_id'] ?? 0);
if ($prefillClientId > 0) {
    foreach ($clients as $clientRow) {
        if ((int)($clientRow['client_id'] ?? 0) === $prefillClientId) {
            $form['client_id'] = (string)$prefillClientId;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, $_POST);
    $result = accounting_create_contract_bundle($_POST, $userId);
    if (!empty($result['ok'])) {
        header('Location: ' . BASE_URL . '/contracts/view.php?id=' . (int)$result['contract_id']);
        exit;
    }
    $errors = $result['errors'] ?? ['Unable to create contract.'];
}

page_header('Contracts', 'contracts');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
  <div>
    <h1 style="margin:0;font-size:28px;">Contracts &amp; Onboarding</h1>
    <div style="opacity:.78;max-width:820px;">Build the order form, service schedule, platform licensing, and recurring billing lane in one pass. The agreement packet follows the same structure the client will actually sign.</div>
  </div>
  <a class="btn btn-secondary" style="width:auto;padding:10px 14px;" href="#contract-builder">New contract</a>
</div>

<div class="stats-grid" style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px;">
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Active contracts</div><div style="font-size:22px;font-weight:800;"><?= (int)$summary['active_count'] ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Draft contracts</div><div style="font-size:22px;font-weight:800;"><?= (int)$summary['draft_count'] ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Monthly contract value</div><div style="font-size:22px;font-weight:800;">$<?= number_format((float)$summary['mrr_value'], 2) ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Expiring in 90 days</div><div style="font-size:22px;font-weight:800;"><?= (int)$summary['expiring_count'] ?></div></div>
</div>

<div class="helper-note-card" style="margin-bottom:16px;">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
    <div>
      <div style="font-weight:800;margin-bottom:4px;">Products &amp; Services still owns the live catalog</div>
      <div style="opacity:.78;font-size:13px;max-width:780px;">This builder is the commercial lane. The catalog page still holds the service inventory, bundle planner, and advanced item maintenance.</div>
    </div>
    <a class="btn btn-secondary" href="<?= accounting_h(BASE_URL) ?>/products/index.php" style="width:auto;min-width:180px;text-decoration:none;">Open Products &amp; Services</a>
  </div>
</div>

<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="contracts-layout contracts-layout--single">
  <div class="card contract-builder-card" id="contract-builder">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div>
        <h2 style="margin:0;font-size:19px;">Contract Builder</h2>
        <div style="opacity:.74;font-size:13px;">Service package + productivity platform + optional infrastructure add-ons.</div>
      </div>
      <span class="badge status-badge" style="display:inline-flex;padding:5px 10px;border-radius:999px;background:rgba(59,130,246,.16);border:1px solid rgba(59,130,246,.28);">Order Form Wizard</span>
    </div>

    <form method="post" class="contract-builder-form">
      <?= csrf_field() ?>
      <input type="hidden" name="quantity" id="contract-qty" value="<?= accounting_h((string)$form['quantity']) ?>">

      <div>
        <label>Existing client</label>
        <select name="client_id" id="contract-client" style="width:100%;padding:10px;">
          <option value="0">Create a new client</option>
          <?php foreach ($clients as $client): ?>
            <option value="<?= (int)$client['client_id'] ?>" <?= ((int)$form['client_id'] === (int)$client['client_id']) ? 'selected' : '' ?>><?= accounting_h((string)($client['dba_name'] ?: $client['legal_name'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="new-client-fields" style="display:grid;gap:12px;">
        <div class="two-col">
          <div><label>Client legal name</label><input type="text" name="legal_name" value="<?= accounting_h((string)$form['legal_name']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>DBA / public name</label><input type="text" name="dba_name" value="<?= accounting_h((string)$form['dba_name']) ?>" style="width:100%;padding:10px;"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
          <div><label>Client email</label><input type="email" name="email" value="<?= accounting_h((string)$form['email']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Phone</label><input type="text" name="phone" value="<?= accounting_h((string)$form['phone']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Website</label><input type="text" name="website" value="<?= accounting_h((string)$form['website']) ?>" style="width:100%;padding:10px;"></div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;">
          <div><label>Primary contact first</label><input type="text" name="contact_first_name" value="<?= accounting_h((string)$form['contact_first_name']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Primary contact last</label><input type="text" name="contact_last_name" value="<?= accounting_h((string)$form['contact_last_name']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Contact email</label><input type="email" name="contact_email" value="<?= accounting_h((string)$form['contact_email']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Contact phone</label><input type="text" name="contact_phone" value="<?= accounting_h((string)$form['contact_phone']) ?>" style="width:100%;padding:10px;"></div>
        </div>
        <div style="display:grid;grid-template-columns:1.2fr 1.2fr .8fr .7fr .8fr;gap:12px;">
          <div><label>Address</label><input type="text" name="address1" value="<?= accounting_h((string)$form['address1']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>City</label><input type="text" name="city" value="<?= accounting_h((string)$form['city']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>State</label><input type="text" name="state" value="<?= accounting_h((string)$form['state']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>ZIP</label><input type="text" name="postal_code" value="<?= accounting_h((string)$form['postal_code']) ?>" style="width:100%;padding:10px;"></div>
          <div><label>Location name</label><input type="text" name="location_name" value="<?= accounting_h((string)$form['location_name']) ?>" style="width:100%;padding:10px;"></div>
        </div>
      </div>

      <div class="two-col">
        <div>
          <label>Service package</label>
          <select name="package_code" id="package-code" style="width:100%;padding:10px;">
            <?php foreach ($packages as $code => $pkg): ?>
              <option value="<?= accounting_h($code) ?>" <?= $form['package_code'] === $code ? 'selected' : '' ?>><?= accounting_h((string)$pkg['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Contract name</label>
          <input type="text" name="contract_name" id="contract-name" value="<?= accounting_h((string)$form['contract_name']) ?>" style="width:100%;padding:10px;">
        </div>
      </div>

      <div class="card package-summary-card" style="background:rgba(15,23,42,.42);display:grid;gap:12px;">
        <div id="package-desc" style="font-size:13px;color:rgba(255,255,255,.82);"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">
          <div>
            <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Included with this package</div>
            <ul id="package-includes" style="margin:0 0 0 18px;padding:0;color:rgba(255,255,255,.78);"></ul>
          </div>
          <div>
            <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Not included unless selected</div>
            <ul id="package-not-included" style="margin:0 0 0 18px;padding:0;color:rgba(255,255,255,.78);"></ul>
          </div>
        </div>
      </div>

      <div class="two-col">
        <div>
          <label>Productivity platform</label>
          <select name="productivity_platform" id="productivity-platform" style="width:100%;padding:10px;">
            <option value="NONE" <?= strtoupper((string)$form['productivity_platform']) === 'NONE' ? 'selected' : '' ?>>No productivity platform selected</option>
            <option value="M365" <?= strtoupper((string)$form['productivity_platform']) === 'M365' ? 'selected' : '' ?>>Microsoft 365</option>
            <option value="GW" <?= strtoupper((string)$form['productivity_platform']) === 'GW' ? 'selected' : '' ?>>Google Workspace</option>
          </select>
        </div>
        <div>
          <label>License level</label>
          <select name="productivity_license" id="productivity-license" data-current="<?= accounting_h((string)$form['productivity_license']) ?>" style="width:100%;padding:10px;">
            <option value="NONE">No license selected</option>
          </select>
        </div>
      </div>

      <div class="card" id="productivity-card" style="display:none;background:rgba(47,108,255,.10);border-color:rgba(47,108,255,.18);">
        <div style="display:grid;grid-template-columns:1.5fr .8fr;gap:14px;align-items:start;">
          <div>
            <div id="productivity-desc" style="font-size:13px;color:rgba(255,255,255,.82);line-height:1.5;"></div>
            <div id="productivity-guidance" style="margin-top:8px;font-size:12px;opacity:.72;line-height:1.5;"></div>
          </div>
          <div>
            <label>License price per user</label>
            <input type="number" step="0.01" min="0" name="productivity_price" id="productivity-price" value="<?= accounting_h((string)$form['productivity_price']) ?>" style="width:100%;padding:10px;">
            <div style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">This rides separately from the service package so you can price service and licensing as different levers.</div>
          </div>
        </div>
      </div>

      <div class="card addon-summary-card" id="addon-card" style="display:none;">
        <div style="font-size:13px;color:rgba(255,255,255,.82);margin-bottom:10px;">Optional infrastructure add-ons</div>
        <div id="addon-list" style="display:grid;gap:10px;"></div>
      </div>

      <div class="four-col-wide contract-count-grid">
        <div>
          <label>Covered workstations</label>
          <input type="number" min="1" step="1" id="contract-covered-devices" name="covered_devices" value="<?= accounting_h((string)($form['covered_devices'] ?? '1')) ?>" style="width:100%;padding:10px;">
          <div style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">This drives the base service package price for Manage, Protect, and Govern.</div>
        </div>
        <div>
          <label>Covered users / seats</label>
          <input type="number" min="0" step="1" id="contract-covered-users" name="covered_users" value="<?= accounting_h((string)($form['covered_users'] ?? '0')) ?>" style="width:100%;padding:10px;">
          <div id="contract-covered-users-help" style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">Use this for Microsoft 365 or Google Workspace licensing and any per-user companion services.</div>
        </div>
        <div>
          <label>Covered servers</label>
          <input type="number" min="0" step="1" id="contract-server-count" name="server_count" value="<?= accounting_h((string)($form['server_count'] ?? '0')) ?>" style="width:100%;padding:10px;">
          <div style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">Server Management and Server Backup bill from this count, not from workstation count.</div>
        </div>
        <div>
          <label>Package price per workstation</label>
          <input type="number" step="0.01" min="0" id="contract-unit-price" name="unit_price" value="<?= accounting_h((string)$form['unit_price']) ?>" style="width:100%;padding:10px;">
          <div style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">Editable so you can lock the exact deal before the client signs.</div>
        </div>
      </div>

      <div class="four-col-wide contract-meta-grid">
        <div><label>Start date</label><input type="date" name="start_date" value="<?= accounting_h((string)$form['start_date']) ?>" style="width:100%;padding:10px;"></div>
        <div><label>Initial term (months)</label><input type="number" min="1" step="1" name="term_months" value="<?= accounting_h((string)$form['term_months']) ?>" style="width:100%;padding:10px;"></div>
        <div>
          <label>Status</label>
          <select name="status" style="width:100%;padding:10px;">
            <option value="DRAFT" <?= $form['status'] === 'DRAFT' ? 'selected' : '' ?>>DRAFT</option>
            <option value="PENDING_SIGNATURE" <?= $form['status'] === 'PENDING_SIGNATURE' ? 'selected' : '' ?>>PENDING SIGNATURE</option>
          </select>
          <div style="margin-top:6px;font-size:12px;opacity:.72;line-height:1.45;">Draft stays internal. Pending Signature queues the packet for signing. Uploading the signed packet starts onboarding.</div>
        </div>
        <div>
          <label>Billing cycle</label>
          <select name="billing_cycle" style="width:100%;padding:10px;">
            <option value="MONTHLY" <?= $form['billing_cycle'] === 'MONTHLY' ? 'selected' : '' ?>>MONTHLY</option>
            <option value="ANNUAL" <?= $form['billing_cycle'] === 'ANNUAL' ? 'selected' : '' ?>>ANNUAL</option>
          </select>
        </div>
      </div>

      <div class="three-col-wide">
        <label><input type="checkbox" name="auto_renew" value="1" <?= !empty($form['auto_renew']) ? 'checked' : '' ?>> Auto renew after the initial term</label>
        <label><input type="checkbox" name="create_draft_invoice" value="1" <?= !empty($form['create_draft_invoice']) ? 'checked' : '' ?>> Create the initial draft invoice when onboarding reaches go-live</label>
        <label><input type="checkbox" name="tax_exempt" value="1"> Tax exempt client</label>
      </div>

      <div class="card total-summary-card" style="background:rgba(47,108,255,.11);border-color:rgba(47,108,255,.2);display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end;">
        <div><div style="font-size:12px;opacity:.8;">Service package</div><div id="package-subtotal" style="font-size:22px;font-weight:800;">$0.00</div></div>
        <div><div style="font-size:12px;opacity:.8;">Productivity licensing</div><div id="productivity-subtotal" style="font-size:22px;font-weight:800;">$0.00</div></div>
        <div><div style="font-size:12px;opacity:.8;">Infrastructure add-ons</div><div id="addon-subtotal" style="font-size:22px;font-weight:800;">$0.00</div></div>
        <div><div style="font-size:12px;opacity:.8;">Estimated monthly recurring total</div><div id="contract-total" style="font-size:28px;font-weight:800;">$0.00</div></div>
      </div>

      <div><label>Agreement notes / scope</label><textarea name="notes" rows="4" style="width:100%;padding:10px;"><?= accounting_h((string)$form['notes']) ?></textarea></div>

      <div class="action-row" style="display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn btn-primary" type="submit" style="width:auto;min-width:180px;">Create contract</button>
        <span style="align-self:center;opacity:.72;font-size:13px;">Creates the client if needed, builds the order form packet, writes the contract lines, and prepares onboarding. Draft first, send for signature, upload the signed PDF, then go live when the checklist is complete.</span>
      </div>
    </form>
  </div>
</div>

<script>
const packages = <?= json_encode($packages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const productivityCatalog = <?= json_encode($productivityCatalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;

const clientSel = document.getElementById('contract-client');
const newClientFields = document.getElementById('new-client-fields');
const packageSel = document.getElementById('package-code');
const packageDesc = document.getElementById('package-desc');
const packageIncludes = document.getElementById('package-includes');
const packageNotIncluded = document.getElementById('package-not-included');
const productivityPlatformSel = document.getElementById('productivity-platform');
const productivityLicenseSel = document.getElementById('productivity-license');
const productivityCard = document.getElementById('productivity-card');
const productivityDesc = document.getElementById('productivity-desc');
const productivityGuidance = document.getElementById('productivity-guidance');
const productivityPriceInput = document.getElementById('productivity-price');
const qtyInput = document.getElementById('contract-qty');
const coveredDevicesInput = document.getElementById('contract-covered-devices');
const coveredUsersInput = document.getElementById('contract-covered-users');
const coveredUsersHelpEl = document.getElementById('contract-covered-users-help');
const serverCountInput = document.getElementById('contract-server-count');
const priceInput = document.getElementById('contract-unit-price');
const packageSubtotalEl = document.getElementById('package-subtotal');
const productivitySubtotalEl = document.getElementById('productivity-subtotal');
const addonSubtotalEl = document.getElementById('addon-subtotal');
const totalEl = document.getElementById('contract-total');
const contractName = document.getElementById('contract-name');
const addonCard = document.getElementById('addon-card');
const addonList = document.getElementById('addon-list');
let lastAutoContractName = contractName ? contractName.value.trim() : '';
const autoContractNames = new Set(Object.values(packages).map((pkg) => `${pkg.name} Agreement`));

function toggleClientMode() {
  if (newClientFields && clientSel) {
    newClientFields.style.display = clientSel.value === '0' ? 'grid' : 'none';
  }
}

function shouldSyncContractName() {
  if (!contractName) return false;
  const current = contractName.value.trim();
  return current === '' || current === lastAutoContractName || autoContractNames.has(current);
}

function includedBaseLicense(packageCode, platform) {
  const pkg = String(packageCode || '').toUpperCase();
  const plat = String(platform || '').toUpperCase();
  if (pkg === 'SECURE') {
    if (plat === 'M365') return 'BASIC';
    if (plat === 'GW') return 'STARTER';
  }
  return 'NONE';
}

function allowedLicenses(packageCode, platform) {
  const pkg = String(packageCode || '').toUpperCase();
  const plat = String(platform || '').toUpperCase();
  if (plat === 'M365') {
    if (pkg === 'COMPLETE') return ['PREMIUM'];
    if (pkg === 'SECURE') return ['NONE', 'STANDARD', 'PREMIUM'];
    return ['BASIC', 'STANDARD', 'PREMIUM'];
  }
  if (plat === 'GW') {
    if (pkg === 'COMPLETE') return ['PLUS'];
    if (pkg === 'SECURE') return ['NONE', 'STANDARD', 'PLUS'];
    return ['STARTER', 'STANDARD', 'PLUS'];
  }
  return [];
}

function defaultLicense(packageCode, platform) {
  const plat = String(platform || '').toUpperCase();
  const pkg = String(packageCode || '').toUpperCase();
  if (pkg === 'SECURE') return 'NONE';
  if (plat === 'M365') return pkg === 'COMPLETE' ? 'PREMIUM' : 'BASIC';
  if (plat === 'GW') return pkg === 'COMPLETE' ? 'PLUS' : 'STARTER';
  return 'NONE';
}

function allowedAddonCodes(packageCode, platform) {
  const pkg = String(packageCode || '').toUpperCase();
  const plat = String(platform || '').toUpperCase();
  const codes = ['EP-BKUP-X150', 'SRVR-MGMT', 'SRVR-BK-500', 'SRVR-BK-1000', 'SRVR-BK-1500', 'SRVR-BK-2000', 'FW-NETSEC'];
  if (pkg === 'ESSENTIAL') {
    codes.unshift('DNS-FLTR', 'EP-BKUP');
    if (plat === 'M365') {
      codes.push('M365-BKUP');
    } else if (plat === 'GW') {
      codes.push('GW-BKUP');
    }
  } else if (pkg === 'SECURE') {
    codes.push('SAT-TRAIN');
  }
  return [...new Set(codes)];
}

function derivedAddonPrice(addon) {
  const itemCode = String(addon?.item_code || '').toUpperCase();
  if (itemCode === 'SRVR-MGMT') {
    return Number(Number(priceInput?.value || 0) + 15).toFixed(2);
  }
  return Number(addon?.default_unit_price || 0).toFixed(2);
}

function formatMoney(value) {
  return `$${Number(value || 0).toFixed(2)}`;
}

function syncHiddenQuantity() {
  const pkg = packages[packageSel?.value] || null;
  if (!pkg || !qtyInput) return;
  const model = String(pkg.pricing_model || 'PER_DEVICE').toUpperCase();
  if (model === 'PER_USER') {
    qtyInput.value = String(Math.max(1, Number(coveredUsersInput?.value || 0)));
  } else if (model === 'PER_LICENSE') {
    qtyInput.value = '1';
  } else {
    qtyInput.value = String(Math.max(1, Number(coveredDevicesInput?.value || 0)));
  }
}

function syncCoveredUserGuidance(platform) {
  if (!coveredUsersHelpEl) return;
  const plat = String(platform || '').toUpperCase();
  if (plat === 'M365') {
    coveredUsersHelpEl.textContent = 'Use this for Microsoft 365 seat count and any other per-user cloud services tied to the tenant.';
    return;
  }
  if (plat === 'GW') {
    coveredUsersHelpEl.textContent = 'Use this for Google Workspace seat count and any other per-user cloud services tied to the tenant.';
    return;
  }
  coveredUsersHelpEl.textContent = 'Use this for Microsoft 365 or Google Workspace licensing and any per-user companion services.';
}

function renderPackageSummary() {
  const pkg = packages[packageSel?.value] || null;
  if (!pkg) return;
  if (packageDesc) packageDesc.textContent = pkg.description || '';
  if (packageIncludes) {
    packageIncludes.innerHTML = '';
    (pkg.included_services || []).forEach((item) => {
      const li = document.createElement('li');
      li.textContent = item;
      packageIncludes.appendChild(li);
    });
  }
  renderNotIncluded(pkg);
  if (priceInput && (!priceInput.value || priceInput.dataset.autofill === '1' || Number(priceInput.value) <= 0 || String(priceInput.dataset.packageCode || '') !== String(pkg.code || ''))) {
    priceInput.value = Number(pkg.default_unit_price || 0).toFixed(2);
    priceInput.dataset.autofill = '1';
  }
  if (priceInput) priceInput.dataset.packageCode = String(pkg.code || '');
  const nextContractName = `${pkg.name} Agreement`;
  if (shouldSyncContractName()) {
    contractName.value = nextContractName;
  }
  lastAutoContractName = nextContractName;
}

function renderNotIncluded(pkg) {
  if (!packageNotIncluded || !pkg) return;
  const items = [...(pkg.not_included_unless_selected || [])];
  const platform = String(productivityPlatformSel?.value || 'NONE').toUpperCase();
  const selectedAddonCodes = [];
  addonList?.querySelectorAll('.addon-row').forEach((row) => {
    const cb = row.querySelector('input[type=checkbox]');
    if (cb && cb.checked) selectedAddonCodes.push(String(row.dataset.itemCode || '').toUpperCase());
  });

  const filtered = items.filter((label) => {
    if (platform !== 'NONE' && /Productivity platform/i.test(label)) return false;
    if (selectedAddonCodes.includes('DNS-FLTR') && /DNS filtering/i.test(label)) return false;
    if (selectedAddonCodes.includes('EP-BKUP') && /Endpoint backup/i.test(label)) return false;
    if ((selectedAddonCodes.includes('M365-BKUP') || selectedAddonCodes.includes('GW-BKUP')) && /SaaS backup/i.test(label)) return false;
    if (selectedAddonCodes.includes('EP-BKUP-X150') && /storage blocks/i.test(label)) return false;
    if (selectedAddonCodes.includes('SRVR-MGMT') && /Server management/i.test(label)) return false;
    if (selectedAddonCodes.some((code) => code.startsWith('SRVR-BK-')) && /Server backup/i.test(label)) return false;
    if (selectedAddonCodes.includes('FW-NETSEC') && /firewall/i.test(label)) return false;
    return true;
  });

  packageNotIncluded.innerHTML = '';
  filtered.forEach((item) => {
    const li = document.createElement('li');
    li.textContent = item;
    packageNotIncluded.appendChild(li);
  });
}

function renderProductivity() {
  const pkgCode = String(packageSel?.value || 'ESSENTIAL').toUpperCase();
  const platform = String(productivityPlatformSel?.value || 'NONE').toUpperCase();
  const allowed = allowedLicenses(pkgCode, platform);
  const priorSelection = String(productivityLicenseSel?.dataset.current || productivityLicenseSel?.value || '').toUpperCase();
  if (productivityLicenseSel) productivityLicenseSel.dataset.current = '';

  productivityLicenseSel.innerHTML = '';
  if (!allowed.length) {
    const opt = document.createElement('option');
    opt.value = 'NONE';
    opt.textContent = 'No license selected';
    productivityLicenseSel.appendChild(opt);
    productivityLicenseSel.value = 'NONE';
    if (productivityCard) productivityCard.style.display = 'none';
    if (productivityDesc) productivityDesc.textContent = '';
    if (productivityGuidance) productivityGuidance.textContent = 'Licensing stays optional until you choose a productivity platform.';
    if (productivityPriceInput) {
      productivityPriceInput.value = '0.00';
      productivityPriceInput.dataset.autofill = '1';
    }
    syncCoveredUserGuidance('NONE');
    calcTotal();
    return;
  }

  const baseCode = includedBaseLicense(pkgCode, platform);
  allowed.forEach((licenseCode) => {
    const opt = document.createElement('option');
    opt.value = licenseCode;
    if (licenseCode === 'NONE') {
      opt.textContent = pkgCode === 'SECURE' ? 'Included with Protect IT' : 'No license selected';
    } else {
      const meta = productivityCatalog?.[platform]?.licenses?.[licenseCode];
      if (!meta) return;
      opt.textContent = meta.item_name;
    }
    productivityLicenseSel.appendChild(opt);
  });

  let desired = priorSelection;
  if (!allowed.includes(desired)) {
    desired = defaultLicense(pkgCode, platform);
  }
  if (!allowed.includes(desired)) {
    desired = allowed[0];
  }
  productivityLicenseSel.value = desired;

  const selected = String(productivityLicenseSel.value || desired).toUpperCase();
  const meta = selected !== 'NONE' ? (productivityCatalog?.[platform]?.licenses?.[selected] || null) : null;
  const baseMeta = baseCode !== 'NONE' ? (productivityCatalog?.[platform]?.licenses?.[baseCode] || null) : null;
  if (productivityCard) productivityCard.style.display = platform !== 'NONE' ? 'block' : 'none';

  if (selected === 'NONE' && pkgCode === 'SECURE' && baseMeta) {
    if (productivityDesc) productivityDesc.textContent = `${baseMeta.item_name} is included with Protect IT when you manage a ${platform === 'M365' ? 'Microsoft 365' : 'Google Workspace'} tenant.`;
    if (productivityGuidance) productivityGuidance.textContent = 'Choose Standard or Premium / Plus only when you want to upgrade above the included base license.';
    if (productivityPriceInput) {
      productivityPriceInput.value = '0.00';
      productivityPriceInput.dataset.autofill = '1';
    }
    syncCoveredUserGuidance(platform);
    calcTotal();
    return;
  }

  if (meta) {
    if (productivityDesc) productivityDesc.textContent = meta.description || meta.item_name || '';
    const packageName = packages[pkgCode]?.name || 'Selected package';
    let suggestedPrice = Number(meta.default_unit_price || 0);
    if (pkgCode === 'SECURE' && baseMeta) {
      suggestedPrice = Math.max(0, suggestedPrice - Number(baseMeta.default_unit_price || 0));
      if (productivityGuidance) {
        productivityGuidance.textContent = `${packageName} includes ${baseMeta.item_name}. The price shown below is the upgrade difference for ${meta.item_name}.`;
      }
    } else if (pkgCode === 'COMPLETE') {
      if (productivityGuidance) productivityGuidance.textContent = `${packageName} requires the stronger ${meta.item_name} lane when a productivity platform is included.`;
    } else if (productivityGuidance) {
      productivityGuidance.textContent = `${packageName} keeps licensing separate from service so you can choose the right seat level without changing the service package.`;
    }
    if (productivityPriceInput && (!productivityPriceInput.value || Number(productivityPriceInput.value) <= 0 || productivityPriceInput.dataset.autofill === '1')) {
      productivityPriceInput.value = Number(suggestedPrice || 0).toFixed(2);
      productivityPriceInput.dataset.autofill = '1';
    }
  }
  syncCoveredUserGuidance(platform);
  calcTotal();
}

function renderAddons(pkg) {
  if (!addonCard || !addonList || !pkg) return;
  addonList.innerHTML = '';
  const platform = String(productivityPlatformSel?.value || 'NONE').toUpperCase();
  const visibleCodes = allowedAddonCodes(pkg.code, platform);
  const addons = (Array.isArray(pkg.addon_services) ? pkg.addon_services : []).filter((addon) => visibleCodes.includes(String(addon.item_code || '').toUpperCase()));
  addonCard.style.display = addons.length ? 'block' : 'none';

  addons.forEach((addon) => {
    const wrap = document.createElement('label');
    wrap.className = 'addon-row';
    const pricingModel = String(addon.pricing_model || 'FIXED').toUpperCase();
    const itemCode = String(addon.item_code || '').toUpperCase();
    const isServerAddon = itemCode === 'SRVR-MGMT' || itemCode.startsWith('SRVR-BK-');
    const defaultQty = isServerAddon
      ? Math.max(0, Number(serverCountInput?.value || 0))
      : (pricingModel === 'PER_USER'
          ? Math.max(0, Number(coveredUsersInput?.value || 0))
          : (pricingModel === 'PER_DEVICE' ? Math.max(1, Number(coveredDevicesInput?.value || 0)) : 1));
    const defaultPrice = derivedAddonPrice(addon);

    wrap.dataset.pricingModel = pricingModel;
    wrap.dataset.itemCode = itemCode;
    wrap.innerHTML = `<input type="checkbox" name="addon_item_ids[]" value="${addon.item_id}">
      <div class="addon-meta">
        <strong>${addon.item_name}</strong>
        <div class="addon-desc">${addon.description || ''}</div>
        <div class="addon-basis">${itemCode === 'SRVR-MGMT'
          ? 'Per Server · package price + $15'
          : itemCode === 'EP-BKUP'
            ? 'Per Workstation · includes up to 250 GB'
            : itemCode === 'EP-BKUP-X150'
              ? 'Per 150 GB block over 250 GB'
              : itemCode === 'SRVR-BK-500'
                ? 'Per Server · up to 500 GB included'
                : itemCode === 'SRVR-BK-1000'
                  ? 'Per Server · 501 GB to 1 TB'
                  : itemCode === 'SRVR-BK-1500'
                    ? 'Per Server · 1 TB to 1.5 TB'
                    : itemCode === 'SRVR-BK-2000'
                      ? 'Per Server · 1.5 TB to 2 TB'
                      : (isServerAddon ? 'Per Server' : (itemCode === 'DNS-FLTR' ? 'Per Device' : pricingModel.replace(/_/g, ' ')))}</div>
      </div>
      <input class="addon-qty" type="number" step="1" min="${isServerAddon || pricingModel === 'PER_USER' ? 0 : 1}" name="addon_qty[${addon.item_id}]" value="${defaultQty}">
      <input class="addon-price" data-autofill="1" type="number" step="0.01" min="0" name="addon_price[${addon.item_id}]" value="${defaultPrice}">
      <select name="addon_cycle[${addon.item_id}]">
        ${['MONTHLY','QUARTERLY','SEMIANNUAL','ANNUAL'].map((c) => `<option value="${c}" ${c === (addon.billing_cycle || 'MONTHLY') ? 'selected' : ''}>${c}</option>`).join('')}
      </select>`;
    addonList.appendChild(wrap);
  });

  addonList.querySelectorAll('input,select').forEach((el) => el.addEventListener('input', () => { renderNotIncluded(pkg); calcTotal(); }));
}

function syncAddonQuantities() {
  if (!addonList) return;
  addonList.querySelectorAll('.addon-row').forEach((row) => {
    const qtyField = row.querySelector('.addon-qty');
    const pricingModel = String(row.dataset.pricingModel || 'FIXED').toUpperCase();
    const itemCode = String(row.dataset.itemCode || '').toUpperCase();
    if (!qtyField) return;
    if (itemCode === 'SRVR-MGMT' || itemCode.startsWith('SRVR-BK-')) {
      qtyField.value = Math.max(0, Number(serverCountInput?.value || 0));
    } else if (pricingModel === 'PER_USER') {
      qtyField.value = Math.max(0, Number(coveredUsersInput?.value || 0));
    } else if (pricingModel === 'PER_DEVICE') {
      qtyField.value = Math.max(1, Number(coveredDevicesInput?.value || 0));
    }
  });
  renderNotIncluded(packages[packageSel?.value] || null);
  calcTotal();
}

function calcTotal() {
  syncHiddenQuantity();
  const workstationQty = Math.max(1, Number(coveredDevicesInput?.value || 0));
  const packageUnit = Number(priceInput?.value || 0);
  const packageSubtotal = workstationQty * packageUnit;

  let productivitySubtotal = 0;
  const platform = String(productivityPlatformSel?.value || 'NONE').toUpperCase();
  if (platform !== 'NONE') {
    const userQty = Math.max(0, Number(coveredUsersInput?.value || 0));
    const licenseUnit = Number(productivityPriceInput?.value || 0);
    productivitySubtotal = userQty * licenseUnit;
  }

  let addonSubtotal = 0;
  if (addonList) {
    addonList.querySelectorAll('.addon-row').forEach((row) => {
      const checked = row.querySelector('input[type=checkbox]');
      const qtyField = row.querySelector('.addon-qty');
      const priceField = row.querySelector('.addon-price');
      if (checked && checked.checked) {
        addonSubtotal += Number(qtyField?.value || 0) * Number(priceField?.value || 0);
      }
    });
  }

  const total = packageSubtotal + productivitySubtotal + addonSubtotal;
  if (packageSubtotalEl) packageSubtotalEl.textContent = formatMoney(packageSubtotal);
  if (productivitySubtotalEl) productivitySubtotalEl.textContent = formatMoney(productivitySubtotal);
  if (addonSubtotalEl) addonSubtotalEl.textContent = formatMoney(addonSubtotal);
  if (totalEl) totalEl.textContent = formatMoney(total);
}

function renderAll() {
  const pkg = packages[packageSel?.value] || null;
  if (!pkg) return;
  renderPackageSummary();
  renderAddons(pkg);
  renderProductivity();
  syncAddonQuantities();
  calcTotal();
}

if (clientSel) clientSel.addEventListener('change', toggleClientMode);
if (packageSel) packageSel.addEventListener('change', renderAll);
if (productivityPlatformSel) productivityPlatformSel.addEventListener('change', renderAll);
if (productivityLicenseSel) productivityLicenseSel.addEventListener('change', renderProductivity);
if (coveredDevicesInput) coveredDevicesInput.addEventListener('input', syncAddonQuantities);
if (coveredUsersInput) coveredUsersInput.addEventListener('input', syncAddonQuantities);
if (serverCountInput) serverCountInput.addEventListener('input', syncAddonQuantities);
function syncAddonDerivedPricing() {
  if (!addonList) return;
  addonList.querySelectorAll('.addon-row').forEach((row) => {
    const itemCode = String(row.dataset.itemCode || '').toUpperCase();
    const priceField = row.querySelector('.addon-price');
    if (!priceField) return;
    if (itemCode === 'SRVR-MGMT') {
      priceField.value = Number(Number(priceInput?.value || 0) + 15).toFixed(2);
      priceField.dataset.autofill = '1';
    }
  });
  calcTotal();
}

if (priceInput) priceInput.addEventListener('input', () => {
  priceInput.dataset.autofill = '0';
  syncAddonDerivedPricing();
});
if (productivityPriceInput) productivityPriceInput.addEventListener('input', () => {
  productivityPriceInput.dataset.autofill = '0';
  calcTotal();
});

toggleClientMode();
renderAll();
</script>
<?php page_footer(); ?>
