<?php
declare(strict_types=1);
http_response_code(410);
header('Content-Type: application/json');
echo json_encode([
    'ok' => false,
    'error' => 'Legacy Authorize.Net endpoint retired. Stripe is the only supported payment gateway in this portal.',
], JSON_UNESCAPED_SLASHES);
