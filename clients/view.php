<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/syncro.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
}
$clientId = (int)($_GET['client_id'] ?? 0);
$client = $clientId > 0 ? client_get_by_id($clientId) : null;
if (!$client) {
    http_response_code(404);
    page_header('Client Not Found', 'clients');
    echo '<p>Client not found.</p>';
    page_footer();
    exit;
}
$locations = client_get_locations($clientId);
$contacts = client_get_contacts($clientId);
$services = function_exists('accounting_list_client_services') ? accounting_list_client_services(100, $clientId) : [];
$contracts = function_exists('accounting_list_contracts') ? accounting_list_contracts(50, $clientId) : [];
$syncroReadiness = syncro_required_fields_status($client);
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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'manual_syncro_link') {
        $manualSyncroCustomerId = (int)($_POST['syncro_customer_id'] ?? 0);
        $result = syncro_manual_link_existing_customer($clientId, $manualSyncroCustomerId, true);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = (string)($result['message'] ?? ('Manually linked to Syncro customer #' . $manualSyncroCustomerId . '.'));
        } else {
            $_SESSION['flash_error'] = implode(' ', array_map('strval', (array)($result['errors'] ?? ['Unable to manually link Syncro customer.'])));
        }
    } elseif ($action === 'retry_syncro') {
        $result = syncro_sync_client($clientId);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = (string)($result['message'] ?? syncro_action_success_message((string)($result['action'] ?? '')));
        } else {
            $_SESSION['flash_error'] = implode(' ', array_map('strval', (array)($result['errors'] ?? ['Unable to retry Syncro sync.'])));
        }
    } elseif ($action === 'repair_stale_syncro_link') {
        $result = syncro_repair_stale_customer_link($clientId);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'Stale Syncro link cleared. ' . (string)($result['message'] ?? syncro_action_success_message((string)($result['action'] ?? '')));
        } else {
            $_SESSION['flash_error'] = implode(' ', array_map('strval', (array)($result['errors'] ?? ['Unable to repair stale Syncro link.'])));
        }
    } elseif ($action === 'retry_syncro_folder_provisioning') {
        $result = syncro_provision_client_folder_map($clientId, !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : null, true);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = (string)($result['message'] ?? 'Syncro folder provisioning checked.');
        } else {
            $_SESSION['flash_error'] = implode(' ', array_map('strval', (array)($result['errors'] ?? ['Unable to retry Syncro folder provisioning.'])));
        }
    } else {
        $_SESSION['flash_error'] = 'Unknown client action.';
    }
    header('Location: ' . BASE_URL . '/clients/view.php?client_id=' . $clientId . '#syncro-folder-map', true, 303);
    exit;
}
$syncroFolderMap = syncro_get_client_folder_map($clientId);
$syncroPolicyAssignmentStatus = !empty($syncroFolderMap['policy_assignment_status']) ? (string)$syncroFolderMap['policy_assignment_status'] : syncro_policy_assignment_status();
$syncroPolicyAssignmentMessage = !empty($syncroFolderMap['policy_assignment_message']) ? (string)$syncroFolderMap['policy_assignment_message'] : syncro_policy_assignment_status_message();
$syncroStagingWritesEnabled = syncro_is_staging_mode() && syncro_staging_writes_allowed();
$syncroStagingWriteMessage = syncro_staging_write_status_message();
page_header((string)$client['legal_name'], 'clients');
?>
<?php if ($flashMsg !== ''): ?><div class="flash-success"><?= htmlspecialchars($flashMsg) ?></div><?php endif; ?>
<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;"><div><h1 style="margin:0;font-size:28px;"><?= htmlspecialchars((string)$client['legal_name']) ?></h1><div style="opacity:.74;">Client detail, contacts, locations, contracts, and linked billing.</div></div><div style="display:flex;gap:10px;flex-wrap:wrap;"><a class="btn btn-secondary" style="width:auto;padding:10px 14px;" href="<?= htmlspecialchars(BASE_URL) ?>/clients/edit.php?client_id=<?= $clientId ?>">Edit company</a><a class="btn btn-danger" style="width:auto;padding:10px 14px;" href="<?= htmlspecialchars(BASE_URL) ?>/clients/delete.php?client_id=<?= $clientId ?>">Delete company</a></div></div>
<?php if ($flashError !== ''): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>
<p><strong>Client Code:</strong> <?= htmlspecialchars((string)$client['client_code']) ?></p>
<p><strong>Status:</strong> <?= htmlspecialchars((string)$client['status']) ?></p>
<p><strong>Email:</strong> <?= htmlspecialchars((string)($client['email'] ?? '')) ?></p>
<p><strong>Phone:</strong> <?= htmlspecialchars((string)($client['phone'] ?? '')) ?></p>
<p><strong>Website:</strong> <?= htmlspecialchars((string)($client['website'] ?? '')) ?></p>
<p><strong>Tax Exempt:</strong> <?= !empty($client['tax_exempt']) ? 'Yes' : 'No' ?></p>
<?php if (!empty($client['notes'])): ?><p><strong>Notes:</strong><br><?= nl2br(htmlspecialchars((string)$client['notes'])) ?></p><?php endif; ?>
<p><strong>Syncro Status:</strong> <?= syncro_status_badge_html((string)($client['syncro_sync_status'] ?? 'PENDING'), !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : null) ?><?php if (!empty($client['syncro_last_error'])): ?><br><small style="color:#b91c1c;">Last error: <?= htmlspecialchars((string)$client['syncro_last_error']) ?></small><?php endif; ?></p>
<p><strong>Syncro Readiness:</strong> <?= empty($syncroReadiness['missing']) ? 'Ready' : 'Missing ' . htmlspecialchars(implode(', ', array_values($syncroReadiness['missing']))) ?></p>

<div id="syncro-folder-map" class="card" style="padding:16px;margin:18px 0;border:1px solid rgba(15,23,42,.12);">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
    <div>
      <h2 style="margin:0;font-size:20px;">Syncro folder map provisioning</h2>
      <div style="opacity:.74;line-height:1.45;">Customer-specific policy folder IDs are stored per client. OPS preserves existing IDs, creates only missing supported folders, and never deletes, renames, or moves assets.</div>
      <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <span style="display:inline-flex;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:800;background:<?= $syncroStagingWritesEnabled ? 'rgba(245,158,11,.18)' : 'rgba(15,23,42,.06)' ?>;color:<?= $syncroStagingWritesEnabled ? '#92400e' : '#334155' ?>;border:1px solid <?= $syncroStagingWritesEnabled ? 'rgba(245,158,11,.35)' : 'rgba(15,23,42,.12)' ?>;">Staging writes <?= $syncroStagingWritesEnabled ? 'ENABLED' : 'blocked' ?></span>
        <span style="font-size:12px;opacity:.78;"><?= htmlspecialchars($syncroStagingWriteMessage) ?></span>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="retry_syncro_folder_provisioning">
        <button class="btn btn-secondary" style="width:auto;padding:9px 12px;" type="submit">Retry folder provisioning</button>
      </form>
      <?php if (strtoupper((string)($client['syncro_sync_status'] ?? '')) === 'STALE_LINK'): ?>
      <form method="post" style="margin:0;" onsubmit="return confirm('Clear the stored Syncro customer ID in OPS and retry customer creation/update? OPS will not delete anything in Syncro.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="repair_stale_syncro_link">
        <button class="btn btn-danger" style="width:auto;padding:9px 12px;" type="submit">Clear stale link &amp; retry</button>
      </form>
      <?php endif; ?>
      <form method="post" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;" onsubmit="return confirm('Validate and manually link this OPS client to the entered Syncro customer ID? OPS will not delete anything in Syncro.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="manual_syncro_link">
        <input
          type="number"
          name="syncro_customer_id"
          min="1"
          step="1"
          inputmode="numeric"
          value="<?= !empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : '' ?>"
          placeholder="Syncro customer ID"
          style="width:190px;max-width:100%;padding:9px 10px;border-radius:10px;border:1px solid rgba(148,163,184,.35);background:rgba(15,23,42,.55);color:inherit;"
        >
        <button class="btn btn-secondary" style="width:auto;padding:9px 12px;" type="submit">Validate &amp; link Syncro ID</button>
      </form>
      <form method="post" style="margin:0;" onsubmit="return confirm('Retry automatic Syncro matching? If multiple customers match, OPS may return this client to manual review. Use manual link if you already know the correct customer ID.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="retry_syncro">
        <button class="btn btn-primary" style="width:auto;padding:9px 12px;" type="submit">Retry Syncro sync</button>
      </form>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;">
    <div><strong>Syncro customer ID</strong><br><?= !empty($syncroFolderMap['syncro_customer_id']) ? (int)$syncroFolderMap['syncro_customer_id'] : (!empty($client['syncro_customer_id']) ? (int)$client['syncro_customer_id'] : '—') ?></div>
    <div><strong>Deploy/Workstations</strong><br><?= !empty($syncroFolderMap['deploy_workstations_folder_id']) ? (int)$syncroFolderMap['deploy_workstations_folder_id'] : '—' ?></div>
    <div><strong>Deploy/Servers</strong><br><?= !empty($syncroFolderMap['deploy_servers_folder_id']) ? (int)$syncroFolderMap['deploy_servers_folder_id'] : '—' ?></div>
    <div><strong>Production/Workstations</strong><br><?= !empty($syncroFolderMap['production_workstations_folder_id']) ? (int)$syncroFolderMap['production_workstations_folder_id'] : '—' ?></div>
    <div><strong>Production/Servers</strong><br><?= !empty($syncroFolderMap['production_servers_folder_id']) ? (int)$syncroFolderMap['production_servers_folder_id'] : '—' ?></div>
    <div><strong>Provision status</strong><br><?= htmlspecialchars((string)($syncroFolderMap['provision_status'] ?? 'PENDING')) ?></div>
    <div><strong>Policy assignment</strong><br><?= htmlspecialchars($syncroPolicyAssignmentStatus) ?></div>
    <div><strong>Provisioned at</strong><br><?= htmlspecialchars((string)($syncroFolderMap['provisioned_at'] ?? '—')) ?></div>
    <div><strong>Updated at</strong><br><?= htmlspecialchars((string)($syncroFolderMap['updated_at'] ?? '—')) ?></div>
  </div>
  <?php if (!empty($syncroFolderMap['provision_message']) || !empty($syncroFolderMap['last_error'])): ?>
    <div style="margin-top:12px;line-height:1.5;">
      <?php if (!empty($syncroFolderMap['provision_message'])): ?><div><strong>Last message:</strong> <?= htmlspecialchars((string)$syncroFolderMap['provision_message']) ?></div><?php endif; ?>
      <?php if (!empty($syncroFolderMap['last_error'])): ?><div style="color:#b91c1c;"><strong>Last error:</strong> <?= htmlspecialchars((string)$syncroFolderMap['last_error']) ?></div><?php endif; ?>
      <?php if ($syncroPolicyAssignmentMessage !== ''): ?><div><strong>Policy assignment message:</strong> <?= htmlspecialchars($syncroPolicyAssignmentMessage) ?></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div style="margin-top:12px;font-size:12px;opacity:.72;line-height:1.45;">Manual verification reference: TEST-Cravin Vapes observed Deploy/Workstations as 5029166 and Production/Workstations as 5029170; the workstation finalizer moved MANAGE-WS-01 and MANAGE-WS-02 when those IDs were configured.</div>
</div>
<hr>
<div style="display:flex;gap:20px;flex-wrap:wrap;">
  <div style="flex:1;min-width:280px;">
    <h2>Locations</h2>
    <p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/location_new.php?client_id=<?= $clientId ?>">+ Add Location</a></p>
    <?php if (!$locations): ?><p>No locations yet.</p><?php else: ?><ul><?php foreach ($locations as $loc): ?><li><strong><?= htmlspecialchars((string)$loc['location_name']) ?></strong><?php if (!empty($loc['city']) || !empty($loc['state'])): ?> - <?= htmlspecialchars(trim(((string)($loc['city'] ?? '')) . ', ' . ((string)($loc['state'] ?? '')), ' ,')) ?><?php endif; ?><?= !empty($loc['is_primary']) ? ' (Primary)' : '' ?> <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/location_edit.php?location_id=<?= (int)$loc['location_id'] ?>">Edit</a></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
  <div style="flex:1;min-width:280px;">
    <h2>Contacts</h2>
    <p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/contact_new.php?client_id=<?= $clientId ?>">+ Add Contact</a></p>
    <?php if (!$contacts): ?><p>No contacts yet.</p><?php else: ?><ul><?php foreach ($contacts as $contact): ?><li><strong><?= htmlspecialchars(trim((string)$contact['first_name'] . ' ' . (string)$contact['last_name'])) ?></strong><?php if (!empty($contact['title'])): ?> - <?= htmlspecialchars((string)$contact['title']) ?><?php endif; ?> <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/contact_edit.php?contact_id=<?= (int)$contact['contact_id'] ?>">Edit</a><?php if (!empty($contact['email'])): ?><br><?= htmlspecialchars((string)$contact['email']) ?><?php endif; ?><?php if (!empty($contact['location_name'])): ?><br><em><?= htmlspecialchars((string)$contact['location_name']) ?></em><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
  </div>
</div>

<hr>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
  <div><h2 style="margin:0;">Contracts</h2><div style="opacity:.75;">Agreements, package terms, and linked billing for this client.</div></div>
  <a href="<?= htmlspecialchars(BASE_URL) ?>/contracts/index.php">+ New Contract</a>
</div>
<?php if (!$contracts): ?>
  <p>No contracts yet.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:12px;">
    <thead><tr><th>Contract</th><th class="date">Term</th><th class="money">Base</th><th class="status">Status</th><th>Open</th></tr></thead>
    <tbody>
    <?php foreach ($contracts as $contract): ?>
      <tr>
        <td><strong><?= htmlspecialchars((string)$contract['contract_number']) ?></strong><br><small><?= htmlspecialchars((string)$contract['contract_name']) ?></small></td>
        <td class="date"><?= htmlspecialchars((string)$contract['start_date']) ?><br><small><?= htmlspecialchars((string)($contract['end_date'] ?: 'Month-to-month')) ?></small></td>
        <td class="money">$<?= number_format((float)$contract['base_amount'], 2) ?></td>
        <td class="status"><?= accounting_contract_status_badge_html((string)$contract['status']) ?></td>
        <td><a href="<?= htmlspecialchars(BASE_URL) ?>/contracts/view.php?id=<?= (int)$contract['contract_id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>


<hr>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
  <div><h2 style="margin:0;">Assigned Services</h2><div style="opacity:.75;">Billable services and licenses currently attached to this client.</div></div>
  <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/services.php?client_id=<?= $clientId ?>">+ Assign Service</a>
</div>
<?php if (!$services): ?>
  <p>No client services assigned yet.</p>
<?php else: ?>
  <table border="1" cellpadding="8" cellspacing="0" style="width:100%;border-collapse:collapse;margin-top:12px;">
    <thead><tr><th>Item</th><th>Model</th><th class="date">Next bill</th><th class="money">Value</th><th class="status">Status</th><th>Open</th></tr></thead>
    <tbody>
    <?php foreach ($services as $service): ?>
      <tr>
        <td><strong><?= htmlspecialchars((string)($service['item_name'] ?: $service['description'])) ?></strong><?php if (!empty($service['item_code'])): ?><br><small><?= htmlspecialchars((string)$service['item_code']) ?></small><?php endif; ?></td>
        <td><?= htmlspecialchars((string)$service['pricing_model']) ?><br><small><?= htmlspecialchars((string)$service['billing_cycle']) ?></small></td>
        <td class="date"><?= htmlspecialchars((string)$service['next_bill_date']) ?></td>
        <td class="money">$<?= number_format((float)$service['quantity'] * (float)$service['unit_price'], 2) ?></td>
        <td class="status"><?= accounting_client_service_status_badge_html((string)$service['status']) ?></td>
        <td><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/service_view.php?id=<?= (int)$service['client_service_id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php page_footer(); ?>
