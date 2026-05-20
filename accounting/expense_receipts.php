<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/onedrive.php';
require_login();
accounting_require_ready();
csrf_check();

$expenseId = (int)($_GET['expense_id'] ?? $_POST['expense_id'] ?? 0);
$expense = $expenseId > 0 ? accounting_get_expense($expenseId) : null;
if (!$expense) {
    http_response_code(404);
    exit('Bill not found.');
}

$message = trim((string)($_GET['message'] ?? ''));
$errors = [];
if (!empty($_GET['error'])) {
    $errors[] = (string)$_GET['error'];
}

$connection = onedrive_connection_status();
$userId = (int)(current_user()['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'upload_receipt') {
    if (!$connection['configured']) {
        $errors[] = 'OneDrive is not configured yet. Add the client ID and secret in inc/config.php first.';
    } elseif (!$connection['connected']) {
        $errors[] = 'Connect OneDrive first, then upload the receipt.';
    } elseif (empty($_FILES['receipt_file'])) {
        $errors[] = 'Choose a receipt file to upload.';
    } else {
        $token = onedrive_get_valid_access_token();
        if (empty($token['ok'])) {
            $errors[] = (string)($token['error'] ?? 'Unable to use the OneDrive connection right now.');
        } else {
            $upload = onedrive_upload_receipt_file((string)$token['access_token'], $expense, $_FILES['receipt_file']);
            if (empty($upload['ok'])) {
                $errors[] = (string)($upload['error'] ?? 'Receipt upload failed.');
            } else {
                $saved = accounting_add_expense_attachment($expenseId, [
                    'provider' => 'ONEDRIVE',
                    'provider_file_id' => $upload['provider_file_id'] ?? '',
                    'file_name' => $upload['file_name'] ?? '',
                    'file_url' => $upload['file_url'] ?? '',
                    'mime_type' => $upload['mime_type'] ?? '',
                    'file_size' => $upload['file_size'] ?? null,
                    'checksum_sha256' => $upload['checksum_sha256'] ?? null,
                    'uploaded_by' => $userId,
                ]);
                if (empty($saved['ok'])) {
                    $errors = array_merge($errors, $saved['errors'] ?? ['Receipt metadata could not be saved.']);
                } else {
                    audit_event($userId, 'EXPENSE_RECEIPT_UPLOADED', ['expense_id' => $expenseId, 'provider' => 'ONEDRIVE']);
                    header('Location: ' . BASE_URL . '/accounting/expense_receipts.php?expense_id=' . $expenseId . '&message=' . urlencode('Receipt uploaded to OneDrive.'));
                    exit;
                }
            }
        }
    }
}

$attachments = accounting_list_expense_attachments($expenseId);
$receiptFolder = onedrive_receipt_folder_path($expense);

page_header('Expense Receipts', 'accounting');
accounting_subnav('bills');
?>
<?php if ($message !== ''): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(34,197,94,.25);background:rgba(34,197,94,.12);"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div style="margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(248,113,113,.25);background:rgba(127,29,29,.30);"><?php foreach ($errors as $error): ?><div><?= accounting_h((string)$error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1.1fr 1.9fr;gap:16px;align-items:start;">
  <div class="card" style="padding:16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <div>
        <h2 style="margin:0 0 6px;font-size:18px;">Receipt vault</h2>
        <div style="opacity:.74;font-size:13px;line-height:1.45;">Upload bills, emailed PDFs, or phone photos for this expense into OneDrive and keep the portal linked to the cloud copy.</div>
      </div>
      <a href="<?= accounting_h(BASE_URL) ?>/accounting/bills.php" style="color:#dbeafe;">Back to bills</a>
    </div>

    <div style="margin-top:14px;padding:12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);display:grid;gap:8px;">
      <div style="font-weight:700;"><?= accounting_h((string)($expense['vendor_name'] ?? 'Vendor')) ?></div>
      <div style="opacity:.78;font-size:13px;">Expense #<?= (int)$expenseId ?> · <?= accounting_h((string)($expense['expense_date'] ?? '')) ?> · $<?= number_format((float)($expense['total_amount'] ?? 0), 2) ?></div>
      <div style="opacity:.72;font-size:13px;line-height:1.45;"><?= accounting_h((string)($expense['memo'] ?? '')) ?: 'No memo entered for this bill yet.' ?></div>
    </div>

    <div style="margin-top:14px;padding:12px;border-radius:12px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.18);display:grid;gap:8px;">
      <div style="font-weight:700;">OneDrive status</div>
      <?php if (!$connection['configured']): ?>
        <div style="opacity:.82;font-size:13px;line-height:1.45;">Add your OneDrive client ID and secret in <code>inc/config.php</code> to light this up.</div>
      <?php elseif ($connection['connected']): ?>
        <div style="opacity:.82;font-size:13px;line-height:1.45;">Connected as <?= accounting_h((string)$connection['account_label']) ?>.</div>
        <div style="opacity:.68;font-size:12px;line-height:1.45;">Uploads land in <code><?= accounting_h($receiptFolder) ?></code>.</div>
        <?php if (!empty($connection['session_only'])): ?>
          <div style="opacity:.78;font-size:12px;line-height:1.45;color:#fde68a;">Connection is active for this browser session, but the token could not be saved to disk. Check write permissions on <code>storage/onedrive</code>.</div>
        <?php elseif (empty($connection['has_refresh_token'])): ?>
          <div style="opacity:.78;font-size:12px;line-height:1.45;color:#fde68a;">Connected without a refresh token. Uploads should work now, but reconnect if the session expires.</div>
        <?php endif; ?>
        <form method="post" action="<?= accounting_h(BASE_URL) ?>/accounting/onedrive_disconnect.php" style="margin-top:4px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <?= csrf_field() ?>
          <input type="hidden" name="return_to" value="<?= accounting_h(BASE_URL) ?>/accounting/expense_receipts.php?expense_id=<?= (int)$expenseId ?>">
          <button type="submit" style="padding:8px 12px;border-radius:10px;border:1px solid rgba(248,113,113,.24);background:rgba(127,29,29,.35);color:#f3f6ff;cursor:pointer;">Disconnect OneDrive</button>
          <a href="<?= accounting_h(BASE_URL) ?>/accounting/onedrive_connect.php?return_to=<?= urlencode(BASE_URL . '/accounting/expense_receipts.php?expense_id=' . $expenseId) ?>" style="color:#dbeafe;">Reconnect</a>
        </form>
      <?php else: ?>
        <div style="opacity:.82;font-size:13px;line-height:1.45;">OneDrive is configured but not connected yet.</div>
        <a href="<?= accounting_h(BASE_URL) ?>/accounting/onedrive_connect.php?return_to=<?= urlencode(BASE_URL . '/accounting/expense_receipts.php?expense_id=' . $expenseId) ?>" style="display:inline-flex;justify-content:center;padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;text-decoration:none;">Connect OneDrive</a>
      <?php endif; ?>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:14px;display:grid;gap:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_receipt">
      <input type="hidden" name="expense_id" value="<?= (int)$expenseId ?>">
      <div>
        <label>Receipt file</label><br>
        <input type="file" name="receipt_file" accept=".pdf,image/*" style="width:100%;padding:10px;box-sizing:border-box;">
      </div>
      <div style="opacity:.72;font-size:13px;line-height:1.45;">Accepted: PDF, JPG, PNG, WEBP, GIF, HEIC, TIFF. Uploads are stored in OneDrive and linked back to this bill.</div>
      <div>
        <button type="submit" style="padding:10px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.18);color:#e8eefc;cursor:pointer;">Upload receipt</button>
      </div>
    </form>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:18px;">Linked receipts</h2>
    <table style="width:100%;border-collapse:collapse;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)">
          <th style="padding:10px 8px;">File</th>
          <th style="padding:10px 8px;">Provider</th>
          <th style="padding:10px 8px;">Uploaded</th>
          <th style="padding:10px 8px;text-align:right;">Size</th>
          <th style="padding:10px 8px;">Open</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$attachments): ?>
        <tr><td colspan="5" style="padding:18px 8px;opacity:.75;">No receipts linked yet. Upload the first one and this page becomes your paper-trail dock.</td></tr>
      <?php else: ?>
        <?php foreach ($attachments as $attachment): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
            <td style="padding:10px 8px;">
              <div style="font-weight:600;"><?= accounting_h((string)$attachment['file_name']) ?></div>
              <div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($attachment['mime_type'] ?? '')) ?></div>
            </td>
            <td style="padding:10px 8px;"><?= accounting_h((string)$attachment['provider']) ?></td>
            <td style="padding:10px 8px;"><?= accounting_h((string)$attachment['uploaded_at']) ?></td>
            <td style="padding:10px 8px;text-align:right;"><?= !empty($attachment['file_size']) ? number_format(((int)$attachment['file_size']) / 1024, 1) . ' KB' : '—' ?></td>
            <td style="padding:10px 8px;">
              <?php if (!empty($attachment['file_url'])): ?>
                <a href="<?= accounting_h((string)$attachment['file_url']) ?>" target="_blank" rel="noopener noreferrer" style="color:#dbeafe;font-weight:600;">Open</a>
              <?php else: ?>
                <span style="opacity:.6;">Unavailable</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer(); ?>
