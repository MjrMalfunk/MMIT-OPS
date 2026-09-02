<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/ops_users.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$token = strtolower(trim((string) ($_GET['token'] ?? $_POST['token'] ?? '')));
$invite = ops_user_invite_table_ready()
    ? ops_user_invite_find_by_token($token)
    : null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['password_confirmation'] ?? '');
    $strongPassword =
        strlen($password) >= 14
        && strlen($password) <= 1024
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^a-zA-Z0-9]/', $password);

    if (!$invite) {
        $error = 'This invitation is invalid, expired, revoked, or already used.';
    } elseif ($password !== $confirmation) {
        $error = 'The password confirmation does not match.';
    } elseif (!$strongPassword) {
        $error = 'Use at least 14 characters with uppercase, lowercase, a number, and a symbol.';
    } else {
        $pdo = db();

        try {
            $pdo->beginTransaction();
            $locked = ops_user_invite_find_by_token($token, true);
            if (!$locked) {
                throw new RuntimeException('INVITE_UNAVAILABLE');
            }

            $email = ops_users_normalize_email((string) $locked['email']);
            $name = trim((string) $locked['display_name']);

            $existing = $pdo->prepare(
                'SELECT user_id FROM portal_user WHERE email = ? LIMIT 1 FOR UPDATE'
            );
            $existing->execute([$email]);
            if ($existing->fetch()) {
                throw new RuntimeException('ACCOUNT_EXISTS');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            if (!is_string($hash) || $hash === '') {
                throw new RuntimeException('HASH_FAILED');
            }

            $insert = $pdo->prepare(
                "INSERT INTO portal_user
                    (tenant_id, email, display_name, password_hash, user_type, is_active)
                 VALUES (NULL, ?, ?, ?, 'INTERNAL', 1)"
            );
            $insert->execute([$email, $name, $hash]);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO portal_user_mfa
                    (user_id, totp_enabled, totp_secret)
                 VALUES (?, 0, NULL)'
            )->execute([$userId]);

            $consume = $pdo->prepare(
                'UPDATE ops_user_invite
                 SET accepted_at = NOW()
                 WHERE invite_id = ?
                   AND accepted_at IS NULL
                   AND revoked_at IS NULL'
            );
            $consume->execute([(int) $locked['invite_id']]);
            if ($consume->rowCount() !== 1) {
                throw new RuntimeException('CONSUME_FAILED');
            }

            $pdo->commit();

            session_regenerate_safe(true);
            $_SESSION['user'] = [
                'user_id' => $userId,
                'email' => $email,
                'display_name' => $name,
                'user_type' => 'INTERNAL',
            ];
            $_SESSION['user_id'] = $userId;
            $_SESSION['auth_method'] = 'password';
            unset(
                $_SESSION['mfa_recent_at'],
                $_SESSION['mfa_pending'],
                $_SESSION['mfa_pending_user_id'],
                $_SESSION['mfa_setup_secret']
            );

            audit_event($userId, 'OPS_USER_INVITE_ACCEPTED', [
                'invite_id' => (int) $locked['invite_id'],
            ]);
            auth_session_commit();

            $returnTo = rtrim((string) BASE_URL, '/') . '/admin/index.php';
            header(
                'Location: ' . rtrim((string) BASE_URL, '/')
                . '/mfa/setup.php?return_to=' . rawurlencode($returnTo)
            );
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e->getMessage() === 'INVITE_UNAVAILABLE') {
                $error = 'This invitation is no longer available.';
            } elseif ($e->getMessage() === 'ACCOUNT_EXISTS') {
                $error = 'An account already exists for this email address.';
            } else {
                error_log('OPS invitation acceptance failed: ' . $e->getMessage());
                $error = 'The account could not be created. Ask the inviter to send a fresh invitation.';
            }
        }
    }
}

$valid = is_array($invite);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>Accept MMIT OPS invitation</title>
  <style>
  *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#071b15;color:#e7f0eb;font-family:system-ui,sans-serif}
  main{width:min(100%,560px);padding:28px;border:1px solid #36564a;border-radius:18px;background:#102a22}h1{margin:10px 0}p{color:#bfd0c8;line-height:1.6}
  .brand{color:#88d8b8;font-size:12px;font-weight:800;letter-spacing:.1em}.identity,.error{margin:18px 0;padding:14px;border-radius:10px;background:#193a30}
  .error{color:#ffd7d3;background:#5a211d}form,label{display:grid;gap:12px}input,button{min-height:46px;padding:10px 12px;border-radius:8px;font:inherit}
  input{color:#fff;background:#091f18;border:1px solid #547065}button{color:#fff;background:#16875d;border:0;font-weight:800;cursor:pointer}a{color:#8de0bd}
  </style>
</head>
<body>
<main>
  <div class="brand">MIDWEST MANAGED IT · OPS</div>
  <?php if (!$valid): ?>
    <h1>Invitation unavailable</h1>
    <p>This link is invalid, expired, revoked, or already used. Ask the MMIT owner to send a fresh invitation.</p>
    <a href="<?= htmlspecialchars(rtrim((string) BASE_URL, '/')) ?>">Return to OPS sign-in</a>
  <?php else: ?>
    <h1>Create your OPS account</h1>
    <p>Create your password. Mandatory authenticator setup comes next, followed by optional passkey registration.</p>
    <div class="identity">
      <strong><?= htmlspecialchars((string) $invite['display_name']) ?></strong><br>
      <?= htmlspecialchars((string) $invite['email']) ?>
    </div>
    <?php if ($error !== ''): ?><div class="error" role="alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <label>Password<input name="password" type="password" required minlength="14" maxlength="1024" autocomplete="new-password"></label>
      <label>Confirm password<input name="password_confirmation" type="password" required minlength="14" maxlength="1024" autocomplete="new-password"></label>
      <p>At least 14 characters with uppercase, lowercase, a number, and a symbol.</p>
      <button type="submit">Create account and set up MFA</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
