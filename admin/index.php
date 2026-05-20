<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/portal_access.php';
require_login();
$security = portal_access_security_snapshot();
page_header('Admin', 'admin');
?>
<div style="display:grid;gap:18px;">
  <div>
    <h1 style="margin:0 0 8px;">Admin</h1>
    <p style="margin:0;opacity:.82;line-height:1.65;max-width:820px;">Admin tools for go-live prep, client admin invites, and security live here. Use this area for portal invites, sign-in security, and go-live maintenance.</p>
  </div>

  <div class="admin-card-grid">
    <section class="card admin-card">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Portal access</div>
      <h2 style="margin:10px 0 10px;">Invites + secure links</h2>
      <p class="admin-card-copy">Invite the client contacts who should have portal access and choose exactly what each login can see.</p>
      <div class="admin-card-actions">
        <a class="btn btn-secondary btn-inline" style="text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/invites.php">Open portal invites</a>
      </div>
    </section>

    <section class="card admin-card">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Security posture</div>
      <h2 style="margin:10px 0 10px;">Your operator session</h2>
      <div class="admin-card-status">
        <div><strong>Auth method:</strong> <?= htmlspecialchars(strtoupper(str_replace('+', ' + ', (string) $security['auth_method']))) ?></div>
        <div><strong>TOTP:</strong> <?= !empty($security['totp_enabled']) ? 'Enabled' : 'Not enabled' ?></div>
        <div><strong>Passkey:</strong> <?= !empty($security['passkey_enabled']) ? 'Registered' : 'Not registered' ?></div>
        <div><strong>Step-up window:</strong> <?= !empty($security['stepup_recent']) ? 'Fresh' : 'Expired or not used yet' ?></div>
      </div>
      <div class="admin-card-actions">
        <a class="btn btn-secondary btn-inline" style="text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/mfa/setup.php">Open security center</a>
      </div>
    </section>

    <section class="card admin-card">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Go-live</div>
      <h2 style="margin:10px 0 10px;">Controlled maintenance</h2>
      <p class="admin-card-copy">High-impact cleanup still lives behind a recent MFA step-up, exactly where the sharp knives belong.</p>
      <div class="admin-card-actions">
        <a class="btn btn-secondary btn-inline" style="text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/go_live_reset.php">Open go-live reset</a>
      </div>
    </section>
  </div>
</div>
<?php page_footer(); ?>
