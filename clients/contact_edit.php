<?php
 declare(strict_types=1);
 require_once __DIR__ . '/../inc/bootstrap.php';
 require_once __DIR__ . '/../inc/clients.php';
 require_once __DIR__ . '/../inc/layout.php';
 require_login();
 $contactId = (int)($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
 $contact = $contactId > 0 ? client_get_contact_by_id($contactId) : null;
 if (!$contact) { http_response_code(404); exit('Contact not found.'); }
 $clientId = (int)$contact['client_id'];
 $client = client_get_by_id($clientId);
 $locations = client_get_locations($clientId);
 $error='';
 $form=['location_id'=>(string)($contact['location_id']??''),'first_name'=>(string)$contact['first_name'],'last_name'=>(string)$contact['last_name'],'title'=>(string)($contact['title']??''),'email'=>(string)($contact['email']??''),'phone'=>(string)($contact['phone']??''),'mobile'=>(string)($contact['mobile']??''),'is_primary'=>!empty($contact['is_primary'])?1:0,'is_billing_contact'=>!empty($contact['is_billing_contact'])?1:0,'is_technical_contact'=>!empty($contact['is_technical_contact'])?1:0,'notes'=>(string)($contact['notes']??'')];
 if ($_SERVER['REQUEST_METHOD']==='POST') {
   csrf_validate_or_die();
   $form=['location_id'=>$_POST['location_id']??'','first_name'=>trim($_POST['first_name']??''),'last_name'=>trim($_POST['last_name']??''),'title'=>trim($_POST['title']??''),'email'=>trim($_POST['email']??''),'phone'=>trim($_POST['phone']??''),'mobile'=>trim($_POST['mobile']??''),'is_primary'=>isset($_POST['is_primary'])?1:0,'is_billing_contact'=>isset($_POST['is_billing_contact'])?1:0,'is_technical_contact'=>isset($_POST['is_technical_contact'])?1:0,'notes'=>trim($_POST['notes']??'')];
   try { client_update_contact($contactId,$form); $_SESSION['flash_msg']='Contact updated.'; header('Location: '.BASE_URL.'/clients/view.php?client_id='.$clientId); exit; } catch (Throwable $e) { $error=$e->getMessage(); }
 }
 page_header('Edit Contact', 'clients');
 ?>
 <p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">&larr; Back to <?= htmlspecialchars((string)($client['legal_name'] ?? 'client')) ?></a></p>
 <?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="contact_id" value="<?= $contactId ?>">
 <p><label>Location<br><select name="location_id"><option value="">-- none --</option><?php foreach ($locations as $loc): ?><option value="<?= (int)$loc['location_id'] ?>" <?= (string)$form['location_id']===(string)$loc['location_id']?'selected':'' ?>><?= htmlspecialchars((string)$loc['location_name']) ?></option><?php endforeach; ?></select></label></p>
 <p><label>First Name *<br><input type="text" name="first_name" required value="<?= htmlspecialchars($form['first_name']) ?>"></label></p>
 <p><label>Last Name *<br><input type="text" name="last_name" required value="<?= htmlspecialchars($form['last_name']) ?>"></label></p>
 <p><label>Title<br><input type="text" name="title" value="<?= htmlspecialchars($form['title']) ?>"></label></p>
 <p><label>Email<br><input type="email" name="email" value="<?= htmlspecialchars($form['email']) ?>"></label></p>
 <p><label>Phone<br><input type="text" name="phone" value="<?= htmlspecialchars($form['phone']) ?>"></label></p>
 <p><label>Mobile<br><input type="text" name="mobile" value="<?= htmlspecialchars($form['mobile']) ?>"></label></p>
 <p><label><input type="checkbox" name="is_primary" value="1" <?= !empty($form['is_primary'])?'checked':'' ?>> Primary Contact</label></p>
 <p><label><input type="checkbox" name="is_billing_contact" value="1" <?= !empty($form['is_billing_contact'])?'checked':'' ?>> Billing Contact</label></p>
 <p><label><input type="checkbox" name="is_technical_contact" value="1" <?= !empty($form['is_technical_contact'])?'checked':'' ?>> Technical Contact</label></p>
 <p><label>Notes<br><textarea name="notes" rows="4" cols="60"><?= htmlspecialchars($form['notes']) ?></textarea></label></p>
 <p><button type="submit">Save Contact</button></p></form>
 <?php page_footer(); ?>
