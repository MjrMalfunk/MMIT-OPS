<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/nav.php';


require_login();
require_recent_mfa('/mfa/backup-codes.php');

$u = current_user();
$codes = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $codes = mfa_generate_backup_codes((int)$u['user_id'], 10, true);
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
  <link rel="icon" type="image/png" href="/assets/brand/mmit-favicon-64.png">
  <link rel="stylesheet" href="/css/portal_shell.css?v=2">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Backup Codes</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;color:#e8eefc;background:linear-gradient(rgba(5,11,24,.80), rgba(5,11,24,.80)),url("<?= htmlspecialchars(BASE_URL) ?>/pix/portal-bg-dark.jpg") center/cover no-repeat fixed;}
.wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px 20px 28px}
.card{width:min(720px,94vw);background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:16px;padding:20px}
code{display:block;padding:12px;background:rgba(0,0,0,.35);border-radius:10px;margin:8px 0;font-size:16px}
button{padding:12px 16px;border-radius:10px;border:0;background:#2f6df6;color:#fff;font-weight:700;cursor:pointer}
.warn{opacity:.85}

    .brandline{display:flex;align-items:center;gap:12px}
    .brandline img{height:36px;width:auto;filter:drop-shadow(0 6px 18px rgba(0,0,0,.35))}
    .brandline .titles{display:flex;flex-direction:column}

      /* centered pill nav */
      .navwrap{display:flex;justify-content:center;margin:12px 0 0}
      .nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
      .navpill{padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#dbeafe;text-decoration:none;font-size:13px}
      .navpill:hover{border-color:rgba(120,160,255,.55)}
      .navpill.active{background:rgba(47,108,255,.35);border-color:rgba(47,108,255,.65)}

.head{width:min(720px,94vw);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin:14px 0}
.warn{opacity:.85}
</style>

  </head>
<body>
<div class="wrap">
  <div class="head">
    <div class="brandline">
      <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" alt="Midwest Managed IT">
      <div class="titles">
        <div style="font-weight:700">Security</div>
        <div class="warn">Backup Codes</div>
      </div>
    </div>
    <?php render_pills('security'); ?>
  </div>
<div class="card">
<h2>Backup Codes</h2>
<p class="warn">
Backup codes can be used if you lose access to your authenticator.
Each code can be used <b>once</b>. Treat them like passwords.
</p>

<form method="post">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
  <button type="submit">Generate new backup codes</button>
</form>

<?php if (is_array($codes)): ?>
  <h3>Your new codes (copy now)</h3>
  <p><b>These will not be shown again.</b></p>
  <?php foreach ($codes as $c): ?>
    <code><?= htmlspecialchars($c) ?></code>
  <?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</body>
</html>
