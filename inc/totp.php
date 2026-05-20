<?php
declare(strict_types=1);

// Minimal TOTP implementation (RFC 6238 style, SHA1)

function base32_decode_str(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $b32));
    $bits = '';
    for ($i=0; $i<strlen($b32); $i++) {
        $v = strpos($alphabet, $b32[$i]);
        if ($v === false) continue;
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i=0; $i+8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function totp_generate_secret(int $bytes=20): string {
    $raw = random_bytes($bytes);
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i=0; $i<strlen($raw); $i++) {
        $bits .= str_pad(decbin(ord($raw[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i=0; $i < strlen($bits); $i += 5) {
        $chunk = substr($bits, $i, 5);
        if (strlen($chunk) < 5) $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return rtrim($out, '=');
}

function totp_code(string $secret_b32, ?int $time=null): string {
    $time = $time ?? time();
    $counter = intdiv($time, MFA_TOTP_PERIOD);

    $key = base32_decode_str($secret_b32);
    // 8-byte big-endian counter
    $bin_counter = pack('N*', 0) . pack('N*', $counter);

    $hash = hash_hmac('sha1', $bin_counter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $part = substr($hash, $offset, 4);
    $val = unpack('N', $part)[1] & 0x7FFFFFFF;
    $mod = 10 ** MFA_TOTP_DIGITS;
    return str_pad((string)($val % $mod), MFA_TOTP_DIGITS, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret_b32, string $code, int $window=1): bool {
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6,8}$/', $code)) return false;

    $now = time();
    for ($w = -$window; $w <= $window; $w++) {
        $t = $now + ($w * MFA_TOTP_PERIOD);
        $expected = totp_code($secret_b32, $t);
        if (hash_equals($expected, $code)) return true;
    }
    return false;
}

function totp_otpauth_uri(string $email, string $secret_b32): string {
    $label = rawurlencode(MFA_TOTP_ISSUER . ':' . $email);
    $issuer = rawurlencode(MFA_TOTP_ISSUER);
    return "otpauth://totp/{$label}?secret={$secret_b32}&issuer={$issuer}&period=" . MFA_TOTP_PERIOD . "&digits=" . MFA_TOTP_DIGITS;
}
