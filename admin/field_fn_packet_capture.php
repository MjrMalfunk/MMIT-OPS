<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();

$h = 'field_ops_h';
$externalNumber = trim((string)(
    $_GET['external_work_order_number']
    ?? $_POST['external_work_order_number']
    ?? ''
));
$packet = $externalNumber !== ''
    ? field_ops_find_fn_packet('FieldNation', $externalNumber)
    : null;
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $result = field_ops_capture_fn_packet(
        $externalNumber,
        (string)($_POST['packet_payload'] ?? ''),
        (int)(current_user()['user_id'] ?? 0)
    );

    if (!empty($result['ok'])) {
        $packet = $result['packet'] ?? $packet;
        $success = 'Field Nation packet captured securely.';
    } else {
        $error = implode(
            ' ',
            (array)($result['errors'] ?? ['Packet capture failed.'])
        );
    }
}

if (!$packet) {
    http_response_code(404);
    echo 'Field Nation packet record not found in OPS.';
    exit;
}

$expectedNumberJson = json_encode(
    (string)$packet['external_work_order_number'],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Capture FN packet <?= $h($packet['external_work_order_number']) ?></title>
  <style>
    :root { color-scheme:dark; font-family:Inter,system-ui,sans-serif; }
    body { margin:0; background:#07111f; color:#eef6ff; }
    main { max-width:720px; margin:0 auto; padding:28px 20px; }
    .card { border:1px solid #24405f; border-radius:18px; padding:20px; background:#0c1a2b; }
    h1 { margin:6px 0 10px; font-size:clamp(24px,5vw,36px); }
    p { color:#a8bed7; line-height:1.55; }
    .eyebrow { color:#7dd3fc; font-size:12px; font-weight:900; letter-spacing:.1em; text-transform:uppercase; }
    .state { margin:16px 0; border-radius:14px; padding:12px 14px; background:#10243a; }
    .success { color:#86efac; border:1px solid #166534; }
    .error { color:#fecaca; border:1px solid #991b1b; }
    a { color:#93c5fd; }
  </style>
</head>
<body>
<main>
  <section class="card">
    <div class="eyebrow">MMIT OPS secure capture</div>
    <h1>Field Nation W/O <?= $h($packet['external_work_order_number']) ?></h1>

    <?php if ($success !== ''): ?>
      <div class="state success" role="status"><?= $h($success) ?></div>
      <p>
        <a href="<?= $h(BASE_URL) ?>/admin/field_fn_packet_view.php?id=<?= (int)$packet['packet_id'] ?>">
          View captured packet
        </a>
        <?php if ((int)($packet['work_order_id'] ?? 0) > 0): ?>
          ·
          <a href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$packet['work_order_id'] ?>">
            Return to work order
          </a>
        <?php endif; ?>
      </p>
    <?php elseif ($error !== ''): ?>
      <div class="state error" role="alert"><?= $h($error) ?></div>
      <p>Return to the Field Nation tab and run the capture bookmark again.</p>
    <?php else: ?>
      <div class="state" id="capture-status" role="status">
        Waiting for the authorized Field Nation page…
      </div>
      <p>
        Keep this window open. The rendered page data is transferred directly
        from your logged-in Field Nation tab; credentials and cookies are never sent.
      </p>
    <?php endif; ?>

    <form method="post" id="capture-form" hidden>
      <?= csrf_field() ?>
      <input
        type="hidden"
        name="external_work_order_number"
        value="<?= $h($packet['external_work_order_number']) ?>"
      >
      <textarea name="packet_payload" id="packet-payload"></textarea>
    </form>
  </section>
</main>

<?php if ($success === '' && $error === ''): ?>
<script>
(() => {
  'use strict';

  const expectedNumber = <?= $expectedNumberJson ?>;
  const status = document.getElementById('capture-status');
  const form = document.getElementById('capture-form');
  const payloadField = document.getElementById('packet-payload');

  const isFieldNationOrigin = (origin) => {
    try {
      const url = new URL(origin);
      return url.protocol === 'https:'
        && (
          url.hostname === 'fieldnation.com'
          || url.hostname.endsWith('.fieldnation.com')
        );
    } catch {
      return false;
    }
  };

  window.addEventListener('message', (event) => {
    if (
      event.source !== window.opener
      || !isFieldNationOrigin(event.origin)
      || !event.data
      || event.data.type !== 'mmit-fn-capture-payload'
      || String(event.data.work_order_number || '') !== expectedNumber
    ) {
      return;
    }

    const encoded = JSON.stringify(event.data.payload || {});

    if (!encoded || encoded.length > 4 * 1024 * 1024) {
      status.textContent = 'Capture payload is empty or too large.';
      status.classList.add('error');
      return;
    }

    payloadField.value = encoded;
    status.textContent = 'Packet received. Storing securely…';
    form.submit();
  });

  if (!window.opener) {
    status.textContent = 'Open this receiver from the Field Nation capture bookmark.';
    status.classList.add('error');
    return;
  }

  window.opener.postMessage({
    type: 'mmit-fn-capture-ready',
    work_order_number: expectedNumber
  }, '*');
})();
</script>
<?php endif; ?>
</body>
</html>
