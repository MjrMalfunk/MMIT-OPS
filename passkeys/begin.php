<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';

use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$body = file_get_contents('php://input') ?: '';
$payload = json_decode($body, true);
$email = strtolower(trim((string)($payload['email'] ?? '')));

// If email provided: scope allowCredentials to that user.
// If no email: allow "discoverable" (resident) credentials.
$user = null;
$allowedCredentials = [];

if ($email !== '') {
    $st = db()->prepare('SELECT user_id, email, display_name, user_type, is_active FROM portal_user WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $u = $st->fetch();

    if (!$u || (int)$u['is_active'] !== 1) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'That email is not ready for passkey sign-in yet. Use the secure link instead.']);
        exit;
    }

    $user = [
        'user_id' => (int)$u['user_id'],
        'email' => (string)$u['email'],
        'display_name' => (string)$u['display_name'],
        'user_type' => (string)$u['user_type'],
    ];

    $rows = passkey_repo_find_all_for_user((int)$u['user_id']);
    if (!$rows) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'No passkey is enrolled for that email yet. Use the secure link first.']);
        exit;
    }

    foreach ($rows as $r) {
        $allowedCredentials[] = PublicKeyCredentialDescriptor::create('public-key', (string)$r['credential_id']);
    }
}

$options = PublicKeyCredentialRequestOptions::create(
    random_bytes(32),
    rpId: webauthn_rp_id(),
    allowCredentials: $allowedCredentials,
    userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
    timeout: 300_000
);

$_SESSION['__regen_at'] = time();

$_SESSION['webauthn_assertion'] = [
    'options' => json_encode($options, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
    'email'   => $email,
    'user_id' => $user['user_id'] ?? null,
    'rpId'    => webauthn_rp_id(),
];

$state = webauthn_issue_state([
    'options' => (string) json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'email' => $email,
    'user_id' => $user['user_id'] ?? null,
    'rpId' => webauthn_rp_id(),
], 300);

session_write_close();
echo json_encode([
    'ok' => true,
    'publicKey' => json_decode(json_encode($options), true),
    'state' => $state,
]);
