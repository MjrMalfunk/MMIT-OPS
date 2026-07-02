<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();

field_ops_ensure_opportunity_schema();

$h = 'field_ops_fn_h';
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'save_email_event') {
        $result = field_ops_save_fn_email_event($_POST);
    } elseif ($action === 'apply_email_event') {
        $result = field_ops_apply_email_event_to_opportunity((int)($_POST['email_event_id'] ?? 0));
    } elseif ($action === 'promote_opportunity') {
        $result = field_ops_promote_opportunity_to_work_order((int)($_POST['opportunity_id'] ?? 0));
    } elseif ($action === 'apply_assigned_email_event') {
        $result = field_ops_apply_assigned_email_event_to_work_order((int)($_POST['email_event_id'] ?? 0));
    } elseif ($action === 'ignore_opportunity') {
        $result = field_ops_ignore_opportunity((int)($_POST['opportunity_id'] ?? 0));
    }

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = match ($action) {
            'save_email_event' => 'FN email parsed and queued.',
            'apply_email_event' => 'FN email applied to opportunity board.',
            'promote_opportunity' => 'Opportunity promoted to REQUESTED W/O.',
            'apply_assigned_email_event' => 'Assigned FN email applied to W/O.',
            'ignore_opportunity' => 'Opportunity ignored.',
            default => 'Saved.',
        };

        header('Location: ' . BASE_URL . '/admin/field_opportunities.php');
        exit;
    }

    $flashError = implode(' ', (array)($result['errors'] ?? ['Action failed.']));
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$summary = field_ops_opportunity_summary();
$events = field_ops_email_events(80);
$opportunities = field_ops_opportunities(200);
$now = date('Y-m-d H:i');

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>FN Opportunities · <?= $h(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      --panel: rgba(255,255,255,.055);
      --line: rgba(255,255,255,.12);
      --text: #eef6ff;
      --muted: rgba(238,246,255,.72);
      --green: #86efac;
      --yellow: #fde68a;
      --red: #fecaca;
    }
    * { box-sizing: border-box; }
    body {
      margin:0;
      font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.14), transparent 28rem),
        linear-gradient(135deg,#081426,#0f2238);
      color:var(--text);
    }
    a { color:#bfdbfe; }
    .page { width:min(100% - 2rem, 1320px); margin:0 auto; padding:28px 0 52px; }
    .topbar { display:flex; justify-content:space-between; gap:14px; align-items:flex-start; margin-bottom:18px; }
    .eyebrow { text-transform:uppercase; letter-spacing:.14em; color:#93c5fd; font-size:12px; font-weight:950; }
    h1 { margin:6px 0 8px; font-size:clamp(34px,5vw,56px); line-height:1; }
    h2 { margin:0 0 12px; font-size:23px; }
    p { color:var(--muted); line-height:1.6; }
    .grid { display:grid; gap:16px; }
    .stats { grid-template-columns:repeat(5,minmax(0,1fr)); }
    .two { grid-template-columns:minmax(0,.8fr) minmax(0,1.2fr); align-items:start; }
    .card { border:1px solid var(--line); background:var(--panel); border-radius:18px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.22); }
    .stat strong { display:block; font-size:28px; line-height:1; margin-bottom:5px; }
    .muted { color:var(--muted); }
    .flash-success,.flash-error { border-radius:14px; padding:12px 14px; margin:12px 0; border:1px solid var(--line); }
    .flash-success { background:rgba(34,197,94,.13); color:var(--green); }
    .flash-error { background:rgba(239,68,68,.13); color:var(--red); }
    label { display:grid; gap:7px; font-size:13px; color:var(--muted); font-weight:850; }
    input,textarea,select { width:100%; border:1px solid var(--line); background:rgba(0,0,0,.22); color:var(--text); border-radius:12px; padding:11px 12px; font:inherit; font-weight:800; }
    textarea { min-height:260px; resize:vertical; font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace; font-size:13px; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .full { grid-column:1 / -1; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:9px 13px; border-radius:999px; border:1px solid rgba(147,197,253,.35); color:var(--text); background:rgba(96,165,250,.16); text-decoration:none; font-weight:900; cursor:pointer; }
    .btn-primary { background:linear-gradient(135deg,#3b82f6,#60a5fa); color:white; }
    .btn-danger { background:rgba(127,29,29,.24); border-color:rgba(252,165,165,.35); color:#fecaca; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:1220px; }
    th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:#bfdbfe; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    code { display:inline-block; max-width:100%; overflow-wrap:anywhere; padding:4px 7px; border-radius:8px; background:rgba(0,0,0,.28); color:#dbeafe; }
    .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:5px 9px; font-size:12px; font-weight:950; letter-spacing:.04em; background:rgba(147,197,253,.1); white-space:nowrap; }
    .green { color:var(--green); background:rgba(34,197,94,.12); }
    .yellow { color:var(--yellow); background:rgba(250,204,21,.10); }
    .red { color:var(--red); background:rgba(239,68,68,.12); }
    .score { font-size:22px; font-weight:950; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    @media (max-width:1000px) { .stats,.two,.form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
  </style>
</head>
<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS Field Work Radar</div>
      <h1>FN Opportunities</h1>
      <p style="margin:0;">Rate new and routed FieldNation work before it becomes a real W/O. Radar can be noisy. Work orders stay clean.</p>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Back to Field Ops</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/index.php">Back to admin</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="grid stats">
    <div class="card stat"><strong><?= (int)$summary['total'] ?></strong><span class="muted">Open opportunities</span></div>
    <div class="card stat"><strong><?= (int)$summary['routed'] ?></strong><span class="muted">Routed to you</span></div>
    <div class="card stat"><strong><?= (int)$summary['available'] ?></strong><span class="muted">Available/new</span></div>
    <div class="card stat"><strong><?= (int)$summary['request_this'] ?></strong><span class="muted">Request-this ratings</span></div>
    <div class="card stat"><strong><?= $h((string)$summary['avg_score']) ?></strong><span class="muted">Average score</span></div>
  </section>

  <section class="grid two" style="margin-top:16px;">
    <article class="card">
      <h2>Paste FN email</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_email_event">

        <label class="full">
          Subject
          <input name="subject" placeholder="New Work: Ft. Wayne IN 46808, Jul 9 9:00pm" required>
        </label>

        <label>
          Sender
          <input name="sender" value="Field Nation &lt;support@fieldnation.com&gt;">
        </label>

        <label>
          Received at
          <input name="received_at" value="<?= $h($now) ?>">
        </label>

        <label class="full">
          Body text
          <textarea name="body_text" placeholder="Paste copied FN email body text here. Use screenshots for us, copied text for the parser."></textarea>
        </label>

        <div class="full">
          <button class="btn btn-primary" type="submit">Parse email</button>
        </div>
      </form>
    </article>

    <article class="card">
      <h2>Scoring v1</h2>
      <p>Opportunities get rated before they touch the real W/O table.</p>
      <div class="grid">
        <div><span class="badge green">80-100</span> <span class="muted">Request this</span></div>
        <div><span class="badge yellow">65-79</span> <span class="muted">Worth reviewing</span></div>
        <div><span class="badge">45-64</span> <span class="muted">Maybe if schedule is open</span></div>
        <div><span class="badge red">0-44</span> <span class="muted">Skip</span></div>
      </div>
      <p>Scoring considers pay, distance, routed bonus, work type, schedule freshness, and buyer preference.</p>
    </article>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Opportunity board</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Score</th>
            <th>Recommendation</th>
            <th>Status</th>
            <th>W/O</th>
            <th>Buyer / title</th>
            <th>Location</th>
            <th>Schedule</th>
            <th>Pay</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$opportunities): ?>
          <tr><td colspan="9" class="muted">No opportunities yet. Paste a routed or new-work FN email above.</td></tr>
        <?php endif; ?>
        <?php foreach ($opportunities as $op):
          $score = (int)$op['score'];
          $scoreClass = $score >= 80 ? 'green' : ($score >= 65 ? 'yellow' : ($score < 45 ? 'red' : ''));
        ?>
          <tr>
            <td><span class="score <?= $h($scoreClass) ?>"><?= $score ?></span></td>
            <td>
              <strong><?= $h($op['recommendation']) ?></strong>
              <?php
                $breakdown = json_decode((string)($op['score_breakdown_json'] ?? ''), true);
                if (is_array($breakdown) && $breakdown):
              ?>
                <div class="muted" style="margin-top:6px;font-size:12px;line-height:1.45;">
                  <?php foreach ($breakdown as $reason): ?>
                    <div><?= $h($reason) ?></div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td><span class="badge"><?= $h($op['status']) ?></span></td>
            <td><?php if (!empty($op['external_work_order_number'])): ?><code><?= $h($op['external_work_order_number']) ?></code><?php endif; ?></td>
            <td>
              <strong><?= $h($op['buyer_name_snapshot'] ?? 'FieldNation') ?></strong><br>
              <span class="muted"><?= $h($op['title']) ?></span>
            </td>
            <td>
              <?= $h(trim((string)($op['city'] ?? '') . ', ' . (string)($op['state'] ?? '') . ' ' . (string)($op['zip'] ?? ''), ' ,')) ?>
              <?php if ($op['distance_miles'] !== null): ?><br><span class="muted"><?= number_format((float)$op['distance_miles'], 1) ?> mi</span><?php endif; ?>
            </td>
            <td><?= $h(field_ops_datetime_display($op['scheduled_start_at'] ?? '')) ?></td>
            <td>
              <?php if ((float)$op['pay_rate'] > 0): ?>$<?= number_format((float)$op['pay_rate'], 2) ?>/hr<br><?php endif; ?>
              <?php if ((float)$op['estimated_gross'] > 0): ?><strong>$<?= number_format((float)$op['estimated_gross'], 2) ?></strong><?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <?php if (!empty($op['promoted_work_order_id'])): ?>
                  <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$op['promoted_work_order_id'] ?>">Open W/O</a>
                <?php else: ?>
                  <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="promote_opportunity">
                    <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                    <button class="btn btn-primary" type="submit">Promote to W/O</button>
                  </form>
                  <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ignore_opportunity">
                    <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                    <button class="btn btn-danger" type="submit">Ignore</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Email events</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Parsed</th>
            <th>Subject</th>
            <th>W/O</th>
            <th>Buyer / title</th>
            <th>Confidence</th>
            <th>State</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$events): ?>
          <tr><td colspan="7" class="muted">No email events yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($events as $event):
          $status = (string)($event['parsed_status'] ?? 'MESSAGE');
          $badgeClass = in_array($status, ['ASSIGNED'], true) ? 'green' : (in_array($status, ['DECLINED', 'CANCELLED'], true) ? 'red' : 'yellow');
        ?>
          <tr>
            <td><span class="badge <?= $h($badgeClass) ?>"><?= $h($status) ?></span></td>
            <td>
              <strong><?= $h($event['subject']) ?></strong><br>
              <span class="muted"><?= $h($event['sender_name'] ?? '') ?> <?= $h($event['sender_email'] ?? '') ?></span>
            </td>
            <td><?php if (!empty($event['parsed_work_order_number'])): ?><code><?= $h($event['parsed_work_order_number']) ?></code><?php endif; ?></td>
            <td>
              <strong><?= $h($event['parsed_buyer_name'] ?? '') ?></strong><br>
              <span class="muted"><?= $h($event['parsed_title'] ?? '') ?></span>
            </td>
            <td><?= (int)$event['confidence'] ?>%</td>
            <td>
              <?php if (!empty($event['applied_at'])): ?>
                <span class="badge green">Applied</span>
              <?php else: ?>
                <span class="badge yellow">Queued</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <?php if (empty($event['applied_at']) && in_array($status, ['AVAILABLE', 'ROUTED', 'MESSAGE', 'DECLINED'], true)): ?>
                  <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_email_event">
                    <input type="hidden" name="email_event_id" value="<?= (int)$event['email_event_id'] ?>">
                    <button class="btn btn-primary" type="submit">Apply to board</button>
                  </form>
                <?php elseif ($status === 'ASSIGNED' && empty($event['applied_at'])): ?>
                  <form method="post" style="margin:0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_assigned_email_event">
                    <input type="hidden" name="email_event_id" value="<?= (int)$event['email_event_id'] ?>">
                    <button class="btn btn-primary" type="submit">Apply to W/O</button>
                  </form>
                <?php elseif ($status === 'ASSIGNED' && !empty($event['matched_work_order_id'])): ?>
                  <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$event['matched_work_order_id'] ?>">Open W/O</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
</body>
</html>
