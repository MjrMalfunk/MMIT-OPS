<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/portal_access.php';

require_login();
require_recent_mfa();

$flashSuccess = null;
$flashError = null;
$currentUser = current_user();
$currentUserId = (int) ($currentUser['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = trim((string) ($_POST['action'] ?? 'create'));

    if ($action === 'create') {
        $result = portal_access_create_invite([
            'client_id' => (int) ($_POST['client_id'] ?? 0),
            'email' => (string) ($_POST['invite_email'] ?? ''),
            'name' => (string) ($_POST['invite_name'] ?? ''),
            'scope_code' => (string) ($_POST['scope_code'] ?? 'PORTAL'),
            'default_service' => (string) ($_POST['default_service'] ?? 'DASHBOARD'),
            'role_id' => (int) ($_POST['role_id'] ?? 0),
            'note' => (string) ($_POST['note'] ?? ''),
        ], $currentUserId);

        if (!empty($result['ok'])) {
            $flashSuccess = 'Portal invite sent.';
        } else {
            $flashError = (string) ($result['error'] ?? 'Invite could not be created.');
        }
    } elseif ($action === 'delete') {
        $result = portal_access_delete_invite((int) ($_POST['invite_id'] ?? 0), $currentUserId);
        if (!empty($result['ok'])) {
            $flashSuccess = 'Portal user deleted. Invite history and saved security setup for that email were cleared.';
        } else {
            $flashError = (string) ($result['error'] ?? 'Portal user could not be deleted.');
        }
    } elseif ($action === 'resend') {
        $result = portal_access_resend_invite((int) ($_POST['invite_id'] ?? 0), (string) ($_POST['service'] ?? ''));
        if (!empty($result['ok'])) {
            $flashSuccess = 'Fresh secure access link sent.';
        } else {
            $flashError = (string) ($result['error'] ?? 'Invite email could not be resent.');
        }
    }
}

$clients = portal_access_client_options();
$roles = portal_access_role_options();
$invites = portal_access_active_invites();
page_header('Portal Invites', 'admin');
?>
<div style="display:grid;gap:18px;">
  <div style="display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 8px;">Portal invites</h1>
      <p style="margin:0;opacity:.82;max-width:780px;line-height:1.65;">Invite the 1 or 2 company admins you want using the branded access layer. Choose the client, assign a portal role, and send a secure sign-in invitation.</p>
    </div>
    <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/index.php">Back to Admin</a>
  </div>

  <?php if (!portal_access_table_ready()): ?>
    <div class="flash-error">The portal invite table is not installed yet. Run <code>sql/2026_04_11_portal_access_invites.sql</code> first.</div>
  <?php endif; ?>
  <?php if ($flashSuccess): ?><div class="flash-success"><?= htmlspecialchars($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="flash-error"><?= htmlspecialchars($flashError) ?></div><?php endif; ?>

  <div style="display:grid;grid-template-columns:minmax(320px,460px) minmax(0,1fr);gap:18px;align-items:start;">
    <section class="card" style="padding:18px;">
      <h2 style="margin-top:0;">Create a new invite</h2>
      <form method="post" style="display:grid;gap:12px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <div>
          <label for="client_id">Client</label>
          <select id="client_id" name="client_id">
            <option value="0">No specific client selected</option>
            <?php foreach ($clients as $client): ?>
              <option value="<?= (int) $client['client_id'] ?>"><?= htmlspecialchars(portal_access_client_label($client)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="invite_name">Contact name</label>
          <input id="invite_name" name="invite_name" type="text" maxlength="150" placeholder="Jane Admin">
        </div>

        <div>
          <label for="invite_email">Email</label>
          <input id="invite_email" name="invite_email" type="email" required placeholder="admin@client.com">
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div>
            <label for="scope_code">Scope</label>
            <select id="scope_code" name="scope_code">
              <option value="PORTAL">Portal access</option>
              <option value="WORKSPACE">Workspace-first</option>
              <option value="BILLING">Billing-first</option>
            </select>
          </div>
          <div>
            <label for="default_service">Landing lane</label>
            <select id="default_service" name="default_service">
              <option value="DASHBOARD" selected>Portal dashboard</option>
              <option value="WORKSPACE">Workspace</option>
              <option value="BILLING">Billing</option>
            </select>
          </div>
        </div>

        <div>
          <label for="role_id">Portal role</label>
          <select id="role_id" name="role_id">
            <?php foreach ($roles as $role): ?>
              <option value="<?= (int) ($role['role_id'] ?? 0) ?>"<?= (($role['key'] ?? '') === 'CLIENT_ADMIN') ? ' selected' : '' ?>><?= htmlspecialchars((string) ($role['name'] ?? 'Client Admin')) ?></option>
            <?php endforeach; ?>
          </select>
          <div style="opacity:.72;font-size:13px;margin-top:6px;line-height:1.5;">Billing sees only billing. Manager sees portal operations without billing. Client Admin sees the full client-side portal.</div>
        </div>

        <div>
          <label for="note">Admin note</label>
          <textarea id="note" name="note" rows="3" placeholder="Optional note for your own tracking."></textarea>
        </div>

        <button class="btn btn-primary" type="submit">Send portal invite</button>
      </form>
    </section>

    <section class="card" style="padding:18px;overflow:auto;">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <h2 style="margin:0;">Recent invites</h2>
        <span style="opacity:.72;"><?= count($invites) ?> record<?= count($invites) === 1 ? '' : 's' ?></span>
      </div>
      <table style="width:100%;border-collapse:collapse;margin-top:14px;">
        <thead>
          <tr>
            <th style="text-align:left;padding:10px 8px;">Contact</th>
            <th style="text-align:left;padding:10px 8px;">Client</th>
            <th style="text-align:left;padding:10px 8px;">Role</th>
            <th style="text-align:left;padding:10px 8px;">State</th>
            <th style="text-align:left;padding:10px 8px;">Last sent</th>
            <th style="text-align:left;padding:10px 8px;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$invites): ?>
            <tr><td colspan="6" style="padding:12px 8px;opacity:.76;">No invites yet. Create the first one and the branded front door wakes up.</td></tr>
          <?php endif; ?>
          <?php foreach ($invites as $invite): ?>
            <?php
              $clientLabel = trim((string) ($invite['dba_name'] ?? '')) ?: trim((string) ($invite['legal_name'] ?? '')) ?: 'Not pinned yet';
              $state = !empty($invite['accepted_at']) ? 'Accepted' : 'Pending';
              $stateTone = !empty($invite['accepted_at']) ? '#c6f5d0' : '#d7ecff';
            ?>
            <tr>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <strong><?= htmlspecialchars(trim((string) ($invite['invite_name'] ?? '')) ?: (string) $invite['invite_email']) ?></strong><br>
                <span style="opacity:.72;"><?= htmlspecialchars((string) $invite['invite_email']) ?></span>
              </td>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <?= htmlspecialchars($clientLabel) ?><br>
                <span style="opacity:.72;"><?= htmlspecialchars((string) ($invite['default_service'] ?? 'WORKSPACE')) ?> lane</span>
              </td>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <strong><?= htmlspecialchars((string) (($invite['role_name'] ?? '') !== '' ? $invite['role_name'] : portal_access_role_label($invite))) ?></strong>
                <?php if (!empty($invite['role_description'])): ?><div style="opacity:.72;margin-top:6px;font-size:13px;"><?= htmlspecialchars((string) $invite['role_description']) ?></div><?php endif; ?>
              </td>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <span style="display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:<?= htmlspecialchars($stateTone) ?>;"><?= htmlspecialchars($state) ?></span>
                <?php if (!empty($invite['accepted_at'])): ?><div style="opacity:.72;margin-top:6px;font-size:13px;">First used <?= htmlspecialchars((string) $invite['accepted_at']) ?></div><?php endif; ?>
              </td>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <?= htmlspecialchars((string) ($invite['last_sent_at'] ?? 'Not sent yet')) ?>
              </td>
              <td style="padding:12px 8px;border-top:1px solid rgba(255,255,255,.08);">
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <form method="post" style="display:inline;">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                      <input type="hidden" name="action" value="resend">
                      <input type="hidden" name="invite_id" value="<?= (int) $invite['invite_id'] ?>">
                      <input type="hidden" name="service" value="<?= htmlspecialchars((string) ($invite['default_service'] ?? 'WORKSPACE')) ?>">
                      <button class="btn btn-secondary" style="width:auto;padding:8px 12px;min-height:0;" type="submit">Resend</button>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this portal user and clear all invite history for this email? They will need a fresh invite and will set up security again.');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="invite_id" value="<?= (int) $invite['invite_id'] ?>">
                      <button class="btn btn-secondary" style="width:auto;padding:8px 12px;min-height:0;" type="submit">Delete</button>
                    </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  </div>
</div>
<?php page_footer(); ?>
