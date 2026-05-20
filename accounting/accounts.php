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
$editAccount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accountId = isset($_POST['account_id']) && $_POST['account_id'] !== '' ? (int)$_POST['account_id'] : null;
    $result = accounting_save_account($_POST, $accountId);
    if (!empty($result['ok'])) {
        $message = (string)$result['message'];
    } else {
        $errors = $result['errors'] ?? ['Unable to save account.'];
        if ($accountId) {
            $editAccount = array_merge(['account_id' => $accountId], $_POST);
        }
    }
}

if (!$editAccount && isset($_GET['edit'])) {
    $editAccount = accounting_get_account((int)$_GET['edit']);
}

$typeFilter = isset($_GET['type']) ? strtoupper(trim((string)$_GET['type'])) : '';
$accounts = accounting_list_accounts($typeFilter !== '' ? $typeFilter : null);
$parentOptions = accounting_list_accounts();

page_header('Chart of Accounts', 'accounting');
accounting_subnav('accounts');
?>
<?php if ($message): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1.2fr 2fr;gap:16px;align-items:start;">
  <div class="card" style="padding:16px;">
    <h2 style="margin:0 0 12px;font-size:18px;"><?= $editAccount ? 'Edit account' : 'Add account' ?></h2>
    <form method="post" style="display:grid;gap:12px;">
      <?= csrf_field() ?>
      <?php if ($editAccount && !empty($editAccount['account_id'])): ?>
        <input type="hidden" name="account_id" value="<?= (int)$editAccount['account_id'] ?>">
      <?php endif; ?>
      <div>
        <label>Account code</label><br>
        <input type="text" name="account_code" value="<?= accounting_h((string)($editAccount['account_code'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
      </div>
      <div>
        <label>Account name</label><br>
        <input type="text" name="account_name" value="<?= accounting_h((string)($editAccount['account_name'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
      </div>
      <div>
        <label>Type</label><br>
        <select name="account_type" style="width:100%;padding:10px;box-sizing:border-box;">
          <option value="">Select type</option>
          <?php foreach (accounting_get_account_types() as $type): ?>
            <option value="<?= accounting_h($type) ?>" <?= (($editAccount['account_type'] ?? '') === $type) ? 'selected' : '' ?>><?= accounting_h($type) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Detail type</label><br>
        <input type="text" name="detail_type" value="<?= accounting_h((string)($editAccount['detail_type'] ?? '')) ?>" style="width:100%;padding:10px;box-sizing:border-box;">
      </div>
      <div>
        <label>Parent account</label><br>
        <select name="parent_account_id" style="width:100%;padding:10px;box-sizing:border-box;">
          <option value="0">None</option>
          <?php foreach ($parentOptions as $parent): ?>
            <option value="<?= (int)$parent['account_id'] ?>" <?= ((int)($editAccount['parent_account_id'] ?? 0) === (int)$parent['account_id']) ? 'selected' : '' ?>><?= accounting_h((string)$parent['account_code']) ?> · <?= accounting_h((string)$parent['account_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Description</label><br>
        <textarea name="description" rows="4" style="width:100%;padding:10px;box-sizing:border-box;"><?= accounting_h((string)($editAccount['description'] ?? '')) ?></textarea>
      </div>
      <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="is_active" value="1" <?= !isset($editAccount['is_active']) || !empty($editAccount['is_active']) ? 'checked' : '' ?>> Active</label>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;"><?= $editAccount ? 'Save changes' : 'Create account' ?></button>
        <?php if ($editAccount): ?><a href="<?= accounting_h(BASE_URL) ?>/accounting/accounts.php" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.16);text-decoration:none;color:#e8eefc;">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px;flex-wrap:wrap;">
      <div>
        <h2 style="margin:0;font-size:18px;">All accounts</h2>
        <div style="opacity:.72;font-size:13px;">Use the chart below as the backbone for expenses, invoices, and reporting.</div>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/accounts.php" style="color:#dbeafe;<?= $typeFilter === '' ? 'font-weight:700;' : '' ?>">All</a>
        <?php foreach (accounting_get_account_types() as $type): ?>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/accounts.php?type=<?= urlencode($type) ?>" style="color:#dbeafe;<?= $typeFilter === $type ? 'font-weight:700;' : '' ?>"><?= accounting_h($type) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
      <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
        <th style="padding:10px 8px;">Code</th>
        <th style="padding:10px 8px;">Name</th>
        <th style="padding:10px 8px;">Type</th>
        <th style="padding:10px 8px;">Parent</th>
        <th style="padding:10px 8px;">Status</th>
        <th style="padding:10px 8px;">System</th>
        <th style="padding:10px 8px;">Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$accounts): ?>
        <tr><td colspan="7" style="padding:18px 8px;opacity:.75;">No accounts found.</td></tr>
      <?php else: ?>
        <?php foreach ($accounts as $account): ?>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:10px 8px;"><?= accounting_h((string)$account['account_code']) ?></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)$account['account_name']) ?><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($account['detail_type'] ?? '')) ?></div></td>
          <td style="padding:10px 8px;"><?= accounting_h((string)$account['account_type']) ?></td>
          <td style="padding:10px 8px;"><?= !empty($account['parent_code']) ? accounting_h((string)$account['parent_code'] . ' · ' . (string)$account['parent_name']) : '—' ?></td>
          <td style="padding:10px 8px;"><?= !empty($account['is_active']) ? 'Active' : 'Inactive' ?></td>
          <td style="padding:10px 8px;"><?= !empty($account['is_system']) ? 'Yes' : 'No' ?></td>
          <td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/accounts.php?edit=<?= (int)$account['account_id'] ?>">Edit</a></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
