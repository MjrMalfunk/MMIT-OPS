<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/portal_access.php';

if (isset($_GET['logout'])) {
    portal_access_session_clear();
    header('Location: ' . BASE_URL . '/portal-access/index.php', true, 302);
    exit;
}

$flashSuccess = $_SESSION['portal_access_flash_ok'] ?? null;
$flashError = $_SESSION['portal_access_flash_error'] ?? null;
unset($_SESSION['portal_access_flash_ok'], $_SESSION['portal_access_flash_error']);

$requestedService = portal_access_safe_service((string) ($_GET['service'] ?? $_GET['requested'] ?? 'DASHBOARD'), 'DASHBOARD');
$next = portal_access_safe_next_path((string) ($_GET['next'] ?? '/client-preview.php'), '/client-preview.php');
$accessToken = trim((string) ($_GET['access'] ?? ''));
if ($accessToken !== '') {
    $verified = portal_access_verify_token($accessToken);
    if ($verified) {
        $invite = (array) $verified['invite'];
        $service = (string) $verified['service'];
        $inviteEmail = (string) ($invite['invite_email'] ?? '');
        portal_access_mark_accepted((int) ($invite['invite_id'] ?? 0));
        audit_event(null, 'PORTAL_ACCESS_LINK_USED', ['invite_id' => (int) ($invite['invite_id'] ?? 0), 'email' => (string) ($verified['email'] ?? '')]);

        $user = portal_access_load_user_by_email($inviteEmail);
        if ($user) {
            if (portal_access_user_has_totp((int) ($user['user_id'] ?? 0))) {
                portal_access_pending_verify_set([
                    'user_id' => (int) ($user['user_id'] ?? 0),
                    'invite_id' => (int) ($invite['invite_id'] ?? 0),
                    'service' => $service,
                    'next_url' => $next,
                ]);
                header('Location: ' . BASE_URL . '/portal-access/verify.php', true, 302);
                exit;
            }

            portal_access_login_user($user, 'email_link');
            portal_access_session_set($invite, 'DASHBOARD');
            auth_session_commit();
            if (!portal_access_user_security_ready((int) ($user['user_id'] ?? 0))) {
                header('Location: ' . portal_access_target_url('DASHBOARD', '/client-security.php?welcome=1&next=' . rawurlencode('/client-preview.php')), true, 302);
                exit;
            }
            header('Location: ' . portal_access_target_url('DASHBOARD', '/client-preview.php'), true, 302);
            exit;
        }

        portal_access_session_set($invite, $service);
        header('Location: ' . BASE_URL . '/portal-access/index.php?service=' . rawurlencode(strtolower($service)), true, 302);
        exit;
    }
    $flashError = 'That secure link is no longer valid. Request a fresh one below.';
}

$session = portal_access_session();
$invite = $session ? portal_access_current_invite() : null;
$activeService = $session ? portal_access_safe_service((string) ($session['service'] ?? $requestedService), $requestedService) : $requestedService;
$copy = portal_access_service_copy($activeService);
$clientLabel = '';
if ($invite) {
    $clientLabel = trim((string) ($invite['dba_name'] ?? '')) ?: trim((string) ($invite['legal_name'] ?? ''));
}
$workspaceUrl = 'https://midwestmanagedit.syncromsp.com/my_profile/user_login';
$assetUrl = 'https://midwestmanagedit.syncromsp.com/my_profile/user_login';
$billingUrl = BASE_URL . '/billing/index.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Portal Access | <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="icon" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon.ico">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=7">
  <style>
    .access-wrap { max-width: 1120px; margin: 0 auto; padding: 18px; }
    .access-shell { display:grid; gap:18px; }
    .access-hero, .access-grid { display:grid; gap:18px; }
    .access-hero { grid-template-columns:minmax(0,1.25fr) minmax(280px,.85fr); }
    .access-grid { grid-template-columns:minmax(0,1.1fr) minmax(300px,.9fr); }
    .access-card { padding:22px; }
    .eyebrow { display:inline-flex; align-items:center; padding:6px 10px; border-radius:999px; background:rgba(140,200,255,.10); border:1px solid rgba(140,200,255,.16); color:#d7ecff; font-size:.78rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
    .access-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:14px; }
    .access-actions .btn { width:auto; min-width:0; padding:10px 14px; }
    .lane-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; }
    .lane-card { padding:16px; }
    .lane-card h3 { margin:8px 0 8px; }
    .muted { color:var(--muted); }
    @media (max-width: 980px) { .access-hero, .access-grid, .lane-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="access-wrap">
    <?php if ($flashSuccess): ?><div class="flash-success"><?= htmlspecialchars((string) $flashSuccess) ?></div><?php endif; ?>
    <?php if ($flashError): ?><div class="flash-error"><?= htmlspecialchars((string) $flashError) ?></div><?php endif; ?>

    <div class="access-shell">
      <?php if (!$invite): ?>
        <section class="access-hero">
          <div class="glass access-card">
            <div class="eyebrow"><?= htmlspecialchars($copy['label']) ?></div>
            <h1 style="margin:12px 0 10px;">A branded handoff before the vendor portals take the wheel.</h1>
            <p class="muted">Use the invited email address for your company admin contact. We will send a short-lived secure link that opens the Midwest Managed IT access layer first, then lets you continue into workspace or billing.</p>
          </div>
          <aside class="glass access-card">
            <div class="eyebrow">Invite-only lane</div>
            <h2 style="margin:12px 0 10px;">Not every end user belongs here</h2>
            <p class="muted">This layer is meant for the 1 or 2 client admins you want handling visibility, billing, and higher-level portal access. Day-to-day support can still flow through the support portal without needing this extra key.</p>
          </aside>
        </section>

        <section class="glass access-card">
          <div class="access-grid">
            <div class="card access-card">
              <div class="eyebrow">Email link login</div>
              <h2 style="margin:12px 0 10px;">Request secure access</h2>
              <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/portal-access/start.php" style="display:grid;gap:12px;max-width:420px;">
                <input type="hidden" name="service" value="<?= htmlspecialchars($activeService) ?>">
                <div>
                  <label for="portal-access-email">Company admin email</label>
                  <input id="portal-access-email" name="email" type="email" autocomplete="email" required placeholder="you@company.com">
                </div>
                <button class="btn btn-primary" type="submit">Send secure link</button>
              </form>
            </div>
            <div class="card access-card">
              <div class="eyebrow">What happens next</div>
              <h2 style="margin:12px 0 10px;">The cleaner handoff</h2>
              <ul class="muted" style="margin:0;padding-left:18px;line-height:1.75;">
                <li>Secure link lands in the invited mailbox</li>
                <li>The Midwest Managed IT access layer opens first</li>
                <li>Workspace and billing stay in distinct lanes instead of one beige hallway</li>
              </ul>
            </div>
          </div>
        </section>
      <?php else: ?>
        <section class="access-hero">
          <div class="glass access-card">
            <div class="eyebrow"><?= htmlspecialchars($copy['label']) ?></div>
            <h1 style="margin:12px 0 10px;"><?= htmlspecialchars($copy['headline']) ?></h1>
            <p class="muted">Signed in as <strong><?= htmlspecialchars((string) ($invite['invite_email'] ?? '')) ?></strong><?php if ($clientLabel !== ''): ?> for <strong><?= htmlspecialchars($clientLabel) ?></strong><?php endif; ?>.</p>
            <div class="access-actions">
              <a class="btn btn-primary" href="<?= htmlspecialchars($workspaceUrl) ?>" target="_blank" rel="noopener noreferrer">Open Syncro sign-in</a>
              <a class="btn btn-secondary" href="<?= htmlspecialchars($billingUrl) ?>" target="_blank" rel="noopener noreferrer">Open billing center</a>
              <a class="btn btn-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/portal-access/index.php?logout=1">Use different email</a>
            </div>
          </div>
          <aside class="glass access-card">
            <div class="eyebrow">Routing notes</div>
            <h2 style="margin:12px 0 10px;">You are in the good hallway now</h2>
            <p class="muted">This is the branded handoff layer. It keeps the experience under Midwest Managed IT before you step into Syncro for live workspace data or the billing center for invoice visibility.</p>
          </aside>
        </section>

        <section class="glass access-card">
          <div class="lane-grid">
            <div class="card lane-card">
              <div class="eyebrow">Workspace</div>
              <h3>Assets, history, and workspace entry</h3>
              <p class="muted">Use the workspace lane when you want Syncro to handle the authenticated client workspace and asset-side visibility.</p>
              <div class="access-actions">
                <a class="btn btn-primary" href="<?= htmlspecialchars($workspaceUrl) ?>" target="_blank" rel="noopener noreferrer">Launch workspace</a>
                <a class="btn btn-secondary" href="<?= htmlspecialchars($assetUrl) ?>" target="_blank" rel="noopener noreferrer">Asset view</a>
              </div>
            </div>
            <div class="card lane-card">
              <div class="eyebrow">Billing</div>
              <h3>Invoices, payment status, and balances</h3>
              <p class="muted">Use the billing lane when you want invoice visibility and hosted payment handoff. Billing stays out of the support clutter on purpose.</p>
              <div class="access-actions">
                <a class="btn btn-primary" href="<?= htmlspecialchars($billingUrl) ?>" target="_blank" rel="noopener noreferrer">Open billing center</a>
              </div>
            </div>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
