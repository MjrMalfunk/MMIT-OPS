<?php
declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $tok = $_POST['csrf_token'] ?? '';
    $ok = is_string($tok) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $tok);
    if (!$ok) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>Security Check</title>';
        echo '<p style="font-family:system-ui">Security check failed (CSRF). Please refresh and try again.</p>';
        echo '<p style="font-family:system-ui"><a href="javascript:history.back()">Go back</a></p>';
        exit;
    }
}

/**
 * Convenience helper for templates.
 * Usage: <?= csrf_field() ?>
 */
function csrf_field(): string {
    $t = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($t, ENT_QUOTES, 'UTF-8') . '">';
}

// Backwards-compatible alias used by some pages.
// Older code calls csrf_validate(); newer helper is csrf_check();
function csrf_validate(): void {
    csrf_check();
}

// Alias used by some newer pages (step-up actions, accounting forms)
function csrf_validate_or_die(): void {
    csrf_check();
}
