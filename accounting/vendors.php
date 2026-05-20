<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_login();
accounting_require_ready();

csrf_check();
$message = null;
$errors = [];
$editVendor = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save_vendor');
    if ($action === 'delete_vendor') {
        $vendorId = (int)($_POST['vendor_id'] ?? 0);
        $result = accounting_remove_vendor($vendorId);
        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
        } else {
            $errors = $result['errors'] ?? ['Unable to remove vendor.'];
        }
    } else {
        $vendorId = isset($_POST['vendor_id']) && $_POST['vendor_id'] !== '' ? (int)$_POST['vendor_id'] : null;
        $result = accounting_save_vendor($_POST, $vendorId);
        if (!empty($result['ok'])) {
            $message = (string)$result['message'];
        } else {
            $errors = $result['errors'] ?? ['Unable to save vendor.'];
            if ($vendorId) {
                $editVendor = array_merge(['vendor_id' => $vendorId], $_POST);
            }
        }
    }
}

if (!$editVendor && isset($_GET['edit'])) {
    $editVendor = accounting_get_vendor((int)$_GET['edit']);
}

$vendors = accounting_list_vendors();
$expenseAccounts = accounting_account_options(['EXPENSE']);

page_header('Vendors', 'accounting');
accounting_subnav('vendors');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1.1fr 2fr;gap:16px;align-items:start;">
  <div class="card" style="padding:16px;">
    <h2 style="margin:0 0 12px;font-size:18px;"><?= $editVendor ? 'Edit vendor' : 'Add vendor' ?></h2>
    <form method="post" style="display:grid;gap:12px;">
      <input type="hidden" name="action" value="save_vendor">
      <?= csrf_field() ?>
      <?php if ($editVendor && !empty($editVendor['vendor_id'])): ?><input type="hidden" name="vendor_id" value="<?= (int)$editVendor['vendor_id'] ?>"><?php endif; ?>
      <div><label>Vendor name</label><br><input type="text" name="vendor_name" value="<?= accounting_h((string)($editVendor['vendor_name'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Vendor code</label><br><input type="text" name="vendor_code" value="<?= accounting_h((string)($editVendor['vendor_code'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Contact name</label><br><input type="text" name="contact_name" value="<?= accounting_h((string)($editVendor['contact_name'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Email</label><br><input type="email" name="email" value="<?= accounting_h((string)($editVendor['email'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Phone</label><br><input type="text" name="phone" value="<?= accounting_h((string)($editVendor['phone'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Website</label><br><input type="text" name="website" value="<?= accounting_h((string)($editVendor['website'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div><label>Payment terms</label><br><input type="text" name="payment_terms" value="<?= accounting_h((string)($editVendor['payment_terms'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;"></div>
      <div>
        <label>Default expense category</label><br>
        <select name="default_expense_account_id" style="width:100%;padding:10px;box-sizing:border-box;">
          <option value="0">None</option>
          <?php foreach ($expenseAccounts as $account): ?>
            <option value="<?= (int)$account['account_id'] ?>" <?= ((int)($editVendor['default_expense_account_id'] ?? 0) === (int)$account['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$account['account_code']) ?> · <?= accounting_h((string)$account['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div><label>Notes</label><br><textarea name="notes" rows="4" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($editVendor['notes'] ?? '')) ?></textarea></div>
      <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="is_active" value="1" <?= !isset($editVendor['is_active']) || !empty($editVendor['is_active']) ? 'checked' : '' ?>> Active</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;"><?= $editVendor ? 'Save changes' : 'Create vendor' ?></button>
        <?php if ($editVendor): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/vendors.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:#e8eefc;">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:18px;">Vendor directory</h2>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
      <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
        <th style="padding:10px 8px;">Vendor</th>
        <th style="padding:10px 8px;">Contact</th>
        <th style="padding:10px 8px;">Default category</th>
        <th style="padding:10px 8px;">Expenses</th>
        <th style="padding:10px 8px;">Status</th>
        <th style="padding:10px 8px;">Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$vendors): ?>
        <tr><td colspan="6" style="padding:18px 8px;opacity:.75;">No vendors found yet.</td></tr>
      <?php else: ?>
        <?php foreach ($vendors as $vendor): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;"><?= accounting_h((string)$vendor['vendor_name']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($vendor['vendor_code'] ?? '')) ?></div></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)($vendor['contact_name'] ?? '')) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($vendor['email'] ?? '')) ?></div></td>
            <td style="padding:10px 8px;"><?= !empty($vendor['account_code']) ? accounting_h((string)$vendor['account_code'] . ' · ' . (string)$vendor['account_name']) : '—' ?></td>
            <td style="padding:10px 8px;"><?= (int)$vendor['expense_count'] ?></td>
            <td style="padding:10px 8px;"><?= !empty($vendor['is_active']) ? 'Active' : 'Inactive' ?></td>
            <td style="padding:10px 8px;"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/vendors.php?edit=<?= (int)$vendor['vendor_id'] ?>">Edit</a><form method="post" style="display:inline;margin:0;" onsubmit="return confirm('<?= (int)$vendor['expense_count'] > 0 ? 'This vendor has expense history and will be deactivated. Continue?' : 'Delete this vendor?' ?>');"><?= csrf_field() ?><input type="hidden" name="action" value="delete_vendor"><input type="hidden" name="vendor_id" value="<?= (int)$vendor['vendor_id'] ?>"><button type="submit" style="background:none;border:none;padding:0;color:#fda4af;cursor:pointer;"><?= (int)$vendor['expense_count'] > 0 ? 'Deactivate' : 'Delete' ?></button></form></div></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
