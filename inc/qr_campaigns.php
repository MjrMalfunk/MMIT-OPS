<?php
declare(strict_types=1);

/**
 * MMIT OPS QR campaign helpers.
 *
 * OPS owns campaign management.
 * Public website owns fast redirect/logging.
 */

function qr_campaigns_is_staging_runtime(): bool
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $base = strtolower((string)(defined('BASE_URL') ? BASE_URL : ''));

    return str_contains($host, 'ops-test')
        || str_contains($host, 'test.')
        || str_contains($base, 'ops-test')
        || str_contains($base, 'test.');
}

function qr_campaigns_account_home(): string
{
    $dir = __DIR__;

    if (preg_match('#^(/home/[^/]+)#', $dir, $m)) {
        return $m[1];
    }

    $home = getenv('HOME');

    if (is_string($home) && $home !== '') {
        return rtrim($home, '/');
    }

    return dirname(__DIR__);
}

function qr_campaigns_storage_dir(): string
{
    $home = qr_campaigns_account_home();
    $default = qr_campaigns_is_staging_runtime()
        ? $home . '/private/mmit-qr-campaigns-test'
        : $home . '/private/mmit-qr-campaigns';

    return getenv('MMIT_QR_LOG_DIR') ?: $default;
}

function qr_campaigns_public_map_path(): string
{
    return qr_campaigns_storage_dir() . '/campaigns.json';
}

function qr_campaigns_scan_log_path(): string
{
    return qr_campaigns_storage_dir() . '/qr-clicks.jsonl';
}

function qr_campaigns_ensure_storage_dir(): void
{
    $dir = qr_campaigns_storage_dir();

    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
}

function qr_campaigns_ensure_schema(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS qr_campaigns (
            campaign_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(160) NOT NULL,
            destination_path VARCHAR(255) NOT NULL,
            utm_source VARCHAR(80) NOT NULL,
            utm_medium VARCHAR(80) NOT NULL,
            utm_campaign VARCHAR(120) NOT NULL,
            utm_content VARCHAR(120) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
            notes TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            launched_at DATETIME NULL,
            archived_at DATETIME NULL,
            INDEX idx_qr_campaigns_status (status),
            INDEX idx_qr_campaigns_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function qr_campaigns_valid_statuses(): array
{
    return ['DRAFT', 'ACTIVE', 'PAUSED', 'ARCHIVED'];
}

function qr_campaigns_clean_code(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
    $value = trim($value, '_-');

    return substr($value !== '' ? $value : 'qr_campaign', 0, 80);
}

function qr_campaigns_destination_is_allowed(string $path): bool
{
    $path = trim($path);

    if ($path === '') {
        return false;
    }

    if (!str_starts_with($path, '/')) {
        return false;
    }

    if (str_starts_with($path, '//')) {
        return false;
    }

    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $path)) {
        return false;
    }

    return (bool)preg_match('/^\/[A-Za-z0-9._~\/?#=&%+\-]+$/', $path);
}

function qr_campaigns_seed_defaults(): void
{
    qr_campaigns_ensure_schema();

    $defaults = [
        [
            'brochure_july_2026',
            'July 2026 Brochure',
            '/it-review.html',
            'brochure',
            'print',
            'july_2026_launch',
            'brochure_july_2026',
            'ACTIVE',
            'Primary print brochure QR campaign.',
        ],
        [
            'business_card_2026',
            'Business Card 2026',
            '/it-review.html',
            'business_card',
            'print',
            'local_outreach_2026',
            'business_card_2026',
            'DRAFT',
            'Future business card QR campaign.',
        ],
        [
            'google_profile_2026',
            'Google Business Profile 2026',
            '/it-review.html',
            'google_business_profile',
            'local_profile',
            'google_profile_2026',
            'profile_link',
            'DRAFT',
            'Future Google profile campaign link.',
        ],
    ];

    $sql = "
        INSERT INTO qr_campaigns
          (code, name, destination_path, utm_source, utm_medium, utm_campaign, utm_content, status, notes, created_at, updated_at, launched_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), CASE WHEN ? = 'ACTIVE' THEN NOW() ELSE NULL END)
        ON DUPLICATE KEY UPDATE code = code
    ";

    $st = db()->prepare($sql);

    foreach ($defaults as $row) {
        $st->execute([
            $row[0],
            $row[1],
            $row[2],
            $row[3],
            $row[4],
            $row[5],
            $row[6],
            $row[7],
            $row[8],
            $row[7],
        ]);
    }
}

function qr_campaigns_list(): array
{
    qr_campaigns_ensure_schema();

    $st = db()->query("
        SELECT *
        FROM qr_campaigns
        ORDER BY
          FIELD(status, 'ACTIVE', 'DRAFT', 'PAUSED', 'ARCHIVED'),
          updated_at DESC,
          campaign_id DESC
    ");

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function qr_campaigns_find(int $campaignId): ?array
{
    qr_campaigns_ensure_schema();

    $st = db()->prepare('SELECT * FROM qr_campaigns WHERE campaign_id = ? LIMIT 1');
    $st->execute([$campaignId]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function qr_campaigns_save(array $input, int $userId = 0): array
{
    qr_campaigns_ensure_schema();

    $campaignId = (int)($input['campaign_id'] ?? 0);
    $code = qr_campaigns_clean_code((string)($input['code'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $destination = trim((string)($input['destination_path'] ?? ''));
    $utmSource = qr_campaigns_clean_code((string)($input['utm_source'] ?? 'qr'));
    $utmMedium = qr_campaigns_clean_code((string)($input['utm_medium'] ?? 'print'));
    $utmCampaign = qr_campaigns_clean_code((string)($input['utm_campaign'] ?? $code));
    $utmContent = qr_campaigns_clean_code((string)($input['utm_content'] ?? $code));
    $status = strtoupper(trim((string)($input['status'] ?? 'DRAFT')));
    $notes = trim((string)($input['notes'] ?? ''));

    $errors = [];

    if ($name === '') {
        $errors[] = 'Campaign name is required.';
    }

    if ($code === '') {
        $errors[] = 'Campaign code is required.';
    }

    if (!qr_campaigns_destination_is_allowed($destination)) {
        $errors[] = 'Destination must be an internal path like /it-review.html.';
    }

    if (!in_array($status, qr_campaigns_valid_statuses(), true)) {
        $errors[] = 'Invalid campaign status.';
    }

    if ($errors !== []) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = db();

    try {
        if ($campaignId > 0) {
            $sql = "
                UPDATE qr_campaigns
                SET code = ?,
                    name = ?,
                    destination_path = ?,
                    utm_source = ?,
                    utm_medium = ?,
                    utm_campaign = ?,
                    utm_content = ?,
                    status = ?,
                    notes = ?,
                    launched_at = CASE WHEN ? = 'ACTIVE' AND launched_at IS NULL THEN NOW() ELSE launched_at END,
                    archived_at = CASE WHEN ? = 'ARCHIVED' AND archived_at IS NULL THEN NOW() ELSE archived_at END,
                    updated_at = NOW()
                WHERE campaign_id = ?
                LIMIT 1
            ";

            $pdo->prepare($sql)->execute([
                $code,
                $name,
                $destination,
                $utmSource,
                $utmMedium,
                $utmCampaign,
                $utmContent,
                $status,
                $notes !== '' ? $notes : null,
                $status,
                $status,
                $campaignId,
            ]);
        } else {
            $sql = "
                INSERT INTO qr_campaigns
                  (code, name, destination_path, utm_source, utm_medium, utm_campaign, utm_content, status, notes, created_by, created_at, updated_at, launched_at, archived_at)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), CASE WHEN ? = 'ACTIVE' THEN NOW() ELSE NULL END, CASE WHEN ? = 'ARCHIVED' THEN NOW() ELSE NULL END)
            ";

            $pdo->prepare($sql)->execute([
                $code,
                $name,
                $destination,
                $utmSource,
                $utmMedium,
                $utmCampaign,
                $utmContent,
                $status,
                $notes !== '' ? $notes : null,
                $userId > 0 ? $userId : null,
                $status,
                $status,
            ]);

            $campaignId = (int)$pdo->lastInsertId();
        }

        qr_campaigns_publish_public_map();

        return ['ok' => true, 'campaign_id' => $campaignId];
    } catch (Throwable $e) {
        return ['ok' => false, 'errors' => ['Campaign could not be saved: ' . $e->getMessage()]];
    }
}

function qr_campaigns_set_status(int $campaignId, string $status): array
{
    qr_campaigns_ensure_schema();

    $status = strtoupper(trim($status));

    if (!in_array($status, qr_campaigns_valid_statuses(), true)) {
        return ['ok' => false, 'errors' => ['Invalid campaign status.']];
    }

    db()->prepare("
        UPDATE qr_campaigns
        SET status = ?,
            launched_at = CASE WHEN ? = 'ACTIVE' AND launched_at IS NULL THEN NOW() ELSE launched_at END,
            archived_at = CASE WHEN ? = 'ARCHIVED' AND archived_at IS NULL THEN NOW() ELSE archived_at END,
            updated_at = NOW()
        WHERE campaign_id = ?
        LIMIT 1
    ")->execute([$status, $status, $status, $campaignId]);

    qr_campaigns_publish_public_map();

    return ['ok' => true];
}

function qr_campaigns_public_map(): array
{
    qr_campaigns_ensure_schema();

    $st = db()->query("
        SELECT code, name, destination_path, utm_source, utm_medium, utm_campaign, utm_content
        FROM qr_campaigns
        WHERE status = 'ACTIVE'
        ORDER BY code ASC
    ");

    $map = [];

    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $code = qr_campaigns_clean_code((string)$row['code']);

        if ($code === '' || !qr_campaigns_destination_is_allowed((string)$row['destination_path'])) {
            continue;
        }

        $map[$code] = [
            'name' => (string)$row['name'],
            'destination' => (string)$row['destination_path'],
            'utm_source' => (string)$row['utm_source'],
            'utm_medium' => (string)$row['utm_medium'],
            'utm_campaign' => (string)$row['utm_campaign'],
            'utm_content' => (string)$row['utm_content'],
        ];
    }

    return $map;
}

function qr_campaigns_publish_public_map(): array
{
    qr_campaigns_ensure_storage_dir();

    $map = qr_campaigns_public_map();
    $path = qr_campaigns_public_map_path();
    $tmp = $path . '.tmp';

    $payload = [
        'published_at_utc' => gmdate('c'),
        'environment' => qr_campaigns_is_staging_runtime() ? 'staging' : 'production',
        'campaigns' => $map,
    ];

    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return ['ok' => false, 'error' => 'Unable to encode campaign map.'];
    }

    if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'Unable to write campaign map.'];
    }

    @chmod($tmp, 0640);

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'Unable to publish campaign map.'];
    }

    @chmod($path, 0640);

    return ['ok' => true, 'path' => $path, 'count' => count($map)];
}

function qr_campaigns_public_url(string $code): string
{
    return 'https://midwestmanagedit.com/qr.php?code=' . rawurlencode(qr_campaigns_clean_code($code));
}

function qr_campaigns_read_scan_rows(int $limit = 5000): array
{
    $path = qr_campaigns_scan_log_path();

    if (!is_file($path)) {
        return [];
    }

    $rows = [];
    $fh = @fopen($path, 'rb');

    if (!$fh) {
        return [];
    }

    while (($line = fgets($fh)) !== false) {
        $row = json_decode($line, true);

        if (is_array($row)) {
            $rows[] = $row;
        }

        if (count($rows) > $limit) {
            array_shift($rows);
        }
    }

    fclose($fh);

    return $rows;
}

function qr_campaigns_scan_summary(): array
{
    $rows = qr_campaigns_read_scan_rows();

    $byCode = [];
    $lastByCode = [];
    $todayByCode = [];
    $uniqueDayHashes = [];
    $today = gmdate('Y-m-d');

    foreach ($rows as $row) {
        $code = (string)($row['code'] ?? 'unknown');
        $ts = (string)($row['ts_utc'] ?? '');
        $day = substr($ts, 0, 10);

        $byCode[$code] = ($byCode[$code] ?? 0) + 1;

        if ($day === $today) {
            $todayByCode[$code] = ($todayByCode[$code] ?? 0) + 1;
        }

        if (!empty($row['visitor_day_hash'])) {
            $uniqueDayHashes[(string)$row['visitor_day_hash']] = true;
        }

        if ($ts !== '' && (!isset($lastByCode[$code]) || strcmp($ts, $lastByCode[$code]) > 0)) {
            $lastByCode[$code] = $ts;
        }
    }

    return [
        'total_scans' => count($rows),
        'unique_scan_days' => count($uniqueDayHashes),
        'by_code' => $byCode,
        'today_by_code' => $todayByCode,
        'last_by_code' => $lastByCode,
        'recent' => array_reverse(array_slice($rows, -25)),
        'log_path' => qr_campaigns_scan_log_path(),
        'map_path' => qr_campaigns_public_map_path(),
    ];
}

function qr_campaigns_asset_dir(): string
{
    return qr_campaigns_storage_dir() . '/assets';
}

function qr_campaigns_svg_asset_path(string $code): string
{
    return qr_campaigns_asset_dir() . '/' . qr_campaigns_clean_code($code) . '.svg';
}

function qr_campaigns_asset_exists(string $code): bool
{
    return is_file(qr_campaigns_svg_asset_path($code));
}

function qr_campaigns_asset_serving_url(int $campaignId): string
{
    return BASE_URL . '/admin/qr_campaign_asset.php?campaign_id=' . $campaignId;
}

function qr_campaigns_generate_svg_asset(int $campaignId): array
{
    $campaign = qr_campaigns_find($campaignId);

    if (!$campaign) {
        return ['ok' => false, 'error' => 'Campaign not found.'];
    }

    $code = qr_campaigns_clean_code((string)$campaign['code']);
    $url = qr_campaigns_public_url($code);
    $outPath = qr_campaigns_svg_asset_path($code);

    $script = dirname(__DIR__) . '/scripts/qr_generate_svg.py';

    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'QR generator script is missing.'];
    }

    qr_campaigns_ensure_storage_dir();

    if (!is_dir(qr_campaigns_asset_dir())) {
        @mkdir(qr_campaigns_asset_dir(), 0750, true);
    }

    $cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($url) . ' ' . escapeshellarg($outPath) . ' 2>&1';
    $output = [];
    $exitCode = 0;

    @exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($outPath)) {
        return [
            'ok' => false,
            'error' => 'QR SVG generation failed: ' . trim(implode("\n", $output)),
        ];
    }

    @chmod($outPath, 0640);

    return [
        'ok' => true,
        'path' => $outPath,
        'url' => qr_campaigns_asset_serving_url($campaignId),
        'public_qr_url' => $url,
    ];
}
