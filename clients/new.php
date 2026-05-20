<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/syncro.php';
require_once __DIR__ . '/../inc/layout.php';

require_login();

$error = '';
$form = [
    'legal_name' => '',
    'dba_name' => '',
    'status' => 'LEAD',
    'email' => '',
    'phone' => '',
    'website' => '',
    'tax_exempt' => 0,
    'notes' => '',
    'contact_first_name' => '',
    'contact_last_name' => '',
    'contact_title' => '',
    'contact_email' => '',
    'contact_phone' => '',
    'location_name' => 'Main Office',
    'address1' => '',
    'address2' => '',
    'city' => '',
    'state' => '',
    'postal_code' => '',
    'country' => 'US',
    'syncro_now' => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $form = [
        'legal_name' => trim($_POST['legal_name'] ?? ''),
        'dba_name' => trim($_POST['dba_name'] ?? ''),
        'status' => trim($_POST['status'] ?? 'LEAD'),
        'email' => trim($_POST['email'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'website' => trim($_POST['website'] ?? ''),
        'tax_exempt' => isset($_POST['tax_exempt']) ? 1 : 0,
        'notes' => trim($_POST['notes'] ?? ''),
        'contact_first_name' => trim($_POST['contact_first_name'] ?? ''),
        'contact_last_name' => trim($_POST['contact_last_name'] ?? ''),
        'contact_title' => trim($_POST['contact_title'] ?? ''),
        'contact_email' => trim($_POST['contact_email'] ?? ''),
        'contact_phone' => trim($_POST['contact_phone'] ?? ''),
        'location_name' => trim($_POST['location_name'] ?? 'Main Office'),
        'address1' => trim($_POST['address1'] ?? ''),
        'address2' => trim($_POST['address2'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'state' => trim($_POST['state'] ?? ''),
        'postal_code' => trim($_POST['postal_code'] ?? ''),
        'country' => trim($_POST['country'] ?? 'US'),
        'syncro_now' => isset($_POST['syncro_now']) ? 1 : 0,
    ];

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $matchedClient = client_find_existing_match($form);
        $clientId = $matchedClient ? (int)$matchedClient['client_id'] : 0;
        $usedExistingClient = $clientId > 0;

        if ($usedExistingClient) {
            client_update($clientId, $form);
            if (!empty($matchedClient['primary_location_id'])) {
                client_update_location((int)$matchedClient['primary_location_id'], [
                    'location_name' => $form['location_name'] !== '' ? $form['location_name'] : 'Main Office',
                    'address1' => $form['address1'],
                    'address2' => $form['address2'],
                    'city' => $form['city'],
                    'state' => $form['state'],
                    'postal_code' => $form['postal_code'],
                    'country' => $form['country'] !== '' ? $form['country'] : 'US',
                    'is_primary' => 1,
                ]);
            } else {
                client_add_location($clientId, [
                    'location_name' => $form['location_name'] !== '' ? $form['location_name'] : 'Main Office',
                    'address1' => $form['address1'],
                    'address2' => $form['address2'],
                    'city' => $form['city'],
                    'state' => $form['state'],
                    'postal_code' => $form['postal_code'],
                    'country' => $form['country'] !== '' ? $form['country'] : 'US',
                    'is_primary' => 1,
                ]);
            }
        } else {
            $clientId = client_create($form);
            client_add_location($clientId, [
                'location_name' => $form['location_name'] !== '' ? $form['location_name'] : 'Main Office',
                'address1' => $form['address1'],
                'address2' => $form['address2'],
                'city' => $form['city'],
                'state' => $form['state'],
                'postal_code' => $form['postal_code'],
                'country' => $form['country'] !== '' ? $form['country'] : 'US',
                'is_primary' => 1,
            ]);
        }

        if ($form['contact_first_name'] !== '' && $form['contact_last_name'] !== '') {
            if ($usedExistingClient && !empty($matchedClient['primary_contact_id'])) {
                client_update_contact((int)$matchedClient['primary_contact_id'], [
                    'first_name' => $form['contact_first_name'],
                    'last_name' => $form['contact_last_name'],
                    'title' => $form['contact_title'],
                    'email' => $form['contact_email'] !== '' ? $form['contact_email'] : $form['email'],
                    'phone' => $form['contact_phone'] !== '' ? $form['contact_phone'] : $form['phone'],
                    'is_primary' => 1,
                    'is_billing_contact' => 1,
                    'is_technical_contact' => 1,
                ]);
            } else {
                client_add_contact($clientId, [
                    'first_name' => $form['contact_first_name'],
                    'last_name' => $form['contact_last_name'],
                    'title' => $form['contact_title'],
                    'email' => $form['contact_email'] !== '' ? $form['contact_email'] : $form['email'],
                    'phone' => $form['contact_phone'] !== '' ? $form['contact_phone'] : $form['phone'],
                    'is_primary' => 1,
                    'is_billing_contact' => 1,
                    'is_technical_contact' => 1,
                ]);
            }
        }

        $pdo->commit();

        $messages = [$usedExistingClient ? 'Existing OPS client matched and updated.' : 'Client created.'];
        $syncroErrors = [];
        if (!empty($form['syncro_now']) && syncro_is_configured()) {
            $syncResult = syncro_sync_client($clientId);
            if (!empty($syncResult['ok'])) {
                $messages[] = function_exists('syncro_action_success_message')
                    ? syncro_action_success_message((string)($syncResult['action'] ?? ''))
                    : (!empty($syncResult['action']) && $syncResult['action'] === 'updated'
                        ? 'Syncro customer updated successfully.'
                        : 'Syncro customer created successfully.');
            } else {
                $syncroErrors = array_values(array_filter((array)($syncResult['errors'] ?? [])));
            }
        }

        $_SESSION['flash_msg'] = implode(' ', $messages);
        if ($syncroErrors) {
            $_SESSION['flash_error'] = implode(' ', $syncroErrors);
        }

        header('Location: ' . BASE_URL . '/clients/view.php?client_id=' . $clientId);
        exit;
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

page_header('New Client', 'clients');
?>
<?php if ($error !== ''): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div style="margin-bottom:14px;opacity:.78;line-height:1.5;">This form now mirrors the contract onboarding flow: company, primary location, primary contact, then optional immediate Syncro push.</div>
<form method="post" style="display:grid;gap:16px;max-width:1120px;">
  <?= csrf_field() ?>

  <div class="card" style="padding:18px;display:grid;gap:14px;">
    <div>
      <h2 style="margin:0 0 4px;font-size:20px;">Company</h2>
      <div style="opacity:.74;font-size:13px;">Core company record used by contracts, invoicing, and Syncro organization sync.</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div><label>Legal Name *</label><br><input type="text" name="legal_name" required value="<?= htmlspecialchars($form['legal_name']) ?>" style="width:100%;"></div>
      <div><label>DBA Name</label><br><input type="text" name="dba_name" value="<?= htmlspecialchars($form['dba_name']) ?>" style="width:100%;"></div>
    </div>
    <div style="display:grid;grid-template-columns:220px 1fr 260px;gap:12px;">
      <div><label>Status</label><br><select name="status" style="width:100%;"><?php foreach (['LEAD'=>'Lead','ACTIVE'=>'Active','SUSPENDED'=>'Suspended','FORMER'=>'Former'] as $k=>$v): ?><option value="<?= $k ?>" <?= $form['status']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Email *</label><br><input type="email" name="email" required value="<?= htmlspecialchars($form['email']) ?>" style="width:100%;"></div>
      <div><label>Phone *</label><br><input type="text" name="phone" required value="<?= htmlspecialchars($form['phone']) ?>" style="width:100%;"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
      <div><label>Website</label><br><input type="url" name="website" value="<?= htmlspecialchars($form['website']) ?>" style="width:100%;"></div>
      <label style="display:flex;align-items:center;gap:8px;padding-bottom:10px;"><input type="checkbox" name="tax_exempt" value="1" <?= !empty($form['tax_exempt'])?'checked':'' ?>> Tax Exempt</label>
    </div>
    <div><label>Notes</label><br><textarea name="notes" rows="4" style="width:100%;"><?= htmlspecialchars($form['notes']) ?></textarea></div>
  </div>

  <div class="card" style="padding:18px;display:grid;gap:14px;">
    <div>
      <h2 style="margin:0 0 4px;font-size:20px;">Primary Location</h2>
      <div style="opacity:.74;font-size:13px;">This location is required for Syncro-ready client records.</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div><label>Location Name *</label><br><input type="text" name="location_name" required value="<?= htmlspecialchars($form['location_name']) ?>" style="width:100%;"></div>
      <div><label>Address 1 *</label><br><input type="text" name="address1" required value="<?= htmlspecialchars($form['address1']) ?>" style="width:100%;"></div>
    </div>
    <div><label>Address 2</label><br><input type="text" name="address2" value="<?= htmlspecialchars($form['address2']) ?>" style="width:100%;"></div>
    <div style="display:grid;grid-template-columns:1.2fr .8fr .5fr .6fr .5fr;gap:12px;">
      <div><label>City *</label><br><input type="text" name="city" required value="<?= htmlspecialchars($form['city']) ?>" style="width:100%;"></div>
      <div><label>State *</label><br><input type="text" name="state" required value="<?= htmlspecialchars($form['state']) ?>" style="width:100%;"></div>
      <div><label>Postal Code *</label><br><input type="text" name="postal_code" required value="<?= htmlspecialchars($form['postal_code']) ?>" style="width:100%;"></div>
      <div><label>Country *</label><br><input type="text" name="country" required value="<?= htmlspecialchars($form['country']) ?>" style="width:100%;"></div>
      <div></div>
    </div>
  </div>

  <div class="card" style="padding:18px;display:grid;gap:14px;">
    <div>
      <h2 style="margin:0 0 4px;font-size:20px;">Primary Contact</h2>
      <div style="opacity:.74;font-size:13px;">Optional, but strongly recommended for Syncro, receipts, and future portal use.</div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
      <div><label>First Name</label><br><input type="text" name="contact_first_name" value="<?= htmlspecialchars($form['contact_first_name']) ?>" style="width:100%;"></div>
      <div><label>Last Name</label><br><input type="text" name="contact_last_name" value="<?= htmlspecialchars($form['contact_last_name']) ?>" style="width:100%;"></div>
      <div><label>Title</label><br><input type="text" name="contact_title" value="<?= htmlspecialchars($form['contact_title']) ?>" style="width:100%;"></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
      <div><label>Contact Email</label><br><input type="email" name="contact_email" value="<?= htmlspecialchars($form['contact_email']) ?>" style="width:100%;"></div>
      <div><label>Contact Phone</label><br><input type="text" name="contact_phone" value="<?= htmlspecialchars($form['contact_phone']) ?>" style="width:100%;"></div>
    </div>
  </div>

  <div class="card" style="padding:18px;display:grid;gap:12px;">
    <label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="syncro_now" value="1" <?= !empty($form['syncro_now']) ? 'checked' : '' ?>> Push this client to Syncro immediately after save</label>
    <div style="opacity:.74;font-size:13px;">If Syncro is configured and the record is complete, this will create or update the organization right away.</div>
    <div style="display:flex;gap:12px;align-items:center;">
      <button type="submit">Create Client</button>
      <a href="<?= htmlspecialchars(BASE_URL) ?>/clients/index.php">Cancel</a>
    </div>
  </div>
</form>
<?php page_footer(); ?>
