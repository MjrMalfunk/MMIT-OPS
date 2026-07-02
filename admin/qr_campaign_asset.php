<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/qr_campaigns.php';

require_login();

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$campaign = $campaignId > 0 ? qr_campaigns_find($campaignId) : null;

if (!$campaign) {
    http_response_code(404);
    echo 'QR campaign not found.';
    exit;
}

$code = qr_campaigns_clean_code((string)$campaign['code']);
$path = qr_campaigns_svg_asset_path($code);

if (!is_file($path)) {
    $result = qr_campaigns_generate_svg_asset($campaignId);

    if (empty($result['ok']) || !is_file($path)) {
        http_response_code(500);
        echo htmlspecialchars((string)($result['error'] ?? 'QR SVG could not be generated.'), ENT_QUOTES, 'UTF-8');
        exit;
    }
}

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Content-Disposition: inline; filename="' . $code . '.svg"');
header('Cache-Control: private, max-age=300');
readfile($path);
exit;
