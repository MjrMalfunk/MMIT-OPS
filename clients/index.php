<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/layout.php';
require_login();
$clients = client_get_all();
$totalClients = count($clients);
$activeClients = count(array_filter($clients, static fn(array $client): bool => (string)($client['status'] ?? '') === 'ACTIVE'));
$syncedClients = count(array_filter($clients, static fn(array $client): bool => (string)($client['syncro_sync_status'] ?? '') === 'SYNCED'));
$activeContracts = array_sum(array_map(static fn(array $client): int => (int)($client['active_contract_count'] ?? 0), $clients));
page_header('Clients', 'clients');
?>
<div class="section-header-row">
  <div>
    <h1 style="margin:0;font-size:28px;">Clients</h1>
    <div style="opacity:.78;max-width:760px;">Your client roster, contact basics, Syncro bridge status, and contract activity in one cleaner view.</div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/contracts/index.php" style="width:auto;min-width:180px;text-decoration:none;">Open contract builder</a>
    <a class="btn btn-primary" href="<?= htmlspecialchars(BASE_URL) ?>/clients/new.php" style="width:auto;min-width:150px;text-decoration:none;">+ New client</a>
  </div>
</div>

<div class="client-summary-grid">
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Total clients</div><div style="font-size:22px;font-weight:800;"><?= $totalClients ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Active clients</div><div style="font-size:22px;font-weight:800;"><?= $activeClients ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Syncro linked</div><div style="font-size:22px;font-weight:800;"><?= $syncedClients ?></div></div>
  <div class="card" style="padding:16px;"><div style="min-height:38px;color:rgba(255,255,255,.78);font-size:14px;">Active contracts</div><div style="font-size:22px;font-weight:800;"><?= $activeContracts ?></div></div>
</div>

<?php if (!$clients): ?>
  <div class="card" style="padding:24px;text-align:center;opacity:.82;">
    <div style="font-size:18px;font-weight:800;margin-bottom:6px;">No clients yet</div>
    <div style="max-width:540px;margin:0 auto 16px;">Once you create a client from the Contract Builder or the client form, they will show up here with contract counts and Syncro status.</div>
    <a class="btn btn-primary" href="<?= htmlspecialchars(BASE_URL) ?>/contracts/index.php" style="width:auto;min-width:220px;text-decoration:none;">Create your first client</a>
  </div>
<?php else: ?>
  <div class="client-card-grid">
    <?php foreach ($clients as $client): ?>
      <?php
        $status = (string)($client['status'] ?? 'LEAD');
        $syncStatus = strtoupper((string)($client['syncro_sync_status'] ?? 'PENDING'));
        $syncClass = 'client-pill';
        if ($syncStatus === 'SYNCED') {
            $syncClass .= ' client-pill--synced';
        } elseif ($syncStatus === 'STAGING_BLOCKED') {
            $syncClass .= ' client-pill--staging';
        } elseif (in_array($syncStatus, ['ERROR','CONFLICT','MANUAL_REVIEW'], true)) {
            $syncClass .= ' client-pill--error';
        } else {
            $syncClass .= ' client-pill--pending';
        }
        $displayName = (string)($client['dba_name'] ?: $client['legal_name']);
      ?>
      <section class="card client-card">
        <div class="client-card-top">
          <div>
            <h2 class="client-card-title"><?= htmlspecialchars($displayName) ?></h2>
            <div class="client-card-subtitle"><?= htmlspecialchars((string)$client['client_code']) ?><?php if (!empty($client['legal_name']) && $client['dba_name']): ?> · <?= htmlspecialchars((string)$client['legal_name']) ?><?php endif; ?></div>
          </div>
          <div class="client-pill-row">
            <span class="client-pill"><?= htmlspecialchars($status) ?></span>
            <span class="<?= $syncClass ?>">Syncro <?= htmlspecialchars($syncStatus) ?></span>
          </div>
        </div>

        <div class="client-meta-grid">
          <div>
            <div class="client-meta-label">Email</div>
            <div class="client-meta-value"><?= htmlspecialchars((string)($client['email'] ?: 'Not set')) ?></div>
          </div>
          <div>
            <div class="client-meta-label">Phone</div>
            <div class="client-meta-value"><?= htmlspecialchars((string)($client['phone'] ?: 'Not set')) ?></div>
          </div>
          <div>
            <div class="client-meta-label">Active contracts</div>
            <div class="client-meta-value"><?= (int)($client['active_contract_count'] ?? 0) ?></div>
          </div>
          <div>
            <div class="client-meta-label">Website</div>
            <div class="client-meta-value"><?= htmlspecialchars((string)($client['website'] ?: '—')) ?></div>
          </div>
        </div>

        <div class="client-card-actions">
          <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= (int)$client['client_id'] ?>" style="text-decoration:none;">Open client</a>
          <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/contracts/index.php?client_id=<?= (int)$client['client_id'] ?>" style="text-decoration:none;">New contract</a>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php page_footer(); ?>
