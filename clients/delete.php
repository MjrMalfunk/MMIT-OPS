<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/layout.php';
require_login();

$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$summary = $clientId > 0 ? client_delete_summary($clientId) : ['client' => null, 'blockers' => [], 'can_delete' => false];
$client = $summary['client'] ?? null;
if (!$client) {
    http_response_code(404);
    page_header('Client Not Found', 'clients');
    echo '<p>Client not found.</p>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    try {
        client_delete($clientId);
        $_SESSION['flash_msg'] = 'Client deleted from OPS.';
        header('Location: ' . BASE_URL . '/clients/index.php');
        exit;
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . BASE_URL . '/clients/delete.php?client_id=' . $clientId);
        exit;
    }
}

$flashMsg = '';
$flashError = '';
if (!empty($_SESSION['flash_msg'])) {
    $flashMsg = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}
if (!empty($_SESSION['flash_error'])) {
    $flashError = (string)$_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

page_header('Delete ' . (string)$client['legal_name'], 'clients');
?>
<?php if ($flashMsg !== ''): ?><div class="flash-success"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
<?php if ($flashError !== ''): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
  <div>
    <h1 style="margin:0;font-size:28px;">Delete company</h1>
    <div style="opacity:.74;">This removes the company from OPS only. Syncro is not changed.</div>
  </div>
  <div><a class="btn btn-secondary" style="width:auto;padding:10px 14px;" href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">Back to client</a></div>
</div>

<div class="card" style="margin-top:16px;">
  <h2 style="margin-top:0;"><?= htmlspecialchars((string)$client['legal_name']) ?></h2>
  <p><strong>Client Code:</strong> <?= htmlspecialchars((string)$client['client_code']) ?></p>
  <p><strong>Syncro Status:</strong> <?= !empty($client['syncro_customer_id']) ? 'Linked to Syncro customer #' . (int)$client['syncro_customer_id'] : 'Not linked' ?></p>
</div>

<div class="card" style="margin-top:16px;">
  <h2 style="margin-top:0;">What will be removed from OPS</h2>
  <ul>
    <li>Locations: <?= (int)$summary['locations'] ?></li>
    <li>Contacts: <?= (int)$summary['contacts'] ?></li>
    <li>Contracts: <?= (int)$summary['contracts'] ?></li>
    <li>Client services: <?= (int)$summary['services'] ?></li>
    <li>Recurring services: <?= (int)$summary['recurring_services'] ?></li>
  </ul>
</div>

<div class="card" style="margin-top:16px;">
  <h2 style="margin-top:0;">Delete safety check</h2>
  <ul>
    <li>Customer invoices: <?= (int)$summary['invoices'] ?></li>
    <li>Customer payments: <?= (int)$summary['payments'] ?></li>
  </ul>
  <?php if (!$summary['can_delete']): ?>
    <div class="flash-error" style="margin:12px 0 0 0;">
      Delete is blocked because this company has accounting history in OPS.
      <?php if (!empty($summary['blockers'])): ?>
        <ul style="margin:8px 0 0 18px;"><?php foreach ($summary['blockers'] as $blocker): ?><li><?= htmlspecialchars((string)$blocker) ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="flash-success" style="margin:12px 0 0 0;">Delete is allowed. This only removes the company from OPS.</div>
  <?php endif; ?>
</div>

<?php if ($summary['can_delete']): ?>
  <form method="post" style="margin-top:16px;">
    <?= csrf_field() ?>
    <input type="hidden" name="client_id" value="<?= $clientId ?>">
    <button class="btn btn-danger" type="submit" style="width:auto;padding:10px 16px;">Delete company from OPS</button>
  </form>
<?php endif; ?>

<?php page_footer(); ?>
