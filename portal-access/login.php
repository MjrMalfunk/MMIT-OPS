<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/portal_access.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (isset($_GET['logout'])) {
    portal_access_logout_customer_session();
    header('Location: ' . portal_access_target_url('DASHBOARD', '/'), true, 302);
    exit;
}

$user = current_user();
if ($user && (($user['user_type'] ?? '') === 'CUSTOMER') && !empty($_SESSION['client_security_ready'])) {
    header('Location: ' . portal_access_target_url('DASHBOARD', '/client-preview.php'), true, 302);
    exit;
}

$next = trim((string) ($_GET['next'] ?? '/client-preview.php'));
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
    $next = '/client-preview.php';
}
$returnUrl = portal_access_target_url('DASHBOARD', '/?next=' . rawurlencode($next));
$emailPrefill = portal_access_normalize_email((string) ($_GET['email'] ?? ''));
$autoPasskey = ((string) ($_GET['auto'] ?? '')) === 'passkey';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Client Login | <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="icon" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon.ico">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=8">
  <style>
    .wrap{max-width:960px;margin:0 auto;padding:18px;display:grid;gap:18px}
    .grid{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(320px,.95fr);gap:18px}
    .eyebrow{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:rgba(140,200,255,.10);border:1px solid rgba(140,200,255,.16);color:#d7ecff;font-size:.78rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
    .actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
    .actions .btn{width:auto;min-width:0;padding:10px 14px}
    @media(max-width:900px){.grid{grid-template-columns:1fr}}
  </style>
</head>
<body>
<div class="wrap">
  <section class="grid">
    <div class="glass access-card">
      <div class="eyebrow">Client login</div>
      <h1 style="margin:12px 0 10px;">Passkey first. Secure link when you need a spare key.</h1>
      <p class="muted">Use your invited company admin email. If this device already holds your passkey, that should be the fast lane. If not, use the email link fallback and then confirm with 2FA.</p>
    </div>
    <aside class="glass access-card">
      <div class="eyebrow">Recovery lane</div>
      <p class="muted" style="margin-top:12px;">New machine? Lost browser profile? The fallback path is built for that exact rainy-day moment: email link first, 2FA second, dashboard third.</p>
    </aside>
  </section>

  <section class="grid">
    <div class="glass access-card">
      <div class="eyebrow">Passkey</div>
      <h2 style="margin:12px 0 10px;">Sign in with passkey</h2>
      <div style="display:grid;gap:12px;max-width:420px;">
        <div>
          <label for="client-login-email">Invited email</label>
          <input id="client-login-email" type="email" autocomplete="email" value="<?= htmlspecialchars($emailPrefill) ?>" placeholder="you@company.com">
        </div>
        <button class="btn btn-primary" id="btnPasskey" type="button">Sign in with passkey</button>
        <div id="passkeyMsg" class="muted"></div>
      </div>
    </div>
    <div class="glass access-card">
      <div class="eyebrow">Email link</div>
      <h2 style="margin:12px 0 10px;">Use the fallback lane</h2>
      <form method="post" action="<?= htmlspecialchars(BASE_URL) ?>/portal-access/start.php" style="display:grid;gap:12px;max-width:420px;">
        <input type="hidden" name="service" value="DASHBOARD">
        <input type="hidden" name="return_url" value="<?= htmlspecialchars($returnUrl) ?>">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
        <div>
          <label for="email-link-email">Invited email</label>
          <input id="email-link-email" name="email" type="email" autocomplete="email" value="<?= htmlspecialchars($emailPrefill) ?>" required placeholder="you@company.com">
        </div>
        <button class="btn btn-secondary" type="submit">Email me a secure link</button>
      </form>
      <p class="muted" style="margin-top:12px;">Need first access? Ask your company admin to invite you through Midwest Managed IT.</p>
    </div>
  </section>
</div>
<script>
const msg = document.getElementById('passkeyMsg');
const emailEl = document.getElementById('client-login-email');
const nextUrl = <?= json_encode(portal_access_target_url('DASHBOARD', $next), JSON_UNESCAPED_SLASHES) ?>;
const csrf = <?= json_encode(csrf_token()) ?>;
const autoPasskey = <?= json_encode($autoPasskey) ?>;
function setMsg(text){ msg.textContent = text; }
document.getElementById('btnPasskey').addEventListener('click', async () => {
  try {
    setMsg('Starting passkey sign-in…');
    const beginRes = await fetch('<?= htmlspecialchars(BASE_URL) ?>/passkeys/begin.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({ email: emailEl.value.trim(), csrf_token: csrf })
    });
    const begin = await beginRes.json();
    if (!begin.ok || !begin.publicKey || !begin.state) throw new Error(begin.error || 'Could not start passkey sign-in.');
    const publicKey = begin.publicKey;
    if (publicKey.challenge) publicKey.challenge = Uint8Array.from(atob(publicKey.challenge.replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0));
    if (Array.isArray(publicKey.allowCredentials)) {
      publicKey.allowCredentials = publicKey.allowCredentials.map(item => ({...item, id: Uint8Array.from(atob(String(item.id).replace(/-/g,'+').replace(/_/g,'/')), c => c.charCodeAt(0))}));
    }
    const cred = await navigator.credentials.get({ publicKey });
    if (!cred) throw new Error('No passkey credential was returned.');
    const enc = (buf) => {
      const bytes = new Uint8Array(buf);
      let str=''; bytes.forEach(b => str += String.fromCharCode(b));
      return btoa(str).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    };
    const assertion = {
      id: cred.id,
      rawId: enc(cred.rawId),
      type: cred.type,
      response: {
        clientDataJSON: enc(cred.response.clientDataJSON),
        authenticatorData: enc(cred.response.authenticatorData),
        signature: enc(cred.response.signature),
        userHandle: cred.response.userHandle ? enc(cred.response.userHandle) : null,
      }
    };
    const finishRes = await fetch('<?= htmlspecialchars(BASE_URL) ?>/passkeys/finish.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','Accept':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify({ assertion, state: begin.state, csrf_token: csrf, next: nextUrl })
    });
    const finish = await finishRes.json();
    if (!finish.ok || !finish.redirect) throw new Error(finish.error || 'Passkey sign-in failed.');
    window.location.href = finish.redirect;
  } catch (err) {
    setMsg(err && err.message ? err.message : 'Passkey sign-in failed.');
  }
});
if (autoPasskey) {
  window.setTimeout(() => document.getElementById('btnPasskey').click(), 120);
}
</script>
</body>
</html>
