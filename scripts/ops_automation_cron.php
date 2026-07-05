<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$host = 'ops-test.midwestmanagedit.com';
$force = false;
$only = '';

foreach ($argv ?? [] as $argument) {
    if ($argument === '--force') {
        $force = true;
        continue;
    }

    if (preg_match('/^--host=(.+)$/', $argument, $match)) {
        $host = trim($match[1]);
        continue;
    }

    if (preg_match('/^--only=([a-z0-9_-]+)$/', $argument, $match)) {
        $only = $match[1];
    }
}

$_SERVER['HTTP_HOST'] = $host;
$_SERVER['HTTPS'] = 'on';
$_SERVER['REQUEST_URI'] = '/';

$lockDir = $root . '/storage/locks';

if (
    !is_dir($lockDir)
    && !mkdir($lockDir, 0775, true)
    && !is_dir($lockDir)
) {
    echo 'Unable to create OPS automation lock directory.', PHP_EOL;
    exit(1);
}

$dispatcherLockPath = $lockDir . '/ops_automation_cron.lock';
$dispatcherLock = @fopen($dispatcherLockPath, 'c+');

if (!is_resource($dispatcherLock)) {
    echo 'Unable to open OPS automation dispatcher lock.', PHP_EOL;
    exit(1);
}

if (!flock($dispatcherLock, LOCK_EX | LOCK_NB)) {
    fclose($dispatcherLock);

    echo 'OPS automation already active; skipping overlap.', PHP_EOL;
    exit(0);
}

function ops_automation_run_task(
    array $task,
    string $workingDirectory
): array {
    $name = (string)$task['name'];
    $command = (array)$task['command'];

    echo PHP_EOL;
    echo '=== ', $name, ' ===', PHP_EOL;

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        echo 'Unable to start task process.', PHP_EOL;

        return [
            'exit_code' => 1,
            'status' => 'START_FAILED',
        ];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if (trim((string)$stdout) !== '') {
        echo rtrim((string)$stdout), PHP_EOL;
    }

    if (trim((string)$stderr) !== '') {
        echo rtrim((string)$stderr), PHP_EOL;
    }

    $status = match ($exitCode) {
        0 => 'OK',
        75 => 'SKIP_LOCKED',
        124 => 'TIMEOUT',
        default => 'FAILED',
    };

    echo sprintf(
        '%s complete: %s (exit %d).',
        $name,
        $status,
        $exitCode
    ), PHP_EOL;

    return [
        'exit_code' => $exitCode,
        'status' => $status,
    ];
}

$php = PHP_BINARY ?: '/usr/local/bin/php';

$tasks = [
    [
        'key' => 'syncro-root-intake',
        'name' => 'Syncro root asset intake',
        'every_minutes' => 5,
        'command' => [
            '/bin/timeout',
            '240',
            $php,
            '-d',
            'display_errors=0',
            $root . '/scripts/syncro_root_asset_intake_cron.php',
            '--host=' . $host,
            '--apply',
        ],
    ],
    [
        'key' => 'fn-radar',
        'name' => 'Field Ops FN radar',
        'every_minutes' => 5,
        'command' => [
            '/bin/timeout',
            '240',
            '/bin/flock',
            '-n',
            '-E',
            '75',
            $lockDir . '/field_ops_fn_radar_cron.lock',
            $php,
            '-d',
            'display_errors=0',
            $root . '/scripts/field_ops_fn_radar_cron.php',
            '--import-limit=100',
            '--apply-limit=200',
        ],
    ],
];

$exitCode = 0;
$selectedTasks = 0;
$epochMinute = intdiv(time(), 60);

try {
    echo 'OPS automation dispatcher starting.', PHP_EOL;
    echo 'Host: ', $host, PHP_EOL;
    echo 'Force: ', $force ? 'YES' : 'NO', PHP_EOL;

    foreach ($tasks as $task) {
        $key = (string)$task['key'];

        if ($only !== '' && $only !== $key) {
            continue;
        }

        $selectedTasks++;

        $everyMinutes = max(
            1,
            (int)$task['every_minutes']
        );

        if (
            !$force
            && ($epochMinute % $everyMinutes) !== 0
        ) {
            echo sprintf(
                'Task %s not due; cadence %d minute(s).',
                $key,
                $everyMinutes
            ), PHP_EOL;

            continue;
        }

        $result = ops_automation_run_task(
            $task,
            $root
        );

        if (
            !in_array(
                (string)$result['status'],
                ['OK', 'SKIP_LOCKED'],
                true
            )
        ) {
            $exitCode = 1;
        }
    }

    if ($selectedTasks === 0) {
        echo 'No matching automation task selected.', PHP_EOL;
        $exitCode = 1;
    }

    echo PHP_EOL;
    echo 'OPS automation dispatcher complete.', PHP_EOL;
} finally {
    flock($dispatcherLock, LOCK_UN);
    fclose($dispatcherLock);

    if (is_file($dispatcherLockPath)) {
        @unlink($dispatcherLockPath);
    }
}

exit($exitCode);
