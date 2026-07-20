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
    } elseif ($action === 'import_fieldnation_mailbox') {
        $limit = max(1, min(100, (int)($_POST['limit'] ?? 25)));
        $result = field_ops_import_fieldnation_mailbox(["limit" => $limit]);
    } elseif ($action === 'apply_email_event') {
        $result = field_ops_apply_email_event_to_opportunity((int)($_POST['email_event_id'] ?? 0));
    } elseif ($action === 'promote_opportunity') {
        $result = field_ops_promote_opportunity_to_work_order((int)($_POST['opportunity_id'] ?? 0));
    } elseif ($action === 'apply_assigned_email_event') {
        $result = field_ops_apply_assigned_email_event_to_work_order((int)($_POST['email_event_id'] ?? 0));
    } elseif ($action === 'apply_declined_email_event') {
        $result = field_ops_apply_declined_email_event((int)($_POST['email_event_id'] ?? 0));
    } elseif ($action === 'ignore_opportunity') {
        $result = field_ops_ignore_opportunity((int)($_POST['opportunity_id'] ?? 0));
    } elseif ($action === 'watch_opportunity') {
        $result = field_ops_watch_opportunity((int)($_POST['opportunity_id'] ?? 0));
    } elseif ($action === 'unwatch_opportunity') {
        $result = field_ops_unwatch_opportunity((int)($_POST['opportunity_id'] ?? 0));
    } elseif ($action === 'update_opportunity_profitability') {
        $result = field_ops_update_opportunity_profitability($_POST);
    } elseif ($action === 'rescore_opportunities') {
        $result = field_ops_rescore_opportunities(500);
    }

    if (!empty($result['ok'])) {
        if ($action === 'import_fieldnation_mailbox') {
            $_SESSION['flash_msg'] = sprintf(
                'FieldNation mailbox import complete. Imported: %d. Duplicates: %d. Skipped: %d.',
                (int)($result['imported'] ?? 0),
                (int)($result['duplicates'] ?? 0),
                (int)($result['skipped'] ?? 0)
            );
        } else {
        $_SESSION['flash_msg'] = match ($action) {
            'save_email_event' => 'FN email parsed and queued.',
            'apply_email_event' => 'FN email applied to opportunity board.',
            'promote_opportunity' => 'Opportunity promoted to REQUESTED W/O.',
            'apply_assigned_email_event' => 'Assigned FN email applied to W/O.',
            'apply_declined_email_event' => 'Declined FN email processed.',
            'ignore_opportunity' => 'Opportunity ignored.',
            'watch_opportunity' => 'Opportunity marked as watching.',
            'unwatch_opportunity' => 'Opportunity returned to available.',
            'update_opportunity_profitability' => 'Profitability assumptions saved and opportunity rescored.',
            'rescore_opportunities' => 'Active opportunities rescored using real profitability.',
            default => 'Saved.',
        };
        }

        $returnParams = [];

        $returnQuery = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
        $returnSort = strtolower((string)($_GET['sort'] ?? 'date'));
        $returnOpportunityFilter = strtolower((string)($_GET['opportunity_filter'] ?? 'all'));
        $returnEventFilter = strtolower((string)($_GET['event_filter'] ?? 'all'));

        if ($returnQuery !== '') {
            $returnParams['q'] = $returnQuery;
        }

        if (in_array($returnSort, ['date', 'score'], true)) {
            $returnParams['sort'] = $returnSort;
        }

        if ($returnOpportunityFilter !== '') {
            $returnParams['opportunity_filter'] = $returnOpportunityFilter;
        }

        if ($returnEventFilter !== '') {
            $returnParams['event_filter'] = $returnEventFilter;
        }

        $updatedOpportunityId = (int)($_POST['opportunity_id'] ?? 0);

        if (
            $action === 'update_opportunity_profitability'
            && $updatedOpportunityId > 0
        ) {
            $returnParams['open_opportunity'] = $updatedOpportunityId;
        }

        $returnAnchor = $action === 'update_opportunity_profitability'
            && $updatedOpportunityId > 0
            ? '#opportunity-' . $updatedOpportunityId
            : match ($action) {
                'promote_opportunity',
                'ignore_opportunity',
                'watch_opportunity',
                'unwatch_opportunity' => '#opportunity-board-filters',
                'rescore_opportunities' => '#opportunity-board-filters',

                'apply_email_event',
                'apply_assigned_email_event',
                'apply_declined_email_event' => '#email-event-filters',

                default => '',
            };

        $returnUrl = BASE_URL . '/admin/field_opportunities.php';

        if ($returnParams) {
            $returnUrl .= '?' . http_build_query($returnParams);
        }

        header('Location: ' . $returnUrl . $returnAnchor);
        exit;
    }

    $flashError = implode(' ', (array)($result['errors'] ?? ['Action failed.']));
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$searchQuery = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$openOpportunityId = max(0, (int)($_GET['open_opportunity'] ?? 0));
$opportunitySort = strtolower((string)($_GET['sort'] ?? 'date'));

if (!in_array($opportunitySort, ['date', 'score'], true)) {
    $opportunitySort = 'date';
}

$summary = field_ops_opportunity_summary();
$allEvents = field_ops_email_events(80);
$allOpportunities = field_ops_opportunities(200, [
    'q' => $searchQuery,
    'sort' => $opportunitySort,
]);

$opportunityFilters = [
    'all' => 'All',
    'routed' => 'Routed',
    'request' => 'Request this',
    'watching' => 'Watching',
    'review' => 'Review',
    'skip' => 'Skip',
];

$opportunityFilter = strtolower((string)($_GET['opportunity_filter'] ?? 'all'));
if (!array_key_exists($opportunityFilter, $opportunityFilters)) {
    $opportunityFilter = 'all';
}

$opportunities = array_values(array_filter($allOpportunities, static function (array $op) use ($opportunityFilter): bool {
    $status = strtoupper((string)($op['status'] ?? 'AVAILABLE'));
    $score = (int)($op['score'] ?? 0);
    $recommendation = strtolower(trim((string)($op['recommendation'] ?? '')));

    return match ($opportunityFilter) {
        'routed' => $status === 'ROUTED',
        'request' => $recommendation === 'request this' || $score >= 80,
        'watching' => $status === 'WATCHING',
        'review' => $score >= 45 && $score < 80,
        'skip' => $recommendation === 'skip' || $score < 45,
        default => true,
    };
}));

$opportunityCount = count($opportunities);
$totalOpportunityCount = (int)($summary['total'] ?? count($allOpportunities));

$eventFilters = [
    'all' => 'All',
    'queued' => 'Queued',
    'applied' => 'Applied',
    'assigned' => 'Assigned',
    'declined' => 'Declined',
    'available' => 'Available/Routed',
];

$eventFilter = strtolower((string)($_GET['event_filter'] ?? 'all'));
if (!array_key_exists($eventFilter, $eventFilters)) {
    $eventFilter = 'all';
}

$events = array_values(array_filter($allEvents, static function (array $event) use ($eventFilter): bool {
    $status = strtoupper((string)($event['parsed_status'] ?? 'MESSAGE'));
    $applied = !empty($event['applied_at']);

    return match ($eventFilter) {
        'queued' => !$applied,
        'applied' => $applied,
        'assigned' => $status === 'ASSIGNED',
        'declined' => in_array($status, ['DECLINED', 'CANCELLED'], true),
        'available' => in_array($status, ['AVAILABLE', 'ROUTED', 'MESSAGE'], true),
        default => true,
    };
}));

$eventCount = count($events);
$totalEventCount = count($allEvents);
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
    .btn-primary {
      background:linear-gradient(135deg,#3b82f6,#60a5fa);
      border-color:rgba(147,197,253,.55);
      color:white;
    }
    .btn-danger {
      background:rgba(127,29,29,.24);
      border-color:rgba(252,165,165,.35);
      color:#fecaca;
    }
    .btn-table {
      min-height:32px;
      padding:6px 10px;
      font-size:12px;
      white-space:nowrap;
    }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:1220px; }
    th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:#bfdbfe; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    code { display:inline-block; max-width:100%; overflow-wrap:anywhere; padding:4px 7px; border-radius:8px; background:rgba(0,0,0,.28); color:#dbeafe; }
    .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:5px 9px; font-size:12px; font-weight:950; letter-spacing:.04em; background:rgba(147,197,253,.1); white-space:nowrap; }
    .badge-routed { color:#c4b5fd; border-color:rgba(196,181,253,.45); background:rgba(124,58,237,.18); }
    .badge-available { color:var(--yellow); border-color:rgba(250,204,21,.35); background:rgba(250,204,21,.10); }
    .badge-watching { color:#bfdbfe; border-color:rgba(147,197,253,.35); background:rgba(59,130,246,.12); }
    .badge-requested { color:var(--green); border-color:rgba(34,197,94,.35); background:rgba(34,197,94,.12); }
    .op-context { margin-top:7px; font-size:12px; line-height:1.45; }
    .green { color:var(--green); background:rgba(34,197,94,.12); }
    .yellow { color:var(--yellow); background:rgba(250,204,21,.10); }
    .red { color:var(--red); background:rgba(239,68,68,.12); }
    .score { font-size:22px; font-weight:950; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .filter-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:10px 0 14px; }
    .board-tools {
      display:grid;
      grid-template-columns:minmax(220px,1fr) minmax(150px,220px) auto auto;
      gap:10px;
      align-items:end;
      margin:14px 0;
    }
    .board-tools .btn { min-height:43px; }
    .search-match td { background:rgba(34,197,94,.10); }
    .search-match td:first-child { box-shadow:inset 4px 0 0 #22c55e; }
OLD,
    'Search styling'
);

$page = $replaceOnce(
    $page,
    <<<'OLD'
    @media (max-width:1000px) { .stats,.two,.form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
OLD,
    <<<'NEW'
    @media (max-width:1000px) {
      .stats,.two,.form-grid { grid-template-columns:1fr; }
      .board-tools { grid-template-columns:1fr 1fr; }
      .full { grid-column:auto; }
    }
    @media (max-width:620px) {
      .board-tools { grid-template-columns:1fr; }
    }
    .filter-pill { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:7px 12px; border-radius:999px; border:1px solid rgba(147,197,253,.28); color:#bfdbfe; background:rgba(15,23,42,.42); text-decoration:none; font-weight:950; font-size:13px; }
    .filter-pill.active { color:white; border-color:rgba(96,165,250,.7); background:linear-gradient(135deg,rgba(37,99,235,.84),rgba(96,165,250,.7)); }
    #opportunity-board-filters,
    #email-event-filters { scroll-margin-top:24px; }
    .action-note { flex-basis:100%; font-size:12px; line-height:1.4; }
    html { scroll-behavior:smooth; }
    .op-detail-row td { padding-top:0; background:rgba(15,23,42,.32); }
    .email-event-row { scroll-margin-top:24px; }
    .email-event-row:target td { background:rgba(59,130,246,.16); box-shadow:inset 4px 0 0 #60a5fa; }
    .op-detail { border:1px solid rgba(147,197,253,.18); border-radius:16px; padding:12px 14px; background:rgba(2,6,23,.22); }
    .op-detail summary { cursor:pointer; color:#bfdbfe; font-weight:950; letter-spacing:.02em; }
    .op-detail[open] summary { margin-bottom:12px; }
    .detail-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-top:10px; }
    .detail-item { border:1px solid rgba(147,197,253,.16); border-radius:14px; padding:10px; background:rgba(15,23,42,.34); min-width:0; }
    .detail-item strong { display:block; color:#bfdbfe; font-size:11px; text-transform:uppercase; letter-spacing:.08em; margin-bottom:5px; }
    .detail-notes { white-space:pre-wrap; overflow-wrap:anywhere; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px; }
    @media (max-width:1000px) { .stats,.two,.form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
    .profit-quick strong { display:block; color:#86efac; font-size:16px; }
    .profit-quick .weak { color:#fecaca; }
    .profit-quick .target { color:#fde68a; }
    .profit-grid {
      display:grid;
      grid-template-columns:repeat(4,minmax(0,1fr));
      gap:10px;
      margin:12px 0;
    }
    .profit-metric {
      border:1px solid rgba(134,239,172,.2);
      border-radius:14px;
      padding:11px;
      background:rgba(20,83,45,.12);
      min-width:0;
    }
    .profit-metric span { display:block; color:var(--muted); font-size:11px; margin-bottom:4px; }
    .profit-metric strong { display:block; font-size:18px; overflow-wrap:anywhere; }
    .profit-metric .weak { color:#fecaca; }
    .profit-metric .target { color:#fde68a; }
    .profit-form {
      display:grid;
      grid-template-columns:repeat(3,minmax(0,1fr));
      gap:10px;
      margin-top:12px;
      padding:14px;
      border:1px solid rgba(96,165,250,.22);
      border-radius:16px;
      background:rgba(15,23,42,.38);
    }
    .profit-form .check-label {
      display:flex;
      align-items:center;
      gap:9px;
      min-height:43px;
    }
    .profit-form .check-label input { width:auto; }
    .profit-note { grid-column:1 / -1; font-size:12px; line-height:1.5; }
    @media (max-width:1000px) {
      .profit-grid,.profit-form { grid-template-columns:repeat(2,minmax(0,1fr)); }
    }
    @media (max-width:620px) {
      .profit-grid,.profit-form { grid-template-columns:1fr; }
      .profit-note { grid-column:auto; }
    }
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
      <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="import_fieldnation_mailbox">
        <label class="muted" for="fn-import-limit">Mailbox limit</label>
        <input
          id="fn-import-limit"
          name="limit"
          type="number"
          min="1"
          max="100"
          value="25"
          style="width:86px"
          aria-label="FieldNation mailbox import limit"
        >
        <button class="btn btn-primary" type="submit">Import from FN mailbox</button>
      </form>
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
      <h2>Profitability scoring v2</h2>
      <p>OPS estimates real door-to-door income before an opportunity touches the W/O table.</p>
      <div class="grid">
        <div><span class="badge green">80-100</span> <span class="muted">Request this</span></div>
        <div><span class="badge yellow">65-79</span> <span class="muted">Worth reviewing</span></div>
        <div><span class="badge">45-64</span> <span class="muted">Maybe if schedule is open</span></div>
        <div><span class="badge red">0-44</span> <span class="muted">Skip</span></div>
      </div>
      <p>Scoring now uses provider, GL and optional OAI fees, round-trip mileage cost, drive time, onsite time, real net, target hourly, routed bonus, work type, schedule freshness, and buyer preference.</p>
    </article>
  </section>

  <section class="card" style="margin-top:16px;">
    <div class="actions" style="justify-content:space-between;align-items:center;">
      <h2 style="margin:0;">Opportunity board</h2>
      <form method="post" style="margin:0;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rescore_opportunities">
        <button class="btn" type="submit">Rescore active opportunities</button>
      </form>
    </div>
    <p class="muted" style="margin-top:-4px;">
      Showing <?= (int)$opportunityCount ?> of <?= (int)$totalOpportunityCount ?> active opportunities.
      <?php if ($searchQuery !== ''): ?>
        Search: <strong><?= $h($searchQuery) ?></strong>.
      <?php endif; ?>
    </p>

    <form method="get" class="board-tools" role="search">
      <input type="hidden" name="opportunity_filter" value="<?= $h($opportunityFilter) ?>">
      <input type="hidden" name="event_filter" value="<?= $h($eventFilter) ?>">

      <label>
        Find W/O or opportunity
        <input
          type="search"
          name="q"
          value="<?= $h($searchQuery) ?>"
          placeholder="W/O #, buyer, title, city, state"
          autocomplete="off"
        >
      </label>

      <label>
        Sort opportunities
        <select name="sort">
          <option value="date" <?= $opportunitySort === 'date' ? 'selected' : '' ?>>Job date</option>
          <option value="score" <?= $opportunitySort === 'score' ? 'selected' : '' ?>>Score</option>
        </select>
      </label>

      <button class="btn btn-primary" type="submit">Search</button>

      <a
        class="btn"
        href="<?= $h(BASE_URL . '/admin/field_opportunities.php?' . http_build_query([
            'sort' => 'date',
            'opportunity_filter' => $opportunityFilter,
            'event_filter' => $eventFilter,
        ])) ?>#opportunity-board-filters"
      >Clear</a>
    </form>

    <nav id="opportunity-board-filters" class="filter-bar" aria-label="Opportunity board filters">
      <?php foreach ($opportunityFilters as $filterKey => $filterLabel): ?>
        <a
          class="filter-pill <?= $opportunityFilter === $filterKey ? 'active' : '' ?>"
          href="<?= $h(BASE_URL . '/admin/field_opportunities.php?' . http_build_query([
              'q' => $searchQuery,
              'sort' => $opportunitySort,
              'opportunity_filter' => $filterKey,
              'event_filter' => $eventFilter,
          ])) ?>#opportunity-board-filters"
          aria-current="<?= $opportunityFilter === $filterKey ? 'page' : 'false' ?>"
        ><?= $h($filterLabel) ?></a>
      <?php endforeach; ?>
    </nav>

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
            <th>Real profit</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$opportunities): ?>
          <tr>
            <td colspan="10" class="muted">
              <?php if ($searchQuery !== ''): ?>
                No active opportunities match “<?= $h($searchQuery) ?>”.
              <?php elseif ($totalOpportunityCount > 0): ?>
                No opportunities match the <?= $h($opportunityFilters[$opportunityFilter] ?? 'selected') ?> filter.
              <?php else: ?>
                No opportunities yet. Import from the FN mailbox or paste a routed/new-work email above.
              <?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($opportunities as $op):
          $score = (int)$op['score'];
          $scoreClass = $score >= 80 ? 'green' : ($score >= 65 ? 'yellow' : ($score < 45 ? 'red' : ''));
          $status = strtoupper((string)($op['status'] ?? 'AVAILABLE'));
          $statusBadgeClass = match ($status) {
              'ROUTED' => 'badge-routed',
              'AVAILABLE' => 'badge-available',
              'WATCHING' => 'badge-watching',
              'REQUESTED' => 'badge-requested',
              default => '',
          };
          $profit = (array)($op['profitability'] ?? []);
          $effectiveHourly = (float)($profit['effective_hourly'] ?? 0);
          $targetHourly = (float)($profit['target_hourly'] ?? 35);
          $profitClass = $effectiveHourly >= $targetHourly
              ? ''
              : ($effectiveHourly >= ($targetHourly * .8) ? 'target' : 'weak');
          $isExactSearchMatch = $searchQuery !== ''
              && strcasecmp(
                  trim((string)($op['external_work_order_number'] ?? '')),
                  $searchQuery
              ) === 0;
        ?>
          <tr class="<?= $isExactSearchMatch ? 'search-match' : '' ?>">
            <td><span class="score <?= $h($scoreClass) ?>"><?= $score ?></span></td>
            <td>
              <strong><?= $h($op['recommendation']) ?></strong>
              <?php
                $breakdown = json_decode((string)($op['score_breakdown_json'] ?? ''), true);
              ?>
              <div class="muted op-context">Open details for score evidence.</div>
            </td>
            <td>
              <span class="badge <?= $h($statusBadgeClass) ?>"><?= $h($status) ?></span>
              <?php if ($status === 'ROUTED'): ?>
                <div class="muted op-context">Routed to you. Not accepted, assigned, or calendar-blocking.</div>
              <?php elseif ($status === 'AVAILABLE'): ?>
                <div class="muted op-context">Public/new opportunity. Safe to review before request.</div>
              <?php endif; ?>
            </td>
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
            <td class="profit-quick">
              <?php if (!empty($profit['complete'])): ?>
                <strong class="<?= $h($profitClass) ?>">$<?= number_format((float)$profit['effective_hourly'], 2) ?>/hr</strong>
                <span class="muted">$<?= number_format((float)$profit['estimated_net'], 2) ?> net</span>
              <?php else: ?>
                <strong class="target">Needs assumptions</strong>
                <span class="muted">Open details</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="actions">
                <?php if (!empty($op['promoted_work_order_id'])): ?>
                  <a class="btn btn-table" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$op['promoted_work_order_id'] ?>">Open W/O</a>
                  <span class="badge badge-requested">REQUESTED W/O</span>
                <?php else: ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Create a REQUESTED W/O only? This will not accept, assign, schedule, or block your calendar.&quot;);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="promote_opportunity">
                    <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                    <button class="btn btn-primary btn-table" type="submit">Create REQUESTED W/O</button>
                  </form>
                  <?php if ($status === 'WATCHING'): ?>
                    <form method="post" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="unwatch_opportunity">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                      <button class="btn btn-table" type="submit">Return to Available</button>
                    </form>
                  <?php elseif ($status !== 'ROUTED'): ?>
                    <form method="post" style="margin:0;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="watch_opportunity">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                      <button class="btn btn-table" type="submit">Watch</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Ignore this opportunity and remove it from the active radar?&quot;);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="ignore_opportunity">
                    <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                    <button class="btn btn-danger btn-table" type="submit">Ignore</button>
                  </form>
                  <div class="muted action-note">Creates a request-stage record only. It does not accept, assign, schedule, or block your calendar.</div>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <tr class="op-detail-row">
            <td colspan="10">
              <details
                id="opportunity-<?= (int)$op['opportunity_id'] ?>"
                class="op-detail"
                <?= $openOpportunityId === (int)$op['opportunity_id'] ? 'open' : '' ?>
              >
                <summary>View opportunity details</summary>

                <div class="detail-grid">
                  <div class="detail-item">
                    <strong>Work type</strong>
                    <?= $h((string)($op['work_type'] ?? 'Field service')) ?>
                  </div>
                  <div class="detail-item">
                    <strong>Source event</strong>
                    <?php if (!empty($op['source_email_event_id'])): ?>
                      <a href="#email-event-<?= (int)$op['source_email_event_id'] ?>">#<?= (int)$op['source_email_event_id'] ?></a>
                    <?php else: ?>
                      <span class="muted">None linked</span>
                    <?php endif; ?>
                  </div>
                  <div class="detail-item">
                    <strong>Created</strong>
                    <?= $h(field_ops_datetime_display($op['created_at'] ?? '')) ?>
                  </div>
                  <div class="detail-item">
                    <strong>Updated</strong>
                    <?= $h(field_ops_datetime_display($op['updated_at'] ?? '')) ?>
                  </div>

                  <div class="detail-item">
                    <strong>Schedule start</strong>
                    <?= $h(field_ops_datetime_display($op['scheduled_start_at'] ?? '')) ?>
                  </div>
                  <div class="detail-item">
                    <strong>Schedule end</strong>
                    <?= $h(field_ops_datetime_display($op['scheduled_end_at'] ?? '')) ?>
                  </div>
                  <div class="detail-item">
                    <strong>Pay model</strong>
                    <?php if ((float)$op['pay_rate'] > 0): ?>
                      $<?= number_format((float)$op['pay_rate'], 2) ?>/hr
                    <?php else: ?>
                      <span class="muted">Rate unknown</span>
                    <?php endif; ?>
                    <?php if ((float)$op['max_hours'] > 0): ?>
                      <br><span class="muted"><?= number_format((float)$op['max_hours'], 2) ?> max hrs</span>
                    <?php endif; ?>
                  </div>
                  <div class="detail-item">
                    <strong>Estimated gross</strong>
                    <?php if ((float)$op['estimated_gross'] > 0): ?>
                      $<?= number_format((float)$op['estimated_gross'], 2) ?>
                    <?php else: ?>
                      <span class="muted">Unknown</span>
                    <?php endif; ?>
                  </div>

                  <div class="detail-item full">
                    <strong>Real profitability</strong>
                    <div class="profit-grid">
                      <div class="profit-metric"><span>Estimated gross</span><strong>$<?= number_format((float)($profit['gross'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>Provider fee</span><strong>-$<?= number_format((float)($profit['platform_fee'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>GL insurance</span><strong>-$<?= number_format((float)($profit['insurance_fee'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>OAI fee</span><strong>-$<?= number_format((float)($profit['oai_fee'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>Round-trip mileage</span><strong><?= $profit['round_trip_miles'] !== null ? number_format((float)$profit['round_trip_miles'], 1) . ' mi' : 'Unknown' ?></strong></div>
                      <div class="profit-metric"><span>Vehicle cost</span><strong>-$<?= number_format((float)($profit['mileage_cost'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>Drive + onsite</span><strong><?= number_format((float)($profit['total_hours'] ?? 0), 2) ?> hrs</strong></div>
                      <div class="profit-metric"><span>Real net</span><strong>$<?= number_format((float)($profit['estimated_net'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>Door-to-door hourly</span><strong class="<?= $h($profitClass) ?>">$<?= number_format((float)($profit['effective_hourly'] ?? 0), 2) ?>/hr</strong></div>
                      <div class="profit-metric"><span>Target hourly</span><strong>$<?= number_format((float)($profit['target_hourly'] ?? 35), 2) ?>/hr</strong></div>
                      <div class="profit-metric"><span>Suggested counter gross</span><strong>$<?= number_format((float)($profit['counteroffer_gross'] ?? 0), 2) ?></strong></div>
                      <div class="profit-metric"><span>Suggested increase</span><strong>$<?= number_format((float)($profit['counteroffer_increase'] ?? 0), 2) ?></strong></div>
                    </div>

                    <form method="post" class="profit-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="update_opportunity_profitability">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">

                      <label>Target hourly<input name="profit_target_hourly" inputmode="decimal" value="<?= number_format((float)($op['profit_target_hourly'] ?? 35), 2, '.', '') ?>"></label>
                      <label>Mileage rate<input name="profit_mileage_rate" inputmode="decimal" value="<?= number_format((float)($op['profit_mileage_rate'] ?? .67), 4, '.', '') ?>"></label>
                      <label>Average driving MPH<input name="profit_average_mph" inputmode="decimal" value="<?= number_format((float)($op['profit_average_mph'] ?? 55), 2, '.', '') ?>"></label>
                      <label>Expected onsite hours<input name="profit_onsite_hours" inputmode="decimal" value="<?= $op['profit_onsite_hours'] !== null ? number_format((float)$op['profit_onsite_hours'], 2, '.', '') : '' ?>" placeholder="Uses FN max hours"></label>
                      <label>Round-trip miles<input name="profit_round_trip_miles" inputmode="decimal" value="<?= $op['profit_round_trip_miles'] !== null ? number_format((float)$op['profit_round_trip_miles'], 2, '.', '') : '' ?>" placeholder="2 × FN distance"></label>
                      <label>Round-trip drive minutes<input name="profit_drive_minutes" inputmode="numeric" value="<?= $op['profit_drive_minutes'] !== null ? (int)$op['profit_drive_minutes'] : '' ?>" placeholder="Calculated from miles / MPH"></label>
                      <label class="check-label"><input type="checkbox" name="profit_oai_applies" value="1" <?= !empty($op['profit_oai_applies']) ? 'checked' : '' ?>> OAI applies (0.5%)</label>
                      <div class="profit-note muted">Blank onsite, mileage, or drive overrides use the values parsed from FieldNation. Counteroffer gross targets your selected hourly rate after fees and mileage cost.</div>
                      <div><button class="btn btn-primary" type="submit">Save assumptions &amp; rescore</button></div>
                    </form>
                  </div>

                  <?php
                    $fieldNationUrl = field_ops_fn_work_order_url(
                        (string)($op['external_work_order_number'] ?? ''),
                        (string)($op['source_url'] ?? '')
                    );
                  ?>
                  <div class="detail-item full">
                    <strong>FieldNation</strong>
                    <?php if ($fieldNationUrl !== null): ?>
                      <a
                        class="btn"
                        href="<?= $h($fieldNationUrl) ?>"
                        target="_blank"
                        rel="noopener noreferrer"
                      >Open on FieldNation</a>
                    <?php else: ?>
                      <span class="muted">No FieldNation W/O link captured.</span>
                    <?php endif; ?>
                  </div>

                  <?php
                    $displayNotes = field_ops_fn_email_snapshot(
                        (string)($op['notes'] ?? ''),
                        5000
                    );
                  ?>
                  <div class="detail-item full">
                    <strong>Notes</strong>
                    <?php if ($displayNotes !== ''): ?>
                      <div class="detail-notes"><?= $h($displayNotes) ?></div>
                    <?php else: ?>
                      <span class="muted">No notes captured yet.</span>
                    <?php endif; ?>
                  </div>

                  <div class="detail-item full">
                    <strong>Score breakdown</strong>
                    <?php if (is_array($breakdown ?? null) && $breakdown): ?>
                      <div class="muted" style="line-height:1.55;">
                        <?php foreach ($breakdown as $reason): ?>
                          <div><?= $h($reason) ?></div>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <span class="muted">No score breakdown available.</span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="detail-actions">
                  <?php if (!empty($op['promoted_work_order_id'])): ?>
                    <a class="btn btn-table" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$op['promoted_work_order_id'] ?>">Open W/O</a>
                    <span class="badge badge-requested">REQUESTED W/O</span>
                  <?php else: ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Create a REQUESTED W/O only? This will not accept, assign, schedule, or block your calendar.&quot;);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="promote_opportunity">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                      <button class="btn btn-primary btn-table" type="submit">Create REQUESTED W/O</button>
                    </form>
                    <?php if ($status === 'WATCHING'): ?>
                      <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="unwatch_opportunity">
                        <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                        <button class="btn btn-table" type="submit">Return to Available</button>
                      </form>
                    <?php elseif ($status !== 'ROUTED'): ?>
                      <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="watch_opportunity">
                        <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                        <button class="btn btn-table" type="submit">Watch</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Ignore this opportunity and remove it from the active radar?&quot;);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="ignore_opportunity">
                      <input type="hidden" name="opportunity_id" value="<?= (int)$op['opportunity_id'] ?>">
                      <button class="btn btn-danger btn-table" type="submit">Ignore</button>
                    </form>
                    <div class="muted action-note">Still request-stage only. No accept, assign, schedule, or calendar block happens here.</div>
                  <?php endif; ?>
                </div>
              </details>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Email events</h2>
    <p class="muted" style="margin-top:-4px;">
      Showing <?= (int)$eventCount ?> of <?= (int)$totalEventCount ?> email events.
    </p>

    <nav id="email-event-filters" class="filter-bar" aria-label="Email event filters">
      <?php foreach ($eventFilters as $filterKey => $filterLabel): ?>
        <a
          class="filter-pill <?= $eventFilter === $filterKey ? 'active' : '' ?>"
          href="<?= $h(BASE_URL . '/admin/field_opportunities.php?' . http_build_query([
              'q' => $searchQuery,
              'sort' => $opportunitySort,
              'opportunity_filter' => $opportunityFilter,
              'event_filter' => $filterKey,
          ])) ?>#email-event-filters"
          aria-current="<?= $eventFilter === $filterKey ? 'page' : 'false' ?>"
        ><?= $h($filterLabel) ?></a>
      <?php endforeach; ?>
    </nav>

    <div id="email-events" class="table-wrap">
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
          <tr>
            <td colspan="7" class="muted">
              <?php if ($totalEventCount > 0): ?>
                No email events match the <?= $h($eventFilters[$eventFilter] ?? 'selected') ?> filter.
              <?php else: ?>
                No email events yet.
              <?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($events as $event):
          $status = (string)($event['parsed_status'] ?? 'MESSAGE');
          $badgeClass = in_array($status, ['ASSIGNED'], true) ? 'green' : (in_array($status, ['DECLINED', 'CANCELLED'], true) ? 'red' : ($status === 'ROUTED' ? 'badge-routed' : 'yellow'));
        ?>
          <tr id="email-event-<?= (int)$event['email_event_id'] ?>" class="email-event-row">
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
                <?php if (empty($event['applied_at']) && in_array($status, ['AVAILABLE', 'ROUTED', 'MESSAGE'], true)): ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Apply this queued FieldNation email to the opportunity board?&quot;);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_email_event">
                    <input type="hidden" name="email_event_id" value="<?= (int)$event['email_event_id'] ?>">
                    <button class="btn btn-primary btn-table" type="submit">Apply to board</button>
                  </form>
                <?php elseif ($status === 'DECLINED' && empty($event['applied_at'])): ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Process this declined FieldNation email and update any matching records?&quot;);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_declined_email_event">
                    <input type="hidden" name="email_event_id" value="<?= (int)$event['email_event_id'] ?>">
                    <button class="btn btn-danger btn-table" type="submit">Process decline</button>
                  </form>
                <?php elseif ($status === 'ASSIGNED' && empty($event['applied_at'])): ?>
                  <form method="post" style="margin:0;" onsubmit="return confirm(&quot;Apply this assigned FieldNation email to the real W/O table?&quot;);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_assigned_email_event">
                    <input type="hidden" name="email_event_id" value="<?= (int)$event['email_event_id'] ?>">
                    <button class="btn btn-primary btn-table" type="submit">Apply to W/O</button>
                  </form>
                <?php elseif ($status === 'ASSIGNED' && !empty($event['matched_work_order_id'])): ?>
                  <a class="btn btn-table" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$event['matched_work_order_id'] ?>">Open W/O</a>
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
