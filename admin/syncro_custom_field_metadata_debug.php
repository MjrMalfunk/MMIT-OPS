<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/syncro_production_mover.php';

require_login();
require_recent_mfa(BASE_URL . '/admin/syncro_custom_field_metadata_debug.php');

$diagnostic = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    try {
        $diagnostic = syncro_production_move_live_settings_metadata_diagnostic();
    } catch (Throwable $e) {
        $error = 'Syncro metadata diagnostic failed: ' . syncro_production_move_mask_secrets($e->getMessage());
    }
}

page_header('Syncro Custom Field Metadata Debug', 'admin');
?>
<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
  <div>
    <h1 style="margin:0 0 8px;font-size:28px;">Syncro custom field metadata debug</h1>
    <div style="opacity:.78;max-width:940px;line-height:1.55;">
      Admin-only, read-only diagnostic for MMIT asset custom-field dropdown/list metadata. It calls the existing OPS Syncro API integration for <code>GET /settings</code>, extracts only MMIT-relevant field metadata, and masks any secret-looking values.
    </div>
  </div>
  <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/index.php">Back to admin</a>
</div>

<section class="card" style="margin-top:18px;padding:18px;">
  <h2 style="margin:0 0 12px;font-size:18px;">Read-only checks</h2>
  <ul style="margin:0 0 16px 18px;line-height:1.65;opacity:.82;">
    <li>No production writes are performed by this page.</li>
    <li>The only live Syncro call is <code>GET /settings</code>.</li>
    <li>The output is limited to these MMIT fields: <?php foreach (syncro_production_move_mmit_field_names() as $index => $fieldName): ?><?= $index > 0 ? ', ' : '' ?><strong><?= htmlspecialchars($fieldName) ?></strong><?php endforeach; ?>.</li>
  </ul>
  <form method="post">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary" style="width:auto;padding:12px 16px;">Run metadata diagnostic</button>
  </form>
</section>

<?php if ($error): ?>
  <div style="margin-top:18px;padding:14px 16px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($diagnostic !== null): ?>
  <section class="card" style="margin-top:18px;padding:18px;">
    <h2 style="margin:0 0 12px;font-size:18px;">Diagnostic result</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px;margin-bottom:14px;">
      <div style="padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);"><strong>GET /settings</strong><br><span style="opacity:.75;"><?= !empty($diagnostic['ok']) ? 'Fetched' : 'Unavailable' ?></span></div>
      <div style="padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);"><strong>Custom field definitions</strong><br><span style="opacity:.75;"><?= !empty($diagnostic['contains_custom_field_definitions']) ? 'Present' : 'Not detected' ?></span></div>
      <div style="padding:12px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);"><strong>Asset custom fields</strong><br><span style="opacity:.75;"><?= !empty($diagnostic['asset_custom_fields_present']) ? 'Present' : 'Not detected' ?></span></div>
    </div>
    <div style="font-size:12px;opacity:.72;margin-bottom:8px;">Secret-masked, MMIT-scoped metadata only. If option lists are absent here, check the listed existing repo Syncro readers to confirm whether asset responses include embedded display values, but they are not definition/option sources.</div>
    <pre style="white-space:pre-wrap;overflow:auto;padding:12px;border-radius:12px;background:rgba(0,0,0,.22);border:1px solid rgba(255,255,255,.12);"><?= htmlspecialchars(json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '') ?></pre>
  </section>
<?php endif; ?>
<?php page_footer(); ?>
