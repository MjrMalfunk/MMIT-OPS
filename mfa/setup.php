<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/totp.php';
require_once __DIR__ . '/../inc/webauthn_repo.php';
require_once __DIR__ . '/../inc/nav.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/portal_access.php';

require_login();
$user = current_user();

// Load existing MFA row
$st = db()->prepare('SELECT totp_enabled, totp_secret FROM portal_user_mfa WHERE user_id = ?');
$st->execute([$user['user_id']]);
$mfa = $st->fetch() ?: ['totp_enabled' => 0, 'totp_secret' => null];

$err = '';
$msg = '';

// Flash message (Post/Redirect/Get)
if (!empty($_SESSION['flash_msg'])) {
    $msg = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$is_reset_mode = (isset($_GET['reset']) && (string)$_GET['reset'] === '1');
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
if ($returnTo === '' || !str_starts_with($returnTo, 'http')) {
    $returnTo = '';
}

// Create a new secret and stash in session until confirmed
if (empty($_SESSION['mfa_setup_secret'])) {
    $_SESSION['mfa_setup_secret'] = totp_generate_secret(16);
}
$secret = $_SESSION['mfa_setup_secret'];


// Build OTP URI + QR render (prefer local SVG via chillerlan/php-qrcode; fallback to Google Chart image)
$otpUri = 'otpauth://totp/' .
    rawurlencode(MFA_TOTP_ISSUER . ':' . $user['email']) .
    '?secret=' . $secret .
    '&issuer=' . rawurlencode(MFA_TOTP_ISSUER) .
    '&digits=' . MFA_TOTP_DIGITS;

$qrSvg = null;
$qrImgUrl = null;

// Try local QR (no external calls) if library is installed.
try {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
    if (class_exists('chillerlan\\QRCode\\QRCode') && class_exists('chillerlan\\QRCode\\QROptions')) {
        $opts = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
            'scale' => 6,
        ]);
        $qrSvg = (new \chillerlan\QRCode\QRCode($opts))->render($otpUri);
    }
} catch (Throwable $e) {
    $qrSvg = null;
}

// Fallback: external image (still works even without any PHP extensions/libraries).
if ($qrSvg === null) {
    $qrImgUrl = 'https://chart.googleapis.com/chart?cht=qr&chs=220x220&chld=M|0&chl=' . rawurlencode($otpUri);
}


// Passkeys list for UI
$passkeys = [];
try {
    if (function_exists('webauthn_list_credentials_for_user')) {
        $passkeys = webauthn_list_credentials_for_user((int)$user['user_id']);
    } else {
        // Fallback if repo wasn't updated yet
        $ps = db()->prepare("SELECT credential_id, label, created_at, last_used_at FROM webauthn_credential WHERE user_id = ? ORDER BY created_at DESC");
        $ps->execute([$user['user_id']]);
        while ($r = $ps->fetch()) {
            $passkeys[] = [
                'credential_id' => b64u_encode((string)$r['credential_id']),
                'label' => (string)($r['label'] ?? ''),
                'created_at' => (string)($r['created_at'] ?? ''),
                'last_used_at' => (string)($r['last_used_at'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    // If the table doesn't exist yet, just show "none registered".
    $passkeys = [];
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    // Step-up for sensitive actions when MFA is already enabled
    $needs_stepup = in_array($action, ['disable_mfa', 'generate_backup', 'delete_passkey'], true)
        && ((int)$mfa['totp_enabled'] === 1);
    if ($needs_stepup && function_exists('require_recent_mfa')) {
        require_recent_mfa($_SERVER['REQUEST_URI'] ?? null);
    }

    if ($action === 'disable_mfa') {
        db()->prepare('UPDATE portal_user_mfa SET totp_enabled = 0, totp_secret = NULL WHERE user_id = ?')
            ->execute([$user['user_id']]);
        db()->prepare('DELETE FROM mfa_backup_code WHERE user_id = ?')->execute([$user['user_id']]);

        unset($_SESSION['mfa_setup_secret']);

        if (function_exists('audit_event')) {
            audit_event($user['user_id'], 'MFA_DISABLED', $_SERVER['REMOTE_ADDR'] ?? null, null);
        }

        $_SESSION['flash_msg'] = 'Two-factor authentication disabled.';
        $_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$user['user_id']);
        header("Location: " . ($_SERVER['PHP_SELF'] ?? "setup.php") . ($returnTo !== '' ? ('?return_to=' . rawurlencode($returnTo)) : ''));
        exit;

    } elseif ($action === 'generate_backup') {
        // Generate backup codes and show once (stored hashed in DB)
        if (!function_exists('mfa_generate_backup_codes')) {
            $err = 'Backup-code generator is missing.';
        } else {
            $codes = mfa_generate_backup_codes((int)$user['user_id'], 10, true);
            $_SESSION['mfa_backup_codes_once'] = $codes;
            $_SESSION['flash_msg'] = 'New backup codes generated. Save them now; you will not see them again.';
            $_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$user['user_id']);
            header("Location: " . ($_SERVER['PHP_SELF'] ?? "setup.php") . ($returnTo !== '' ? ('?return_to=' . rawurlencode($returnTo)) : ''));
            exit;
        }

    } elseif ($action === 'delete_passkey') {
        $cid = (string)($_POST['credential_id'] ?? '');
        $ok = false;

        if (function_exists('webauthn_delete_credential_for_user')) {
            $ok = webauthn_delete_credential_for_user((int)$user['user_id'], $cid);
        } else {
            // Fallback if repo wasn't updated yet
            $cidBin = b64u_decode($cid);
            if ($cidBin !== '') {
                db()->prepare("DELETE FROM webauthn_credential WHERE user_id = ? AND credential_id = ?")
                    ->execute([$user['user_id'], $cidBin]);
                $ok = true;
            }
        }

        if (!$ok) {
            $err = 'Invalid passkey id.';
        } else {
            if (function_exists('audit_event')) {
                audit_event($user['user_id'], 'PASSKEY_DELETED', $_SERVER['REMOTE_ADDR'] ?? null, null);
            }
            $msg = 'Passkey removed.';
            $_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$user['user_id']);

            // Refresh list
            if (function_exists('webauthn_list_credentials_for_user')) {
                $passkeys = webauthn_list_credentials_for_user((int)$user['user_id']);
            } else {
                $passkeys = [];
                $ps = db()->prepare("SELECT credential_id, label, created_at, last_used_at FROM webauthn_credential WHERE user_id = ? ORDER BY created_at DESC");
                $ps->execute([$user['user_id']]);
                while ($r = $ps->fetch()) {
                    $passkeys[] = [
                        'credential_id' => b64u_encode((string)$r['credential_id']),
                        'label' => (string)($r['label'] ?? ''),
                        'created_at' => (string)($r['created_at'] ?? ''),
                        'last_used_at' => (string)($r['last_used_at'] ?? ''),
                    ];
                }
            }
        }

    } else {
        // Confirm TOTP setup (enable or reset)
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== MFA_TOTP_DIGITS) {
            $err = 'Enter the 6-digit code from your authenticator app.';
        } elseif (!totp_verify($secret, $code, MFA_TOTP_PERIOD, MFA_TOTP_DIGITS, 1)) {
            $err = 'That code did not match. Try again.';
            if (function_exists('audit_event')) {
                audit_event($user['user_id'], 'MFA_SETUP_FAIL', $_SERVER['REMOTE_ADDR'] ?? null, null);
            }
        } else {
            // Upsert secret + enable
            $up = db()->prepare('INSERT INTO portal_user_mfa (user_id, totp_secret, totp_enabled) VALUES (?, ?, 1)
                                 ON DUPLICATE KEY UPDATE totp_secret = VALUES(totp_secret), totp_enabled = 1');
            $up->execute([$user['user_id'], $secret]);

            unset($_SESSION['mfa_setup_secret']);

            if (function_exists('audit_event')) {
                audit_event($user['user_id'], 'MFA_ENABLED', $_SERVER['REMOTE_ADDR'] ?? null, null);
            }

            // Generate backup codes automatically on enable/reset and show once
            if (function_exists('mfa_generate_backup_codes')) {
                $_SESSION['mfa_backup_codes_once'] = mfa_generate_backup_codes((int)$user['user_id'], 10, true);
                $_SESSION['flash_msg'] = 'Two-factor authentication enabled. Backup codes generated below. Save them now; you will not see them again.';
            } else {
                $_SESSION['flash_msg'] = 'Two-factor authentication enabled.';
            }

            $_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$user['user_id']);
            header("Location: " . ($_SERVER['PHP_SELF'] ?? "setup.php") . ($returnTo !== '' ? ('?return_to=' . rawurlencode($returnTo)) : ''));
            exit;
        }
    }
}

$backup_codes = $_SESSION['mfa_backup_codes_once'] ?? [];
unset($_SESSION['mfa_backup_codes_once']);

$backup_remaining = 0;
try {
    if ((int)($mfa['totp_enabled'] ?? 0) === 1) {
        $cs = db()->prepare('SELECT COUNT(*) AS c FROM mfa_backup_code WHERE user_id = ? AND used_at IS NULL');
        $cs->execute([$user['user_id']]);
        $backup_remaining = (int)($cs->fetchColumn() ?: 0);
    }
} catch (Throwable $e) {
    $backup_remaining = 0;
}

?>
<?php
page_header('Security', 'admin');
?>
<style>
.security-page { display:grid; gap:18px; }
.security-top-grid { display:grid; grid-template-columns:minmax(0, 1.3fr) minmax(320px, .9fr); gap:16px; align-items:start; }
.security-card { padding:20px; }
.security-kicker { opacity:.72; text-transform:uppercase; letter-spacing:.08em; font-size:12px; margin-bottom:8px; }
.security-title { margin:0 0 8px; font-size:22px; }
.security-subtitle { margin:0; opacity:.82; line-height:1.6; max-width:72ch; }
.security-meta-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; margin-top:16px; }
.security-meta-item { padding:14px 16px; border-radius:14px; border:1px solid rgba(148,163,184,.14); background:rgba(255,255,255,.03); min-width:0; }
.security-meta-label { font-size:11px; text-transform:uppercase; letter-spacing:.06em; opacity:.62; margin-bottom:6px; }
.security-meta-value { font-size:15px; font-weight:700; line-height:1.4; word-break:break-word; }
.security-meta-value--mono { font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight:600; }
.security-action-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; justify-content:flex-start; margin-top:18px; }
.security-action-row .btn { width:auto; }
.security-setup-grid { display:grid; grid-template-columns:minmax(240px, .9fr) minmax(0, 1fr); gap:18px; align-items:start; margin-top:18px; }
.security-qr-shell { background:#ffffff; padding:12px; border-radius:16px; display:inline-flex; line-height:0; box-shadow:0 10px 30px rgba(15,23,42,.24); }
.security-code-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-top:14px; }
.security-code-pill { display:block; padding:10px 12px; border-radius:12px; border:1px solid rgba(148,163,184,.18); background:rgba(255,255,255,.05); font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; letter-spacing:.08em; }
.security-passkey-table { width:100%; border-collapse:collapse; margin-top:12px; }
.security-passkey-table th, .security-passkey-table td { padding:12px 10px; border-bottom:1px solid rgba(255,255,255,.08); text-align:left; vertical-align:middle; }
.security-passkey-table th { opacity:.78; font-size:12px; letter-spacing:.04em; text-transform:uppercase; }
.security-passkey-table td:last-child { text-align:right; }
.security-inline-status { font-size:13px; opacity:.78; }
.security-empty { margin-top:12px; opacity:.78; }
@media (max-width: 980px) { .security-top-grid, .security-setup-grid, .security-meta-grid { grid-template-columns:1fr; } }
@media (max-width: 640px) { .security-action-row .btn { width:100%; } .security-passkey-table, .security-passkey-table thead, .security-passkey-table tbody, .security-passkey-table tr, .security-passkey-table th, .security-passkey-table td { display:block; width:100%; } .security-passkey-table thead { display:none; } .security-passkey-table tr { padding:10px 0; border-bottom:1px solid rgba(255,255,255,.08); } .security-passkey-table td { padding:6px 0; border-bottom:none; text-align:left !important; } }
</style>

<div class="security-page">
  <div style="display:grid;gap:8px;">
    <a href="<?= htmlspecialchars($returnTo !== '' ? $returnTo : (BASE_URL . '/admin/index.php')) ?>">← Back</a>
    <div>
      <h1 style="margin:0 0 8px;">Security</h1>
      <p style="margin:0;opacity:.82;line-height:1.6;max-width:860px;">Manage two-factor authentication, backup codes, and passkeys for your operator account in one place.</p>
    </div>
  </div>

  <?php if ($err): ?><div class="flash-error"><?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="flash-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

  <div class="security-top-grid">
    <section class="card security-card">
      <div class="security-kicker">Operator security</div>
      <h2 class="security-title">Account posture</h2>
      <p class="security-subtitle">This is the sign-in lane for OPS. TOTP, backup codes, and passkeys all live here so the lockbox stays tidy.</p>

      <div class="security-meta-grid">
        <div class="security-meta-item">
          <div class="security-meta-label">Account</div>
          <div class="security-meta-value"><?= htmlspecialchars((string)$user['email']) ?></div>
        </div>
        <div class="security-meta-item">
          <div class="security-meta-label">TOTP status</div>
          <div class="security-meta-value"><?= (int)$mfa['totp_enabled'] === 1 ? 'Enabled ✅' : 'Not enabled yet' ?></div>
        </div>
        <div class="security-meta-item">
          <div class="security-meta-label">Backup codes remaining</div>
          <div class="security-meta-value"><?= (int)$backup_remaining ?></div>
        </div>
        <div class="security-meta-item">
          <div class="security-meta-label">Passkeys registered</div>
          <div class="security-meta-value"><?= count($passkeys) ?></div>
        </div>
      </div>

      <?php if ((int)$mfa['totp_enabled'] === 1 && !$is_reset_mode): ?>
        <div class="security-action-row">
          <a class="btn btn-secondary btn-inline" href="setup.php?reset=1<?= $returnTo !== '' ? '&amp;return_to=' . rawurlencode($returnTo) : '' ?>">Reset authenticator</a>

          <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <?php if ($returnTo !== ''): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="generate_backup">
            <button type="submit" class="btn btn-secondary btn-inline">Regenerate backup codes</button>
          </form>

          <form method="post" onsubmit="return confirm('Disable 2FA for this account?');" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <?php if ($returnTo !== ''): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>"><?php endif; ?>
            <input type="hidden" name="action" value="disable_mfa">
            <button type="submit" class="btn btn-danger btn-inline">Disable 2FA</button>
          </form>
        </div>
      <?php endif; ?>
    </section>

    <section class="card security-card">
      <div class="security-kicker">Passkeys</div>
      <h2 class="security-title">Passwordless sign-in</h2>
      <p class="security-subtitle">Register Face ID, Touch ID, or Windows Hello as a phishing-resistant sign-in factor.</p>

      <div class="security-meta-grid">
        <div class="security-meta-item">
          <div class="security-meta-label">Saved credentials</div>
          <div class="security-meta-value"><?= count($passkeys) ?> active</div>
        </div>
        <div class="security-meta-item">
          <div class="security-meta-label">Browser support</div>
          <div class="security-meta-value" id="passkeyStatus">Checking support…</div>
        </div>
      </div>

      <div class="security-action-row">
        <button type="button" id="registerPasskeyBtn" class="btn btn-secondary btn-inline">Register a passkey</button>
      </div>
    </section>
  </div>

  <section class="card security-card">
    <div class="security-kicker">Authenticator app</div>
    <h2 class="security-title"><?php echo ((int)$mfa['totp_enabled'] === 1 && !$is_reset_mode) ? 'TOTP is enabled' : (((int)$mfa['totp_enabled'] === 1 && $is_reset_mode) ? 'Reset authenticator' : 'Enable with an authenticator app'); ?></h2>

    <?php if ((int)$mfa['totp_enabled'] === 1 && !$is_reset_mode): ?>
      <p class="security-subtitle">On login you will be asked for a 6-digit code from your authenticator app. Backup codes are single-use lifeboats if your device ever takes an unexpected vacation.</p>

      <?php if (!empty($backup_codes)): ?>
        <div style="margin-top:16px;">
          <div class="security-meta-label" style="margin-bottom:8px;">New backup codes</div>
          <div class="security-code-grid">
            <?php foreach ($backup_codes as $c): ?>
              <span class="security-code-pill"><?= htmlspecialchars($c) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="security-subtitle">Scan the QR code in Google Authenticator, Microsoft Authenticator, 1Password, or a compatible app, then confirm with the 6-digit code it generates.</p>

      <div class="security-setup-grid">
        <div>
          <div class="security-qr-shell">
            <?php if ($qrSvg !== null): ?>
              <?php
                $qrTrim = ltrim($qrSvg);
                $startsWith = function(string $haystack, string $needle): bool {
                    return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
                };
                if ($startsWith($qrTrim, '<svg')) {
                    echo $qrSvg;
                } elseif ($startsWith($qrTrim, 'data:image')) {
                    echo '<img src="' . htmlspecialchars($qrSvg) . '" width="220" height="220" alt="TOTP QR" />';
                } else {
                    echo '<img src="' . htmlspecialchars($qrImgUrl) . '" width="220" height="220" alt="TOTP QR" />';
                }
              ?>
            <?php else: ?>
              <img src="<?= htmlspecialchars($qrImgUrl) ?>" width="220" height="220" alt="TOTP QR" />
            <?php endif; ?>
          </div>
        </div>

        <div>
          <div class="security-meta-item">
            <div class="security-meta-label">Manual setup secret</div>
            <div class="security-meta-value security-meta-value--mono"><?= htmlspecialchars($secret) ?></div>
          </div>

          <form method="post" style="display:grid;gap:12px;margin-top:16px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
            <?php if ($returnTo !== ''): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>"><?php endif; ?>

            <div>
              <label for="code">Enter the 6-digit code to confirm</label>
              <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required>
            </div>

            <div class="security-action-row" style="margin-top:0;">
              <button type="submit" class="btn btn-primary btn-inline"><?= ((int)$mfa['totp_enabled'] === 1 && $is_reset_mode) ? 'Confirm reset' : 'Enable 2FA' ?></button>
              <?php if ((int)$mfa['totp_enabled'] === 1 && $is_reset_mode): ?>
                <a class="btn btn-secondary btn-inline" href="setup.php<?= $returnTo !== '' ? '?return_to=' . rawurlencode($returnTo) : '' ?>">Cancel reset</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="card security-card">
    <div class="security-kicker">Registered credentials</div>
    <h2 class="security-title">Passkey inventory</h2>
    <p class="security-subtitle">Keep a second credential on deck when you can. One key is fine. Two keys are weatherproof.</p>

    <?php if (empty($passkeys)): ?>
      <p class="security-empty">No passkeys registered yet.</p>
    <?php else: ?>
      <table class="security-passkey-table">
        <thead>
          <tr>
            <th>Label</th>
            <th>Created</th>
            <th>Last used</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($passkeys as $k): ?>
            <tr>
              <td><?= htmlspecialchars($k['label'] !== '' ? $k['label'] : 'Passkey') ?></td>
              <td><?= htmlspecialchars($k['created_at']) ?></td>
              <td><?= htmlspecialchars($k['last_used_at'] ?: '—') ?></td>
              <td>
                <form method="post" onsubmit="return confirm('Remove this passkey?');" style="margin:0;">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                  <?php if ($returnTo !== ''): ?><input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>"><?php endif; ?>
                  <input type="hidden" name="action" value="delete_passkey">
                  <input type="hidden" name="credential_id" value="<?= htmlspecialchars($k['credential_id']) ?>">
                  <button class="btn btn-danger btn-inline" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  // TOTP QR is rendered server-side.

// Passkey registration
  const btn = document.getElementById('registerPasskeyBtn');
  const statusEl = document.getElementById('passkeyStatus');

  function setStatus(msg) { if (statusEl) statusEl.textContent = msg || ''; }

  function b64urlToBuf(b64url) {
    const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
    const b64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
    const str = atob(b64);
    const bytes = new Uint8Array(str.length);
    for (let i = 0; i < str.length; i++) bytes[i] = str.charCodeAt(i);
    return bytes.buffer;
  }
  function bufToB64url(buf) {
    const bytes = new Uint8Array(buf);
    let str = '';
    for (let i = 0; i < bytes.length; i++) str += String.fromCharCode(bytes[i]);
    return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/,'');
  }

  function normalizeCreationOptions(o) {
    o.challenge = b64urlToBuf(o.challenge);
    if (o.user && o.user.id) o.user.id = b64urlToBuf(o.user.id);
    if (Array.isArray(o.excludeCredentials)) {
      o.excludeCredentials = o.excludeCredentials.map(c => ({...c, id: b64urlToBuf(c.id)}));
    }
    return o;
  }

  function serializeAttestation(cred) {
    return {
      // Some browsers provide cred.id in a form that doesn't exactly match rawId.
      // webauthn-lib is strict about id/rawId matching, so force id from rawId.
      id: bufToB64url(cred.rawId),
      rawId: bufToB64url(cred.rawId),
      type: cred.type,
      response: {
        attestationObject: bufToB64url(cred.response.attestationObject),
        clientDataJSON: bufToB64url(cred.response.clientDataJSON),
      }
    };
  }

  async function postJSON(url, body) {
    const r = await fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.ok === false) throw new Error(j.error || 'Request failed');
    return j;
  }

  if (!window.PublicKeyCredential || !navigator.credentials || !navigator.credentials.create) {
    btn.disabled = true;
    btn.textContent = 'Passkeys not supported in this browser';
    setStatus('This browser/device does not support passkeys.');
    return;
  }
  setStatus('Passkeys supported ✅');

  btn.addEventListener('click', async () => {
    btn.disabled = true;
    setStatus('Starting passkey registration…');

    try {
      function guessPlatformLabel() {
      const ua = navigator.userAgent || '';
      const plat = (navigator.platform || '').toLowerCase();

      const isIOS = /iPhone|iPad|iPod/i.test(ua);
      const isMac = /Macintosh|Mac OS X/i.test(ua) || plat.includes('mac');
      const isWin = /Windows/i.test(ua) || plat.includes('win');
      const isAndroid = /Android/i.test(ua);
      const isLinux = /Linux/i.test(ua) || plat.includes('linux');

      let platform = 'Device';
      if (isIOS) platform = 'iPhone/iPad';
      else if (isMac) platform = 'Mac';
      else if (isWin) platform = 'Windows';
      else if (isAndroid) platform = 'Android';
      else if (isLinux) platform = 'Linux';

      let browser = 'Browser';
      if (/Edg\//i.test(ua)) browser = 'Edge';
      else if (/Chrome\//i.test(ua) && !/Edg\//i.test(ua)) browser = 'Chrome';
      else if (/Safari\//i.test(ua) && !/Chrome\//i.test(ua)) browser = 'Safari';
      else if (/Firefox\//i.test(ua)) browser = 'Firefox';

      const auth = (platform === 'Windows') ? 'Windows Hello'
                : (platform === 'Mac') ? 'Touch ID'
                : (platform === 'iPhone/iPad') ? 'Face ID / Touch ID'
                : 'Passkey';

      return `${auth} (${browser})`;
    }

    const defaultLabel = guessPlatformLabel();
    const label = prompt('Label this passkey:', defaultLabel) || defaultLabel;

      const opt = await postJSON('<?= htmlspecialchars(BASE_URL) ?>/passkeys/register_begin.php', {
        label: label,
        csrf_token: <?= json_encode(csrf_token()) ?>
      });

      if (!opt.publicKey) throw new Error('Invalid server response (missing publicKey).');

      const publicKey = normalizeCreationOptions(opt.publicKey);
      const cred = await navigator.credentials.create({ publicKey });

      if (!cred) throw new Error('No credential returned.');

      await postJSON('<?= htmlspecialchars(BASE_URL) ?>/passkeys/register_finish.php', {
        label: label,
        attestation: serializeAttestation(cred),
        csrf_token: <?= json_encode(csrf_token()) ?>
      });

      setStatus('Passkey registered. Reloading…');
      window.location.reload();
    } catch (e) {
      alert(e && e.message ? e.message : 'Passkey registration failed');
      setStatus('');
      btn.disabled = false;
    }
  });
})();
</script>
<?php page_footer(); ?>
