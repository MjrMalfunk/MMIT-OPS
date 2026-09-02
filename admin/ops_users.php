<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/ops_users.php';

require_login();
require_recent_mfa();

$current = current_user() ?: [];
$currentUserId = (int) ($current['user_id'] ?? 0);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? ''));
    $result = ['ok' => false, 'error' => 'Unknown OPS user action.'];

    if ($action === 'invite') {
        $result = ops_user_invite_create(
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['display_name'] ?? ''),
            $currentUserId
        );
        if (!empty($result['ok'])) {
            $success = 'OPS invitation sent.';
        }
    } elseif ($action === 'resend') {
        $result = ops_user_invite_resend(
            (int) ($_POST['invite_id'] ?? 0),
            $currentUserId
        );
        if (!empty($result['ok'])) {
            $success = 'A fresh one-time invitation was sent.';
        }
    } elseif ($action === 'revoke') {
        $result = ops_user_invite_revoke(
            (int) ($_POST['invite_id'] ?? 0),
            $currentUserId
        );
        if (!empty($result['ok'])) {
            $success = 'Invitation revoked.';
        }
    } elseif ($action === 'set_active') {
        $active = (string) ($_POST['active'] ?? '') === '1';
        $result = ops_user_set_active(
            (int) ($_POST['user_id'] ?? 0),
            $active,
            $currentUserId
        );
        if (!empty($result['ok'])) {
            $success = $active ? 'OPS user enabled.' : 'OPS user disabled.';
        }
    }

    if (empty($result['ok'])) {
        $error = (string) ($result['error'] ?? 'The action could not be completed.');
    }
}

$users = ops_users_list_internal();
$invites = ops_user_invite_table_ready() ? ops_user_invites_list() : [];

function ops_invite_state(array $invite): array
{
    if (!empty($invite['accepted_at'])) {
        return ['Accepted', '#c6f5d0'];
    }
    if (!empty($invite['revoked_at'])) {
        return ['Revoked', '#f3b7b7'];
    }
    if (strtotime((string) ($invite['expires_at'] ?? '')) <= time()) {
        return ['Expired', '#f5d18a'];
    }
    return ['Pending', '#d7ecff'];
}

page_header('OPS Users', 'admin');
?>
<style>
.ops-users-page { display:grid; gap:18px; }
.ops-users-grid { display:grid; grid-template-columns:minmax(300px,420px) minmax(0,1fr); gap:18px; align-items:start; }
.ops-users-form { display:grid; gap:12px; }
.ops-users-form .btn { width:auto; justify-self:start; }
.ops-users-table { width:100%; border-collapse:collapse; }
.ops-users-table th, .ops-users-table td { padding:11px 9px; text-align:left; border-top:1px solid rgba(255,255,255,.08); vertical-align:top; }
.ops-users-table th { border-top:0; opacity:.72; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
.ops-users-actions { display:flex; gap:8px; flex-wrap:wrap; }
.ops-users-actions form { margin:0; }
.ops-users-actions .btn { width:auto; min-height:0; padding:7px 10px; }
.ops-users-status { display:inline-flex; padding:5px 9px; border-radius:999px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.08); }
@media (max-width:900px) {
  .ops-users-grid { grid-template-columns:1fr; }
  .ops-users-table, .ops-users-table thead, .ops-users-table tbody, .ops-users-table tr, .ops-users-table th, .ops-users-table td { display:block; width:100%; }
  .ops-users-table thead { display:none; }
  .ops-users-table tr { padding:10px 0; border-top:1px solid rgba(255,255,255,.08); }
  .ops-users-table td { padding:6px 0; border:0; }
}
</style>

<div class="ops-users-page">
  <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 8px;">OPS users</h1>
      <p style="margin:0;opacity:.82;max-width:800px;line-height:1.6;">Invite trusted internal operators. Password sign-in requires TOTP; a registered passkey satisfies the MFA requirement.</p>
    </div>
    <a class="btn btn-secondary btn-inline" style="text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/index.php">Back to Admin</a>
  </div>

  <?php if ($success !== ''): ?><div class="flash-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <?php if (!ops_user_invite_table_ready()): ?>
    <div class="flash-error">The OPS invitation table is not installed.</div>
  <?php else: ?>
    <div class="ops-users-grid">
      <section class="card" style="padding:18px;">
        <h2 style="margin-top:0;">Invite an operator</h2>
        <p style="opacity:.78;line-height:1.55;">The link expires after 24 hours and can be used once. The recipient creates their own password before mandatory TOTP setup.</p>
        <form method="post" class="ops-users-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
          <input type="hidden" name="action" value="invite">
          <div>
            <label for="display_name">Display name</label>
            <input id="display_name" name="display_name" maxlength="150" required autocomplete="name">
          </div>
          <div>
            <label for="email">Email address</label>
            <input id="email" name="email" type="email" maxlength="254" required autocomplete="email">
          </div>
          <button class="btn btn-primary" type="submit">Send OPS invitation</button>
        </form>
      </section>

      <section class="card" style="padding:18px;overflow:auto;">
        <h2 style="margin-top:0;">Internal operators</h2>
        <table class="ops-users-table">
          <thead><tr><th>User</th><th>Security</th><th>Last login</th><th>Action</th></tr></thead>
          <tbody>
          <?php foreach ($users as $user): ?>
            <tr>
              <td>
                <strong><?= htmlspecialchars((string) $user['display_name']) ?></strong><br>
                <span style="opacity:.72;"><?= htmlspecialchars((string) $user['email']) ?></span>
              </td>
              <td>
                TOTP: <?= !empty($user['totp_enabled']) ? 'Enabled' : 'Not configured' ?><br>
                Passkey: <?= !empty($user['passkey_enabled']) ? 'Registered' : 'Not registered' ?>
              </td>
              <td><?= htmlspecialchars((string) ($user['last_login_at'] ?? 'Never')) ?></td>
              <td>
                <?php if ((int) $user['user_id'] === $currentUserId): ?>
                  <span style="opacity:.72;">Current user</span>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="set_active">
                    <input type="hidden" name="user_id" value="<?= (int) $user['user_id'] ?>">
                    <input type="hidden" name="active" value="<?= !empty($user['is_active']) ? '0' : '1' ?>">
                    <button class="btn btn-secondary" type="submit"><?= !empty($user['is_active']) ? 'Disable' : 'Enable' ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </section>
    </div>

    <section class="card" style="padding:18px;overflow:auto;">
      <h2 style="margin-top:0;">Invitation history</h2>
      <table class="ops-users-table">
        <thead><tr><th>Recipient</th><th>State</th><th>Expires</th><th>Last sent</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$invites): ?><tr><td colspan="5" style="opacity:.72;">No OPS invitations yet.</td></tr><?php endif; ?>
        <?php foreach ($invites as $invite): ?>
          <?php [$state, $tone] = ops_invite_state($invite); ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars((string) $invite['display_name']) ?></strong><br>
              <span style="opacity:.72;"><?= htmlspecialchars((string) $invite['email']) ?></span>
            </td>
            <td><span class="ops-users-status" style="color:<?= htmlspecialchars($tone) ?>;"><?= htmlspecialchars($state) ?></span></td>
            <td><?= htmlspecialchars((string) $invite['expires_at']) ?></td>
            <td><?= htmlspecialchars((string) ($invite['last_sent_at'] ?? 'Not sent')) ?></td>
            <td>
              <?php if ($state === 'Pending'): ?>
                <div class="ops-users-actions">
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resend">
                    <input type="hidden" name="invite_id" value="<?= (int) $invite['invite_id'] ?>">
                    <button class="btn btn-secondary" type="submit">Resend</button>
                  </form>
                  <form method="post" onsubmit="return confirm('Revoke this OPS invitation?');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="revoke">
                    <input type="hidden" name="invite_id" value="<?= (int) $invite['invite_id'] ?>">
                    <button class="btn btn-danger" type="submit">Revoke</button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>
</div>
<?php page_footer(); ?>
