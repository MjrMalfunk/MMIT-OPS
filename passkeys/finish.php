<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

use Webauthn\AuthenticatorAssertionResponse;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input') ?: '';
$payload = json_decode($body, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

$session = null;
$state = webauthn_verify_state((string)($payload['state'] ?? ''));
if ($state && !empty($state['options'])) {
    $session = $state;
} else {
    $session = $_SESSION['webauthn_assertion'] ?? null;
    if (!$session || empty($session['options'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Passkey session expired. Please try again.']);
        exit;
    }

    $csrfPosted = (string)($payload['csrf_token'] ?? '');
    $csrfSession = (string)($_SESSION['csrf_token'] ?? '');
    if ($csrfPosted === '' || $csrfSession === '' || !hash_equals($csrfSession, $csrfPosted)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Security token mismatch.']);
        exit;
    }
}

$credentialPayload = $payload['assertion'] ?? $payload['credential'] ?? $payload;
if (!is_array($credentialPayload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid assertion payload.']);
    exit;
}

if (empty($credentialPayload['type'])) {
    $credentialPayload['type'] = 'public-key';
}
if (empty($credentialPayload['rawId']) && !empty($credentialPayload['id'])) {
    $credentialPayload['rawId'] = $credentialPayload['id'];
}

try {
    $publicKeyCredential = webauthn_load_public_key_credential_array($credentialPayload);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid assertion response.']);
    exit;
}

$rpId = (string)($session['rpId'] ?? webauthn_rp_id());

// Lookup stored credential by rawId (binary)
$credRow = passkey_repo_find_one_by_credential_id((string)$publicKeyCredential->rawId);
if (!$credRow) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unknown passkey.']);
    exit;
}

if (!empty($session['user_id']) && (int)$credRow['user_id'] !== (int)$session['user_id']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Passkey does not match that account.']);
    exit;
}

// Rebuild PublicKeyCredentialSource from stored JSON
$publicKeyJson = (string)($credRow['public_key'] ?? '');
if ($publicKeyJson === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Stored passkey is missing its public key.']);
    exit;
}

$publicKeyCredentialSource = webauthn_credential_source_from_json($publicKeyJson);
$requestOptions = webauthn_request_options_from_json((string)$session['options']);

try {
    $userHandle = !empty($session['user_id']) ? (string)$session['user_id'] : null;

    $validatedSource = webauthn_assertion_validator()->check(
        $publicKeyCredentialSource,
        $publicKeyCredential->response,
        $requestOptions,
        webauthn_origin(),
        $userHandle,
        [$rpId]
    );
} catch (Throwable $e) {
    if (function_exists('audit_event')) {
        audit_event((int)$credRow['user_id'], 'PASSKEY_FAIL', null, ['reason' => 'assertion_invalid']);
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Passkey verification failed.']);
    exit;
}

// Resolve the user and issue a top-level redirect token. This avoids relying on
// fetch/XHR session cookie timing between the WebAuthn ceremony and the final browser navigation.
$st = db()->prepare('SELECT user_id, email, display_name, user_type, is_active FROM portal_user WHERE user_id = ? LIMIT 1');
$st->execute([(int)$credRow['user_id']]);
$u = $st->fetch();
if (!$u || (int)$u['is_active'] !== 1) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Account disabled.']);
    exit;
}

passkey_repo_update_on_use((string)$credRow['credential_id'], (int)$validatedSource->getCounter());

if (function_exists('audit_event')) {
    audit_event((int)$u['user_id'], 'PASSKEY_SUCCESS', null, ['rpId' => $rpId]);
    audit_event((int)$u['user_id'], 'LOGIN_SUCCESS', null, ['via' => 'passkey']);
}

$next = trim((string)($payload['next'] ?? $_SESSION['mfa_next'] ?? ''));
if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//')) {
    $next = '/dashboard/index.php';
}
unset($_SESSION['mfa_next'], $_SESSION['webauthn_assertion']);

$token = webauthn_issue_state([
    'kind' => 'passkey_login',
    'user_id' => (int)$u['user_id'],
    'next' => $next,
    'auth_method' => 'passkey',
], 300);

$redirect = BASE_URL . '/passkeys/complete.php?token=' . rawurlencode($token);
echo json_encode(['ok' => true, 'redirect' => $redirect]);
