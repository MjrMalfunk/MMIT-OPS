<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/webauthn.php';
require_once __DIR__ . '/../inc/portal_access.php';

use Webauthn\AuthenticatorAttestationResponse;

header('Content-Type: application/json; charset=utf-8');

require_login();

$session = $_SESSION['webauthn_registration'] ?? null;
if (!$session || empty($session['options']) || empty($session['user_id'])) {
    error_log('PASSKEY register_finish: registration session expired or missing');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Registration session expired.']);
    exit;
}

$cu = current_user();
if ((int)($cu['user_id'] ?? 0) !== (int)$session['user_id']) {
    error_log('PASSKEY register_finish: user mismatch');
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'User mismatch.']);
    exit;
}

/**
 * Return the first non-empty array payload we can use as a credential object.
 */
function extract_credential_payload(array $payload): ?array
{
    $candidates = [
        $payload['attestation'] ?? null,
        $payload['credential'] ?? null,
        $payload['publicKeyCredential'] ?? null,
        $payload,
    ];

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                $candidate = $decoded;
            }
        }

        if (!is_array($candidate)) {
            continue;
        }

        // Accept wrapped or partially-normalized credential arrays.
        if (
            isset($candidate['id']) ||
            isset($candidate['rawId']) ||
            isset($candidate['response'])
        ) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Normalize likely frontend variations to the shape expected by the WebAuthn lib.
 */
function normalize_credential_payload(array $credential): array
{
    if (!isset($credential['type']) || $credential['type'] === '') {
        $credential['type'] = 'public-key';
    }

    if (empty($credential['rawId']) && !empty($credential['id'])) {
        $credential['rawId'] = $credential['id'];
    }

    if (!isset($credential['response']) || !is_array($credential['response'])) {
        $credential['response'] = [];
    }

    $response = $credential['response'];

    if (!isset($response['clientDataJSON']) && isset($response['clientDataJson'])) {
        $response['clientDataJSON'] = $response['clientDataJson'];
    }
    if (!isset($response['clientDataJSON']) && isset($response['client_data_json'])) {
        $response['clientDataJSON'] = $response['client_data_json'];
    }

    if (!isset($response['attestationObject']) && isset($response['attestation_object'])) {
        $response['attestationObject'] = $response['attestation_object'];
    }

    $credential['response'] = $response;

    return $credential;
}

$body = file_get_contents('php://input') ?: '';
$payload = json_decode($body, true);

if (!is_array($payload)) {
    error_log('PASSKEY register_finish: request body did not decode to array');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

$credentialPayload = extract_credential_payload($payload);
if (!$credentialPayload) {
    error_log('PASSKEY register_finish: no credential payload extracted');
    if (function_exists('audit_event')) {
        audit_event((int)$cu['user_id'], 'PASSKEY_REGISTER_FAIL', null, [
            'reason' => 'payload_shape_invalid',
            'payload_keys' => array_keys($payload),
        ]);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

$credentialPayload = normalize_credential_payload($credentialPayload);

if (
    empty($credentialPayload['id']) ||
    empty($credentialPayload['rawId']) ||
    empty($credentialPayload['type']) ||
    empty($credentialPayload['response']) ||
    !is_array($credentialPayload['response']) ||
    empty($credentialPayload['response']['clientDataJSON']) ||
    empty($credentialPayload['response']['attestationObject'])
) {
    error_log('PASSKEY register_finish: missing expected credential fields');
    if (function_exists('audit_event')) {
        audit_event((int)$cu['user_id'], 'PASSKEY_REGISTER_FAIL', null, [
            'reason' => 'missing_expected_fields',
            'credential_keys' => array_keys($credentialPayload),
            'response_keys' => array_keys((array)($credentialPayload['response'] ?? [])),
        ]);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

try {
    $publicKeyCredential = webauthn_load_public_key_credential_array($credentialPayload);
} catch (Throwable $e) {
    error_log('PASSKEY register_finish: credential_deserialize_failed: ' . $e->getMessage());
    if (function_exists('audit_event')) {
        audit_event((int)$cu['user_id'], 'PASSKEY_REGISTER_FAIL', null, [
            'reason' => 'credential_deserialize_failed',
            'message' => $e->getMessage(),
        ]);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid credential payload.']);
    exit;
}

if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
    error_log('PASSKEY register_finish: invalid attestation response type');
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid attestation response.']);
    exit;
}

$rpId = (string)($session['rpId'] ?? webauthn_rp_id());

try {
    $publicKeyCredentialSource = webauthn_attestation_validator()->check(
        $publicKeyCredential->response,
        webauthn_creation_options_from_json((string)$session['options']),
        webauthn_origin(),
        [$rpId]
    );
} catch (Throwable $e) {
    error_log('PASSKEY register_finish: attestation_invalid: ' . $e->getMessage());
    if (function_exists('audit_event')) {
        audit_event((int)$cu['user_id'], 'PASSKEY_REGISTER_FAIL', null, [
            'reason' => 'attestation_invalid',
            'message' => $e->getMessage(),
        ]);
    }
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Passkey registration failed.']);
    exit;
}

$nickname = trim((string)($payload['nickname'] ?? $payload['label'] ?? ''));
if ($nickname === '') {
    $nickname = null;
}

$transports = null;
try {
    $t = $publicKeyCredentialSource->getTransports();
    if (is_array($t) && $t) {
        $transports = implode(',', $t);
    }
} catch (Throwable $e) {
    $transports = null;
}

passkey_repo_insert((int)$cu['user_id'], null, $publicKeyCredentialSource, $nickname, $transports);

unset($_SESSION['webauthn_registration']);
$_SESSION['client_security_ready'] = portal_access_user_security_ready((int)$cu['user_id']);

if (function_exists('audit_event')) {
    audit_event((int)$cu['user_id'], 'PASSKEY_REGISTER_SUCCESS', null, ['rpId' => $rpId]);
}

echo json_encode(['ok' => true]);
