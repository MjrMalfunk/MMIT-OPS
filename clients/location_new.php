<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/clients.php';
require_once __DIR__ . '/../inc/layout.php';
require_login();
$clientId = (int)($_GET['client_id'] ?? $_POST['client_id'] ?? 0);
$client = $clientId > 0 ? client_get_by_id($clientId) : null;
if (!$client) { http_response_code(404); exit('Client not found.'); }
$error='';
$form=['location_name'=>'','address1'=>'','address2'=>'','city'=>'','state'=>'','postal_code'=>'','country'=>'US','is_primary'=>0,'notes'=>''];
if ($_SERVER['REQUEST_METHOD']==='POST') {
 csrf_validate_or_die();
 $form=[
 'location_name'=>trim($_POST['location_name']??''),'address1'=>trim($_POST['address1']??''),'address2'=>trim($_POST['address2']??''),'city'=>trim($_POST['city']??''),'state'=>trim($_POST['state']??''),'postal_code'=>trim($_POST['postal_code']??''),'country'=>trim($_POST['country']??'US'),'is_primary'=>isset($_POST['is_primary'])?1:0,'notes'=>trim($_POST['notes']??''),
 ];
 try { client_add_location($clientId,$form); header('Location: '.BASE_URL.'/clients/view.php?client_id='.$clientId); exit; } catch (Throwable $e) { $error=$e->getMessage(); }
}
page_header('Add Location', 'clients');
?>
<p><a href="<?= htmlspecialchars(BASE_URL) ?>/clients/view.php?client_id=<?= $clientId ?>">&larr; Back to <?= htmlspecialchars((string)$client['legal_name']) ?></a></p>
<?php if ($error): ?><div style="color:#b00020;"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<div style="margin-bottom:12px;opacity:.78;line-height:1.5;">This location feeds Syncro. Address, city, state, and postal code are now required.</div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="client_id" value="<?= $clientId ?>">
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
