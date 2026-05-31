<?php
declare(strict_types=1);

// Local HTTP smoke checks for the public Stripe webhook endpoint. These avoid a
// database connection by exercising guarded paths that return before webhook
// event logging/reconciliation starts.

$repoRoot = dirname(__DIR__);
$secret = 'whsec_endpoint_smoke';
$configFile = tempnam(sys_get_temp_dir(), 'ops-stripe-webhook-config-');
if ($configFile === false) {
    fwrite(STDERR, "FAIL: Unable to create temporary config file.\n");
    exit(1);
}
$config = <<<'PHP_CONFIG'
<?php
declare(strict_types=1);
define('APP_ENV', 'staging');
define('APP_NAME', 'MITT Ops Smoke');
define('BASE_URL', 'https://ops-test.example.test');
define('SESSION_NAME', 'mittops_smoke');
define('SESSION_TTL_SECONDS', 600);
define('SESSION_ABS_TTL_SECONDS', 43200);
define('SESSION_REGEN_SECONDS', 900);
define('SESSION_BIND_UA_IP', false);
define('SESSION_COOKIE_DOMAIN', '');
define('COOKIE_SECURE', false);
define('COOKIE_HTTPONLY', true);
define('COOKIE_SAMESITE', 'Lax');
define('APP_TIMEZONE', 'UTC');
define('DB_HOST', 'localhost');
define('DB_NAME', 'unused');
define('DB_USER', 'unused');
define('DB_PASS', 'unused');
define('DB_CHARSET', 'utf8mb4');
define('MFA_TOTP_ISSUER', 'MITT Ops Smoke');
define('MFA_TOTP_PERIOD', 30);
define('MFA_TOTP_DIGITS', 6);
define('ACCOUNTING_ENABLED', true);
define('APP_ENC_KEY_B64', 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
define('PAYMENT_GATEWAY_DEFAULT', 'STRIPE');
define('PAYMENT_DEFAULT_DEPOSIT_ACCOUNT_CODE', '1000');
define('PAYMENT_DEFAULT_FEE_EXPENSE_ACCOUNT_CODE', '5070');
define('STRIPE_SECRET_KEY', 'sk_test_endpoint_smoke');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_endpoint_smoke');
define('STRIPE_WEBHOOK_SECRET', '');
define('STRIPE_TEST_WEBHOOK_SECRET', 'whsec_endpoint_smoke');
define('STRIPE_LIVE_WEBHOOK_SECRET', '');
define('STRIPE_CHECKOUT_SUCCESS_URL', BASE_URL . '/payments/return.php?gateway=stripe&session_id={CHECKOUT_SESSION_ID}');
define('STRIPE_CHECKOUT_CANCEL_URL', BASE_URL . '/payments/pay.php?cancelled=1');
PHP_CONFIG;
file_put_contents($configFile, $config);

$serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!is_resource($serverSocket)) {
    fwrite(STDERR, "FAIL: Unable to allocate local server port: {$errstr}\n");
    @unlink($configFile);
    exit(1);
}
$socketName = stream_socket_get_name($serverSocket, false);
fclose($serverSocket);
$port = (int)substr(strrchr((string)$socketName, ':'), 1);

$cmd = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $repoRoot];
$descriptorSpec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$env = array_merge($_ENV, ['OPS_CONFIG_FILE' => $configFile]);
$proc = proc_open($cmd, $descriptorSpec, $pipes, $repoRoot, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "FAIL: Unable to start PHP built-in server.\n");
    @unlink($configFile);
    exit(1);
}
foreach ($pipes as $pipe) {
    stream_set_blocking($pipe, false);
}

$cleanup = static function () use (&$proc, &$pipes, $configFile): void {
    if (is_resource($proc)) {
        proc_terminate($proc);
        proc_close($proc);
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    @unlink($configFile);
};

$request = static function (string $method, string $path, string $body = '', array $headers = []) use ($port): array {
    $fp = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 5);
    if (!is_resource($fp)) {
        throw new RuntimeException('HTTP connection failed: ' . $errstr);
    }
    $headerLines = [
        $method . ' ' . $path . ' HTTP/1.1',
        'Host: 127.0.0.1:' . $port,
        'Connection: close',
    ];
    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }
    if ($body !== '') {
        $headerLines[] = 'Content-Type: application/json';
        $headerLines[] = 'Content-Length: ' . strlen($body);
    }
    fwrite($fp, implode("\r\n", $headerLines) . "\r\n\r\n" . $body);
    $response = stream_get_contents($fp);
    fclose($fp);
    $parts = explode("\r\n\r\n", (string)$response, 2);
    $rawHeaders = $parts[0] ?? '';
    $responseBody = $parts[1] ?? '';
    preg_match('/^HTTP\/\d\.\d\s+(\d+)/', $rawHeaders, $matches);
    return ['status' => (int)($matches[1] ?? 0), 'headers' => $rawHeaders, 'body' => $responseBody];
};

$assert = static function (bool $condition, string $message) use (&$cleanup): void {
    if (!$condition) {
        $cleanup();
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    echo "PASS: {$message}\n";
};

try {
    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $fp = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
        if (is_resource($fp)) {
            fclose($fp);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    $assert($ready, 'PHP built-in server starts for Stripe webhook endpoint smoke checks');

    $get = $request('GET', '/payments/webhook_stripe.php');
    $assert($get['status'] === 405, 'webhook GET returns HTTP 405');
    $assert(stripos($get['headers'], 'Allow: POST') !== false, 'webhook GET advertises Allow: POST');
    $assert(json_decode($get['body'], true) === ['ok' => false, 'error' => 'POST required.'], 'webhook GET returns controlled JSON body');

    $unsigned = $request('POST', '/payments/webhook_stripe.php', '{}', ['X-Forwarded-Proto' => 'https']);
    $assert($unsigned['status'] === 400, 'unsigned webhook POST returns HTTP 400 instead of 500');
    $assert((json_decode($unsigned['body'], true)['error'] ?? '') === 'Missing Stripe signature.', 'unsigned webhook POST returns controlled missing signature JSON');

    $invalidSig = $request('POST', '/payments/webhook_stripe.php', '{}', [
        'X-Forwarded-Proto' => 'https',
        'Stripe-Signature' => 't=' . time() . ',v1=not-a-valid-signature',
    ]);
    $assert($invalidSig['status'] === 400, 'invalid signature webhook POST returns HTTP 400 instead of 500');
    $assert((json_decode($invalidSig['body'], true)['error'] ?? '') === 'Invalid Stripe signature.', 'invalid signature webhook POST returns controlled JSON');

    $invalidJson = '{';
    $timestamp = (string)time();
    $invalidJsonSig = hash_hmac('sha256', $timestamp . '.' . $invalidJson, $secret);
    $badJson = $request('POST', '/payments/webhook_stripe.php', $invalidJson, [
        'X-Forwarded-Proto' => 'https',
        'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $invalidJsonSig,
    ]);
    $assert($badJson['status'] === 400, 'invalid JSON webhook POST returns HTTP 400 instead of 500');
    $assert((json_decode($badJson['body'], true)['error'] ?? '') === 'Invalid JSON payload.', 'invalid JSON webhook POST returns controlled JSON');

    $signedPayload = json_encode(['livemode' => false, 'type' => 'payment_intent.succeeded'], JSON_UNESCAPED_SLASHES);
    $timestamp = (string)time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $signedPayload, $secret);
    $signed = $request('POST', '/payments/webhook_stripe.php', (string)$signedPayload, [
        'X-Forwarded-Proto' => 'https',
        'Stripe-Signature' => 't=' . $timestamp . ',v1=' . $signature,
    ]);
    $assert($signed['status'] === 400, 'valid signed test payload passes signature verification and reaches event validation');
    $assert((json_decode($signed['body'], true)['error'] ?? '') === 'Missing event id.', 'valid signed test payload is not rejected as an invalid signature');
} catch (Throwable $e) {
    $cleanup();
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
}

$cleanup();
echo "Stripe webhook endpoint smoke check passed.\n";
