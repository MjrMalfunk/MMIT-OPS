<?php
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/field_ops.php';
ob_end_clean();

$options = [
    'limit' => isset($argv[1]) ? max(1, min(100, (int)$argv[1])) : 25,
];

$result = field_ops_import_fieldnation_mailbox($options);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit(!empty($result['ok']) ? 0 : 1);
