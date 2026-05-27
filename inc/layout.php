<?php
declare(strict_types=1);

require_once __DIR__ . '/nav.php';

function page_header(string $title, string $activeNav = '', bool $showNav = true): void {
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars(APP_NAME) ?></title>
  <link rel="icon" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon.ico">
  <link rel="icon" type="image/png" sizes="64x64" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-64.png">
  <link rel="icon" type="image/png" sizes="512x512" href="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-favicon-512.png">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/css/portal_shell.css?v=6">
</head>
<body>
  <div class="page-wrap">
    <div class="page-title">
      <a class="portal-brand" href="<?= htmlspecialchars(BASE_URL) ?>/dashboard/index.php" aria-label="<?= htmlspecialchars(APP_NAME) ?>">
        <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/brand/mmit-logo-horizontal-light.svg" class="portal-logo" alt="<?= htmlspecialchars(APP_NAME) ?>">
      </a>
    </div>

    <?php if ($showNav): ?>
      <div class="nav-wrap">
        <?php render_pills($activeNav); ?>
      </div>
    <?php endif; ?>

    <?php if (function_exists('ops_is_staging_env') && ops_is_staging_env()): ?>
      <div class="ops-staging-banner" role="status" aria-live="polite">STAGING - OPS TEST</div>
    <?php endif; ?>

    <div class="glass">
<?php
}

function page_footer(): void {
?>
    </div>
  </div>
  <script>
  (() => {
    const opsIdleSeconds = <?= json_encode((int) SESSION_TTL_SECONDS) ?>;
    const logoutUrl = <?= json_encode(BASE_URL . '/logout.php?reason=inactive') ?>;
    let timer = null;
    const resetTimer = () => {
      if (timer) window.clearTimeout(timer);
      timer = window.setTimeout(() => { window.location.href = logoutUrl; }, Math.max(60, opsIdleSeconds) * 1000);
    };
    ['click','keydown','mousemove','scroll','touchstart'].forEach((evt) => {
      document.addEventListener(evt, resetTimer, { passive: true });
    });
    resetTimer();
  })();
  </script>
</body>
</html>
<?php
}
