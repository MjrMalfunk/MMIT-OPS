<?php
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';
require_once __DIR__ . '/../inc/cove.php';

$apply = in_array('--apply', $argv, true);
$partnerId = cove_config_int('COVE_DEFAULT_PARTNER_ID', 0);

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--partner-id=')) {
        $partnerId = max(0, (int)substr($arg, strlen('--partner-id=')));
    }
}

echo 'Cove API smoke starting in ' . ($apply ? 'apply' : 'dry-run') . " mode.\n";
echo 'DB: ' . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";

$missing = cove_missing_config();
if ($missing !== []) {
    echo 'Cove API is not configured. Missing: ' . implode(', ', $missing) . "\n";
    echo "No API call attempted. Add real values only in private OPS config.\n";
    exit(0);
}

try {
    echo "Attempting Cove Login...\n";
    $session = cove_login();

    $user = is_array($session['user'] ?? null) ? $session['user'] : [];
    $loginPartnerId = isset($user['PartnerId']) ? (int)$user['PartnerId'] : 0;

    echo "Cove Login: OK\n";
    echo 'API user: ' . (string)($user['Name'] ?? $user['EmailAddress'] ?? '[not returned]') . "\n";
    echo 'Login PartnerId: ' . ($loginPartnerId > 0 ? (string)$loginPartnerId : '[not returned]') . "\n";

    $visa = (string)$session['visa'];

    if ($partnerId <= 0 && $loginPartnerId > 0) {
        $partnerId = $loginPartnerId;
    }

    if ($partnerId <= 0) {
        echo "No PartnerId available for device statistics. Login smoke passed only.\n";

        if ($apply) {
            vendor_integration_update_status('cove', 'CONNECTED');
            echo "Updated Cove integration status to CONNECTED.\n";
        }

        exit(0);
    }

    echo "Attempting EnumerateAccountStatistics for PartnerId {$partnerId}...\n";
    $statsResponse = cove_enumerate_account_statistics($visa, $partnerId, 0, 10);
    $rows = cove_account_statistics_rows($statsResponse);

    echo 'Device statistics rows returned: ' . count($rows) . "\n";

    $shown = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $settings = cove_settings_map($row);
        $deviceName = (string)($settings['I1'] ?? $settings['I18'] ?? '[unknown]');
        $computerName = (string)($settings['I18'] ?? '');
        $usedBytes = cove_storage_bytes($settings['I14'] ?? null);
        $role = cove_device_role_from_os_type($settings['I32'] ?? null) ?? 'unknown';

        echo json_encode([
            'account_id' => $row['AccountId'] ?? null,
            'partner_id' => $row['PartnerId'] ?? null,
            'device_name' => $deviceName,
            'computer_name' => $computerName,
            'role' => $role,
            'storage_used_bytes' => $usedBytes,
            'active_data_sources' => $settings['I78'] ?? null,
        ], JSON_UNESCAPED_SLASHES) . "\n";

        $shown++;
        if ($shown >= 5) {
            break;
        }
    }

    if ($apply) {
        vendor_integration_update_status('cove', 'CONNECTED');
        echo "Updated Cove integration status to CONNECTED.\n";
    }

    echo "Cove API smoke complete.\n";
} catch (Throwable $e) {
    $message = cove_mask_sensitive($e->getMessage());
    fwrite(STDERR, 'Cove API smoke failed: ' . $message . "\n");

    if ($apply) {
        vendor_integration_update_status('cove', 'ERROR', $message);
    }

    exit(1);
}
