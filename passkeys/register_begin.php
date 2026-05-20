<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

header('Content-Type: application/json; charset=utf-8');

require_login();
//require_recent_mfa('/passkeys/manage.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

// CSRF: accept token from either form POST or JSON body
$tok = (string)($_POST['csrf_token'] ?? '');
if ($tok === '') {
    $body = file_get_contents('php://input') ?: '';
    $p = json_decode($body, true);
    $tok = (string)($p['csrf_token'] ?? '');
}
if (!is_string($tok) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $tok)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF check failed.']);
    exit;
}

$cu = current_user();
$userId = (int)$cu['user_id'];

$rpEntity = PublicKeyCredentialRpEntity::create(
    APP_NAME,
    webauthn_rp_id(),
    null
);

$userEntity = PublicKeyCredentialUserEntity::create(
    (string)$cu['email'],
    (string)$userId,
    (string)$cu['display_name'],
    null
);

$excluded = [];
$rows = passkey_repo_find_all_for_user($userId);
foreach ($rows as $r) {
    $excluded[] = PublicKeyCredentialDescriptor::create('public-key', (string)$r['credential_id']);
}

$authSel = AuthenticatorSelectionCriteria::create(
    authenticatorAttachment: AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
    userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
    residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED
);

$options = PublicKeyCredentialCreationOptions::create(
    $rpEntity,
    $userEntity,
    random_bytes(32),
    authenticatorSelection: $authSel,
    attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
    excludeCredentials: $excluded,
    timeout: 300_000
);

$_SESSION['webauthn_registration'] = [
    'user_id' => $userId,
    'options' => json_encode($options, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    'rpId'    => webauthn_rp_id(),
];

echo json_encode(['ok' => true, 'publicKey' => json_decode(json_encode($options), true)]);
