<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/qr_campaigns.php';

require_login();

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

qr_campaigns_ensure_schema();
qr_campaigns_seed_defaults();

$user = current_user() ?: [];
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_campaign') {
        $result = qr_campaigns_save($_POST, (int)($user['user_id'] ?? 0));

        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'QR campaign saved and public map published.';
            header('Location: ' . BASE_URL . '/admin/qr_campaigns.php');
            exit;
        }

        $flashError = implode(' ', (array)($result['errors'] ?? ['QR campaign could not be saved.']));
    } elseif ($action === 'set_status') {
        $result = qr_campaigns_set_status((int)($_POST['campaign_id'] ?? 0), (string)($_POST['status'] ?? ''));

        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'QR campaign status updated and public map published.';
            header('Location: ' . BASE_URL . '/admin/qr_campaigns.php');
            exit;
        }

        $flashError = implode(' ', (array)($result['errors'] ?? ['Status could not be updated.']));
    } elseif ($action === 'generate_svg') {
        $result = qr_campaigns_generate_svg_asset((int)($_POST['campaign_id'] ?? 0));

        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'QR SVG generated.';
            header('Location: ' . BASE_URL . '/admin/qr_campaigns.php');
            exit;
        }

        $flashError = (string)($result['error'] ?? 'QR SVG could not be generated.');
    } elseif ($action === 'publish') {
        $result = qr_campaigns_publish_public_map();

        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'Published ' . (int)($result['count'] ?? 0) . ' active QR campaigns.';
            header('Location: ' . BASE_URL . '/admin/qr_campaigns.php');
            exit;
        }

        $flashError = (string)($result['error'] ?? 'Campaign map could not be published.');
    }
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$campaigns = qr_campaigns_list();
$summary = qr_campaigns_scan_summary();

$totalScans = (int)$summary['total_scans'];
$uniqueScanDays = (int)$summary['unique_scan_days'];
$activeCount = count(array_filter($campaigns, static fn($row): bool => (string)$row['status'] === 'ACTIVE'));
$mapPath = (string)$summary['map_path'];
$logPath = (string)$summary['log_path'];

$editCampaign = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $editCampaign = qr_campaigns_find($editId);
}


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>QR Campaigns · <?= $h(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      --bg: #081426;
      --panel: rgba(255,255,255,.055);
      --panel-strong: rgba(255,255,255,.085);
      --line: rgba(255,255,255,.12);
      --text: #eef6ff;
      --muted: rgba(238,246,255,.72);
      --blue: #60a5fa;
      --green: #86efac;
      --yellow: #fde68a;
      --red: #fecaca;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.15), transparent 28rem),
        linear-gradient(135deg, #081426, #0f2238);
      color: var(--text);
    }
    a { color: #bfdbfe; }
    .page { width: min(100% - 2rem, 1180px); margin: 0 auto; padding: 28px 0 48px; }
    .topbar { display: flex; gap: 12px; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .eyebrow { text-transform: uppercase; letter-spacing: .14em; color: #93c5fd; font-size: 12px; font-weight: 800; }
    h1 { margin: 6px 0 8px; font-size: clamp(30px, 5vw, 48px); line-height: 1; }
    h2 { margin: 0 0 12px; font-size: 22px; }
    h3 { margin: 0 0 8px; font-size: 17px; }
    p { color: var(--muted); line-height: 1.6; }
    .grid { display: grid; gap: 16px; }
    .stats { grid-template-columns: repeat(4, minmax(0, 1fr)); margin: 18px 0; }
    .two { grid-template-columns: minmax(0, .88fr) minmax(0, 1.12fr); align-items: start; }
    .card {
      border: 1px solid var(--line);
      background: var(--panel);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 20px 60px rgba(0,0,0,.22);
    }
    .stat-value { font-size: 30px; font-weight: 900; }
    .stat-label { color: var(--muted); font-size: 13px; }
    .flash-success, .flash-error {
      border-radius: 14px;
      padding: 12px 14px;
      margin: 12px 0;
      border: 1px solid var(--line);
    }
    .flash-success { background: rgba(34,197,94,.13); color: var(--green); }
    .flash-error { background: rgba(239,68,68,.13); color: var(--red); }
    label { display: grid; gap: 7px; font-size: 13px; color: var(--muted); font-weight: 700; }
    input, textarea, select {
      width: 100%;
      border: 1px solid var(--line);
      background: rgba(0,0,0,.22);
      color: var(--text);
      border-radius: 12px;
      padding: 11px 12px;
      font: inherit;
    }
    textarea { min-height: 94px; resize: vertical; }
    .form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .full { grid-column: 1 / -1; }
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 40px;
      padding: 10px 14px;
      border-radius: 999px;
      border: 1px solid rgba(147,197,253,.35);
      color: var(--text);
      background: rgba(96,165,250,.16);
      text-decoration: none;
      font-weight: 800;
      cursor: pointer;
    }
    .btn-primary { background: linear-gradient(135deg, #3b82f6, #60a5fa); color: white; }
    .btn-small { min-height: 32px; padding: 7px 10px; font-size: 12px; }
    .table-wrap { overflow: auto; }
    table { width: 100%; border-collapse: collapse; min-width: 980px; }
    th, td { padding: 11px 9px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
    th { color: #bfdbfe; font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
    code {
      display: inline-block;
      max-width: 100%;
      overflow-wrap: anywhere;
      padding: 4px 7px;
      border-radius: 8px;
      background: rgba(0,0,0,.26);
      color: #dbeafe;
    }
    .badge {
      display: inline-flex;
      border: 1px solid var(--line);
      border-radius: 999px;
      padding: 5px 9px;
      font-size: 12px;
      font-weight: 900;
      letter-spacing: .04em;
    }
    .ACTIVE { color: var(--green); background: rgba(34,197,94,.12); }
    .DRAFT { color: #dbeafe; background: rgba(147,197,253,.1); }
    .PAUSED { color: var(--yellow); background: rgba(250,204,21,.1); }
    .ARCHIVED { color: rgba(238,246,255,.6); background: rgba(255,255,255,.06); }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .recent-list { display: grid; gap: 10px; }
    .recent-row {
      border: 1px solid var(--line);
      background: rgba(0,0,0,.18);
      border-radius: 12px;
      padding: 10px;
      color: var(--muted);
      font-size: 13px;
    }
    @media (max-width: 900px) {
      .stats, .two, .form-grid { grid-template-columns: 1fr; }
      .full { grid-column: auto; }
    }
  </style>
</head>
<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS marketing</div>
      <h1>QR Campaigns</h1>
      <p style="margin:0;">Create QR campaign links, publish the public campaign map, and track scan activity.</p>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/index.php">Back to admin</a>
      <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="publish">
        <button class="btn btn-primary" type="submit">Publish active campaigns</button>
      </form>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="grid stats">
    <article class="card"><div class="stat-value"><?= $h((string)$totalScans) ?></div><div class="stat-label">Total scans in log</div></article>
    <article class="card"><div class="stat-value"><?= $h((string)$uniqueScanDays) ?></div><div class="stat-label">Unique scan-days</div></article>
    <article class="card"><div class="stat-value"><?= $h((string)$activeCount) ?></div><div class="stat-label">Active campaigns</div></article>
    <article class="card"><div class="stat-value"><?= $h((string)count($campaigns)) ?></div><div class="stat-label">Total campaigns</div></article>
  </section>

  <section class="grid two">
    <article class="card">
      <h2><?= $editCampaign ? "Edit campaign" : "Create campaign" ?></h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_campaign">
        <input type="hidden" name="campaign_id" value="<?= $editCampaign ? (int)$editCampaign['campaign_id'] : 0 ?>">

        <label>
          Campaign name
          <input name="name" placeholder="July 2026 Brochure" value="<?= $h($editCampaign['name'] ?? '') ?>" required>
        </label>

        <label>
          Campaign code
          <input name="code" placeholder="brochure_july_2026" value="<?= $h($editCampaign['code'] ?? '') ?>" required>
        </label>

        <label class="full">
          Destination path
          <input name="destination_path" value="<?= $h($editCampaign['destination_path'] ?? '/it-review.html') ?>" required>
        </label>

        <label>
          UTM source
          <input name="utm_source" value="<?= $h($editCampaign['utm_source'] ?? 'brochure') ?>" required>
        </label>

        <label>
          UTM medium
          <input name="utm_medium" value="<?= $h($editCampaign['utm_medium'] ?? 'print') ?>" required>
        </label>

        <label>
          UTM campaign
          <input name="utm_campaign" value="<?= $h($editCampaign['utm_campaign'] ?? 'july_2026_launch') ?>" required>
        </label>

        <label>
          UTM content
          <input name="utm_content" placeholder="brochure_july_2026" value="<?= $h($editCampaign['utm_content'] ?? '') ?>" required>
        </label>

        <label>
          Status
          <select name="status">
            <?php $selectedStatus = (string)($editCampaign['status'] ?? 'DRAFT'); ?>
            <?php foreach (qr_campaigns_valid_statuses() as $statusOption): ?>
              <option value="<?= $h($statusOption) ?>" <?= $selectedStatus === $statusOption ? 'selected' : '' ?>><?= $h(ucfirst(strtolower($statusOption))) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="full">
          Notes
          <textarea name="notes" placeholder="Where this QR will be used, print batch, audience, etc."><?= $h($editCampaign['notes'] ?? '') ?></textarea>
        </label>

        <div class="full">
          <button class="btn btn-primary" type="submit"><?= $editCampaign ? "Update campaign" : "Save campaign" ?></button>
          <?php if ($editCampaign): ?><a class="btn" href="<?= $h(BASE_URL) ?>/admin/qr_campaigns.php">Cancel edit</a><?php endif; ?>
        </div>
      </form>
    </article>

    <article class="card">
      <h2>System paths</h2>
      <p><strong>Public campaign map:</strong><br><code><?= $h($mapPath) ?></code></p>
      <p><strong>Scan log:</strong><br><code><?= $h($logPath) ?></code></p>
      <p><strong>Current print QR URL:</strong><br><code><?= $h(qr_campaigns_public_url('brochure_july_2026')) ?></code></p>
      <p style="margin-bottom:0;">Active campaigns are exported to <code>campaigns.json</code>. The public website redirector reads that file and falls back to built-in defaults if the file is missing.</p>
    </article>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Campaigns</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Campaign</th>
            <th>Public URL</th>
            <th>QR SVG</th>
            <th>Destination</th>
            <th>Scans</th>
            <th>Today</th>
            <th>Last scan</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$campaigns): ?>
          <tr><td colspan="9" style="opacity:.72;">No QR campaigns yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($campaigns as $row):
          $code = (string)$row['code'];
          $status = (string)$row['status'];
          $scans = (int)(($summary['by_code'][$code] ?? 0));
          $today = (int)(($summary['today_by_code'][$code] ?? 0));
          $last = (string)(($summary['last_by_code'][$code] ?? ''));
        ?>
          <tr>
            <td><span class="badge <?= $h($status) ?>"><?= $h($status) ?></span></td>
            <td>
              <strong><?= $h($row['name']) ?></strong><br>
              <code><?= $h($code) ?></code>
              <?php if (!empty($row['notes'])): ?><div style="margin-top:6px;opacity:.72;"><?= $h($row['notes']) ?></div><?php endif; ?>
            </td>
            <td><code><?= $h(qr_campaigns_public_url($code)) ?></code></td>
            <td>
              <?php if (qr_campaigns_asset_exists($code)): ?>
                <a class="btn btn-small" href="<?= $h(qr_campaigns_asset_serving_url((int)$row['campaign_id'])) ?>" target="_blank" rel="noopener">Preview</a>
              <?php else: ?>
                <span style="opacity:.55;">Not generated</span>
              <?php endif; ?>
            </td>
            <td>
              <code><?= $h($row['destination_path']) ?></code><br>
              <span style="opacity:.72;"><?= $h($row['utm_source']) ?> / <?= $h($row['utm_medium']) ?> / <?= $h($row['utm_campaign']) ?></span>
            </td>
            <td><?= $h((string)$scans) ?></td>
            <td><?= $h((string)$today) ?></td>
            <td><?= $last !== '' ? $h($last) : '<span style="opacity:.5;">Never</span>' ?></td>
            <td>
              <div class="actions">
                <a class="btn btn-small" href="<?= $h(BASE_URL) ?>/admin/qr_campaigns.php?edit=<?= (int)$row['campaign_id'] ?>">Edit</a>
                <form method="post" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="generate_svg">
                  <input type="hidden" name="campaign_id" value="<?= (int)$row['campaign_id'] ?>">
                  <button class="btn btn-small" type="submit">Generate SVG</button>
                </form>
                <?php foreach (['ACTIVE', 'PAUSED', 'ARCHIVED'] as $nextStatus): ?>
                  <?php if ($status !== $nextStatus): ?>
                    <form method="post" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="set_status">
                      <input type="hidden" name="campaign_id" value="<?= (int)$row['campaign_id'] ?>">
                      <input type="hidden" name="status" value="<?= $h($nextStatus) ?>">
                      <button class="btn btn-small" type="submit"><?= $h(ucfirst(strtolower($nextStatus))) ?></button>
                    </form>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Recent scans</h2>
    <div class="recent-list">
      <?php if (empty($summary['recent'])): ?>
        <p style="margin:0;">No scans yet.</p>
      <?php endif; ?>
      <?php foreach ((array)$summary['recent'] as $scan): ?>
        <div class="recent-row">
          <strong><?= $h($scan['code'] ?? 'unknown') ?></strong>
          · <?= $h($scan['ts_utc'] ?? '') ?>
          · <?= !empty($scan['known']) ? 'known' : 'unknown' ?><br>
          <span><?= $h($scan['destination'] ?? '') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</main>
</body>
</html>
