<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/clients.php';
require_once __DIR__ . '/inc/layout.php';
require_login();

$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$summary = $clientId > 0 ? client_delete_summary($clientId) : null;
if (!$summary) {
    http_response_code(404);
    page_header('Client Not Found', 'clients');
    echo '<p>Client not found.</p>';
    page_footer();
    exit;
}
$client = $summary['client'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    try {
        client_delete($clientId);
        $_SESSION['flash_msg'] = 'Client deleted from OPS. Syncro was not modified.';
        header('Location: ' . BASE_URL . '/clients/index.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

page_header('Delete Client', 'clients');
?>
<p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">&larr; Back to <?= htmlspecialchars((string)$client['legal_name']) ?></a></p>
<?php if ($error !== ''): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div class="card" style="padding:18px;display:grid;gap:14px;max-width:860px;">
  <div>
    <h1 style="margin:0 0 4px;font-size:26px;">Delete company</h1>
    <div style="opacity:.74;">This removes the company from OPS only. It does not delete the organization or assets in Syncro.</div>
  </div>

  <div style="padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);">
    <div style="font-weight:800;font-size:20px;margin-bottom:6px;"><?= htmlspecialchars((string)$client['legal_name']) ?></div>
    <div style="opacity:.82;display:grid;gap:4px;">
      <div><strong>Client Code:</strong> <?= htmlspecialchars((string)$client['client_code']) ?></div>
      <div><strong>Status:</strong> <?= htmlspecialchars((string)$client['status']) ?></div>
      <div><strong>Syncro:</strong> <?php if (!empty($client['syncro_customer_id'])): ?>Linked to customer #<?= (int)$client['syncro_customer_id'] ?><?php else: ?>Not linked<?php endif; ?></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
    <div style="padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);">
      <div style="font-weight:700;margin-bottom:8px;">Will be removed with the company</div>
      <?php if (!$summary['cascade']): ?>
        <div style="opacity:.78;">No child records found.</div>
      <?php else: ?>
        <ul style="margin:0;padding-left:18px;"><?php foreach ($summary['cascade'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
    <div style="padding:14px;border:1px solid rgba(255,255,255,.12);border-radius:14px;background:rgba(255,255,255,.03);">
      <div style="font-weight:700;margin-bottom:8px;">Delete blockers</div>
      <?php if (empty($summary['blocking'])): ?>
        <div style="color:#86efac;">No blockers found. This company can be deleted.</div>
      <?php else: ?>
        <div style="color:#fca5a5;margin-bottom:8px;">Delete is blocked because accounting history already exists.</div>
        <ul style="margin:0;padding-left:18px;"><?php foreach ($summary['blocking'] as $item): ?><li><?= htmlspecialchars($item) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($summary['can_delete'])): ?>
    <form method="post" onsubmit="return confirm('Delete this company from OPS? This will not touch Syncro.');" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <?= csrf_field() ?>
      <input type="hidden" name="client_id" value="<?= $clientId ?>">
      <button type="submit" style="background:#7f1d1d;border:1px solid #7f1d1d;color:#fff;">Delete from OPS</button>
      <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">Cancel</a>
    </form>
  <?php else: ?>
    <div class="flash-error">This company cannot be deleted because it already has invoice or payment history. Leave it in OPS or mark it Former instead.</div>
    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
      <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/clients/edit.php?client_id=<?= $clientId ?>" style="text-decoration:none;">Edit company instead</a>
      <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">Back to client</a>
    </div>
  <?php endif; ?>
</div>
<?php page_footer();
