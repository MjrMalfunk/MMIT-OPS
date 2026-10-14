<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/outlook_calendar.php';

require_login();
field_ops_ensure_schema();
outlook_calendar_ensure_schema();

$h = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$userId = (int)(current_user()['user_id'] ?? 0);
$flashSuccess = trim((string)($_GET['message'] ?? ''));
$flashError = trim((string)($_GET['error'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'add_flex_block') {
        $result = outlook_calendar_add_flex_block($_POST, $userId);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'Flex block added to Outlook.';
        } elseif ((int)($result['calendar_event_id'] ?? 0) > 0) {
            $_SESSION['flash_msg'] = 'Flex block saved in OPS; Outlook needs attention: '
                . implode(' ', (array)($result['errors'] ?? ['Sync is pending.']));
        } else {
            $_SESSION['flash_error'] = implode(' ', (array)($result['errors'] ?? ['Unable to add the Flex block.']));
        }
    } elseif ($action === 'sync_work_order') {
        $result = outlook_calendar_sync_work_order((int)($_POST['work_order_id'] ?? 0), $userId);
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = !empty($result['not_ready'])
                ? 'This work order needs an assigned status and a complete schedule before it can sync.'
                : 'FieldNation work order synced to Outlook.';
        } else {
            $_SESSION['flash_error'] = (string)($result['error'] ?? 'Outlook sync failed.');
        }
    } elseif ($action === 'retry_event') {
        $result = outlook_calendar_retry_event((int)($_POST['calendar_event_id'] ?? 0));
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'Outlook sync completed.';
        } else {
            $_SESSION['flash_error'] = (string)($result['error'] ?? 'Outlook sync failed.');
        }
    } elseif ($action === 'cancel_flex_block') {
        $result = outlook_calendar_cancel_flex_block((int)($_POST['calendar_event_id'] ?? 0));
        if (!empty($result['ok'])) {
            $_SESSION['flash_msg'] = 'Flex block removed from Outlook.';
        } else {
            $_SESSION['flash_error'] = (string)($result['error'] ?? 'Unable to remove the Flex block.');
        }
    }

    header('Location: ' . BASE_URL . '/admin/field_calendar.php');
    exit;
}

if (!empty($_SESSION['flash_msg'])) {
    if ($flashSuccess === '') {
        $flashSuccess = (string)$_SESSION['flash_msg'];
    }
    unset($_SESSION['flash_msg']);
}
if (!empty($_SESSION['flash_error'])) {
    if ($flashError === '') {
        $flashError = (string)$_SESSION['flash_error'];
    }
    unset($_SESSION['flash_error']);
}

$connection = outlook_calendar_connection_status();
$unlinkedWorkOrders = outlook_calendar_unlinked_work_orders(40);
$calendarEvents = outlook_calendar_pending_events(40);
$defaultStart = date('Y-m-d\TH:i', time() + 3600);
$defaultEnd = date('Y-m-d\TH:i', time() + 4 * 3600);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Outlook calendar · Field Ops</title>
  <style>
    :root{color-scheme:dark;--bg:#0b1220;--surface:#161f2e;--surface2:#1d293a;--line:#344256;--text:#f2f5fa;--muted:#aebbd0;--accent:#75e8ce;--blue:#7fb5ff;--red:#ff9b9b;--green:#9ee8ad}
    *{box-sizing:border-box}body{margin:0;background:linear-gradient(135deg,#12152b,var(--bg) 48%);color:var(--text);font:15px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}.page{max-width:1120px;margin:0 auto;padding:28px 20px 56px}.topbar,.row-head,.row-actions{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.topbar{margin-bottom:22px}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.13em;text-transform:uppercase;color:var(--accent)}h1{font-size:clamp(28px,4vw,40px);line-height:1.1;margin:5px 0}h2{font-size:21px;margin:0 0 8px}p{color:var(--muted);margin:.3rem 0}.actions,.row-actions{display:flex;gap:8px;align-items:center}.btn,button{border:1px solid var(--line);border-radius:10px;background:#202d40;color:var(--text);padding:10px 14px;font:inherit;font-weight:750;text-decoration:none;cursor:pointer}.btn-primary{background:var(--accent);color:#08201d;border-color:var(--accent)}.btn-danger{color:#ffd8d8;border-color:#8b4548;background:#3b2027}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.card{background:linear-gradient(145deg,var(--surface2),var(--surface));border:1px solid var(--line);border-radius:16px;padding:20px;margin-bottom:16px;box-shadow:0 12px 30px #0002}.wide{grid-column:1/-1}.status{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:4px 10px;font-size:12px;font-weight:850;letter-spacing:.04em}.status-SYNCED{color:var(--green)}.status-ERROR{color:var(--red)}.status-PENDING,.status-NOT_READY{color:#ffd78a}.flash{padding:13px 16px;border-radius:12px;margin:0 0 16px}.success{background:#153326;border:1px solid #2f7c59}.error{background:#382126;border:1px solid #9a4d55}.notice{border-left:3px solid var(--blue);padding:10px 12px;background:#142338;border-radius:0 9px 9px 0;color:#d5e5ff;margin:14px 0}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.field{display:grid;gap:5px;font-weight:700}.field input,.field textarea{width:100%;border:1px solid #46566d;border-radius:9px;background:#0c1420;color:var(--text);padding:11px;font:inherit}.field textarea{min-height:86px;resize:vertical}.full{grid-column:1/-1}.list{display:grid;gap:10px}.item{border:1px solid var(--line);background:#111b2a;border-radius:12px;padding:14px}.item-title{font-weight:800}.meta{font-size:13px;color:var(--muted)}.item p{font-size:13px;overflow-wrap:anywhere}.empty{border:1px dashed var(--line);padding:18px;border-radius:12px;color:var(--muted)}small,.hint{color:var(--muted)}
    .status-DELETE_ERROR{color:var(--red)}@media(max-width:760px){.grid,.form-grid{grid-template-columns:1fr}.wide,.full{grid-column:auto}.page{padding:18px 12px 36px}.card{padding:16px}}
  </style>
</head>
<body>
<main class="page">
  <header class="topbar">
    <div><div class="eyebrow">Field Ops · Microsoft 365</div><h1>Outlook calendar</h1><p>Keep confirmed FieldNation work and accepted Flex blocks on your calendar.</p></div>
    <nav class="actions" aria-label="Field Ops navigation"><a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Field Ops</a><a class="btn" href="<?= $h(BASE_URL) ?>/admin/index.php">Admin</a></nav>
  </header>

  <?php if ($flashSuccess !== ''): ?><div class="flash success" role="status"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash error" role="alert"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="card" aria-labelledby="connection-title">
    <div class="row-head"><div><div class="eyebrow">Connection</div><h2 id="connection-title">Microsoft 365 calendar access</h2></div>
      <span class="status <?= !empty($connection['calendar_ready']) ? 'status-SYNCED' : 'status-PENDING' ?>"><?= !empty($connection['calendar_ready']) ? 'CONNECTED' : 'SETUP NEEDED' ?></span>
    </div>
    <?php if (!empty($connection['calendar_ready'])): ?>
      <p>Connected as <strong><?= $h($connection['account_label'] ?: 'Microsoft 365 account') ?></strong>. Calendar events are private, marked busy, and get a 30-minute reminder.</p>
    <?php elseif (empty($connection['configured'])): ?>
      <p>Microsoft sign-in has not been configured for OPS yet. Add the existing app registration details before connecting Outlook.</p>
    <?php elseif (!empty($connection['connected']) && empty($connection['calendar_permission'])): ?>
      <p>OneDrive is connected, but OPS needs separate Outlook calendar access. Connecting requests Microsoft Graph delegated <code>Calendars.ReadWrite</code> for your Microsoft 365 account. If Microsoft says the permission is unavailable, add it to the OPS app registration first.</p>
      <form method="post" action="<?= $h(BASE_URL) ?>/accounting/onedrive_connect.php" class="actions" style="margin-top:12px;">
        <?= csrf_field() ?><input type="hidden" name="calendar" value="1"><input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_calendar.php') ?>">
        <button class="btn btn-primary" type="submit">Connect Outlook Calendar</button>
      </form>
    <?php else: ?>
      <p>Connect the Microsoft 365 account whose Outlook calendar should receive the work blocks.</p>
      <form method="post" action="<?= $h(BASE_URL) ?>/accounting/onedrive_connect.php" class="actions" style="margin-top:12px;">
        <?= csrf_field() ?><input type="hidden" name="calendar" value="1"><input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_calendar.php') ?>">
        <button class="btn btn-primary" type="submit">Connect Microsoft 365 Calendar</button>
      </form>
    <?php endif; ?>
    <div class="notice">OPS copies the scheduled window exactly as entered on the work order. Add your drive and contingency time to that window when you schedule the job; OPS will not add another buffer.</div>
  </section>

  <div class="grid">
    <section class="card" aria-labelledby="flex-title">
      <div class="eyebrow">Amazon Flex</div><h2 id="flex-title">Add an accepted block</h2>
      <p>Enter a block after accepting it in the Flex app. OPS will create a private busy event in Outlook.</p>
      <form method="post" class="form-grid" style="margin-top:14px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_flex_block">
        <label class="field full">Title<input name="title" maxlength="160" value="Amazon Flex block"></label>
        <label class="field">Starts<input type="datetime-local" name="starts_at" required value="<?= $h($defaultStart) ?>"></label>
        <label class="field">Ends<input type="datetime-local" name="ends_at" required value="<?= $h($defaultEnd) ?>"></label>
        <label class="field">Expected pay (optional)<input name="expected_pay" inputmode="decimal" placeholder="79.00"></label>
        <label class="field">Notes (optional)<input name="notes" maxlength="500" placeholder="Station or block details"></label>
        <div class="full"><button type="submit" class="btn btn-primary">Add to Outlook</button></div>
      </form>
    </section>

    <section class="card" aria-labelledby="existing-title">
      <div class="eyebrow">FieldNation</div><h2 id="existing-title">Add scheduled OPS jobs</h2>
      <p>New assigned jobs sync automatically when their schedule is saved. Use this list to add existing scheduled jobs once.</p>
      <div class="notice">Only add jobs that are not already on your Outlook calendar. Events you entered manually cannot be matched automatically.</div>
      <div class="list">
        <?php if ($unlinkedWorkOrders === []): ?><div class="empty">No scheduled FieldNation work orders need linking.</div><?php endif; ?>
        <?php foreach ($unlinkedWorkOrders as $wo): ?>
          <article class="item">
            <div class="item-title">W/O <?= $h($wo['external_work_order_number'] ?: ('#' . (int)$wo['work_order_id'])) ?> · <?= $h($wo['title']) ?></div>
            <div class="meta"><?= $h($wo['status']) ?> · <?= $h(field_ops_datetime_display($wo['scheduled_start_at'])) ?> – <?= $h(field_ops_datetime_display($wo['scheduled_end_at'])) ?></div>
            <div class="meta"><?= $h(trim((string)($wo['buyer_name'] ?? '') . ' · ' . (string)($wo['city'] ?? '') . ', ' . (string)($wo['state'] ?? ''), ' ·,')) ?></div>
            <div class="row-actions" style="margin-top:10px;">
              <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync_work_order"><input type="hidden" name="work_order_id" value="<?= (int)$wo['work_order_id'] ?>"><button type="submit" class="btn">Add to Outlook</button></form>
              <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$wo['work_order_id'] ?>">Open W/O</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card wide" aria-labelledby="events-title">
      <div class="row-head"><div><div class="eyebrow">Sync history</div><h2 id="events-title">Calendar items</h2></div></div>
      <div class="list">
        <?php if ($calendarEvents === []): ?><div class="empty">No calendar items have been added from OPS yet.</div><?php endif; ?>
        <?php foreach ($calendarEvents as $event): ?>
          <article class="item">
            <div class="row-head"><div><div class="item-title"><?= $h($event['title']) ?></div><div class="meta"><?= $h(field_ops_datetime_display($event['starts_at'])) ?> – <?= $h(field_ops_datetime_display($event['ends_at'])) ?> · <?= $h($event['source_type'] === 'FLEX' ? 'Amazon Flex' : 'FieldNation') ?></div></div><span class="status status-<?= $h($event['sync_status']) ?>"><?= $h($event['sync_status']) ?></span></div>
            <?php if (trim((string)($event['last_error'] ?? '')) !== ''): ?><p><?= $h($event['last_error']) ?></p><?php endif; ?>
            <?php if (in_array((string)$event['sync_status'], ['ERROR','DELETE_ERROR','PENDING','NOT_READY'], true)): ?>
              <div class="row-actions" style="margin-top:9px;"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="retry_event"><input type="hidden" name="calendar_event_id" value="<?= (int)$event['calendar_event_id'] ?>"><button type="submit" class="btn">Retry sync</button></form>
              <?php if ($event['source_type'] === 'FLEX' && $event['sync_status'] !== 'CANCELLED'): ?><form method="post" onsubmit="return confirm('Remove this Flex block from Outlook?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_flex_block"><input type="hidden" name="calendar_event_id" value="<?= (int)$event['calendar_event_id'] ?>"><button type="submit" class="btn btn-danger">Remove block</button></form><?php endif; ?></div>
            <?php elseif ($event['source_type'] === 'FLEX' && $event['sync_status'] === 'SYNCED'): ?>
              <div class="row-actions" style="margin-top:9px;"><form method="post" onsubmit="return confirm('Remove this Flex block from Outlook?');"><?= csrf_field() ?><input type="hidden" name="action" value="cancel_flex_block"><input type="hidden" name="calendar_event_id" value="<?= (int)$event['calendar_event_id'] ?>"><button type="submit" class="btn btn-danger">Remove block</button></form></div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </div>
</main>
</body>
</html>
