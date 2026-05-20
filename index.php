<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (!function_exists('ops_safe_next_path')) {
    function ops_safe_next_path(?string $raw, string $default = '/dashboard/index.php'): string {
        $raw = trim((string)($raw ?? ''));
        if ($raw === '') {
            return $default;
        }
        if ($raw[0] !== '/' || str_starts_with($raw, '//')) {
            return $default;
        }
        return $raw;
    }
}
if (!function_exists('ops_same_origin_login_post')) {
    function ops_same_origin_login_post(): bool {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = trim(explode(':', $host)[0] ?? '');
        if ($host === '') {
            return true;
        }

        foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
            $raw = trim((string)($_SERVER[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            $originHost = strtolower(trim((string)parse_url($raw, PHP_URL_HOST)));
            if ($originHost !== '' && $originHost !== $host) {
                return false;
            }
        }

        return true;
    }
}

$next = ops_safe_next_path((string)($_GET['next'] ?? $_POST['next'] ?? ''), '/dashboard/index.php');

if (current_user()) {
    header('Location: ' . BASE_URL . $next);
    exit;
}

$error = ((string) ($_GET['timeout'] ?? '') === '1') ? 'Your OPS session timed out after 10 minutes of inactivity. Please sign in again.' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string)($_POST['csrf_token'] ?? '');
    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $csrfOk = ($postedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $postedToken));
    if (!$csrfOk && !ops_same_origin_login_post()) {
        $error = 'Your login page expired. Please try again.';
    } else {
        if (!$csrfOk) {
            // Login itself is same-origin and credential-driven. When the page
            // token is stale, reseed the form token instead of hard-failing the
            // operator on a fresh-looking login screen.
            unset($_SESSION['csrf_token']);
            csrf_token();
        }
        $email = trim((string)($_POST['email'] ?? ''));
        $pass = (string)($_POST['password'] ?? '');
        $res = auth_login_attempt($email, $pass);

        if (!$res['ok']) {
            $error = (string)($res['error'] ?? 'Login failed.');
        } elseif (!empty($res['mfa_required'])) {
            $uid = $res['user_id'] ?? ($res['user']['user_id'] ?? null) ?? ($res['id'] ?? null);

            if (!$uid) {
                $error = 'Login succeeded, but MFA could not start.';
            } else {
                $_SESSION['mfa_pending_user_id'] = (int)$uid;
                $_SESSION['mfa_pending_at'] = time();
                $_SESSION['mfa_next'] = $next;
                auth_session_commit();
                header('Location: ' . BASE_URL . '/mfa/verify.php?next=' . rawurlencode($next));
                exit;
            }
        } else {
            auth_session_commit();
            header('Location: ' . BASE_URL . $next);
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-64.png">
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=4">
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-card glass" aria-labelledby="login-title">
            <div class="login-brand">
                <div>
                    <h1 id="login-title">Operator login</h1>
                    <p class="login-subtitle">Sign in to access the OPS portal.</p>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="form-alert" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" autocomplete="on" class="login-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

                <label for="email">Email</label>
                <input
                    id="email"
                    name="email"
                    type="email"
                    required
                    autofocus
                    autocomplete="username webauthn"
                    value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>"
                >

                <label for="password">Password</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password webauthn"
                >

                <div class="login-actions">
                    <button class="btn btn-primary" type="submit">Sign in</button>
                    <button class="btn btn-secondary" type="button" id="passkeyBtn">Use Passkey</button>
                </div>

                <p id="passkeyHint" class="login-note">Tip: enter your email for faster passkey matching.</p>
            </form>

            <div class="login-footer">
                If your account uses MFA, you'll be prompted for your verification code next.
            </div>
        </section>
    </main>

    <script>
    (() => {
        const btn = document.getElementById('passkeyBtn');
        const emailEl = document.getElementById('email');
        const hint = document.getElementById('passkeyHint');
        const next = <?= json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const autoPasskey = <?= json_encode(((string)($_GET['auto'] ?? '')) === 'passkey') ?>;

        if (!btn) return;

        if (!window.PublicKeyCredential || !navigator.credentials || !navigator.credentials.get) {
            btn.classList.add('hide');
            hint.classList.add('hide');
            return;
        }

        function b64urlToBuf(b64url) {
            const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
            const b64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
            const str = atob(b64);
            const bytes = new Uint8Array(str.length);
            for (let i = 0; i < str.length; i += 1) bytes[i] = str.charCodeAt(i);
            return bytes.buffer;
        }

        function bufToB64url(buf) {
            const bytes = new Uint8Array(buf);
            let str = '';
            for (let i = 0; i < bytes.length; i += 1) str += String.fromCharCode(bytes[i]);
            return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
        }

        function normalizeRequestOptions(options) {
            options.challenge = b64urlToBuf(options.challenge);
            if (Array.isArray(options.allowCredentials)) {
                options.allowCredentials = options.allowCredentials.map((credential) => ({
                    ...credential,
                    id: b64urlToBuf(credential.id),
                }));
            }
            return options;
        }

        function serializeAssertion(cred) {
            return {
                id: bufToB64url(cred.rawId),
                rawId: bufToB64url(cred.rawId),
                type: cred.type,
                response: {
                    authenticatorData: bufToB64url(cred.response.authenticatorData),
                    clientDataJSON: bufToB64url(cred.response.clientDataJSON),
                    signature: bufToB64url(cred.response.signature),
                    userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
                },
            };
        }

        async function postJSON(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(body || {}),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.error || 'Request failed');
            }
            return payload;
        }

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            const oldTxt = btn.textContent;
            btn.textContent = 'Checking passkey...';

            try {
                const email = (emailEl.value || '').trim() || null;
                const optRes = await postJSON(
                    '<?= htmlspecialchars(BASE_URL) ?>/passkeys/begin.php',
                    {
                        email,
                        csrf_token: '<?= htmlspecialchars(csrf_token()) ?>',
                    }
                );

                if (!optRes.publicKey || !optRes.state) throw new Error('Invalid server response.');

                const cred = await navigator.credentials.get({
                    publicKey: normalizeRequestOptions(optRes.publicKey),
                });

                if (!cred) throw new Error('No credential returned.');

                const verifyRes = await postJSON(
                    '<?= htmlspecialchars(BASE_URL) ?>/passkeys/finish.php',
                    {
                        email,
                        next,
                        assertion: serializeAssertion(cred),
                        state: optRes.state,
                        csrf_token: '<?= htmlspecialchars(csrf_token()) ?>',
                    }
                );

                if (verifyRes.mfa_required) {
                    window.location.href = '<?= htmlspecialchars(BASE_URL) ?>/mfa/verify.php?next=' + encodeURIComponent(next);
                    return;
                }

                window.location.href = verifyRes.redirect || ('<?= htmlspecialchars(BASE_URL) ?>' + next);
            } catch (e) {
                const msg = e && e.message ? e.message : 'Passkey login failed';
                if (/expired|token mismatch|security/i.test(msg)) {
                    alert(msg + ' Refreshing the login page…');
                    window.location.reload();
                    return;
                }
                alert(msg);
                btn.disabled = false;
                btn.textContent = oldTxt;
            }
        });

        if (autoPasskey) {
            window.setTimeout(() => btn.click(), 120);
        }
    })();
    </script>
</body>
</html>
