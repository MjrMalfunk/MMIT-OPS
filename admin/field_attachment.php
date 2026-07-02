<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();

$attachmentId = (int)($_GET['id'] ?? 0);
$attachment = $attachmentId > 0 ? field_ops_find_attachment($attachmentId) : null;

if (!$attachment) {
    http_response_code(404);
    echo 'Attachment not found.';
    exit;
}

$path = (string)$attachment['storage_path'];

if ($path === '' || !is_file($path)) {
    http_response_code(404);
    echo 'Stored file not found.';
    exit;
}

$mime = trim((string)($attachment['mime_type'] ?? '')) ?: 'application/octet-stream';
$name = field_ops_safe_filename((string)($attachment['original_filename'] ?? 'attachment'));

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
header('Cache-Control: private, max-age=300');

readfile($path);
exit;
