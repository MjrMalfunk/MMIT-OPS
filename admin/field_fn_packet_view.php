<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();

$h = 'field_ops_h';
$packetId = (int)($_GET['id'] ?? 0);
$packet = field_ops_find_fn_packet_by_id($packetId);
$snapshot = $packet ? field_ops_read_fn_packet_snapshot($packet) : null;

if (!$packet || !$snapshot) {
    http_response_code(404);
    echo 'Captured Field Nation packet not found or failed integrity validation.';
    exit;
}

$sourceUrl = (string)($snapshot['source_url'] ?? '');
$links = (array)($snapshot['links'] ?? []);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>FN packet <?= $h($packet['external_work_order_number']) ?></title>
  <style>
    :root { color-scheme:dark; font-family:Inter,system-ui,sans-serif; }
    body { margin:0; background:#07111f; color:#eef6ff; }
    main { max-width:1100px; margin:0 auto; padding:28px 20px 60px; }
    .card { margin-top:18px; border:1px solid #24405f; border-radius:18px; padding:20px; background:#0c1a2b; }
    .top { display:flex; gap:16px; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; }
    h1,h2 { margin:6px 0 10px; }
    .eyebrow { color:#7dd3fc; font-size:12px; font-weight:900; letter-spacing:.1em; text-transform:uppercase; }
    .muted { color:#a8bed7; }
    .actions { display:flex; gap:10px; flex-wrap:wrap; }
    .btn { display:inline-flex; padding:10px 14px; border:1px solid #365d85; border-radius:999px; color:#dbeafe; text-decoration:none; font-weight:850; }
    pre { margin:0; white-space:pre-wrap; overflow-wrap:anywhere; font:14px/1.6 ui-monospace,SFMono-Regular,Consolas,monospace; }
    ul { padding-left:20px; }
    li { margin:9px 0; overflow-wrap:anywhere; }
    a { color:#93c5fd; }
  </style>
</head>
<body>
<main>
  <div class="top">
    <div>
      <div class="eyebrow">Captured Field Nation packet</div>
      <h1>W/O <?= $h($packet['external_work_order_number']) ?></h1>
      <div class="muted">
        Captured <?= $h($packet['captured_at'] ?? '') ?>
        · SHA-256 <?= $h($packet['content_hash'] ?? '') ?>
      </div>
    </div>
    <div class="actions">
      <?php if ((int)($packet['work_order_id'] ?? 0) > 0): ?>
        <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$packet['work_order_id'] ?>">
          Back to work order
        </a>
      <?php endif; ?>
      <?php if (field_ops_fn_packet_source_is_allowed($sourceUrl)): ?>
        <a class="btn" href="<?= $h($sourceUrl) ?>" target="_blank" rel="noopener noreferrer">
          Open Field Nation source
        </a>
      <?php endif; ?>
    </div>
  </div>

  <section class="card">
    <div class="eyebrow">Page title</div>
    <h2><?= $h($snapshot['page_title'] ?? 'Field Nation work order') ?></h2>
    <div class="muted">
      Browser capture: <?= $h($snapshot['browser_captured_at'] ?? 'Not provided') ?>
      · Stored: <?= $h($snapshot['stored_at'] ?? '') ?>
    </div>
  </section>

  <section class="card">
    <div class="eyebrow">Rendered work-order text</div>
    <pre><?= $h($snapshot['visible_text'] ?? '') ?></pre>
  </section>

  <?php if ($links): ?>
    <section class="card">
      <div class="eyebrow">Visible page links</div>
      <h2><?= count($links) ?> captured links</h2>
      <ul>
        <?php foreach ($links as $link): ?>
          <?php
            $url = trim((string)($link['url'] ?? ''));
            $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
          ?>
          <?php if (in_array($scheme, ['http', 'https'], true)): ?>
            <li>
              <a href="<?= $h($url) ?>" target="_blank" rel="noopener noreferrer">
                <?= $h(trim((string)($link['text'] ?? '')) ?: $url) ?>
              </a>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
