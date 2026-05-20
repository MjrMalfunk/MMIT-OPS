<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';
require_once __DIR__ . '/../inc/nav.php';


require_login();

$cu = current_user();
$rows = passkey_repo_find_all_for_user((int)$cu['user_id']);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-64.png">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=3">
<meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Passkeys | <?= htmlspecialchars(APP_NAME) ?></title>
  <style>
    body{margin:0;color:#e8eefc;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:linear-gradient(rgba(5,11,24,.80), rgba(5,11,24,.80)),url("<?= htmlspecialchars(BASE_URL) ?>/pix/portal-bg-dark.jpg") center/cover no-repeat fixed;}
    .wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px 20px 28px}
    .card{width:min(760px,94vw);background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:18px}
    h1{margin:0 0 6px 0;font-size:22px}
    p{margin:8px 0;opacity:.86;line-height:1.35}
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:12px 0}
    button{padding:10px 12px;border-radius:12px;border:0;background:#2f6df6;color:white;font-weight:700;cursor:pointer}
    .list{margin-top:14px;border-top:1px solid rgba(255,255,255,.12);padding-top:12px}
    .item{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:10px;border:1px solid rgba(255,255,255,.12);border-radius:14px;margin:10px 0;background:rgba(0,0,0,.18)}
    .meta{opacity:.85;font-size:13px}
    .pill{display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.18);font-size:12px}
    input{padding:10px 12px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:rgba(0,0,0,.25);color:#e8eefc;min-width:220px}
    a{color:#79a8ff;text-decoration:none}
    .err{background:rgba(255,60,80,.12);border:1px solid rgba(255,60,80,.3);padding:10px;border-radius:12px;display:none}
    .ok{background:rgba(0,220,140,.12);border:1px solid rgba(0,220,140,.3);padding:10px;border-radius:12px;display:none}
    @media (max-width:520px){
      .item{flex-direction:column;align-items:flex-start}
      input{width:100%}
      button{width:100%}
    }
  
    .brandline{display:flex;align-items:center;gap:12px}
    .brandline img{height:36px;width:auto;filter:drop-shadow(0 6px 18px rgba(0,0,0,.35))}
    .brandline .titles{display:flex;flex-direction:column}

      /* centered pill nav */
      .navwrap{display:flex;justify-content:center;margin:12px 0 0}
      .nav{display:flex;gap:10px;flex-wrap:wrap;justify-content:center}
      .navpill{padding:8px 12px;border-radius:999px;border:1px solid rgba(255,255,255,.14);background:rgba(0,0,0,.25);color:#dbeafe;text-decoration:none;font-size:13px}
      .navpill:hover{border-color:rgba(120,160,255,.55)}
      .navpill.active{background:rgba(47,108,255,.35);border-color:rgba(47,108,255,.65)}

.head{width:min(760px,94vw);display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin:14px 0}
.muted{opacity:.8;font-size:13px}
</style>

  </head>
<body>
<div class="wrap">
  <div class="head">
    <div class="brandline">
      <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" alt="<?= htmlspecialchars(APP_NAME) ?>">
      <div class="titles">
        <div style="font-weight:700;font-size:18px">Security</div>
        <div class="muted">Passkeys</div>
      </div>
    </div>
    <?php render_pills('security'); ?>
  </div>
  <div class="card">
    <h1>Passkeys</h1>
    <p>Use passkeys (Face ID / Touch ID / device biometrics) to sign in without typing a password. For setup and changes, we require a recent MFA verification.</p>

    <div id="msgOk" class="ok"></div>
    <div id="msgErr" class="err"></div>

    <div class="row">
      <input id="nickname" placeholder="Nickname (optional) e.g. Doc’s iPhone">
      <button id="btnAdd">Add a passkey</button>
      <a class="pill" href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/index.php">Back to dashboard</a>
    </div>

    <div class="list">
      <div class="meta"><span class="pill"><?= count($rows) ?></span> passkey(s) registered.</div>

      <?php foreach ($rows as $r): ?>
        <div class="item">
          <div>
            <div><strong><?= htmlspecialchars($r['nickname'] ?: 'Passkey') ?></strong></div>
            <div class="meta">
              Created: <?= htmlspecialchars((string)$r['created_at']) ?>
              <?php if (!empty($r['last_used_at'])): ?> • Last used: <?= htmlspecialchars((string)$r['last_used_at']) ?><?php endif; ?>
            </div>
          </div>
          <div class="meta">
            <span class="pill"><?= htmlspecialchars((string)($r['aaguid'] ?? '')) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<script>
const csrfToken = <?= json_encode(csrf_token()) ?>;

function b64urlToBuf(b64url) {
  let s = (b64url || "").replace(/-/g, "+").replace(/_/g, "/");
  const pad = s.length % 4;
  if (pad) s += "=".repeat(4 - pad);
  const bin = atob(s);
  const bytes = new Uint8Array(bin.length);
  for (let i=0;i<bin.length;i++) bytes[i]=bin.charCodeAt(i);
  return bytes.buffer;
}

function bufToB64url(buf) {
  const bytes = new Uint8Array(buf);
  let bin = "";
  for (let i=0;i<bytes.length;i++) bin += String.fromCharCode(bytes[i]);
  return btoa(bin).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/,"");
}

function showMsg(id, text) {
  document.getElementById("msgOk").style.display = "none";
  document.getElementById("msgErr").style.display = "none";
  const el = document.getElementById(id);
  el.textContent = text;
  el.style.display = "block";
}

async function registerPasskey() {
  const beginRes = await fetch("<?= htmlspecialchars(BASE_URL) ?>/passkeys/register_begin.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ csrf_token: csrfToken })
  });
  const begin = await beginRes.json();
  if (!begin.ok) throw new Error(begin.error || "Failed to start passkey registration.");

  const publicKey = begin.publicKey;
  publicKey.challenge = b64urlToBuf(publicKey.challenge);
  publicKey.user.id = b64urlToBuf(publicKey.user.id);
  if (publicKey.excludeCredentials) {
    publicKey.excludeCredentials = publicKey.excludeCredentials.map(c => ({...c, id: b64urlToBuf(c.id)}));
  }

  const cred = await navigator.credentials.create({ publicKey });
  if (!cred) throw new Error("Passkey creation cancelled.");

  const att = cred.response;
  const nickname = (document.getElementById("nickname").value || "").trim();

  const payload = {
    id: cred.id,
    type: cred.type,
    rawId: bufToB64url(cred.rawId),
    nickname,
    response: {
      clientDataJSON: bufToB64url(att.clientDataJSON),
      attestationObject: bufToB64url(att.attestationObject),
    }
  };

  const finRes = await fetch("<?= htmlspecialchars(BASE_URL) ?>/passkeys/register_finish.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  });
  const fin = await finRes.json();
  if (!fin.ok) throw new Error(fin.error || "Passkey registration failed.");

  showMsg("msgOk", "Passkey added. Refreshing…");
  setTimeout(() => location.reload(), 600);
}

document.getElementById("btnAdd").addEventListener("click", async () => {
  try {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      showMsg("msgErr", "This browser/device doesn't support passkeys.");
      return;
    }
    await registerPasskey();
  } catch (e) {
    showMsg("msgErr", e.message || String(e));
  }
});
</script>
</body>
</html>
