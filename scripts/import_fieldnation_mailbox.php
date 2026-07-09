<?php
$host = trim(
    (string)(getenv('MMIT_CLI_HTTP_HOST') ?: '')
);

foreach ($argv ?? [] as $argument) {
    if (
        preg_match(
            '/^--host=(.+)$/',
            $argument,
            $match
        )
    ) {
        $host = trim($match[1]);
        break;
    }
}

$_SERVER['HTTP_HOST'] = $host !== ''
    ? $host
    : (
        $_SERVER['HTTP_HOST']
        ?? 'ops-test.midwestmanagedit.com'
    );
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

ob_start();
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/field_ops.php';
ob_end_clean();

$importLimit = 25;

foreach ($argv ?? [] as $index => $argument) {
    if (
        preg_match(
            '/^--import-limit=(\d+)$/',
            $argument,
            $match
        )
    ) {
        $importLimit = max(
            1,
            min(100, (int)$match[1])
        );

        continue;
    }

    if (
        $index === 1
        && ctype_digit((string)$argument)
    ) {
        $importLimit = max(
            1,
            min(100, (int)$argument)
        );
    }
}

$options = [
    'limit' => $importLimit,
];

$result = field_ops_import_fieldnation_mailbox($options);

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

exit(!empty($result['ok']) ? 0 : 1);
