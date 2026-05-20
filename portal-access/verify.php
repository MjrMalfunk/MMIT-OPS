<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/portal_access.php';

$pending = portal_access_pending_verify_get();
if (!$pending) {
    header('Location: ' . BASE_URL . '/portal-access/login.php', true, 302);
    exit;
}

$user = portal_access_load_user_by_id((int) ($pending['user_id'] ?? 0));
$invite = portal_access_get_invite((int) ($pending['invite_id'] ?? 0));
if (!$user || !$invite) {
    portal_access_pending_verify_clear();
    header('Location: ' . BASE_URL . '/portal-access/login.php', true, 302);
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $normalized = preg_replace('/[^A-Z0-9]/', '', $code);
    if ($normalized === '') {
        $err = 'Enter your authenticator code or a backup code.';
    } else {
        $ok = false;
        if (preg_match('/^\d{6,8}$/', $normalized)) {
            $st = db()->prepare('SELECT totp_enabled, totp_secret FROM portal_user_mfa WHERE user_id = ? LIMIT 1');
            $st->execute([(int) $user['user_id']]);
            $mfa = $st->fetch();
            if ($mfa && (int) ($mfa['totp_enabled'] ?? 0) === 1 && !empty($mfa['totp_secret']) && totp_verify((string) $mfa['totp_secret'], $normalized)) {
                db()->prepare('UPDATE portal_user_mfa SET last_used_at = NOW() WHERE user_id = ?')->execute([(int) $user['user_id']]);
                $ok = true;
                audit_event((int) $user['user_id'], 'MFA_SUCCESS', ['method' => 'totp']);
            }
        } else {
            $st = db()->prepare('SELECT code_id, code_hash FROM mfa_backup_code WHERE user_id = ? AND used_at IS NULL');
            $st->execute([(int) $user['user_id']]);
            foreach ($st->fetchAll() as $row) {
                if (!empty($row['code_hash']) && password_verify($normalized, (string) $row['code_hash'])) {
                    db()->prepare('UPDATE mfa_backup_code SET used_at = NOW() WHERE code_id = ?')->execute([(int) $row['code_id']]);
                    audit_event((int) $user['user_id'], 'BACKUP_CODE_USED', ['method' => 'backup_code']);
                    $ok = true;
                    break;
                }
            }
        }

        if ($ok) {
            portal_access_login_user($user, 'email_link+2fa');
            portal_access_session_set($invite, 'DASHBOARD');
            $_SESSION['mfa_recent_at'] = time();
            portal_access_pending_verify_clear();
            audit_event((int) $user['user_id'], 'LOGIN_SUCCESS', ['via' => 'email_link+2fa']);
            if (!portal_access_user_security_ready((int) ($user['user_id'] ?? 0))) {
                header('Location: ' . portal_access_target_url('DASHBOARD', '/client-security.php?welcome=1&next=' . rawurlencode('/client-preview.php')), true, 302);
                exit;
            }
            header('Location: ' . portal_access_target_url('DASHBOARD', '/client-preview.php'), true, 302);
            exit;
        }
        $err = 'That code did not match. Try the current authenticator code or an unused backup code.';
        audit_event((int) $user['user_id'], 'MFA_FAIL', ['reason' => 'invalid_code']);
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verify | <?= htmlspecialchars(APP_NAME) ?></title>
<link rel="icon" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon.ico">
<style>
*,*::before,*::after{box-sizing:border-box}body{margin:0;color:#e8eefc;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial;background:#050b16 url("/pix/portal-bg-dark.jpg") center/cover no-repeat fixed}.wrap{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:20px 20px 28px}.card{width:min(420px,92vw);background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:18px;padding:18px}h1{margin:0 0 8px;font-size:22px}p{margin:8px 0;opacity:.9}label{display:block;margin-top:14px;margin-bottom:6px;font-weight:600}input{width:100%;max-width:100%;padding:12px;border-radius:12px;border:1px solid rgba(255,255,255,.16);background:rgba(0,0,0,.25);color:#e8eefc;font-size:16px}button{width:100%;margin-top:16px;padding:12px;border-radius:12px;border:0;background:#2f6df6;color:white;font-weight:700;font-size:16px;cursor:pointer}.err{background:rgba(255,60,80,.12);border:1px solid rgba(255,60,80,.3);padding:10px;border-radius:12px;margin-top:10px}.tip{margin-top:6px;font-size:14px;opacity:.8}.head{width:min(960px,94vw);display:flex;justify-content:center;align-items:center;gap:16px;flex-wrap:wrap;margin:14px 0}.titles{display:flex;flex-direction:column;align-items:center;text-align:center}.muted{opacity:.8;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <div class="head"><div class="titles"><div style="font-weight:700">Security</div><div class="muted">Email link fallback verification</div></div></div>
  <div class="card">
    <h1>Enter your 2FA code</h1>
    <p>We already confirmed the inbox. This step confirms that you also hold the authenticator or a backup code.</p>
    <?php if ($err): ?><p class="err"><?= htmlspecialchars($err) ?></p><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <label for="code">Code</label>
      <input id="code" name="code" autocomplete="one-time-code" placeholder="123456 or ABCD-EFGH" required autofocus>
      <p class="tip">Daily sign-in should still use passkeys where available. This lane is the safe fallback.</p>
      <button type="submit">Verify and continue</button>
    </form>
  </div>
</div>
</body>
</html>
