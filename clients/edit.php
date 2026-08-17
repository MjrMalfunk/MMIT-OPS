<?php
 declare(strict_types=1);
 require_once __DIR__ . '/../inc/bootstrap.php';
 require_once __DIR__ . '/../inc/clients.php';
 require_once __DIR__ . '/../inc/syncro.php';
 require_once __DIR__ . '/../inc/layout.php';
 require_login();
 $syncroEnabled = syncro_is_enabled();
 $clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
 $client = $clientId > 0 ? client_get_by_id($clientId) : null;
 if (!$client) { http_response_code(404); exit('Client not found.'); }
 $error = '';
 $form = [
   'legal_name' => (string)($client['legal_name'] ?? ''),
   'dba_name' => (string)($client['dba_name'] ?? ''),
   'status' => (string)($client['status'] ?? 'LEAD'),
   'email' => (string)($client['email'] ?? ''),
   'phone' => (string)($client['phone'] ?? ''),
   'website' => (string)($client['website'] ?? ''),
   'tax_exempt' => !empty($client['tax_exempt']) ? 1 : 0,
   'notes' => (string)($client['notes'] ?? ''),
   'syncro_now' => 0,
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
     'syncro_now' => $syncroEnabled && isset($_POST['syncro_now']) ? 1 : 0,
   ];
   try {
     client_update($clientId, $form);
     $messages = ['Client updated.'];
     if ($syncroEnabled && !empty($form['syncro_now']) && syncro_is_configured()) {
       $syncResult = syncro_sync_client($clientId);
       if (!empty($syncResult['ok'])) {
         $messages[] = syncro_action_success_message((string)($syncResult['action'] ?? ''));
       } elseif (!empty($syncResult['errors'])) {
         $_SESSION['flash_error'] = implode(' ', array_map('strval', (array)$syncResult['errors']));
       }
     }
     $_SESSION['flash_msg'] = implode(' ', $messages);
     header('Location: ' . BASE_URL . '/clients/view.php?client_id=' . $clientId);
     exit;
   } catch (Throwable $e) { $error = $e->getMessage(); }
 }
 page_header('Edit Client', 'clients');
 ?>
 <p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">&larr; Back to <?= htmlspecialchars((string)$client['legal_name']) ?></a></p>
 <?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <form method="post" style="display:grid;gap:16px;max-width:960px;">
   <?= csrf_field() ?>
   <input type="hidden" name="client_id" value="<?= $clientId ?>">
   <div class="card" style="padding:18px;display:grid;gap:14px;">
     <div><h2 style="margin:0 0 4px;font-size:20px;">Company</h2><div style="opacity:.74;font-size:13px;">Edit the core company record used by contracts and invoicing<?= $syncroEnabled ? ', plus Syncro' : '' ?>.</div></div>
     <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
       <div><label>Legal Name *</label><br><input type="text" name="legal_name" required value="<?= htmlspecialchars($form['legal_name']) ?>" style="width:100%;"></div>
       <div><label>DBA Name</label><br><input type="text" name="dba_name" value="<?= htmlspecialchars($form['dba_name']) ?>" style="width:100%;"></div>
     </div>
     <div style="display:grid;grid-template-columns:220px 1fr 260px;gap:12px;">
       <div><label>Status</label><br><select name="status" style="width:100%;"><?php foreach (['LEAD'=>'Lead','ACTIVE'=>'Active','SUSPENDED'=>'Suspended','FORMER'=>'Former'] as $k=>$v): ?><option value="<?= $k ?>" <?= $form['status']===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
       <div><label>Email</label><br><input type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" style="width:100%;"></div>
       <div><label>Phone</label><br><input type="text" name="phone" value="<?= htmlspecialchars($form['phone']) ?>" style="width:100%;"></div>
     </div>
     <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
       <div><label>Website</label><br><input type="url" name="website" value="<?= htmlspecialchars($form['website']) ?>" style="width:100%;"></div>
       <label style="display:flex;align-items:center;gap:8px;padding-bottom:10px;"><input type="checkbox" name="tax_exempt" value="1" <?= !empty($form['tax_exempt'])?'checked':'' ?>> Tax Exempt</label>
     </div>
     <div><label>Notes</label><br><textarea name="notes" rows="4" style="width:100%;"><?= htmlspecialchars($form['notes']) ?></textarea></div>
   </div>
   <div class="card" style="padding:18px;display:grid;gap:12px;">
     <?php if ($syncroEnabled): ?>
     <label style="display:flex;align-items:center;gap:10px;"><input type="checkbox" name="syncro_now" value="1" <?= !empty($form['syncro_now']) ? 'checked' : '' ?>> Update Syncro after save</label>
     <?php else: ?>
     <div style="opacity:.78;font-size:13px;">Syncro is archived/disabled. This update will remain in OPS only.</div>
     <?php endif; ?>
     <div style="display:flex;gap:12px;align-items:center;"><button type="submit">Save Client</button><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">Cancel</a></div>
   </div>
 </form>
 <?php page_footer(); ?>
