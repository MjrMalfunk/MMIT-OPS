<?php
declare(strict_types=1);

// --- Load config ---
$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
$host = preg_replace('/:\d+$/', '', $host);

$configOverride = '';
if (defined('OPS_CONFIG_FILE') && is_string(OPS_CONFIG_FILE) && OPS_CONFIG_FILE !== '') {
    $configOverride = OPS_CONFIG_FILE;
} else {
    $envOverride = getenv('OPS_CONFIG_FILE');
    if (is_string($envOverride) && $envOverride !== '') {
        $configOverride = $envOverride;
    }
}

if ($configOverride !== '') {
    $cfg = $configOverride;
    $missingMessage = 'Missing OPS config override file.';
} elseif ($host === 'ops-test.midwestmanagedit.com') {
    $cfg = '/home/mjrmstlj/private/ops-secrets.staging.php';
    $missingMessage = 'Missing OPS staging config.';
} else {
    $cfg = '/home/mjrmstlj/private/ops-secrets.php';
    $missingMessage = 'Missing OPS config.';
}

if (!is_string($cfg) || $cfg === '' || !file_exists($cfg)) {
    http_response_code(500);
    echo $missingMessage;
    exit;
}
require_once $cfg;

// --- Timezone ---
// Keep date defaults consistent across servers (receipt entry uses server default date).
// Override via define('APP_TIMEZONE','America/Indiana/Indianapolis') in inc/config.php if desired.
if (function_exists('date_default_timezone_set')) {
    $tz = defined('APP_TIMEZONE') && is_string(APP_TIMEZONE) && APP_TIMEZONE !== ''
        ? APP_TIMEZONE
        : 'America/Indiana/Indianapolis';
    @date_default_timezone_set($tz);
}

// --- Force HTTPS ---
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (!empty($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https') {
        return true;
    }

    if (!empty($_SERVER['SERVER_PORT']) && (string)$_SERVER['SERVER_PORT'] === '443') {
        return true;
    }

    $xfp = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($xfp !== '') {
        $firstProto = strtolower(trim(explode(',', $xfp)[0] ?? ''));
        if ($firstProto === 'https') {
            return true;
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    if (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower((string)$_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
        return true;
    }

    $cfVisitor = (string)($_SERVER['HTTP_CF_VISITOR'] ?? '');
    if ($cfVisitor !== '' && stripos($cfVisitor, 'https') !== false) {
        return true;
    }

    return false;
}

if (!is_https()) {
    $host = $_SERVER['HTTP_HOST'] ?? parse_url((string)BASE_URL, PHP_URL_HOST) ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    header('Location: https://' . $host . $uri, true, 302);
    exit;
}

// --- Secure session ---
// Security-focused defaults (keep behavior stable on shared hosting)
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

$cookieParams = session_get_cookie_params();
$sessionCookieDomain = '';
if (defined('SESSION_COOKIE_DOMAIN') && is_string(SESSION_COOKIE_DOMAIN) && SESSION_COOKIE_DOMAIN !== '') {
    $sessionCookieDomain = SESSION_COOKIE_DOMAIN;
}
session_name(SESSION_NAME);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $cookieParams['path'] ?? '/',
    'domain' => $sessionCookieDomain !== '' ? $sessionCookieDomain : ($cookieParams['domain'] ?? ''),
    'secure' => COOKIE_SECURE,
    'httponly' => COOKIE_HTTPONLY,
    'samesite' => COOKIE_SAMESITE,
]);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Session lifecycle hardening
$now = time();
$idleTtl = (int)SESSION_TTL_SECONDS; // idle timeout
$absTtl  = (int)(defined('SESSION_ABS_TTL_SECONDS') ? SESSION_ABS_TTL_SECONDS : 43200); // 12h default

if (!isset($_SESSION['__created_at'])) {
    $_SESSION['__created_at'] = $now;
}
if (!isset($_SESSION['__last_seen'])) {
    $_SESSION['__last_seen'] = $now;
}

// Optional lightweight session binding (OFF unless enabled in config)
if (defined('SESSION_BIND_UA_IP') && SESSION_BIND_UA_IP) {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $fp = hash('sha256', $ua . '|' . $ip);
    if (!isset($_SESSION['__fp'])) {
        $_SESSION['__fp'] = $fp;
    } elseif (!hash_equals((string)$_SESSION['__fp'], $fp)) {
        // Fingerprint mismatch: treat as invalid session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', $now - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        session_start();
        $_SESSION['__created_at'] = $now;
        $_SESSION['__last_seen'] = $now;
        $_SESSION['__fp'] = $fp;
    }
}

// Expire session on idle or absolute TTL
if (($now - (int)$_SESSION['__last_seen']) > $idleTtl || ($now - (int)$_SESSION['__created_at']) > $absTtl) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', $now - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    session_start();
    $_SESSION['__created_at'] = $now;
    $_SESSION['__last_seen'] = $now;
}

function request_path(): string {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return is_string($path) && $path !== '' ? $path : '/';
}

function request_is_webauthn_flow(): bool {
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    $path = request_path();
    $sensitive = [
        '/passkeys/begin.php',
        '/passkeys/finish.php',
        '/passkeys/register_begin.php',
        '/passkeys/register_finish.php',
    ];
    $result = in_array($path, $sensitive, true);
    return $result;
}

// Rolling session id rotation (mitigates fixation/window hijack)
// Skip regeneration during WebAuthn request pairs so the browser does not lose
// the session cookie between the begin and finish calls.
$regenEvery = (int)(defined('SESSION_REGEN_SECONDS') ? SESSION_REGEN_SECONDS : 900); // 15m default
if (!isset($_SESSION['__regen_at'])) {
    $_SESSION['__regen_at'] = $now;
} elseif (!request_is_webauthn_flow() && (($now - (int)$_SESSION['__regen_at']) >= $regenEvery)) {
    session_regenerate_id(true);
    $_SESSION['__regen_at'] = $now;
}

$_SESSION['__last_seen'] = $now;

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/auth.php';

// Basic headers
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
