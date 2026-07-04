<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';
require_once __DIR__ . '/../inc/onedrive.php';

require_login();

field_ops_ensure_schema();

$h = 'field_ops_h';
$fmtMoney = static fn($value): string => '$' . number_format((float)$value, 2);
$fmtNum = static fn($value): string => number_format((float)$value, 2);
$moneyInput = static fn($value = 0): string => field_ops_money_input_value($value);
$dtInput = static fn($value = ''): string => field_ops_datetime_input_value($value);
$dtDisplay = static fn($value = ''): string => field_ops_datetime_display($value);

$workOrderId = (int)($_GET['id'] ?? $_POST['work_order_id'] ?? 0);
$wo = $workOrderId > 0 ? field_ops_find_work_order($workOrderId) : null;

if (!$wo) {
    http_response_code(404);
    echo 'Work order not found.';
    exit;
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'add_material') {
        $result = field_ops_add_material_pull($_POST);
    } elseif ($action === 'add_expense') {
        $result = field_ops_add_expense($_POST);
    } elseif ($action === 'add_time') {
        $result = field_ops_add_time_entry($_POST);
    } elseif ($action === 'update_state') {
        $result = field_ops_update_work_order_state($_POST);
    } elseif ($action === 'create_invoice') {
        $result = field_ops_create_draft_invoice_from_work_order($_POST, (int)((current_user()['user_id'] ?? 0)));
    } elseif ($action === 'upload_attachment') {
        $result = field_ops_upload_work_order_attachment($_POST, $_FILES['attachment_file'] ?? [], (int)((current_user()['user_id'] ?? 0)));
    } elseif ($action === 'delete_attachment') {
        $result = field_ops_delete_attachment((int)($_POST['attachment_id'] ?? 0));
    } elseif ($action === 'sync_attachment_onedrive') {
        $result = field_ops_sync_attachment_to_onedrive((int)($_POST['attachment_id'] ?? 0));
    } elseif ($action === 'sync_all_attachments_onedrive') {
        $result = field_ops_sync_work_order_attachments_to_onedrive($workOrderId);
    } elseif ($action === 'discard_work_order') {
        $result = field_ops_discard_work_order($workOrderId, (string)($_POST['delete_reason'] ?? 'Discarded from detail page.'));
    }

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = match ($action) {
            'add_material' => 'Material pulled and inventory updated.',
            'add_expense' => 'Expense added.',
            'add_time' => 'Time entry added.',
            'update_state' => 'Work order updated.',
            'create_invoice' => 'Draft invoice created from W/O.',
            'upload_attachment' => 'Attachment uploaded.',
            'delete_attachment' => 'Attachment removed.',
            'sync_attachment_onedrive' => 'Attachment synced to OneDrive.',
            'sync_all_attachments_onedrive' => 'W/O attachments synced to OneDrive.',
            'discard_work_order' => 'Work order discarded.',
            default => 'Saved.',
        };

        if ($action === 'discard_work_order') {
            header('Location: ' . BASE_URL . '/admin/field_ops.php');
            exit;
        }

        header('Location: ' . BASE_URL . '/admin/field_work_order.php?id=' . $workOrderId);
        exit;
    }

    $flashError = implode(' ', (array)($result['errors'] ?? ['Save failed.']));
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$wo = field_ops_find_work_order($workOrderId);
$materials = field_ops_work_order_materials($workOrderId);
$expenses = field_ops_work_order_expenses($workOrderId);
$timeEntries = field_ops_work_order_time_entries($workOrderId);
$items = field_ops_inventory_items();
$totals = field_ops_work_order_totals($workOrderId);
$attachments = field_ops_work_order_attachments($workOrderId);
$clients = field_ops_clients_for_invoice();
$linkedInvoice = field_ops_existing_invoice_for_work_order($workOrderId);
$onedriveStatus = onedrive_connection_status();
$canCreateInvoice = field_ops_can_invoice_work_order($wo);
$defaultRevenueAccountId = field_ops_default_revenue_account_id();

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $h($wo['title']) ?> · Field Ops</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      --panel: rgba(255,255,255,.055);
      --line: rgba(255,255,255,.12);
      --text: #eef6ff;
      --muted: rgba(238,246,255,.72);
      --green: #86efac;
      --yellow: #fde68a;
      --red: #fecaca;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.14), transparent 28rem),
        linear-gradient(135deg, #081426, #0f2238);
      color: var(--text);
    }
    a { color: #bfdbfe; }
    .page { width: min(100% - 2rem, 1220px); margin: 0 auto; padding: 28px 0 48px; }
    .topbar { display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .eyebrow { text-transform:uppercase; letter-spacing:.14em; color:#93c5fd; font-size:12px; font-weight:900; }
    h1 { margin:6px 0 8px; font-size:clamp(30px,5vw,48px); line-height:1; }
    h2 { margin:0 0 12px; font-size:22px; }
    p { color: var(--muted); line-height:1.6; }
    .grid { display:grid; gap:16px; }
    .stats { grid-template-columns: repeat(5, minmax(0,1fr)); margin:18px 0; }
    .two { grid-template-columns: minmax(0,1fr) minmax(0,1fr); align-items:start; }
    .card { border:1px solid var(--line); background:var(--panel); border-radius:18px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.22); }
    .stat-value { font-size:28px; font-weight:950; }
    .stat-label { color:var(--muted); font-size:13px; }
    .flash-success,.flash-error { border-radius:14px; padding:12px 14px; margin:12px 0; border:1px solid var(--line); }
    .flash-success { background:rgba(34,197,94,.13); color:var(--green); }
    .flash-error { background:rgba(239,68,68,.13); color:var(--red); }
    label { display:grid; gap:7px; font-size:13px; color:var(--muted); font-weight:800; }
    input,textarea,select { width:100%; border:1px solid var(--line); background:rgba(0,0,0,.22); color:var(--text); border-radius:12px; padding:11px 12px; font:inherit; }
    textarea { min-height:82px; resize:vertical; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .full { grid-column:1 / -1; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:10px 14px; border-radius:999px; border:1px solid rgba(147,197,253,.35); color:var(--text); background:rgba(96,165,250,.16); text-decoration:none; font-weight:900; cursor:pointer; }
    .btn-primary { background:linear-gradient(135deg,#3b82f6,#60a5fa); color:white; }
    .btn-danger { background:rgba(127,29,29,.24); border-color:rgba(252,165,165,.35); color:#fecaca; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:760px; }
    th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:#bfdbfe; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    code { display:inline-block; max-width:100%; overflow-wrap:anywhere; padding:4px 7px; border-radius:8px; background:rgba(0,0,0,.26); color:#dbeafe; }
    .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:5px 9px; font-size:12px; font-weight:950; letter-spacing:.04em; background:rgba(147,197,253,.1); }
    .muted { color:var(--muted); }
    .field-hint { color:var(--muted); font-size:11px; margin-top:-4px; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    @media (max-width:1000px) { .stats,.two,.form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
  
    /* Final native dropdown readability override */
    body select,
    body select:focus {
      background-color: #0b1626 !important;
      color: #eef6ff !important;
      color-scheme: light !important;
      font-weight: 900 !important;
    }

    body select option,
    body select optgroup {
      background-color: #f8fafc !important;
      color: #0f172a !important;
      font-size: 15px !important;
      font-weight: 900 !important;
      line-height: 1.8 !important;
      text-shadow: none !important;
    }

    body select option:checked {
      background-color: #60a5fa !important;
      color: #06101f !important;
      font-weight: 950 !important;
    }

  </style>
</head>
<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">Field Ops work order</div>
      <h1><?= $h($wo['title']) ?></h1>
      <p style="margin:0;">
        <?= $h($wo['buyer_name'] ?? 'No buyer') ?>
        <?php if (!empty($wo['external_work_order_number'])): ?> · <code><?= $h($wo['external_work_order_number']) ?></code><?php endif; ?>
      </p>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Back to Field Ops</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="grid stats">
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['gross'] ?? 0) ?></div><div class="stat-label">Gross</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['material_cost'] ?? 0) ?></div><div class="stat-label">Materials cost</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['expense_cost'] ?? 0) ?></div><div class="stat-label">Expenses</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['estimated_net'] ?? 0) ?></div><div class="stat-label">Estimated net</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['effective_hourly'] ?? 0) ?></div><div class="stat-label">Effective hourly</div></article>
  </section>

  <section class="grid two">
    <article class="card">
      <h2>Update job state</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_state">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Status
          <select name="status">
            <?php foreach (field_ops_work_statuses() as $status): ?>
              <option value="<?= $h($status) ?>" <?= (string)$wo['status'] === $status ? 'selected' : '' ?>><?= $h(str_replace('_', ' ', $status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Payment
          <select name="payment_status">
            <?php foreach (field_ops_payment_statuses() as $status): ?>
              <option value="<?= $h($status) ?>" <?= (string)$wo['payment_status'] === $status ? 'selected' : '' ?>><?= $h($status) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Scheduled start
          <input
            type="text"
            name="scheduled_start_at"
            value="<?= $h($dtDisplay($wo['scheduled_start_at'] ?? '')) ?>"
            placeholder="2026-07-06 14:00"
          >
          <span style="font-size:11px;color:var(--muted);margin-top:-4px;">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>
          Scheduled end
          <input
            type="text"
            name="scheduled_end_at"
            value="<?= $h($dtDisplay($wo['scheduled_end_at'] ?? '')) ?>"
            placeholder="2026-07-06 19:00"
          >
          <span style="font-size:11px;color:var(--muted);margin-top:-4px;">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>Gross pay <input name="gross_pay" inputmode="decimal" value="<?= $h($moneyInput($wo['gross_pay'])) ?>"></label>
        <label>Provider fee <input name="platform_fee" inputmode="decimal" value="<?= $h($moneyInput($wo['platform_fee'])) ?>"></label>
        <label>Insurance fee <input name="insurance_fee" inputmode="decimal" value="<?= $h($moneyInput($wo['insurance_fee'] ?? 0)) ?>"></label>
        <label>Bonus pay <input name="bonus_pay" inputmode="decimal" value="<?= $h($moneyInput($wo['bonus_pay'])) ?>"></label>
        <label>Reimbursement <input name="reimbursement_amount" inputmode="decimal" value="<?= $h($moneyInput($wo['reimbursement_amount'])) ?>"></label>
        <label>Mileage <input name="mileage" value="<?= $h($wo['mileage']) ?>"></label>
        <label>Mileage rate <input name="mileage_rate" value="<?= $h($wo['mileage_rate']) ?>"></label>
        <label>Drive minutes <input name="drive_minutes" value="<?= (int)$wo['drive_minutes'] ?>"></label>
        <label>Onsite minutes <input name="onsite_minutes" value="<?= (int)$wo['onsite_minutes'] ?>"></label>
        <label>Admin minutes <input name="admin_minutes" value="<?= (int)$wo['admin_minutes'] ?>"></label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">Update job</button>
        </div>
      </form>
    </article>

    <article class="card">
      <h2>Pull material from inventory</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_material">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label class="full">
          Inventory item
          <select name="inventory_item_id">
            <option value="">Manual material line</option>
            <?php foreach ($items as $item): ?>
              <option value="<?= (int)$item['item_id'] ?>">
                <?= $h($item['item_name']) ?> · <?= $fmtNum($item['qty_on_hand']) ?> <?= $h($item['unit']) ?> · cost <?= $fmtMoney($item['default_unit_cost']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="full">Description <input name="description" placeholder="Auto-fills from item if blank"></label>
        <label>Qty used <input name="quantity_used" inputmode="decimal" placeholder="1" required></label>
        <label>Unit cost <input name="unit_cost" inputmode="decimal" placeholder="$0.00 or inventory default"></label>
        <label>Bill price <input name="bill_price" inputmode="decimal" placeholder="$0.00 or inventory default"></label>

        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="billable" value="1" style="width:auto;"> Billable</label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursable" value="1" style="width:auto;"> Reimbursable</label>

        <div class="full">
          <button class="btn btn-primary" type="submit">Pull material</button>
        </div>
      </form>
    </article>
  </section>

  <section class="grid two" style="margin-top:16px;">
    <article class="card">
      <h2>Add expense</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_expense">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>Date <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Vendor <input name="vendor" placeholder="Home Depot"></label>
        <label>Category <input name="category" value="Tools"></label>
        <label>Amount <input name="amount" inputmode="decimal" value="<?= $h($moneyInput(0)) ?>" placeholder="$0.00"></label>
        <label class="full">Description <input name="description" placeholder="Fish poles, labels, parking, etc." required></label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursable" value="1" style="width:auto;"> Reimbursable</label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursed" value="1" style="width:auto;"> Already reimbursed</label>
        <div class="full"><button class="btn btn-primary" type="submit">Add expense</button></div>
      </form>
    </article>

    <article class="card">
      <h2>Add time</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_time">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Type
          <select name="entry_type">
            <option value="drive">Drive</option>
            <option value="onsite" selected>Onsite</option>
            <option value="paperwork">Paperwork</option>
            <option value="shopping">Shopping</option>
            <option value="waiting">Waiting</option>
          </select>
        </label>
        <label>Minutes <input name="minutes" inputmode="numeric" placeholder="60" required></label>
        <label>Started <input type="text" name="started_at" placeholder="2026-07-02 14:00"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span></label>
        <label>Ended <input type="text" name="ended_at" placeholder="2026-07-02 19:30"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span></label>
        <label class="full">Notes <input name="notes" placeholder="Optional detail"></label>
        <div class="full"><button class="btn btn-primary" type="submit">Add time</button></div>
      </form>
    </article>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Materials</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Description</th><th>Qty</th><th>Unit cost</th><th>Bill price</th><th>Cost total</th><th>Billable</th></tr></thead>
        <tbody>
        <?php if (!$materials): ?><tr><td colspan="6" class="muted">No materials pulled yet.</td></tr><?php endif; ?>
        <?php foreach ($materials as $m): ?>
          <tr>
            <td><strong><?= $h($m['description']) ?></strong><br><span class="muted"><?= $h($m['item_name'] ?? '') ?></span></td>
            <td><?= $fmtNum($m['quantity_used']) ?></td>
            <td><?= $fmtMoney($m['unit_cost']) ?></td>
            <td><?= $fmtMoney($m['bill_price']) ?></td>
            <td><?= $fmtMoney((float)$m['quantity_used'] * (float)$m['unit_cost']) ?></td>
            <td><?= !empty($m['billable']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="grid two" style="margin-top:16px;">
    <article class="card">
      <h2>Expenses</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Vendor</th><th>Description</th><th>Amount</th><th>Reimb.</th></tr></thead>
          <tbody>
          <?php if (!$expenses): ?><tr><td colspan="5" class="muted">No expenses yet.</td></tr><?php endif; ?>
          <?php foreach ($expenses as $e): ?>
            <tr><td><?= $h($e['expense_date']) ?></td><td><?= $h($e['vendor'] ?? '') ?></td><td><?= $h($e['description']) ?></td><td><?= $fmtMoney($e['amount']) ?></td><td><?= !empty($e['reimbursable']) ? 'Yes' : 'No' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="card">
      <h2>Time entries</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Type</th><th>Minutes</th><th>Notes</th></tr></thead>
          <tbody>
          <?php if (!$timeEntries): ?><tr><td colspan="3" class="muted">No detailed time entries yet.</td></tr><?php endif; ?>
          <?php foreach ($timeEntries as $t): ?>
            <tr><td><span class="badge"><?= $h($t['entry_type']) ?></span></td><td><?= (int)$t['minutes'] ?></td><td><?= $h($t['notes'] ?? '') ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>



  <section class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <div>
        <h2>Attachments</h2>
        <p style="margin-top:0;">Upload receipts, job photos, cable tester exports, signed paperwork, and lead/customer sign-off files. Files are stored privately and can also sync to OneDrive.</p>
      </div>
      <div class="actions">
        <?php if (empty($onedriveStatus['configured'])): ?>
          <span class="badge">OneDrive not configured</span>
        <?php elseif (empty($onedriveStatus['has_refresh_token'])): ?>
          <a class="btn" href="<?= $h(BASE_URL) ?>/accounting/onedrive_connect.php?return_to=<?= urlencode(BASE_URL . '/admin/field_work_order.php?id=' . (int)$workOrderId) ?>">Connect OneDrive</a>
        <?php else: ?>
          <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_all_attachments_onedrive">
            <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
            <button class="btn btn-primary" type="submit">Sync all to OneDrive</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-bottom:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_attachment">
      <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

      <label>
        Type
        <select name="attachment_type">
          <?php foreach (field_ops_attachment_types() as $typeCode => $typeLabel): ?>
            <option value="<?= $h($typeCode) ?>"><?= $h($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        File
        <input type="file" name="attachment_file" required>
      </label>

      <label class="full">
        Description
        <input name="description" placeholder="Receipt for fish poles, before photo, signed lead release, tester export, etc.">
      </label>

      <div class="full">
        <button class="btn btn-primary" type="submit">Upload attachment</button>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Type</th>
            <th>File</th>
            <th>Description</th>
            <th>Size</th>
            <th>Uploaded</th>
            <th>OneDrive</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$attachments): ?>
          <tr><td colspan="7" class="muted">No attachments yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($attachments as $attachment): ?>
          <tr>
            <td><span class="badge"><?= $h(field_ops_attachment_types()[$attachment['attachment_type']] ?? $attachment['attachment_type']) ?></span></td>
            <td>
              <a href="<?= $h(BASE_URL) ?>/admin/field_attachment.php?id=<?= (int)$attachment['attachment_id'] ?>" target="_blank" rel="noopener">
                <?= $h($attachment['original_filename']) ?>
              </a>
              <div class="muted"><?= $h($attachment['mime_type'] ?? '') ?></div>
            </td>
            <td><?= $h($attachment['description'] ?? '') ?></td>
            <td><?= number_format(((int)$attachment['file_size_bytes']) / 1024, 1) ?> KB</td>
            <td><?= $h($attachment['uploaded_at']) ?></td>
            <td>
              <?php if (!empty($attachment['onedrive_web_url'])): ?>
                <a class="btn" href="<?= $h($attachment['onedrive_web_url']) ?>" target="_blank" rel="noopener" style="min-height:32px;padding:7px 10px;">Open in OneDrive</a>
              <?php elseif (!empty($onedriveStatus['has_refresh_token'])): ?>
                <form method="post" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="sync_attachment_onedrive">
                  <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
                  <input type="hidden" name="attachment_id" value="<?= (int)$attachment['attachment_id'] ?>">
                  <button class="btn" type="submit" style="min-height:32px;padding:7px 10px;">Sync</button>
                </form>
              <?php else: ?>
                <span class="muted">Local only</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="margin:0;" onsubmit="return confirm('Remove this attachment from the W/O?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_attachment">
                <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
                <input type="hidden" name="attachment_id" value="<?= (int)$attachment['attachment_id'] ?>">
                <button class="btn btn-danger" type="submit" style="min-height:32px;padding:7px 10px;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <div>
        <h2>Create draft invoice</h2>
        <p style="margin-top:0;">For direct, referral, QR/website, or existing-client work, create a draft invoice from W/O labor, billable materials, and reimbursable expenses. FieldNation work stays payout-only.</p>
      </div>
      <?php if ($linkedInvoice): ?>
        <a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$linkedInvoice['invoice_id'] ?>">Open invoice</a>
      <?php endif; ?>
    </div>

    <?php if (!$canCreateInvoice): ?>
      <div class="flash-error" style="margin-bottom:0;">This W/O source is FieldNation, so OPS invoicing is disabled. Track payout, fees, materials, and net here only.</div>
    <?php elseif ($linkedInvoice): ?>
      <div class="flash-success" style="margin-bottom:0;">
        Linked invoice exists:
        <strong><?= $h($linkedInvoice['invoice_number'] ?? ('Invoice #' . (int)$linkedInvoice['invoice_id'])) ?></strong>
        · <?= $h($linkedInvoice['status'] ?? '') ?>
      </div>
    <?php else: ?>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_invoice">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
        <input type="hidden" name="revenue_account_id" value="<?= (int)$defaultRevenueAccountId ?>">

        <label class="full">
          Client to invoice
          <select name="client_id" required>
            <option value="">Select client</option>
            <?php foreach ($clients as $client): ?>
              <?php $clientLabel = trim((string)($client['dba_name'] ?: $client['legal_name'])); ?>
              <option value="<?= (int)$client['client_id'] ?>" <?= (int)($wo['client_id'] ?? 0) === (int)$client['client_id'] ? 'selected' : '' ?>>
                <?= $h($clientLabel) ?><?= !empty($client['client_code']) ? ' · ' . $h($client['client_code']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Invoice date
          <input type="text" name="invoice_date" value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
        </label>

        <label>
          Due date
          <input type="text" name="due_date" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" placeholder="YYYY-MM-DD">
        </label>

        <label class="full">
          Labor description
          <input name="labor_description" value="<?= $h('Field service labor - ' . (string)$wo['title']) ?>">
        </label>

        <label>
          Labor amount
          <input name="labor_amount" inputmode="decimal" value="<?= $h($moneyInput($wo['gross_pay'] ?? 0)) ?>">
        </label>

        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="include_billable_materials" value="1" checked style="width:auto;">
          Include billable materials
        </label>

        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="include_reimbursable_expenses" value="1" checked style="width:auto;">
          Include reimbursable expenses
        </label>

        <label class="full">
          Invoice memo
          <textarea name="memo"><?= $h('Created from Field Ops W/O #' . (int)$workOrderId . ': ' . (string)$wo['title']) ?></textarea>
        </label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">Create draft invoice</button>
          <span class="muted">Creates a draft only. You still review, issue, and send it from Accounting.</span>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Discard work order</h2>
    <p>Use this if FN rejects it, you withdraw, the buyer cancels, or this was only a test record. It hides the W/O from the active dashboard but keeps the audit trail.</p>
    <form method="post" class="actions" onsubmit="return confirm('Discard this work order from active Field Ops?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="discard_work_order">
      <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
      <input type="hidden" name="delete_reason" value="Discarded from work order detail page.">
      <button class="btn btn-danger" type="submit">Discard from active dashboard</button>
    </form>
  </section>
</main>
</body>
</html>
