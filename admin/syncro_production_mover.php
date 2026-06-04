<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/syncro_production_mover.php';

require_login();
require_recent_mfa(BASE_URL . '/admin/syncro_production_mover.php');

$result = null;
$findResult = null;
$error = null;
$customerId = (int)($_POST['customer_id'] ?? $_GET['customer_id'] ?? 35912652);
$assetId = (int)($_POST['asset_id'] ?? $_GET['asset_id'] ?? 12561086);
$ticketIdRaw = trim((string)($_POST['ticket_id'] ?? $_GET['ticket_id'] ?? '4211'));
$ticketId = $ticketIdRaw !== '' ? (int)ltrim($ticketIdRaw, '#') : null;
$dryRun = (string)($_POST['dry_run'] ?? $_GET['dry_run'] ?? '1') === '1';
$closeTicket = isset($_POST['close_ticket']) && (string)$_POST['close_ticket'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $action = (string)($_POST['action'] ?? 'dry_run');
    try {
        if ($action === 'find_ready') {
            $findResult = syncro_production_move_find_ready_assets($customerId);
        } elseif ($action === 'move_asset') {
            if (!$dryRun && trim((string)($_POST['confirm_phrase'] ?? '')) !== 'MOVE TO PRODUCTION') {
                $error = 'To execute the move, type MOVE TO PRODUCTION exactly. Leave Dry run checked to preview only.';
            } else {
                $result = syncro_production_move_asset($customerId, $assetId, $ticketId, $dryRun, $closeTicket);
            }
        }
    } catch (Throwable $e) {
        $error = 'Production mover failed: ' . syncro_production_move_mask_secrets($e->getMessage());
    }
}

page_header('Syncro Production Mover', 'admin');
?>
<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
  <div>
    <h1 style="margin:0 0 8px;font-size:28px;">Syncro production mover</h1>
    <div style="opacity:.78;max-width:920px;line-height:1.55;">
      Manual, dry-run-first mover for MMIT onboarding assets. This tool uses the OPS Syncro API integration only; it does not use browser cookies or CSRF tokens from Syncro.
    </div>
  </div>
  <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/index.php">Back to admin</a>
</div>

<?php if ($error): ?>
  <div style="margin-top:18px;padding:14px 16px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<section class="card" style="margin-top:18px;padding:18px;">
  <h2 style="margin:0 0 12px;font-size:18px;">Folder allowlist</h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
    <?php foreach (syncro_production_folder_allowlist() as $label => $id): ?>
      <div style="padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);"><strong><?= htmlspecialchars($label) ?></strong><br><span style="opacity:.75;">#<?= (int)$id ?></span></div>
    <?php endforeach; ?>
  </div>
  <p style="margin:12px 0 0;opacity:.76;line-height:1.55;">Only <strong>Production/Workstations</strong> and <strong>Production/Servers</strong> are valid production move targets. Acceptance remains manual and is not attached to Deploy policies.</p>
</section>

<div style="display:grid;grid-template-columns:minmax(320px,1fr) minmax(320px,1fr);gap:18px;margin-top:18px;align-items:start;">
  <section class="card" style="padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Find READY assets</h2>
    <form method="post" style="display:grid;gap:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="find_ready">
      <label style="display:grid;gap:6px;">
        <span style="font-size:13px;opacity:.78;">Customer ID</span>
        <input type="number" name="customer_id" value="<?= htmlspecialchars((string)$customerId) ?>" min="1" required style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;">
      </label>
      <button type="submit" class="btn btn-secondary" style="width:auto;padding:12px 16px;">Find READY assets</button>
    </form>
  </section>

  <section class="card" style="padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Move a reviewed asset</h2>
    <form method="post" style="display:grid;gap:12px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="move_asset">
      <label style="display:grid;gap:6px;"><span style="font-size:13px;opacity:.78;">Customer ID</span><input type="number" name="customer_id" value="<?= htmlspecialchars((string)$customerId) ?>" min="1" required style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;"></label>
      <label style="display:grid;gap:6px;"><span style="font-size:13px;opacity:.78;">Asset ID</span><input type="number" name="asset_id" value="<?= htmlspecialchars((string)$assetId) ?>" min="1" required style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;"></label>
      <label style="display:grid;gap:6px;"><span style="font-size:13px;opacity:.78;">Ready ticket ID (optional)</span><input type="text" name="ticket_id" value="<?= htmlspecialchars($ticketIdRaw) ?>" placeholder="#4211" style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;"></label>
      <input type="hidden" name="dry_run" value="0">
      <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="dry_run" value="1" <?= $dryRun ? 'checked' : '' ?>> <span>Dry run / preview only</span></label>
      <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="close_ticket" value="1" <?= $closeTicket ? 'checked' : '' ?>> <span>Close ready ticket after successful move/no-op</span></label>
      <label style="display:grid;gap:6px;"><span style="font-size:13px;opacity:.78;">Execute confirmation</span><input type="text" name="confirm_phrase" value="" placeholder="MOVE TO PRODUCTION" autocomplete="off" style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;"></label>
      <button type="submit" class="btn btn-primary" style="width:auto;padding:12px 16px;">Preview or move asset</button>
      <div style="font-size:12px;opacity:.68;line-height:1.55;">Requirements: MMIT Onboarding Status = READY, MMIT Ready To Move = Yes, and MMIT Production Folder Target = Production/Workstations or Production/Servers.</div>
    </form>
  </section>
</div>

<?php if ($findResult !== null): ?>
  <section class="card" style="margin-top:18px;padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">READY assets found</h2>
    <?php if (empty($findResult['ok'])): ?>
      <div style="color:#fecaca;"><?= htmlspecialchars(implode(' ', (array)($findResult['errors'] ?? []))) ?></div>
    <?php elseif (empty($findResult['assets'])): ?>
      <div style="opacity:.78;">No READY assets matched the production mover requirements.</div>
    <?php else: ?>
      <div style="display:grid;gap:10px;">
        <?php foreach ($findResult['assets'] as $row): $asset = (array)$row['asset']; $validation = (array)$row['validation']; ?>
          <div style="padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);">
            <strong><?= htmlspecialchars(syncro_production_move_asset_name($asset)) ?></strong> — Asset #<?= (int)($asset['id'] ?? 0) ?><br>
            <span style="opacity:.75;">Target: <?= htmlspecialchars((string)$validation['target']) ?> (#<?= (int)$validation['target_folder_id'] ?>)</span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
<?php endif; ?>

<?php if ($result !== null): ?>
  <section class="card" style="margin-top:18px;padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Mover result</h2>
    <div style="padding:14px 16px;border:1px solid <?= !empty($result['ok']) ? '#bbf7d0' : '#fecaca' ?>;background:<?= !empty($result['ok']) ? '#f0fdf4' : '#fff1f2' ?>;color:<?= !empty($result['ok']) ? '#166534' : '#991b1b' ?>;border-radius:14px;">
      <strong><?= !empty($result['dry_run']) ? 'Dry run' : 'Execution' ?> <?= !empty($result['ok']) ? 'passed' : 'failed' ?>.</strong>
      <div style="margin-top:6px;"><?= htmlspecialchars((string)($result['message'] ?? implode(' ', (array)($result['errors'] ?? [])))) ?></div>
    </div>
    <?php if (!empty($result['warnings'])): ?>
      <div style="margin-top:12px;padding:12px 14px;border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:14px;">
        <strong>Warnings:</strong> <?= htmlspecialchars(implode(' ', array_map('strval', (array)$result['warnings']))) ?>
      </div>
    <?php endif; ?>
    <?php if (!empty($result['validation'])): $validation = (array)$result['validation']; ?>
      <div style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;">
        <div>Status: <strong><?= htmlspecialchars((string)$validation['status']) ?></strong></div>
        <div>Ready To Move: <strong><?= htmlspecialchars((string)$validation['ready_to_move']) ?></strong></div>
        <div>Target: <strong><?= htmlspecialchars((string)$validation['target']) ?></strong></div>
        <div>Target folder: <strong>#<?= (int)($validation['target_folder_id'] ?? 0) ?></strong></div>
      </div>
    <?php endif; ?>
    <?php if (!empty($result['validation']['custom_field_debug'])): ?>
      <h3 style="margin:16px 0 8px;font-size:15px;">Custom field parse debug</h3>
      <div style="font-size:12px;opacity:.72;margin-bottom:8px;">Secret-masked Syncro custom field structure shown only when validation fails.</div>
      <pre style="white-space:pre-wrap;overflow:auto;padding:12px;border-radius:12px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.12);"><?= htmlspecialchars(json_encode($result['validation']['custom_field_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
    <?php endif; ?>
    <?php if (!empty($result['payload'])): ?>
      <h3 style="margin:16px 0 8px;font-size:15px;">Policy assignment payload</h3>
      <pre style="white-space:pre-wrap;overflow:auto;padding:12px;border-radius:12px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.12);"><?= htmlspecialchars(json_encode($result['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php page_footer(); ?>
