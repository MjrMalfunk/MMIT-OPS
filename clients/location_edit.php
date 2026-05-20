<?php
 declare(strict_types=1);
 require_once __DIR__ . '/../inc/bootstrap.php';
 require_once __DIR__ . '/../inc/clients.php';
 require_once __DIR__ . '/../inc/layout.php';
 require_login();
 $locationId = (int)($_GET['location_id'] ?? $_POST['location_id'] ?? 0);
 $location = $locationId > 0 ? client_get_location_by_id($locationId) : null;
 if (!$location) { http_response_code(404); exit('Location not found.'); }
 $clientId = (int)$location['client_id'];
 $client = client_get_by_id($clientId);
 $error='';
 $form=['location_name'=>(string)$location['location_name'],'address1'=>(string)($location['address1']??''),'address2'=>(string)($location['address2']??''),'city'=>(string)($location['city']??''),'state'=>(string)($location['state']??''),'postal_code'=>(string)($location['postal_code']??''),'country'=>(string)($location['country']??'US'),'is_primary'=>!empty($location['is_primary'])?1:0,'notes'=>(string)($location['notes']??'')];
 if ($_SERVER['REQUEST_METHOD']==='POST') {
   csrf_validate_or_die();
   $form=['location_name'=>trim($_POST['location_name']??''),'address1'=>trim($_POST['address1']??''),'address2'=>trim($_POST['address2']??''),'city'=>trim($_POST['city']??''),'state'=>trim($_POST['state']??''),'postal_code'=>trim($_POST['postal_code']??''),'country'=>trim($_POST['country']??'US'),'is_primary'=>isset($_POST['is_primary'])?1:0,'notes'=>trim($_POST['notes']??'')];
   try { client_update_location($locationId,$form); $_SESSION['flash_msg']='Location updated.'; header('Location: '.BASE_URL.'/clients/view.php?client_id='.$clientId); exit; } catch (Throwable $e) { $error=$e->getMessage(); }
 }
 page_header('Edit Location', 'clients');
 ?>
 <p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">&larr; Back to <?= htmlspecialchars((string)($client['legal_name'] ?? 'client')) ?></a></p>
 <?php if ($error): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
 <form method="post"><?= csrf_field() ?><input type="hidden" name="location_id" value="<?= $locationId ?>">
 <p><label>Location Name *<br><input type="text" name="location_name" required value="<?= htmlspecialchars($form['location_name']) ?>"></label></p>
 <p><label>Address 1 *<br><input type="text" name="address1" required value="<?= htmlspecialchars($form['address1']) ?>"></label></p>
 <p><label>Address 2<br><input type="text" name="address2" value="<?= htmlspecialchars($form['address2']) ?>"></label></p>
 <p><label>City *<br><input type="text" name="city" required value="<?= htmlspecialchars($form['city']) ?>"></label></p>
 <p><label>State *<br><input type="text" name="state" required value="<?= htmlspecialchars($form['state']) ?>"></label></p>
 <p><label>Postal Code *<br><input type="text" name="postal_code" required value="<?= htmlspecialchars($form['postal_code']) ?>"></label></p>
 <p><label>Country<br><input type="text" name="country" value="<?= htmlspecialchars($form['country']) ?>"></label></p>
 <p><label><input type="checkbox" name="is_primary" value="1" <?= !empty($form['is_primary'])?'checked':'' ?>> Primary Location</label></p>
 <p><label>Notes<br><textarea name="notes" rows="4" cols="60"><?= htmlspecialchars($form['notes']) ?></textarea></label></p>
 <p><button type="submit">Save Location</button></p></form>
 <?php page_footer(); ?>
