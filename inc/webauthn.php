<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/db.php';

// Composer autoloader (created under the site root: /vendor)
require_once __DIR__ . '/../vendor/autoload.php';

use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\TokenBinding\IgnoreTokenBindingHandler;
use Webauthn\Exception\InvalidDataException;

function webauthn_rp_id(): string
{
    $host = (string)parse_url(BASE_URL, PHP_URL_HOST);
    if ($host === '') {
        $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $host = preg_replace('/:\d+$/', '', $host);
    }
    return $host !== '' ? $host : 'localhost';
}

function webauthn_origin(): string
{
    $scheme = (string)parse_url(BASE_URL, PHP_URL_SCHEME);
    $host = (string)parse_url(BASE_URL, PHP_URL_HOST);
    $port = parse_url(BASE_URL, PHP_URL_PORT);
    if ($scheme === '' || $host === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
        return $scheme . '://' . $host;
    }
    $origin = $scheme . '://' . $host;
    if ($port && !in_array((int)$port, [80, 443], true)) {
        $origin .= ':' . (int)$port;
    }
    return $origin;
}


function webauthn_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthn_b64url_decode(string $data): string
{
    $pad = strlen($data) % 4;
    if ($pad > 0) {
        $data .= str_repeat('=', 4 - $pad);
    }
    $decoded = base64_decode(strtr($data, '-_', '+/'), true);
    if ($decoded === false) {
        throw new RuntimeException('Invalid base64url payload.');
    }
    return $decoded;
}

function webauthn_state_secret(): string
{
    $base = defined('APP_ENC_KEY_B64') ? (string)APP_ENC_KEY_B64 : (string)DB_PASS;
    return hash('sha256', $base . '|webauthn-state|' . SESSION_NAME, true);
}

function webauthn_issue_state(array $payload, int $ttlSeconds = 300): string
{
    $payload['iat'] = time();
    $payload['exp'] = time() + max(60, $ttlSeconds);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Could not encode WebAuthn state.');
    }
    $body = webauthn_b64url_encode($json);
    $sig = webauthn_b64url_encode(hash_hmac('sha256', $body, webauthn_state_secret(), true));
    return $body . '.' . $sig;
}

function webauthn_verify_state(string $token): ?array
{
    $token = trim($token);
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }
    [$body, $sig] = explode('.', $token, 2);
    $expected = webauthn_b64url_encode(hash_hmac('sha256', $body, webauthn_state_secret(), true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }
    try {
        $json = webauthn_b64url_decode($body);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($data)) {
        return null;
    }
    if ((int)($data['exp'] ?? 0) < time()) {
        return null;
    }
    return $data;
}

function webauthn_attestation_support_manager(): AttestationStatementSupportManager
{
    static $manager = null;
    if ($manager instanceof AttestationStatementSupportManager) {
        return $manager;
    }

    $manager = AttestationStatementSupportManager::create();
    $manager->add(NoneAttestationStatementSupport::create());

    return $manager;
}

function webauthn_attestation_object_loader(): AttestationObjectLoader
{
    static $loader = null;
    if ($loader instanceof AttestationObjectLoader) {
        return $loader;
    }

    $loader = AttestationObjectLoader::create(webauthn_attestation_support_manager());
    return $loader;
}

function webauthn_public_key_credential_loader(): PublicKeyCredentialLoader
{
    static $loader = null;
    if ($loader instanceof PublicKeyCredentialLoader) {
        return $loader;
    }

    $loader = PublicKeyCredentialLoader::create(webauthn_attestation_object_loader(), null);
    return $loader;
}

function webauthn_attestation_validator(): AuthenticatorAttestationResponseValidator
{
    static $v = null;
    if ($v instanceof AuthenticatorAttestationResponseValidator) {
        return $v;
    }

    $ceremonyStepManagerFactory = new CeremonyStepManagerFactory(
        webauthn_attestation_support_manager(),
        null,
        new IgnoreTokenBindingHandler(),
        null
    );

    $v = new AuthenticatorAttestationResponseValidator(
        webauthn_attestation_support_manager(),
        null,
        new IgnoreTokenBindingHandler(),
        null,
        null,
        $ceremonyStepManagerFactory->creationCeremony([webauthn_rp_id()])
    );

    return $v;
}

function webauthn_assertion_validator(): AuthenticatorAssertionResponseValidator
{
    static $v = null;
    if ($v instanceof AuthenticatorAssertionResponseValidator) {
        return $v;
    }

    $ceremonyStepManagerFactory = new CeremonyStepManagerFactory(
        webauthn_attestation_support_manager(),
        null,
        new IgnoreTokenBindingHandler(),
        null
    );

    $v = new AuthenticatorAssertionResponseValidator(
        null,
        new IgnoreTokenBindingHandler(),
        null,
        null,
        null,
        $ceremonyStepManagerFactory->requestCeremony([webauthn_rp_id()])
    );

    return $v;
}

function webauthn_creation_options_from_json(string $json): \Webauthn\PublicKeyCredentialCreationOptions
{
    return \Webauthn\PublicKeyCredentialCreationOptions::createFromString($json);
}

function webauthn_request_options_from_json(string $json): \Webauthn\PublicKeyCredentialRequestOptions
{
    return \Webauthn\PublicKeyCredentialRequestOptions::createFromString($json);
}

function webauthn_credential_source_from_json(string $json): PublicKeyCredentialSource
{
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid stored passkey JSON.');
    }

    return PublicKeyCredentialSource::createFromArray($data);
}

/**
 * Load the PublicKeyCredential object from the JSON sent by the browser.
 */
function webauthn_load_public_key_credential(string $json): PublicKeyCredential
{
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new InvalidDataException('Invalid WebAuthn credential payload.');
    }

    return webauthn_load_public_key_credential_array($data);
}

/**
 * Load the PublicKeyCredential object from an already-decoded browser payload.
 * This bypasses Symfony serializer paths entirely.
 */
function webauthn_load_public_key_credential_array(array $data): PublicKeyCredential
{
    return webauthn_public_key_credential_loader()->loadArray($data);
}

/* =========================
   DB helpers (user_passkey)
   ========================= */

function passkey_repo_find_all_for_user(int $userId): array
{
    $st = db()->prepare('SELECT credential_id, label, created_at, last_used_at, aaguid, transports, public_key, sign_count FROM webauthn_credential WHERE user_id = ? ORDER BY created_at DESC');
    $st->execute([$userId]);
    $rows = $st->fetchAll() ?: [];
    foreach ($rows as &$row) {
        $row['nickname'] = (string)($row['label'] ?? '');
    }
    return $rows;
}

function passkey_repo_find_one_by_credential_id(string $credentialIdBinary): ?array
{
    $st = db()->prepare('SELECT credential_id, user_id, public_key, sign_count, aaguid, transports, label FROM webauthn_credential WHERE credential_id = ? LIMIT 1');
    $st->execute([$credentialIdBinary]);
    $row = $st->fetch();
    if ($row) {
        $row['nickname'] = (string)($row['label'] ?? '');
    }
    return $row ?: null;
}

function passkey_repo_insert(int $userId, ?int $tenantId, PublicKeyCredentialSource $src, ?string $nickname, ?string $transports): void
{
    $credentialId = $src->getPublicKeyCredentialId();
    $publicKeyJson = json_encode($src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($publicKeyJson === false) {
        throw new RuntimeException('Failed to encode passkey source.');
    }
    $aaguid = (string)$src->getAaguid();
    $signCount = (int)$src->getCounter();
    $label = $nickname !== null ? trim($nickname) : null;
    if ($label === '') {
        $label = null;
    }
    $st = db()->prepare('INSERT INTO webauthn_credential (credential_id, user_id, public_key, sign_count, transports, aaguid, label, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE public_key = VALUES(public_key), sign_count = VALUES(sign_count), transports = VALUES(transports), aaguid = VALUES(aaguid), label = VALUES(label)');
    $st->execute([$credentialId, $userId, $publicKeyJson, $signCount, $transports, $aaguid !== '' ? $aaguid : null, $label]);
}

function passkey_repo_update_on_use(string $credentialIdBinary, int $newSignCount): void
{
    $st = db()->prepare('UPDATE webauthn_credential SET sign_count = ?, last_used_at = NOW() WHERE credential_id = ? LIMIT 1');
    $st->execute([$newSignCount, $credentialIdBinary]);
}
