<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/syncro.php';
require_login();
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
