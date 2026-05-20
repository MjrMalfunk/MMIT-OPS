<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';

require_login();
accounting_require_ready();
csrf_check();

$message = null;
$errors = [];
$revenueAccounts = accounting_account_options(['INCOME']);
$summary = accounting_summary();
accounting_service_packages(); // keep managed package pricing and backup catalog defaults in sync
$serviceCategories = accounting_list_service_categories(false);
$categoryGroups = accounting_group_categories_by_type($serviceCategories);

$defaults = [
    'item_code' => '',
    'item_name' => '',
    'item_type' => 'SERVICE',
    'category_id' => '0',
    'description' => '',
    'billing_mode' => 'RECURRING',
    'default_unit_price' => '0.00',
    'default_billing_cycle' => 'MONTHLY',
    'term_months' => '0',
    'revenue_account_id' => (string)(accounting_find_account_id_by_code('4000') ?? 0),
    'is_taxable' => '0',
    'is_active' => '1',
];
$form = $defaults;
$editing = isset($_GET['item_id']) ? accounting_get_catalog_item((int)$_GET['item_id']) : null;

if ($editing) {
    $form = [
        'item_code' => (string)($editing['item_code'] ?? ''),
        'item_name' => (string)($editing['item_name'] ?? ''),
        'item_type' => (string)($editing['item_type'] ?? 'SERVICE'),
        'category_id' => (string)($editing['category_id'] ?? '0'),
        'description' => (string)($editing['description'] ?? ''),
        'billing_mode' => (string)($editing['billing_mode'] ?? 'RECURRING'),
        'default_unit_price' => (string)($editing['default_unit_price'] ?? '0.00'),
        'default_billing_cycle' => (string)($editing['default_billing_cycle'] ?? 'MONTHLY'),
        'term_months' => (string)($editing['term_months'] ?? '0'),
        'revenue_account_id' => (string)($editing['revenue_account_id'] ?? '0'),
        'is_taxable' => !empty($editing['is_taxable']) ? '1' : '0',
        'is_active' => !empty($editing['is_active']) ? '1' : '0',
    ];
}

$bundleDefaults = [
    'bundle_code' => '',
    'bundle_name' => '',
    'description' => '',
    'pricing_model' => 'PER_DEVICE',
    'default_unit_price' => '0.00',
    'default_billing_cycle' => 'MONTHLY',
    'term_months' => '12',
    'revenue_account_id' => (string)(accounting_find_account_id_by_code('4000') ?? 0),
    'base_item_id' => '0',
    'included_item_ids' => [],
    'addon_item_ids' => [],
    'is_taxable' => '0',
    'is_active' => '1',
];

$bundleForm = $bundleDefaults;
$bundleEditing = isset($_GET['bundle_id']) ? accounting_get_bundle((int)$_GET['bundle_id']) : null;

if ($bundleEditing) {
    $bundleForm = array_merge($bundleDefaults, [
        'bundle_code' => (string)($bundleEditing['bundle_code'] ?? ''),
        'bundle_name' => (string)($bundleEditing['bundle_name'] ?? ''),
        'description' => (string)($bundleEditing['description'] ?? ''),
        'pricing_model' => (string)($bundleEditing['pricing_model'] ?? 'PER_DEVICE'),
        'default_unit_price' => (string)($bundleEditing['default_unit_price'] ?? '0.00'),
        'default_billing_cycle' => (string)($bundleEditing['default_billing_cycle'] ?? 'MONTHLY'),
        'term_months' => (string)($bundleEditing['term_months'] ?? '12'),
        'revenue_account_id' => (string)($bundleEditing['revenue_account_id'] ?? '0'),
        'base_item_id' => (string)($bundleEditing['base_item_id'] ?? '0'),
        'is_taxable' => !empty($bundleEditing['is_taxable']) ? '1' : '0',
        'is_active' => !empty($bundleEditing['is_active']) ? '1' : '0',
    ]);

    foreach (accounting_get_bundle_items((int)$bundleEditing['bundle_id']) as $bundleItem) {
        if ((string)$bundleItem['item_role'] === 'INCLUDED') {
            $bundleForm['included_item_ids'][] = (int)$bundleItem['item_id'];
        }
        if ((string)$bundleItem['item_role'] === 'ADDON_OPTION') {
            $bundleForm['addon_item_ids'][] = (int)$bundleItem['item_id'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formName = (string)($_POST['form_name'] ?? 'catalog_item');

    if ($formName === 'bundle') {
        $bundleForm = array_merge($bundleForm, $_POST);
        $bundleId = (int)($_POST['bundle_id'] ?? 0);
        $bundleForm['included_item_ids'] = array_map('intval', (array)($_POST['included_item_ids'] ?? []));
        $bundleForm['addon_item_ids'] = array_map('intval', (array)($_POST['addon_item_ids'] ?? []));

        $result = accounting_save_bundle($_POST, $bundleId > 0 ? $bundleId : null);

        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
            $bundleForm = $bundleDefaults;
            $bundleEditing = null;
        } else {
            $errors = $result['errors'] ?? ['Unable to save bundle.'];
        }
    } else {
        $form = array_merge($form, $_POST);
        $itemId = (int)($_POST['item_id'] ?? 0);

        $result = accounting_save_catalog_item($_POST, $itemId > 0 ? $itemId : null);

        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
            $form = $defaults;
            $editing = null;
        } else {
            $errors = $result['errors'] ?? ['Unable to save catalog item.'];
        }
    }
}

$items = accounting_list_catalog_items();
$bundleRows = accounting_list_bundles();
$baseItems = accounting_list_catalog_items('SERVICE', true, 'RECURRING', null, accounting_bundle_base_category_ids());
$includedItems = accounting_list_catalog_items('SERVICE', true, 'RECURRING', null, accounting_bundle_included_category_ids());
$addonItems = accounting_list_catalog_items('SERVICE', true, 'RECURRING', null, accounting_bundle_addon_category_ids());

$groupItemsByCategory = static function (array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $label = (string)($row['category_name'] ?? 'Uncategorized');
        if (!isset($out[$label])) {
            $out[$label] = [];
        }
        $out[$label][] = $row;
    }
    return $out;
};

$includedGroups = $groupItemsByCategory($includedItems);
$addonGroups = $groupItemsByCategory($addonItems);

$includedLookup = [];
foreach ($includedItems as $row) {
    $includedLookup[(int)$row['item_id']] = [
        'item_id' => (int)$row['item_id'],
        'item_name' => (string)$row['item_name'],
        'item_code' => (string)($row['item_code'] ?? ''),
        'category_name' => (string)($row['category_name'] ?? 'Included Services'),
    ];
}

$addonLookup = [];
foreach ($addonItems as $row) {
    $addonLookup[(int)$row['item_id']] = [
        'item_id' => (int)$row['item_id'],
        'item_name' => (string)$row['item_name'],
        'item_code' => (string)($row['item_code'] ?? ''),
        'category_name' => (string)($row['category_name'] ?? 'Optional Add-Ons'),
    ];
}

$baseItemLookup = [];
foreach ($baseItems as $row) {
    $baseItemLookup[(int)$row['item_id']] = [
        'item_id' => (int)$row['item_id'],
        'item_name' => (string)$row['item_name'],
        'item_code' => (string)($row['item_code'] ?? ''),
    ];
}

$bundlePayload = [];
$bundleOrder = [];

foreach ($bundleRows as $bundle) {
    $bundleId = (int)$bundle['bundle_id'];
    $bundleOrder[] = $bundleId;

    $payload = [
        'bundle_id' => $bundleId,
        'bundle_code' => (string)($bundle['bundle_code'] ?? ''),
        'bundle_name' => (string)($bundle['bundle_name'] ?? ''),
        'description' => (string)($bundle['description'] ?? ''),
        'pricing_model' => (string)($bundle['pricing_model'] ?? 'PER_DEVICE'),
        'default_unit_price' => (string)($bundle['default_unit_price'] ?? '0.00'),
        'default_billing_cycle' => (string)($bundle['default_billing_cycle'] ?? 'MONTHLY'),
        'term_months' => (string)($bundle['term_months'] ?? '12'),
        'revenue_account_id' => (string)($bundle['revenue_account_id'] ?? '0'),
        'base_item_id' => (string)($bundle['base_item_id'] ?? '0'),
        'base_item_name' => (string)($bundle['base_item_name'] ?? ''),
        'is_taxable' => !empty($bundle['is_taxable']) ? '1' : '0',
        'is_active' => !empty($bundle['is_active']) ? '1' : '0',
        'included_item_ids' => [],
        'addon_item_ids' => [],
        'included_items' => [],
        'addon_items' => [],
    ];

    foreach (accounting_get_bundle_items($bundleId) as $bundleItem) {
        $itemId = (int)$bundleItem['item_id'];
        $role = (string)$bundleItem['item_role'];

        if ($role === 'INCLUDED' && isset($includedLookup[$itemId])) {
            $payload['included_item_ids'][] = $itemId;
            $payload['included_items'][] = $includedLookup[$itemId];
        }

        if ($role === 'ADDON_OPTION' && isset($addonLookup[$itemId])) {
            $payload['addon_item_ids'][] = $itemId;
            $payload['addon_items'][] = $addonLookup[$itemId];
        }
    }

    $bundlePayload[$bundleId] = $payload;
}

$selectedBundleId = 0;
if ($bundleEditing) {
    $selectedBundleId = (int)$bundleEditing['bundle_id'];
} elseif ($bundleRows) {
    $selectedBundleId = (int)$bundleRows[0]['bundle_id'];
}

$selectedBundlePayload = $selectedBundleId && isset($bundlePayload[$selectedBundleId])
    ? $bundlePayload[$selectedBundleId]
    : null;

page_header('Products & Services', 'products');
?>
<style>
.products-top-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:16px; }
.bundle-catalog-summary-card .portal-table .money { text-align:right; }
.bundle-catalog-summary-card .portal-table .contents-cell { min-width: 180px; }
@media (max-width: 960px) { .products-top-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width: 640px) { .products-top-stats { grid-template-columns:1fr; } }
</style>

<?php if ($message): ?>
    <div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);">
        <?= accounting_h($message) ?>
    </div>
<?php endif; ?>

<?php if ($errors): ?>
    <div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);">
        <?php foreach ($errors as $error): ?>
            <div><?= accounting_h((string)$error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="products-top-stats stats-grid">
    <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Active catalog items</div><div style="font-size:22px;font-weight:800;"><?= (int)$summary['catalog_item_count'] ?></div></div>
    <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Active recurring items</div><div style="font-size:22px;font-weight:800;"><?= (int)$summary['recurring_item_count'] ?></div></div>
    <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Bundle plans</div><div style="font-size:22px;font-weight:800;"><?= count($bundleRows) ?></div></div>
    <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Service categories</div><div style="font-size:22px;font-weight:800;"><?= count($serviceCategories) ?></div></div>
</div>

<div class="card bundle-catalog-summary-card">
    <div class="section-header-row">
        <div>
            <h2 style="margin:0;font-size:20px;">Bundle Catalog</h2>
            <div style="opacity:.75;margin-top:4px;">These are the live plans the Contract Builder reads from. Static bundles stay easy to review here, while the advanced editor is tucked below for the rare day you need to reshape the stack.</div>
        </div>
        <a href="<?= accounting_h(BASE_URL) ?>/contracts/index.php" style="color:#dbeafe;white-space:nowrap;">Open contract builder →</a>
    </div>
    <?php if (!$bundleRows): ?>
        <div style="padding:12px 14px;border-radius:12px;border:1px solid rgba(245,158,11,.25);background:rgba(120,53,15,.26);">No bundle plans are configured yet.</div>
    <?php else: ?>
        <table class="portal-table">
            <thead>
                <tr>
                    <th>Bundle</th>
                    <th>Base item</th>
                    <th class="money">Default price</th>
                    <th>Contents</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bundleRows as $bundle): ?>
                    <tr>
                        <td>
                            <strong><?= accounting_h((string)$bundle['bundle_name']) ?></strong>
                            <div class="bundle-catalog-meta">Internal code: <?= accounting_h((string)$bundle['bundle_code']) ?></div>
                        </td>
                        <td>
                            <strong><?= accounting_h((string)($bundle['base_item_name'] ?: 'No base item')) ?></strong>
                            <div class="bundle-catalog-meta"><?= accounting_h((string)($bundle['base_item_code'] ?: '—')) ?></div>
                        </td>
                        <td class="money">
                            $<?= number_format((float)$bundle['default_unit_price'], 2) ?>
                            <?php $pricingLabel = strtoupper((string)$bundle['pricing_model']) === 'PER_DEVICE' ? 'Per workstation' : ucwords(strtolower(str_replace('_', ' ', (string)$bundle['pricing_model']))); ?>
                            <div class="bundle-catalog-meta"><?= accounting_h($pricingLabel) ?> · <?= accounting_h((string)$bundle['default_billing_cycle']) ?></div>
                        </td>
                        <td class="contents-cell">
                            <?php $bundlePreview = $bundlePayload[(int)$bundle['bundle_id']] ?? null; ?>
                            <div class="bundle-catalog-contents">
                                <strong><?= (int)$bundle['bundle_item_count'] ?> mapped item<?= (int)$bundle['bundle_item_count'] === 1 ? '' : 's' ?></strong>
                                <?php if ($bundlePreview): ?>
                                    <span><?= count((array)$bundlePreview['included_items']) ?> included · <?= count((array)$bundlePreview['addon_items']) ?> optional</span>
                                <?php else: ?>
                                    <span>Bundle contents ready for the contract builder.</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<details class="card products-admin-details" <?= $bundleEditing ? 'open' : '' ?>>
    <summary>Advanced bundle planner</summary>
    <div class="bundle-planner-card" style="padding-top:16px;">

    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
        <div>
            <h2 style="margin:0;font-size:20px;">Bundle Planner Workspace</h2>
            <div style="opacity:.75;margin-top:4px;">Keep this tucked away unless you are actively changing a live plan. Most days the Bundle Catalog above is all you need.</div>
        </div>
        <a href="<?= accounting_h(BASE_URL) ?>/contracts/index.php" style="color:#dbeafe;white-space:nowrap;">Open contract builder →</a>
    </div>

    <?php if (!accounting_bundle_catalog_ready()): ?>
        <div style="padding:12px 14px;border-radius:12px;border:1px solid rgba(245,158,11,.25);background:rgba(120,53,15,.26);">
            Run the new service catalog engine migration to enable bundle plans and add-ons.
        </div>
    <?php else: ?>
        <div class="bundle-planner-grid">
            <div>
                <div style="font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.75;margin-bottom:10px;">Bundle Plans</div>
                <div id="bundle-selector" class="bundle-selector-grid">
                    <?php if (!$bundleRows): ?>
                        <div style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);opacity:.75;">
                            No bundle plans yet. Create your first plan in the editor.
                        </div>
                    <?php else: ?>
                        <?php foreach ($bundleRows as $bundle): ?>
                            <?php $bundleId = (int)$bundle['bundle_id']; ?>
                            <button
                                type="button"
                                class="bundle-select-btn"
                                data-bundle-id="<?= $bundleId ?>"
                                style="text-align:left;padding:14px;border-radius:14px;border:1px solid <?= $selectedBundleId === $bundleId ? 'rgba(59,130,246,.55)' : 'rgba(255,255,255,.10)' ?>;background:<?= $selectedBundleId === $bundleId ? 'rgba(59,130,246,.16)' : 'rgba(255,255,255,.04)' ?>;color:#e8eefc;cursor:pointer;"
                            >
                                <div style="display:flex;justify-content:space-between;gap:10px;align-items:flex-start;">
                                    <div>
                                        <div style="font-weight:800;"><?= accounting_h((string)$bundle['bundle_name']) ?></div>
                                        <div style="opacity:.65;font-size:12px;margin-top:3px;"><?= accounting_h((string)$bundle['bundle_code']) ?></div>
                                    </div>
                                    <div style="text-align:right;">
                                        <div style="font-weight:800;">$<?= number_format((float)$bundle['default_unit_price'], 2) ?></div>
                                        <div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$bundle['pricing_model']) ?></div>
                                    </div>
                                </div>
                                <div style="margin-top:8px;opacity:.78;font-size:12px;">
                                    <?= (int)$bundle['bundle_item_count'] ?> linked item<?= (int)$bundle['bundle_item_count'] === 1 ? '' : 's' ?> · <?= !empty($bundle['is_active']) ? 'Active' : 'Inactive' ?>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    <?php endif; ?>

                    <button
                        type="button"
                        id="new-bundle-btn"
                        style="text-align:center;padding:12px;border-radius:14px;border:1px dashed rgba(255,255,255,.18);background:rgba(255,255,255,.03);color:#e8eefc;cursor:pointer;"
                    >
                        + New bundle plan
                    </button>
                </div>
            </div>

            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
                    <div style="font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.75;">Bundle Editor</div>
                    <div style="opacity:.65;font-size:12px;">Base item = billable plan line. Included = bundled services. Add-ons = optional extras.</div>
                </div>

                <form method="post" id="bundle-form" class="bundle-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="form_name" value="bundle">
                    <input type="hidden" name="bundle_id" id="bundle-id-input" value="<?= (int)($bundleEditing['bundle_id'] ?? 0) ?>">

                    <div class="two-col">
                        <div>
                            <label>Bundle code</label>
                            <input type="text" name="bundle_code" id="bundle-code-input" value="<?= accounting_h((string)$bundleForm['bundle_code']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label>Pricing model</label>
                            <select name="pricing_model" id="pricing-model-input" style="width:100%;padding:10px;box-sizing:border-box;">
                                <?php foreach (accounting_get_pricing_models() as $model): ?>
                                    <option value="<?= accounting_h($model) ?>" <?= ((string)$bundleForm['pricing_model'] === $model) ? 'selected' : '' ?>><?= accounting_h($model) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label>Bundle name</label>
                        <input type="text" name="bundle_name" id="bundle-name-input" value="<?= accounting_h((string)$bundleForm['bundle_name']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                    </div>

                    <div>
                        <label>Description</label>
                        <textarea name="description" id="bundle-description-input" rows="3" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)$bundleForm['description']) ?></textarea>
                    </div>

                    <div class="two-col">
                        <div>
                            <label>Base catalog item</label>
                            <select name="base_item_id" id="base-item-input" style="width:100%;padding:10px;box-sizing:border-box;">
                                <option value="0">Select base item</option>
                                <?php foreach ($baseItems as $item): ?>
                                    <option value="<?= (int)$item['item_id'] ?>" <?= ((int)$bundleForm['base_item_id'] === (int)$item['item_id']) ? 'selected' : '' ?>>
                                        <?= accounting_h((string)$item['item_name']) ?><?= !empty($item['item_code']) ? ' · ' . accounting_h((string)$item['item_code']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div style="opacity:.65;font-size:12px;margin-top:6px;">Only Bundle Base services appear here.</div>
                        </div>
                        <div>
                            <label>Revenue account</label>
                            <select name="revenue_account_id" id="revenue-account-input" style="width:100%;padding:10px;box-sizing:border-box;">
                                <option value="0">Select revenue account</option>
                                <?php foreach ($revenueAccounts as $account): ?>
                                    <option value="<?= (int)$account['account_id'] ?>" <?= ((int)$bundleForm['revenue_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>>
                                        <?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="three-col">
                        <div>
                            <label>Default unit price</label>
                            <input type="number" step="0.01" min="0" name="default_unit_price" id="bundle-price-input" value="<?= accounting_h((string)$bundleForm['default_unit_price']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label>Billing cycle</label>
                            <select name="default_billing_cycle" id="bundle-cycle-input" style="width:100%;padding:10px;box-sizing:border-box;">
                                <?php foreach (accounting_get_recurring_cycles() as $cycle): ?>
                                    <option value="<?= accounting_h($cycle) ?>" <?= ((string)$bundleForm['default_billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Default term</label>
                            <select name="term_months" id="bundle-term-input" style="width:100%;padding:10px;box-sizing:border-box;">
                                <?php foreach (accounting_get_term_options() as $months => $label): ?>
                                    <option value="<?= (int)$months ?>" <?= ((int)$bundleForm['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="two-col">
                        <div>
                            <label>Included services</label>
                            <select name="included_item_ids[]" id="included-select" multiple size="12" style="width:100%;padding:10px;box-sizing:border-box;">
                                <?php foreach ($includedGroups as $groupName => $rows): ?>
                                    <optgroup label="<?= accounting_h($groupName) ?>">
                                        <?php foreach ($rows as $item): ?>
                                            <option value="<?= (int)$item['item_id'] ?>" <?= in_array((int)$item['item_id'], array_map('intval', (array)$bundleForm['included_item_ids']), true) ? 'selected' : '' ?>>
                                                <?= accounting_h((string)$item['item_name']) ?><?= !empty($item['item_code']) ? ' · ' . accounting_h((string)$item['item_code']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div style="opacity:.65;font-size:12px;margin-top:6px;">Bundle-eligible recurring services only.</div>
                        </div>

                        <div>
                            <label>Optional add-ons</label>
                            <select name="addon_item_ids[]" id="addon-select" multiple size="12" style="width:100%;padding:10px;box-sizing:border-box;">
                                <?php foreach ($addonGroups as $groupName => $rows): ?>
                                    <optgroup label="<?= accounting_h($groupName) ?>">
                                        <?php foreach ($rows as $item): ?>
                                            <option value="<?= (int)$item['item_id'] ?>" <?= in_array((int)$item['item_id'], array_map('intval', (array)$bundleForm['addon_item_ids']), true) ? 'selected' : '' ?>>
                                                <?= accounting_h((string)$item['item_name']) ?><?= !empty($item['item_code']) ? ' · ' . accounting_h((string)$item['item_code']) : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div style="opacity:.65;font-size:12px;margin-top:6px;">Add-on eligible services only.</div>
                        </div>
                    </div>

                    <div class="toggle-row">
                        <label><input type="checkbox" name="is_taxable" id="bundle-taxable-input" value="1" <?= !empty($bundleForm['is_taxable']) ? 'checked' : '' ?>> Taxable</label>
                        <label><input type="checkbox" name="is_active" id="bundle-active-input" value="1" <?= !empty($bundleForm['is_active']) ? 'checked' : '' ?>> Active</label>
                    </div>

                    <div class="action-row">
                        <button type="submit" id="bundle-submit-btn" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;">
                            <?= $bundleEditing ? 'Update bundle' : 'Save bundle' ?>
                        </button>
                        <button type="button" id="bundle-reset-btn" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.04);color:#e8eefc;cursor:pointer;">
                            Clear / Reset
                        </button>
                        <?php if ($bundleEditing): ?>
                            <a href="<?= accounting_h(BASE_URL) ?>/products/index.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.14);color:#e8eefc;text-decoration:none;">Cancel edit</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div>
                <div style="font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;opacity:.75;margin-bottom:10px;">Live Preview</div>
                <div id="bundle-preview-card" class="bundle-preview-card" style="border-radius:16px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);">
                    <div id="bundle-preview-name" style="font-size:20px;font-weight:800;margin-bottom:6px;">
                        <?= accounting_h((string)($selectedBundlePayload['bundle_name'] ?? 'New Bundle Plan')) ?>
                    </div>
                    <div id="bundle-preview-meta" style="opacity:.75;margin-bottom:14px;">
                        <?php if ($selectedBundlePayload): ?>
                            <?= accounting_h((string)$selectedBundlePayload['pricing_model']) ?> ·
                            $<?= number_format((float)$selectedBundlePayload['default_unit_price'], 2) ?> ·
                            <?= accounting_h((string)$selectedBundlePayload['default_billing_cycle']) ?>
                        <?php else: ?>
                            Select a bundle or start a new one.
                        <?php endif; ?>
                    </div>

                    <div id="bundle-preview-base" style="margin-bottom:16px;padding:12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);">
                        <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:6px;">Base Plan Item</div>
                        <div style="font-weight:700;">
                            <?= accounting_h((string)($selectedBundlePayload['base_item_name'] ?? 'No base item selected')) ?>
                        </div>
                    </div>

                    <div style="display:grid;gap:14px;">
                        <div>
                            <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Included in this bundle</div>
                            <div id="bundle-preview-included">
                                <?php if (!empty($selectedBundlePayload['included_items'])): ?>
                                    <?php foreach ($selectedBundlePayload['included_items'] as $item): ?>
                                        <div class="bundle-preview-pill" style="background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.16);">
                                            <?= accounting_h((string)$item['item_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="opacity:.65;">No included services selected yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Optional add-ons</div>
                            <div id="bundle-preview-addons">
                                <?php if (!empty($selectedBundlePayload['addon_items'])): ?>
                                    <?php foreach ($selectedBundlePayload['addon_items'] as $item): ?>
                                        <div class="bundle-preview-pill" style="background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.16);">
                                            <?= accounting_h((string)$item['item_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="opacity:.65;">No optional add-ons selected yet. Use add-ons for backup, email security, Microsoft 365 admin, or servers.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Suggested upgrade path</div>
                            <div id="bundle-preview-upgrade" style="padding:12px;border-radius:12px;background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.16);">
                                <?php if (count($bundleOrder) > 1): ?>
                                    Choose a bundle to view the next-tier suggestion.
                                <?php else: ?>
                                    Add more than one bundle plan to enable upgrade suggestions.
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
    </div>
</details>

<details class="card" style="padding:16px;margin-bottom:16px;">
    <summary style="cursor:pointer;font-weight:800;font-size:18px;">Catalog item editor</summary>
    <div style="display:grid;grid-template-columns:1.05fr 1.95fr;gap:16px;align-items:start;margin-top:16px;">
        <div class="card" style="padding:16px;">
            <h2 style="margin:0 0 12px;font-size:18px;"><?= $editing ? 'Edit catalog item' : 'New catalog item' ?></h2>
            <form method="post" style="display:grid;gap:12px;">
                <?= csrf_field() ?>
                <input type="hidden" name="form_name" value="catalog_item">
                <input type="hidden" name="item_id" value="<?= (int)($editing['item_id'] ?? 0) ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label>Item code</label><br>
                        <input type="text" name="item_code" value="<?= accounting_h((string)$form['item_code']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label>Type</label><br>
                        <select name="item_type" style="width:100%;padding:10px;box-sizing:border-box;">
                            <?php foreach (accounting_get_catalog_item_types() as $type): ?>
                                <option value="<?= accounting_h($type) ?>" <?= ((string)$form['item_type'] === $type) ? 'selected' : '' ?>><?= accounting_h($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label>Item name</label><br>
                    <input type="text" name="item_name" value="<?= accounting_h((string)$form['item_name']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                </div>

                <div>
                    <label>Service category</label><br>
                    <select name="category_id" style="width:100%;padding:10px;box-sizing:border-box;">
                        <option value="0">Select category</option>
                        <?php foreach ($categoryGroups as $groupName => $groupRows): ?>
                            <optgroup label="<?= accounting_h($groupName) ?>">
                                <?php foreach ($groupRows as $category): ?>
                                    <option value="<?= (int)$category['category_id'] ?>" <?= ((int)$form['category_id'] === (int)$category['category_id']) ? 'selected' : '' ?>>
                                        <?= accounting_h((string)$category['category_name']) ?><?= !empty($category['category_code']) ? ' · ' . accounting_h((string)$category['category_code']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Billing mode</label><br>
                    <select name="billing_mode" id="billing-mode" style="width:100%;padding:10px;box-sizing:border-box;">
                        <?php foreach (accounting_get_catalog_billing_modes() as $mode): ?>
                            <option value="<?= accounting_h($mode) ?>" <?= ((string)$form['billing_mode'] === $mode) ? 'selected' : '' ?>><?= accounting_h($mode) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Description</label><br>
                    <textarea name="description" rows="3" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)$form['description']) ?></textarea>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label>Default unit price</label><br>
                        <input type="number" step="0.01" min="0" name="default_unit_price" value="<?= accounting_h((string)$form['default_unit_price']) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
                    </div>
                    <div>
                        <label>Revenue account</label><br>
                        <select name="revenue_account_id" style="width:100%;padding:10px;box-sizing:border-box;">
                            <option value="0">Select revenue account</option>
                            <?php foreach ($revenueAccounts as $account): ?>
                                <option value="<?= (int)$account['account_id'] ?>" <?= ((int)$form['revenue_account_id'] === (int)$account['account_id']) ? 'selected' : '' ?>>
                                    <?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="recurring-defaults" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div>
                        <label>Default billing cycle</label><br>
                        <select name="default_billing_cycle" id="default-billing-cycle" style="width:100%;padding:10px;box-sizing:border-box;">
                            <?php foreach (accounting_get_recurring_cycles() as $cycle): ?>
                                <option value="<?= accounting_h($cycle) ?>" <?= ((string)$form['default_billing_cycle'] === $cycle) ? 'selected' : '' ?>><?= accounting_h($cycle) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Default term</label><br>
                        <select name="term_months" id="catalog-term-months" style="width:100%;padding:10px;box-sizing:border-box;">
                            <?php foreach (accounting_get_term_options() as $months => $label): ?>
                                <option value="<?= (int)$months ?>" <?= ((int)$form['term_months'] === (int)$months) ? 'selected' : '' ?>><?= accounting_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex;gap:18px;flex-wrap:wrap;">
                    <label><input type="checkbox" name="is_taxable" value="1" <?= !empty($form['is_taxable']) ? 'checked' : '' ?>> Taxable</label>
                    <label><input type="checkbox" name="is_active" value="1" <?= !empty($form['is_active']) ? 'checked' : '' ?>> Active</label>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;"><?= $editing ? 'Update item' : 'Save item' ?></button>
                    <?php if ($editing): ?>
                        <a href="<?= accounting_h(BASE_URL) ?>/products/index.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.14);color:#e8eefc;text-decoration:none;">Cancel edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="card" style="padding:16px;overflow:auto;">
            <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
                <div>
                    <h2 style="margin:0;font-size:18px;">Catalog items</h2>
                    <div style="opacity:.75;">Service categories drive bundle-base, included-service, and add-on filtering for your Manage, Protect, and Govern stack.</div>
                </div>
            </div>

            <table style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
                        <th style="padding:10px 8px;">Item</th>
                        <th style="padding:10px 8px;">Category</th>
                        <th style="padding:10px 8px;">Billing</th>
                        <th class="money" style="padding:10px 8px;">Price</th>
                        <th style="padding:10px 8px;">Revenue</th>
                        <th style="padding:10px 8px;text-align:right;">Recurring</th>
                        <th style="padding:10px 8px;">Open</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$items): ?>
                    <tr><td colspan="7" style="padding:18px 8px;opacity:.75;">No catalog items yet. Create your first billable service on the left.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
                            <td style="padding:10px 8px;">
                                <strong><?= accounting_h((string)$item['item_name']) ?></strong>
                                <div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($item['item_code'] ?? '—')) ?> · <?= accounting_h((string)$item['item_type']) ?></div>
                            </td>
                            <td style="padding:10px 8px;"><?= accounting_service_category_badge_html((string)($item['category_name'] ?? ''), (string)($item['category_code'] ?? '')) ?></td>
                            <td style="padding:10px 8px;"><?= accounting_h((string)($item['billing_mode'] ?? '—')) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($item['default_billing_cycle'] ?? '')) ?></div></td>
                            <td class="money" style="padding:10px 8px;">$<?= number_format((float)$item['default_unit_price'], 2) ?></td>
                            <td style="padding:10px 8px;"><?= accounting_h((string)($item['account_code'] ?? '—')) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($item['account_name'] ?? 'No revenue account')) ?></div></td>
                            <td style="padding:10px 8px;text-align:right;"><?= (int)$item['recurring_count'] ?></td>
                            <td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/products/index.php?item_id=<?= (int)$item['item_id'] ?>" style="color:#dbeafe;">Edit</a></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</details>

<script>
const bundleData = <?= json_encode($bundlePayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const bundleOrder = <?= json_encode($bundleOrder) ?>;
const baseItemLookup = <?= json_encode($baseItemLookup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const includedLookup = <?= json_encode($includedLookup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const addonLookup = <?= json_encode($addonLookup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const bundleDefaults = <?= json_encode($bundleDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const billingMode = document.getElementById('billing-mode');
const recurringDefaults = document.getElementById('recurring-defaults');
const defaultBillingCycle = document.getElementById('default-billing-cycle');
const catalogTermMonths = document.getElementById('catalog-term-months');

function syncBillingMode() {
    if (!billingMode || !recurringDefaults) return;
    const isRecurring = billingMode.value === 'RECURRING';
    recurringDefaults.style.display = isRecurring ? 'grid' : 'none';
    if (defaultBillingCycle) defaultBillingCycle.disabled = !isRecurring;
    if (catalogTermMonths) catalogTermMonths.disabled = !isRecurring;

    if (!isRecurring) {
        if (defaultBillingCycle) defaultBillingCycle.value = '';
        if (catalogTermMonths) catalogTermMonths.value = '0';
    } else if (defaultBillingCycle && defaultBillingCycle.value === '') {
        defaultBillingCycle.value = 'MONTHLY';
    }
}
if (billingMode) {
    billingMode.addEventListener('change', syncBillingMode);
    syncBillingMode();
}

const bundleForm = document.getElementById('bundle-form');
const bundleButtons = document.querySelectorAll('.bundle-select-btn');
const newBundleBtn = document.getElementById('new-bundle-btn');
const resetBundleBtn = document.getElementById('bundle-reset-btn');

const bundleIdInput = document.getElementById('bundle-id-input');
const bundleCodeInput = document.getElementById('bundle-code-input');
const bundleNameInput = document.getElementById('bundle-name-input');
const bundleDescriptionInput = document.getElementById('bundle-description-input');
const pricingModelInput = document.getElementById('pricing-model-input');
const bundlePriceInput = document.getElementById('bundle-price-input');
const bundleCycleInput = document.getElementById('bundle-cycle-input');
const bundleTermInput = document.getElementById('bundle-term-input');
const revenueAccountInput = document.getElementById('revenue-account-input');
const baseItemInput = document.getElementById('base-item-input');
const includedSelect = document.getElementById('included-select');
const addonSelect = document.getElementById('addon-select');
const bundleTaxableInput = document.getElementById('bundle-taxable-input');
const bundleActiveInput = document.getElementById('bundle-active-input');
const bundleSubmitBtn = document.getElementById('bundle-submit-btn');

const previewName = document.getElementById('bundle-preview-name');
const previewMeta = document.getElementById('bundle-preview-meta');
const previewBase = document.getElementById('bundle-preview-base');
const previewIncluded = document.getElementById('bundle-preview-included');
const previewAddons = document.getElementById('bundle-preview-addons');
const previewUpgrade = document.getElementById('bundle-preview-upgrade');

function setSelectedBundleButton(bundleId) {
    bundleButtons.forEach((btn) => {
        const active = String(btn.dataset.bundleId) === String(bundleId);
        btn.style.border = active ? '1px solid rgba(59,130,246,.55)' : '1px solid rgba(255,255,255,.10)';
        btn.style.background = active ? 'rgba(59,130,246,.16)' : 'rgba(255,255,255,.04)';
    });
}

function setMultiSelectValues(selectEl, values) {
    if (!selectEl) return;
    const wanted = new Set((values || []).map(String));
    Array.from(selectEl.options).forEach((opt) => {
        opt.selected = wanted.has(String(opt.value));
    });
}

function getSelectedValues(selectEl) {
    return Array.from(selectEl.selectedOptions).map((opt) => parseInt(opt.value, 10)).filter(Boolean);
}

function renderPillList(container, items, emptyText, bg, border) {
    if (!container) return;
    if (!items.length) {
        container.innerHTML = `<div style="opacity:.65;">${emptyText}</div>`;
        return;
    }
    container.innerHTML = items.map((item) => {
        const name = escapeHtml(item.item_name || '');
        return `<div style="padding:8px 10px;border-radius:10px;background:${bg};border:1px solid ${border};margin-bottom:8px;">${name}</div>`;
    }).join('');
}

function getNextTierBundle(currentBundleId) {
    const idx = bundleOrder.findIndex((id) => String(id) === String(currentBundleId));
    if (idx === -1) return null;
    const nextId = bundleOrder[idx + 1] || null;
    if (!nextId) return null;
    return bundleData[nextId] || null;
}

function renderBundlePreviewFromForm() {
    const bundleName = (bundleNameInput?.value || '').trim() || 'New Bundle Plan';
    const pricingModel = pricingModelInput?.value || 'PER_DEVICE';
    const unitPrice = parseFloat(bundlePriceInput?.value || '0') || 0;
    const cycle = bundleCycleInput?.value || 'MONTHLY';
    const baseId = parseInt(baseItemInput?.value || '0', 10);
    const currentBundleId = parseInt(bundleIdInput?.value || '0', 10);

    const includedItems = getSelectedValues(includedSelect).map((id) => includedLookup[id]).filter(Boolean);
    const addonItems = getSelectedValues(addonSelect).map((id) => addonLookup[id]).filter(Boolean);
    const nextTier = getNextTierBundle(currentBundleId);

    previewName.textContent = bundleName;
    previewMeta.textContent = `${pricingModel} · $${unitPrice.toFixed(2)} · ${cycle}`;
    previewBase.innerHTML = `
        <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:6px;">Base Plan Item</div>
        <div style="font-weight:700;">${escapeHtml(baseItemLookup[baseId]?.item_name || 'No base item selected')}</div>
    `;

    renderPillList(previewIncluded, includedItems, 'No included services selected yet.', 'rgba(34,197,94,.08)', 'rgba(34,197,94,.16)');
    renderPillList(previewAddons, addonItems, 'No optional add-ons selected yet. Use add-ons for backup, email security, Microsoft 365 admin, or servers.', 'rgba(59,130,246,.08)', 'rgba(59,130,246,.16)');

    if (nextTier) {
        previewUpgrade.innerHTML = `
            <div style="font-weight:700;margin-bottom:6px;">Upgrade path: ${escapeHtml(nextTier.bundle_name)}</div>
            <div style="opacity:.82;font-size:13px;">${escapeHtml(nextTier.description || 'Next tier bundle available.')}</div>
        `;
    } else if (bundleOrder.length > 1) {
        previewUpgrade.innerHTML = 'Top tier selected. No higher bundle available.';
    } else {
        previewUpgrade.innerHTML = 'Add more than one bundle plan to enable upgrade suggestions.';
    }
}

function fillBundleForm(bundle) {
    if (!bundle) return;
    bundleIdInput.value = bundle.bundle_id || 0;
    bundleCodeInput.value = bundle.bundle_code || '';
    bundleNameInput.value = bundle.bundle_name || '';
    bundleDescriptionInput.value = bundle.description || '';
    pricingModelInput.value = bundle.pricing_model || 'PER_DEVICE';
    bundlePriceInput.value = bundle.default_unit_price || '0.00';
    bundleCycleInput.value = bundle.default_billing_cycle || 'MONTHLY';
    bundleTermInput.value = bundle.term_months || '12';
    revenueAccountInput.value = bundle.revenue_account_id || '0';
    baseItemInput.value = bundle.base_item_id || '0';
    bundleTaxableInput.checked = String(bundle.is_taxable || '0') === '1';
    bundleActiveInput.checked = String(bundle.is_active || '0') === '1';
    setMultiSelectValues(includedSelect, bundle.included_item_ids || []);
    setMultiSelectValues(addonSelect, bundle.addon_item_ids || []);
    bundleSubmitBtn.textContent = bundle.bundle_id ? 'Update bundle' : 'Save bundle';
    setSelectedBundleButton(bundle.bundle_id || 0);
    renderBundlePreviewFromForm();
}

function clearBundleForm() {
    bundleIdInput.value = 0;
    bundleCodeInput.value = bundleDefaults.bundle_code || '';
    bundleNameInput.value = bundleDefaults.bundle_name || '';
    bundleDescriptionInput.value = bundleDefaults.description || '';
    pricingModelInput.value = bundleDefaults.pricing_model || 'PER_DEVICE';
    bundlePriceInput.value = bundleDefaults.default_unit_price || '0.00';
    bundleCycleInput.value = bundleDefaults.default_billing_cycle || 'MONTHLY';
    bundleTermInput.value = bundleDefaults.term_months || '12';
    revenueAccountInput.value = bundleDefaults.revenue_account_id || '0';
    baseItemInput.value = bundleDefaults.base_item_id || '0';
    bundleTaxableInput.checked = String(bundleDefaults.is_taxable || '0') === '1';
    bundleActiveInput.checked = String(bundleDefaults.is_active || '1') === '1';
    setMultiSelectValues(includedSelect, []);
    setMultiSelectValues(addonSelect, []);
    bundleSubmitBtn.textContent = 'Save bundle';
    setSelectedBundleButton(0);
    renderBundlePreviewFromForm();
}

function escapeHtml(str) {
    return String(str)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

bundleButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
        const bundleId = btn.dataset.bundleId;
        if (bundleData[bundleId]) {
            fillBundleForm(bundleData[bundleId]);
        }
    });
});

if (newBundleBtn) {
    newBundleBtn.addEventListener('click', clearBundleForm);
}
if (resetBundleBtn) {
    resetBundleBtn.addEventListener('click', clearBundleForm);
}

[
    bundleCodeInput,
    bundleNameInput,
    bundleDescriptionInput,
    pricingModelInput,
    bundlePriceInput,
    bundleCycleInput,
    bundleTermInput,
    revenueAccountInput,
    baseItemInput,
    includedSelect,
    addonSelect,
    bundleTaxableInput,
    bundleActiveInput
].forEach((el) => {
    if (!el) return;
    el.addEventListener('change', renderBundlePreviewFromForm);
    if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA') {
        el.addEventListener('input', renderBundlePreviewFromForm);
    }
});

<?php if ($selectedBundlePayload): ?>
fillBundleForm(bundleData[<?= (int)$selectedBundleId ?>]);
<?php else: ?>
clearBundleForm();
<?php endif; ?>
</script>

<?php page_footer(); ?>