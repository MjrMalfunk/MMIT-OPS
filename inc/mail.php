<?php
declare(strict_types=1);

function ops_mail_config_string(string $name, string $default = ''): string
{
    if (defined($name)) {
        return trim((string) constant($name));
    }
    $env = getenv($name);
    if ($env !== false) {
        return trim((string) $env);
    }
    return $default;
}

function ops_mail_config_int(string $name, int $default): int
{
    if (defined($name)) {
        return (int) constant($name);
    }
    $env = getenv($name);
    if ($env !== false && $env !== '') {
        return (int) $env;
    }
    return $default;
}

function ops_mail_config_bool(string $name, bool $default = false): bool
{
    if (defined($name)) {
        return (bool) constant($name);
    }
    $env = getenv($name);
    if ($env === false) {
        return $default;
    }
    $value = strtolower(trim((string) $env));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}


function ops_mail_log(string $message): void
{
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
    $target = ops_mail_config_string('MAIL_LOG_FILE', '');
    if ($target !== '') {
        @file_put_contents($target, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        return;
    }
    error_log('[ops-mail] ' . $message);
}

function ops_mail_debug(string $message): void
{
    if (ops_mail_config_bool('MAIL_DEBUG', false)) {
        ops_mail_log($message);
    }
}

function ops_mail_allow_native_fallback(): bool
{
    return ops_mail_config_bool('MAIL_ALLOW_NATIVE_FALLBACK', false);
}

function ops_mail_graph_save_to_sent_items(): bool
{
    return ops_mail_config_bool('MAIL_GRAPH_SAVE_TO_SENT_ITEMS', true);
}

function ops_mail_sandbox_enabled(): bool
{
    return ops_mail_config_bool('MAIL_SANDBOX_ENABLED', false);
}

function ops_mail_sandbox_to(): string
{
    return ops_mail_config_string('MAIL_SANDBOX_TO', '');
}

function ops_mail_primary_transport(): string
{
    return strtolower(ops_mail_config_string('MAIL_TRANSPORT_PRIMARY', 'graph'));
}

function ops_mail_fallback_transport(): string
{
    return strtolower(ops_mail_config_string('MAIL_TRANSPORT_FALLBACK', 'smtp'));
}

function ops_mail_sender_profile(string $channel = 'billing'): array
{
    $channel = strtolower(trim($channel));
    $aliases = [
        'invoice' => 'billing',
        'receipt' => 'billing',
        'contract' => 'billing',
        'billing_portal' => 'billing',
        'ticket' => 'support',
        'helpdesk' => 'support',
        'portal' => 'noreply',
        'auth' => 'noreply',
        'login' => 'noreply',
    ];
    if (isset($aliases[$channel])) {
        $channel = $aliases[$channel];
    }

    $profiles = [
        'billing' => [
            'name' => ops_mail_config_string('MAIL_SENDER_BILLING_NAME', 'Midwest Managed IT Billing'),
            'email' => ops_mail_config_string('MAIL_SENDER_BILLING_EMAIL', 'billing@midwestmanagedit.com'),
            'reply_to_name' => ops_mail_config_string('MAIL_SENDER_BILLING_REPLY_TO_NAME', ops_mail_config_string('MAIL_SENDER_BILLING_NAME', 'Midwest Managed IT Billing')),
            'reply_to_email' => ops_mail_config_string('MAIL_SENDER_BILLING_REPLY_TO_EMAIL', ops_mail_config_string('MAIL_SENDER_BILLING_EMAIL', 'billing@midwestmanagedit.com')),
        ],
        'support' => [
            'name' => ops_mail_config_string('MAIL_SENDER_SUPPORT_NAME', 'Midwest Managed IT Support'),
            'email' => ops_mail_config_string('MAIL_SENDER_SUPPORT_EMAIL', 'support@midwestmanagedit.com'),
            'reply_to_name' => ops_mail_config_string('MAIL_SENDER_SUPPORT_REPLY_TO_NAME', ops_mail_config_string('MAIL_SENDER_SUPPORT_NAME', 'Midwest Managed IT Support')),
            'reply_to_email' => ops_mail_config_string('MAIL_SENDER_SUPPORT_REPLY_TO_EMAIL', ops_mail_config_string('MAIL_SENDER_SUPPORT_EMAIL', 'support@midwestmanagedit.com')),
        ],
        'noreply' => [
            'name' => ops_mail_config_string('MAIL_SENDER_NOREPLY_NAME', 'Midwest Managed IT'),
            'email' => ops_mail_config_string('MAIL_SENDER_NOREPLY_EMAIL', 'noreply@midwestmanagedit.com'),
            'reply_to_name' => ops_mail_config_string('MAIL_SENDER_NOREPLY_REPLY_TO_NAME', ops_mail_config_string('MAIL_SENDER_SUPPORT_NAME', 'Midwest Managed IT Support')),
            'reply_to_email' => ops_mail_config_string('MAIL_SENDER_NOREPLY_REPLY_TO_EMAIL', ops_mail_config_string('MAIL_SENDER_SUPPORT_EMAIL', 'support@midwestmanagedit.com')),
        ],
        'default' => [
            'name' => ops_mail_config_string('MAIL_SENDER_DEFAULT_NAME', 'Midwest Managed IT'),
            'email' => ops_mail_config_string('MAIL_SENDER_DEFAULT_EMAIL', 'billing@midwestmanagedit.com'),
            'reply_to_name' => ops_mail_config_string('MAIL_SENDER_DEFAULT_REPLY_TO_NAME', ops_mail_config_string('MAIL_SENDER_DEFAULT_NAME', 'Midwest Managed IT')),
            'reply_to_email' => ops_mail_config_string('MAIL_SENDER_DEFAULT_REPLY_TO_EMAIL', ops_mail_config_string('MAIL_SENDER_DEFAULT_EMAIL', 'billing@midwestmanagedit.com')),
        ],
    ];

    if (!isset($profiles[$channel])):
        $channel = 'default';
    endif;

    $profile = $profiles[$channel];
    if ($profile['email'] === '') {
        $profile = $profiles['default'];
    }
    if ($profile['reply_to_email'] === '') {
        $profile['reply_to_email'] = $profile['email'];
    }
    if ($profile['reply_to_name'] === '') {
        $profile['reply_to_name'] = $profile['name'];
    }
    $profile['channel'] = $channel;
    return $profile;
}

function ops_mail_graph_enabled(): bool
{
    return ops_mail_config_string('MAIL_GRAPH_TENANT_ID') !== ''
        && ops_mail_config_string('MAIL_GRAPH_CLIENT_ID') !== ''
        && ops_mail_config_string('MAIL_GRAPH_CLIENT_SECRET') !== '';
}

function ops_mail_smtp_credentials(string $channel = 'billing'): array
{
    $channel = strtoupper(trim($channel));
    $username = ops_mail_config_string('MAIL_SMTP_' . $channel . '_USERNAME');
    $password = ops_mail_config_string('MAIL_SMTP_' . $channel . '_PASSWORD');
    if ($username === '') {
        $username = ops_mail_config_string('MAIL_SMTP_USERNAME');
    }
    if ($password === '') {
        $password = ops_mail_config_string('MAIL_SMTP_PASSWORD');
    }
    return ['username' => $username, 'password' => $password];
}

function ops_mail_smtp_enabled(string $channel = 'billing'): bool
{
    $creds = ops_mail_smtp_credentials($channel);
    return ops_mail_config_string('MAIL_SMTP_HOST', 'smtp.office365.com') !== ''
        && $creds['username'] !== ''
        && $creds['password'] !== '';
}

function ops_mail_sanitize_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], ' ', $value));
}

function ops_mail_validate_message(array $message): array
{
    $to = trim((string) ($message['to'] ?? ''));
    $subject = ops_mail_sanitize_header_value((string) ($message['subject'] ?? ''));
    $textBody = (string) ($message['text_body'] ?? '');
    $htmlBody = (string) ($message['html_body'] ?? '');
    $senderChannel = strtolower(trim((string) ($message['sender_channel'] ?? 'billing')));
    $profile = ops_mail_sender_profile($senderChannel);

    $errors = [];
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Recipient email is invalid.';
    }
    if ($subject === '') {
        $errors[] = 'Email subject is required.';
    }
    if (trim($textBody) === '' and trim(strip_tags($htmlBody)) === '') {
        $errors[] = 'Email body is required.';
    }
    if ($profile['email'] === '' || !filter_var($profile['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Sender mailbox is not configured.';
    }
    if ($profile['reply_to_email'] !== '' && !filter_var($profile['reply_to_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Reply-to mailbox is invalid.';
    }

    return [
        'ok' => $errors === [],
        'errors' => $errors,
        'to' => $to,
        'subject' => $subject,
        'text_body' => $textBody,
        'html_body' => $htmlBody,
        'attachments' => is_array($message['attachments'] ?? null) ? $message['attachments'] : [],
        'profile' => $profile,
    ];
}

function ops_mail_apply_sandbox(array $prepared): array
{
    $prepared['original_to'] = $prepared['to'];
    $prepared['effective_to'] = $prepared['to'];
    $prepared['sandbox_note'] = '';

    if (ops_mail_sandbox_enabled()) {
        $sandboxTo = ops_mail_sandbox_to();
        if ($sandboxTo === '' || !filter_var($sandboxTo, FILTER_VALIDATE_EMAIL)) {
            $prepared['sandbox_error'] = 'MAIL_SANDBOX_ENABLED is true but MAIL_SANDBOX_TO is missing or invalid. Email was not sent.';
            return $prepared;
        }

        $prepared['effective_to'] = $sandboxTo;
        $prepared['sandbox_note'] = 'STAGING EMAIL - original recipient was ' . $prepared['original_to'];
        if (!preg_match('/^\[TEST\]/', $prepared['subject'])) {
            $prepared['subject'] = '[TEST] ' . $prepared['subject'];
        }

        if (stripos($prepared['subject'], 'STAGING EMAIL - original recipient was') === false) {
            $prepared['subject'] .= ' | ' . $prepared['sandbox_note'];
        }

        if (trim((string) $prepared['text_body']) !== '') {
            $prepared['text_body'] = $prepared['sandbox_note'] . "\n\n" . $prepared['text_body'];
        }
        if (trim((string) $prepared['html_body']) !== '') {
            $safeNote = htmlspecialchars($prepared['sandbox_note'], ENT_QUOTES, 'UTF-8');
            $prepared['html_body'] = '<p><strong>' . $safeNote . '</strong></p>' . $prepared['html_body'];
        }
    }

    return $prepared;
}

function ops_mail_http_post_json(string $url, array $headers, string $body): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cURL is not available on this host.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => ops_mail_config_int('MAIL_HTTP_TIMEOUT', 20),
    ]);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error !== '' ? $error : null,
    ];
}

function ops_mail_graph_access_token(): array
{
    static $cached = null;

    if (is_array($cached) && (int) ($cached['expires_at'] ?? 0) > (time() + 60)) {
        return ['ok' => true, 'token' => (string) $cached['token']];
    }

    $tenantId = ops_mail_config_string('MAIL_GRAPH_TENANT_ID');
    $clientId = ops_mail_config_string('MAIL_GRAPH_CLIENT_ID');
    $clientSecret = ops_mail_config_string('MAIL_GRAPH_CLIENT_SECRET');

    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        return ['ok' => false, 'error' => 'Microsoft Graph mail credentials are incomplete.'];
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL is not available on this host.'];
    }

    ops_mail_debug('Requesting Microsoft Graph access token for tenant ' . $tenantId . ' and client ' . $clientId . '.');
    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
        CURLOPT_TIMEOUT => ops_mail_config_int('MAIL_HTTP_TIMEOUT', 20),
    ]);
    $responseBody = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        ops_mail_debug('Microsoft Graph token request cURL error: ' . $error);
        return ['ok' => false, 'error' => $error];
    }
    $data = json_decode(is_string($responseBody) ? $responseBody : '', true);
    if ($status < 200 || $status >= 300 || !is_array($data) || empty($data['access_token'])) {
        $message = is_array($data) ? (string) ($data['error_description'] ?? $data['error'] ?? 'Unknown token error.') : 'Unknown token error.';
        ops_mail_debug('Microsoft Graph token request failed with status ' . $status . '. Body: ' . substr((string) $responseBody, 0, 1200));
        return ['ok' => false, 'error' => $message];
    }

    ops_mail_debug('Microsoft Graph access token acquired successfully.');

    $cached = [
        'token' => (string) $data['access_token'],
        'expires_at' => time() + max(300, (int) ($data['expires_in'] ?? 3600)),
    ];

    return ['ok' => true, 'token' => $cached['token']];
}

function ops_mail_send_via_graph(array $prepared): array
{
    $token = ops_mail_graph_access_token();
    if (empty($token['ok'])) {
        return ['ok' => false, 'transport' => 'graph', 'error' => (string) ($token['error'] ?? 'Unable to obtain Microsoft Graph token.')];
    }

    $profile = $prepared['profile'];
    $replyTo = [];
    if ($profile['reply_to_email'] !== '') {
        $replyTo[] = ['emailAddress' => ['address' => $profile['reply_to_email'], 'name' => $profile['reply_to_name']]];
    }

    $attachments = [];
    foreach ($prepared['attachments'] as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $filename = ops_mail_sanitize_header_value((string) ($attachment['filename'] ?? 'attachment.bin'));
        $contentType = trim((string) ($attachment['content_type'] ?? 'application/octet-stream'));
        $contentBytes = $attachment['content_bytes'] ?? null;
        if (!is_string($contentBytes) || $contentBytes == '') {
            continue;
        }
        $attachments[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $filename !== '' ? $filename : 'attachment.bin',
            'contentType' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'contentBytes' => base64_encode($contentBytes),
        ];
    }

    $bodyType = trim($prepared['html_body']) !== '' ? 'HTML' : 'Text';
    $bodyContent = $bodyType === 'HTML' ? $prepared['html_body'] : $prepared['text_body'];

    $payload = [
        'message' => [
            'subject' => $prepared['subject'],
            'body' => ['contentType' => $bodyType, 'content' => $bodyContent],
            'from' => [
                'emailAddress' => ['address' => $profile['email']],
            ],
            'toRecipients' => [['emailAddress' => ['address' => $prepared['effective_to']]]],
        ],
        'saveToSentItems' => ops_mail_graph_save_to_sent_items(),
    ];
    if ($replyTo !== []) {
        $payload['message']['replyTo'] = $replyTo;
    }
    if ($attachments !== []) {
        $payload['message']['attachments'] = $attachments;
    }

    ops_mail_debug('Sending Microsoft Graph mail as ' . $profile['email'] . ' to ' . $prepared['effective_to'] . '.');
    $response = ops_mail_http_post_json(
        'https://graph.microsoft.com/v1.0/users/' . rawurlencode($profile['email']) . '/sendMail',
        [
            'Authorization: Bearer ' . $token['token'],
            'Content-Type: application/json',
        ],
        (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    if (!$response['ok']) {
        $graphError = (string) ($response['error'] ?? '');
        $body = trim((string) ($response['body'] ?? ''));
        ops_mail_debug('Microsoft Graph sendMail failed with status ' . (string) ($response['status'] ?? 0) . '. Body: ' . substr($body, 0, 1200));
        if ($body !== '') {
            $json = json_decode($body, true);
            if (is_array($json)) {
                $graphError = (string) ($json['error']['message'] ?? $json['error_description'] ?? $graphError);
            }
        }
        return ['ok' => false, 'transport' => 'graph', 'error' => $graphError !== '' ? $graphError : 'Microsoft Graph sendMail failed.'];
    }

    ops_mail_debug('Microsoft Graph sendMail accepted the message for processing with status ' . (string) ($response['status'] ?? 0) . '.');
    return ['ok' => true, 'transport' => 'graph'];
}

function ops_mail_rfc822_headers(array $prepared, string $contentTypeHeader): array
{
    $profile = $prepared['profile'];
    $headers = [
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'From: ' . ops_mail_sanitize_header_value($profile['name']) . ' <' . $profile['email'] . '>',
        'To: ' . $prepared['effective_to'],
        'Subject: ' . $prepared['subject'],
        'MIME-Version: 1.0',
        'X-Mailer: MITT Ops',
        $contentTypeHeader,
    ];

    if ($profile['reply_to_email'] !== '') {
        $headers[] = 'Reply-To: ' . ops_mail_sanitize_header_value($profile['reply_to_name']) . ' <' . $profile['reply_to_email'] . '>';
    }

    return $headers;
}

function ops_mail_build_rfc822(array $prepared): array
{
    $textBody = str_replace(["\r\n", "\r"], "\n", (string) $prepared['text_body']);
    $htmlBody = (string) $prepared['html_body'];
    $hasHtml = trim($htmlBody) !== '';
    $hasAttachments = !empty($prepared['attachments']);

    if (!$hasHtml && !$hasAttachments) {
        $headers = ops_mail_rfc822_headers($prepared, 'Content-Type: text/plain; charset=UTF-8');
        $body = $textBody;
        return ['headers' => $headers, 'body' => $body, 'raw' => implode("\r\n", $headers) . "\r\n\r\n" . $body];
    }

    if ($hasHtml && !$hasAttachments) {
        $alt = 'ops_alt_' . bin2hex(random_bytes(8));
        $headers = ops_mail_rfc822_headers($prepared, 'Content-Type: multipart/alternative; boundary="' . $alt . '"');
        $body = '--' . $alt . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $textBody . "\r\n\r\n";
        $body .= '--' . $alt . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $htmlBody . "\r\n\r\n";
        $body .= '--' . $alt . "--\r\n";
        return ['headers' => $headers, 'body' => $body, 'raw' => implode("\r\n", $headers) . "\r\n\r\n" . $body];
    }

    $mixed = 'ops_mix_' . bin2hex(random_bytes(8));
    $headers = ops_mail_rfc822_headers($prepared, 'Content-Type: multipart/mixed; boundary="' . $mixed . '"');
    $body = '';

    if ($hasHtml) {
        $alt = 'ops_alt_' . bin2hex(random_bytes(8));
        $body .= '--' . $mixed . "\r\n";
        $body .= 'Content-Type: multipart/alternative; boundary="' . $alt . "\"\r\n\r\n";
        $body .= '--' . $alt . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $textBody . "\r\n\r\n";
        $body .= '--' . $alt . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $htmlBody . "\r\n\r\n";
        $body .= '--' . $alt . "--\r\n";
    } else {
        $body .= '--' . $mixed . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . $textBody . "\r\n\r\n";
    }

    foreach ($prepared['attachments'] as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        $filename = ops_mail_sanitize_header_value((string) ($attachment['filename'] ?? 'attachment.bin'));
        $contentType = trim((string) ($attachment['content_type'] ?? 'application/octet-stream'));
        $contentBytes = $attachment['content_bytes'] ?? null;
        if (!is_string($contentBytes) || $contentBytes == '') {
            continue;
        }
        $body .= '--' . $mixed . "\r\n";
        $body .= 'Content-Type: ' . ($contentType !== '' ? $contentType : 'application/octet-stream') . '; name="' . $filename . "\"\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n";
        $body .= 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n";
        $body .= chunk_split(base64_encode($contentBytes)) . "\r\n";
    }

    $body .= '--' . $mixed . "--\r\n";
    return ['headers' => $headers, 'body' => $body, 'raw' => implode("\r\n", $headers) . "\r\n\r\n" . $body];
}

function ops_mail_smtp_expect($socket, array $codes): array
{
    $response = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (preg_match('/^\d{3} /', $line)) {
            break;
        }
    }
    $status = (int) substr($response, 0, 3);
    return ['ok' => in_array($status, $codes, true), 'status' => $status, 'response' => trim($response)];
}

function ops_mail_smtp_command($socket, string $command, array $codes): array
{
    fwrite($socket, $command . "\r\n");
    return ops_mail_smtp_expect($socket, $codes);
}

function ops_mail_send_via_smtp(array $prepared): array
{
    $profile = $prepared['profile'];
    $creds = ops_mail_smtp_credentials($profile['channel']);
    $host = ops_mail_config_string('MAIL_SMTP_HOST', 'smtp.office365.com');
    $port = ops_mail_config_int('MAIL_SMTP_PORT', 587);
    $timeout = ops_mail_config_int('MAIL_SMTP_TIMEOUT', 20);
    $encryption = strtolower(ops_mail_config_string('MAIL_SMTP_ENCRYPTION', 'tls'));
    $ehlo = ops_mail_config_string('MAIL_SMTP_EHLO', parse_url((string) BASE_URL, PHP_URL_HOST) ?: 'localhost');

    if ($host == '' || $creds['username'] == '' || $creds['password'] == '') {
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP fallback is not fully configured.'];
    }

    $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP connect failed: ' . $errstr . ' (' . $errno . ')'];
    }
    stream_set_timeout($socket, $timeout);

    $resp = ops_mail_smtp_expect($socket, [220]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP banner error: ' . $resp['response']];
    }
    $resp = ops_mail_smtp_command($socket, 'EHLO ' . $ehlo, [250]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP EHLO failed: ' . $resp['response']];
    }

    if ($encryption === 'tls') {
        $resp = ops_mail_smtp_command($socket, 'STARTTLS', [220]);
        if (!$resp['ok']) {
            fclose($socket);
            return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP STARTTLS failed: ' . $resp['response']];
        }
        $cryptoOk = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if ($cryptoOk !== true) {
            fclose($socket);
            return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP TLS negotiation failed.'];
        }
        $resp = ops_mail_smtp_command($socket, 'EHLO ' . $ehlo, [250]);
        if (!$resp['ok']) {
            fclose($socket);
            return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP EHLO after STARTTLS failed: ' . $resp['response']];
        }
    }

    $resp = ops_mail_smtp_command($socket, 'AUTH LOGIN', [334]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP AUTH LOGIN failed: ' . $resp['response']];
    }
    $resp = ops_mail_smtp_command($socket, base64_encode($creds['username']), [334]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP username rejected: ' . $resp['response']];
    }
    $resp = ops_mail_smtp_command($socket, base64_encode($creds['password']), [235]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP password rejected: ' . $resp['response']];
    }

    $resp = ops_mail_smtp_command($socket, 'MAIL FROM:<' . $profile['email'] . '>', [250]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP MAIL FROM failed: ' . $resp['response']];
    }
    $resp = ops_mail_smtp_command($socket, 'RCPT TO:<' . $prepared['effective_to'] . '>', [250, 251]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP RCPT TO failed: ' . $resp['response']];
    }
    $resp = ops_mail_smtp_command($socket, 'DATA', [354]);
    if (!$resp['ok']) {
        fclose($socket);
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP DATA failed: ' . $resp['response']];
    }

    $rfc822 = ops_mail_build_rfc822($prepared);
    $data = str_replace("\r\n.", "\r\n..", $rfc822['raw']);
    fwrite($socket, $data . "\r\n.\r\n");
    $resp = ops_mail_smtp_expect($socket, [250]);
    @ops_mail_smtp_command($socket, 'QUIT', [221]);
    fclose($socket);

    if (!$resp['ok']) {
        return ['ok' => false, 'transport' => 'smtp', 'error' => 'SMTP delivery failed: ' . $resp['response']];
    }
    return ['ok' => true, 'transport' => 'smtp'];
}

function ops_mail_send_via_native_mail(array $prepared): array
{
    $rfc822 = ops_mail_build_rfc822($prepared);
    $ok = @mail($prepared['effective_to'], $prepared['subject'], $rfc822['body'], implode("\r\n", $rfc822['headers']));
    return ['ok' => $ok, 'transport' => 'native', 'error' => $ok ? null : 'PHP mail() returned false.'];
}

function ops_mail_send(array $message): array
{
    $prepared = ops_mail_validate_message($message);
    if (empty($prepared['ok'])) {
        return ['ok' => false, 'errors' => $prepared['errors'], 'error' => implode(' ', $prepared['errors'])];
    }

    $prepared = ops_mail_apply_sandbox($prepared);
    if (!empty($prepared['sandbox_error'])) {
        $msg = (string) $prepared['sandbox_error'];
        ops_mail_log($msg);
        return [
            'ok' => false,
            'errors' => [$msg],
            'error' => $msg,
            'to' => $prepared['effective_to'],
            'original_to' => $prepared['original_to'],
            'subject' => $prepared['subject'],
            'sender_email' => $prepared['profile']['email'],
            'sender_channel' => $prepared['profile']['channel'],
        ];
    }
    $transports = [];
    foreach ([ops_mail_primary_transport(), ops_mail_fallback_transport()] as $transport) {
        $transport = strtolower(trim((string) $transport));
        if ($transport === '' || !in_array($transport, ['graph', 'smtp', 'native'], true)) {
            continue;
        }
        if (!in_array($transport, $transports, true)) {
            $transports[] = $transport;
        }
    }
    if (ops_mail_allow_native_fallback() && !in_array('native', $transports, true)) {
        $transports[] = 'native';
    }

    $lastError = 'No enabled mail transport is configured.';
    foreach ($transports as $transport) {
        if ($transport === 'graph') {
            if (!ops_mail_graph_enabled()) {
                $lastError = 'Microsoft Graph mail is not configured yet.';
                continue;
            }
            $result = ops_mail_send_via_graph($prepared);
        } elseif ($transport === 'smtp') {
            if (!ops_mail_smtp_enabled($prepared['profile']['channel'])) {
                $lastError = 'SMTP fallback is not configured yet.';
                continue;
            }
            $result = ops_mail_send_via_smtp($prepared);
        } else {
            $result = ops_mail_send_via_native_mail($prepared);
        }

        if (!empty($result['ok'])) {
            $result['to'] = $prepared['effective_to'];
            $result['original_to'] = $prepared['original_to'];
            $result['subject'] = $prepared['subject'];
            $result['sender_email'] = $prepared['profile']['email'];
            $result['sender_channel'] = $prepared['profile']['channel'];
            return $result;
        }

        $lastError = (string) ($result['error'] ?? $lastError);
        ops_mail_debug('ops_mail_send ' . $transport . ' failed: ' . $lastError);
    }

    return [
        'ok' => false,
        'errors' => [$lastError],
        'error' => $lastError,
        'to' => $prepared['effective_to'],
        'original_to' => $prepared['original_to'],
        'subject' => $prepared['subject'],
        'sender_email' => $prepared['profile']['email'],
        'sender_channel' => $prepared['profile']['channel'],
    ];
}
