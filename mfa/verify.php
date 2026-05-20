<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/auth.php';
$stepup = !empty($_GET['stepup']);
$cu = current_user();

// If already authenticated, only show this page for step-up
if ($cu && !$stepup) {
    header('Location: ' . BASE_URL . '/dashboard/');
    exit;
}

// Bind step-up MFA to current user if needed
if ($stepup && $cu && empty($_SESSION['mfa_pending_user_id']) && empty($_SESSION['mfa_pending']['user_id'])) {
    $_SESSION['mfa_pending_user_id'] = (int)$cu['user_id'];
    // For step-up flows, auth_mfa_verify() expects a pending user context.
    // Mirror the login-MFA pending structure so TOTP works for step-up.
    $_SESSION['mfa_pending'] = [
        'user_id' => (int)$cu['user_id'],
        'email' => (string)($cu['email'] ?? ''),
        'display_name' => (string)($cu['display_name'] ?? ''),
        'user_type' => (string)($cu['user_type'] ?? ''),
    ];
}

// If no MFA context at all, bounce to login
if (
    !$stepup &&
    empty($_SESSION['mfa_pending_user_id']) &&
    empty($_SESSION['mfa_pending']['user_id'])
) {
    header('Location: ' . BASE_URL . '/');
    exit;
}

$err = '';

// PHP < 8 compatibility
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool {
        return $n === '' || strncmp($h, $n, strlen($n)) === 0;
    }
}

// Resolve user ID for auditing
function resolve_mfa_user_id(): ?int {
    if (!empty($_SESSION['user']['user_id'])) {
        return (int)$_SESSION['user']['user_id'];
    }
    if (!empty($_SESSION['mfa_pending']['user_id'])) {
        return (int)$_SESSION['mfa_pending']['user_id'];
    }
    if (!empty($_SESSION['mfa_pending_user_id'])) {
        return (int)$_SESSION['mfa_pending_user_id'];
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $raw = trim((string)($_POST['code'] ?? ''));
    $raw = preg_replace('/\s+/', '', $raw);

    $uid = resolve_mfa_user_id();
    $res = 'error';
    $err_detail = '';

    // 6-digit TOTP
    if (preg_match('/^\d{6}$/', $raw)) {
        // Prefer direct call so we can surface a useful error message.
        if (function_exists('auth_mfa_verify')) {
            $out = auth_mfa_verify($raw);
            if (!empty($out['ok'])) {
                $res = 'ok';
            } else {
                $res = 'error';
                $err_detail = (string)($out['error'] ?? 'Invalid code.');
            }
        } else {
            $res = complete_mfa_code($raw);
        }
    }
    // Backup code
    else {
        if (function_exists('complete_mfa_backup_code')) {
            $res = complete_mfa_backup_code($raw);
            if ($res === 'fail') {
                $res = 'error';
            }
            if ($res !== 'ok') {
                $err_detail = 'Invalid backup code.';
            }
        }
    }

    if ($res === 'ok') {
        if (function_exists('mark_mfa_recent')) {
            mark_mfa_recent();
        } else {
            $_SESSION['mfa_recent_at'] = time();
        }

        if (function_exists('audit_event')) {
            audit_event($uid, 'MFA_SUCCESS', ['stepup' => $stepup ? 1 : 0]);
            audit_event(
                $uid,
                $stepup ? 'STEPUP_SUCCESS' : 'LOGIN_SUCCESS',
                $stepup ? ['next' => (string)($_GET['next'] ?? '')] : ['via' => 'mfa']
            );
        }

        $next = (string)($_GET['next'] ?? ($_SESSION['mfa_next'] ?? ''));
        auth_session_commit();
        if ($stepup && $next && str_starts_with($next, '/')) {
            header('Location: ' . BASE_URL . $next);
        } else {
            header('Location: ' . BASE_URL . '/dashboard/');
        }
        exit;
    }

    $err = $err_detail !== '' ? $err_detail : 'Invalid code. Try again.';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
  <link rel="icon" type="image/png" href="/assets/brand/mmit-favicon-64.png">
  <link rel="stylesheet" href="/css/portal_shell.css?v=2">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify | <?= htmlspecialchars(APP_NAME) ?></title>

<style>
/* ---- Layout safety ---- */
*, *::before, *::after { box-sizing: border-box; }

body{
margin:0;
  color:#e8eefc;
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;
  background: #050b16 url("/pix/portal-bg-dark.jpg") center/cover no-repeat fixed;
}

.wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px 20px 28px}

.card{
  width:min(420px,92vw);
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.12);
  border-radius:18px;
  padding:18px;
}

h1{margin:0 0 8px;font-size:22px}
p{margin:8px 0;opacity:.9}

label{
  display:block;
  margin-top:14px;
  margin-bottom:6px;
  font-weight:600;
}

input{
  width:100%;
  max-width:100%;
  padding:12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(0,0,0,.25);
  color:#e8eefc;
  font-size:16px;
}

button{
  width:100%;
  margin-top:16px;
  padding:12px;
  border-radius:12px;
  border:0;
  background:#2f6df6;
  color:white;
  font-weight:700;
  font-size:16px;
  cursor:pointer;
}

.err{
  background:rgba(255,60,80,.12);
  border:1px solid rgba(255,60,80,.3);
  padding:10px;
  border-radius:12px;
  margin-top:10px;
}

.tip{
  margin-top:6px;
  font-size:14px;
  opacity:.8;
}

a{color:#79a8ff;text-decoration:none}

    /* Minimal header (no logo on verify screen) */
    .head{width:min(960px,94vw);display:flex;justify-content:center;align-items:center;gap:16px;flex-wrap:wrap;margin:14px 0}
    .head .titles{display:flex;flex-direction:column;align-items:center;text-align:center}
    .head .muted{opacity:.8;font-size:13px}
</style>
</head>

<body>
<div class="wrap">
  <div class="head">
    <div class="titles">
      <div style="font-weight:700">Security</div>
      <div class="muted">MFA Verification</div>
    </div>
  </div>

<div class="card">
    <h1>Enter your 2FA code</h1>

    <p>
      Enter a <b>6-digit</b> code from your authenticator app,
      or a <b>backup code</b> like <b>ABCD-EFGH</b>.
    </p>

    <?php if ($err): ?>
      <p class="err"><?= htmlspecialchars($err) ?></p>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

      <label for="code">Code</label>
      <input
        id="code"
        name="code"
        autocomplete="one-time-code"
        placeholder="123456 or ABCD-EFGH"
        required
        autofocus
      >

      <p class="tip">
        Backup codes are letters and numbers and may include a dash.
      </p>

      <button type="submit">Verify</button>
    </form>

    <p style="margin-top:14px">
      <a href="<?= htmlspecialchars(BASE_URL) ?>/login.php">Back to login</a>
    </p>
  </div>
</div>

<script>
/* Light input cleanup for backup codes */
(function(){
  const el = document.getElementById('code');
  if (!el) return;

  el.addEventListener('input', () => {
    let v = el.value.toUpperCase().replace(/\s+/g, '');
    if (!/^\d{0,6}$/.test(v)) {
      v = v.replace(/[^A-Z0-9]/g, '');
      if (v.length > 4) v = v.slice(0,4) + '-' + v.slice(4,8);
    }
    el.value = v;
  });
})();
</script>

</body>
</html>
