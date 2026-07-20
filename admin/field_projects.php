<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();
field_ops_ensure_schema();

$user = current_user() ?: [];
$userId = (int)($user['user_id'] ?? 0);
$h = 'field_ops_h';
$fmtMoney = static fn($value): string => '$' . number_format((float)$value, 2);
$projectId = (int)($_GET['id'] ?? $_POST['project_id'] ?? 0);
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'save_project') {
        $result = field_ops_save_project($_POST, $userId);
    } elseif ($action === 'assign_work_order') {
        $result = field_ops_assign_work_order_to_project($_POST, $userId);
    } elseif ($action === 'remove_work_order') {
        $result = field_ops_remove_work_order_from_project($_POST, $userId);
    } elseif ($action === 'remove_project') {
        $result = field_ops_remove_project($_POST, $userId);
    }

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = match ($action) {
            'save_project' => $projectId > 0
                ? 'Project updated.'
                : 'Project created.',
            'assign_work_order' => 'Work order added to project.',
            'remove_work_order' => 'Work order removed from project.',
            'remove_project' => 'Project recoverably removed.',
            default => 'Saved.',
        };

        if ($action === 'remove_project') {
            header('Location: ' . BASE_URL . '/admin/field_projects.php');
            exit;
        }

        $redirectId = (int)($result['project_id'] ?? $projectId);
        header(
            'Location: ' . BASE_URL . '/admin/field_projects.php?id=' . $redirectId
        );
        exit;
    }

    $flashError = implode(
        ' ',
        (array)($result['errors'] ?? ['Project action failed.'])
    );
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$query = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$projects = field_ops_projects(200, ['q' => $query]);
$buyers = field_ops_buyers();
$project = $projectId > 0 ? field_ops_find_project($projectId) : null;

if ($projectId > 0 && !$project) {
    http_response_code(404);
    echo 'Project not found.';
    exit;
}

$members = $project ? field_ops_project_members($projectId) : [];
$totals = $project ? field_ops_project_totals($projectId) : [];
$allWorkOrders = field_ops_work_orders(500);
$availableWorkOrders = array_values(array_filter(
    $allWorkOrders,
    static fn(array $wo): bool =>
        field_ops_project_for_work_order((int)$wo['work_order_id']) === null
));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Field Ops Projects</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root { color-scheme:dark; --panel:rgba(255,255,255,.055); --line:rgba(255,255,255,.12); --text:#eef6ff; --muted:rgba(238,246,255,.72); --green:#86efac; --red:#fecaca; }
    * { box-sizing:border-box; }
    body { margin:0; font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; background:radial-gradient(circle at top left,rgba(96,165,250,.14),transparent 28rem),linear-gradient(135deg,#081426,#0f2238); color:var(--text); }
    a { color:#bfdbfe; }
    .page { width:min(100% - 2rem,1280px); margin:0 auto; padding:28px 0 48px; }
    .topbar,.actions { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .topbar { justify-content:space-between; margin-bottom:18px; }
    .eyebrow { text-transform:uppercase; letter-spacing:.14em; color:#93c5fd; font-size:12px; font-weight:900; }
    h1 { margin:6px 0 8px; font-size:clamp(30px,5vw,48px); line-height:1; }
    h2 { margin:0 0 12px; font-size:22px; }
    h3 { margin:0 0 6px; }
    p { color:var(--muted); line-height:1.55; }
    .grid { display:grid; gap:16px; }
    .two { grid-template-columns:minmax(280px,.72fr) minmax(0,1.28fr); align-items:start; }
    .stats { grid-template-columns:repeat(6,minmax(0,1fr)); margin:16px 0; }
    .card { border:1px solid var(--line); background:var(--panel); border-radius:18px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.22); }
    .stat-value { font-size:25px; font-weight:950; }
    .stat-label,.muted,.field-hint { color:var(--muted); }
    .stat-label { font-size:12px; }
    .field-hint { font-size:11px; }
    .flash-success,.flash-error { border-radius:14px; padding:12px 14px; margin:12px 0; border:1px solid var(--line); }
    .flash-success { background:rgba(34,197,94,.13); color:var(--green); }
    .flash-error { background:rgba(239,68,68,.13); color:var(--red); }
    label { display:grid; gap:7px; font-size:13px; color:var(--muted); font-weight:800; }
    input,textarea,select { width:100%; border:1px solid var(--line); background:rgba(0,0,0,.22); color:var(--text); border-radius:12px; padding:11px 12px; font:inherit; }
    textarea { min-height:88px; resize:vertical; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .full { grid-column:1 / -1; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:10px 14px; border-radius:999px; border:1px solid rgba(147,197,253,.35); color:var(--text); background:rgba(96,165,250,.16); text-decoration:none; font-weight:900; cursor:pointer; }
    .btn-primary { background:linear-gradient(135deg,#3b82f6,#60a5fa); color:white; }
    .btn-danger { background:rgba(127,29,29,.24); border-color:rgba(252,165,165,.35); color:#fecaca; }
    .project-list { display:grid; gap:10px; }
    .project-row { display:block; border:1px solid var(--line); border-radius:14px; padding:13px; text-decoration:none; background:rgba(0,0,0,.14); }
    .project-row.active { border-color:#60a5fa; background:rgba(59,130,246,.16); }
    .project-meta { display:flex; gap:8px 14px; flex-wrap:wrap; margin-top:6px; color:var(--muted); font-size:12px; }
    .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:4px 8px; font-size:11px; font-weight:950; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:900px; }
    th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:#bfdbfe; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    code { display:inline-block; padding:4px 7px; border-radius:8px; background:rgba(0,0,0,.26); color:#dbeafe; }
    body select option,body select optgroup { background-color:#f8fafc !important; color:#0f172a !important; font-size:15px !important; font-weight:900 !important; }
    @media (max-width:900px) { .two,.stats { grid-template-columns:1fr 1fr; } }
    @media (max-width:620px) { .two,.stats,.form-grid { grid-template-columns:1fr; } .full { grid-column:auto; } }
  </style>
</head>
<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS field work</div>
      <h1>Projects</h1>
      <p style="margin:0;">Group related days and sites while every work order keeps its own operational and financial lifecycle.</p>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Back to Field Ops</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="grid two">
    <aside class="card">
      <div class="topbar" style="margin-bottom:12px;">
        <h2 style="margin:0;">Project list</h2>
        <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_projects.php">New</a>
      </div>

      <form method="get" class="actions" style="margin-bottom:14px;">
        <input name="q" value="<?= $h($query) ?>" placeholder="Project, buyer, parent ref" style="flex:1 1 190px;">
        <button class="btn" type="submit">Search</button>
      </form>

      <div class="project-list">
        <?php if (!$projects): ?><p class="muted">No projects found.</p><?php endif; ?>
        <?php foreach ($projects as $row): $rowTotals = (array)($row['totals'] ?? []); ?>
          <a class="project-row <?= (int)$row['project_id'] === $projectId ? 'active' : '' ?>" href="<?= $h(BASE_URL) ?>/admin/field_projects.php?id=<?= (int)$row['project_id'] ?>">
            <h3><?= $h($row['project_name']) ?></h3>
            <div><span class="badge"><?= $h($row['project_status']) ?></span></div>
            <div class="project-meta">
              <span><?= (int)($rowTotals['work_order_count'] ?? 0) ?> W/O</span>
              <span><?= $fmtMoney($rowTotals['gross'] ?? 0) ?> gross</span>
              <span><?= $fmtMoney($rowTotals['estimated_net'] ?? 0) ?> net</span>
            </div>
            <?php if (!empty($row['external_reference'])): ?><div class="project-meta"><code><?= $h($row['external_reference']) ?></code></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </aside>

    <div class="grid">
      <section class="card">
        <h2><?= $project ? 'Project details' : 'Create project' ?></h2>
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_project">
          <input type="hidden" name="project_id" value="<?= (int)($project['project_id'] ?? 0) ?>">

          <label class="full">Project name<input name="project_name" required value="<?= $h((string)($project['project_name'] ?? '')) ?>" placeholder="BPI Fort Wayne IT conversion"></label>
          <label>Platform<input name="platform" value="<?= $h((string)($project['platform'] ?? 'FieldNation')) ?>"></label>
          <label>Buyer<select name="buyer_id"><option value="">No buyer selected</option><?php foreach ($buyers as $buyer): ?><option value="<?= (int)$buyer['buyer_id'] ?>" <?= (int)$buyer['buyer_id'] === (int)($project['buyer_id'] ?? 0) ? 'selected' : '' ?>><?= $h($buyer['platform']) ?> · <?= $h($buyer['buyer_name']) ?></option><?php endforeach; ?></select></label>
          <label>Parent reference<input name="external_reference" value="<?= $h((string)($project['external_reference'] ?? '')) ?>" placeholder="SR-04417 / WO-07958"></label>
          <label>Status<select name="project_status"><?php foreach (field_ops_project_statuses() as $status): ?><option value="<?= $h($status) ?>" <?= $status === (string)($project['project_status'] ?? 'ACTIVE') ? 'selected' : '' ?>><?= $h(ucfirst(strtolower($status))) ?></option><?php endforeach; ?></select></label>
          <label class="full">Notes<textarea name="notes" placeholder="Tracks Keith/MMIT work only; overall scope or release notes."><?= $h((string)($project['notes'] ?? '')) ?></textarea></label>
          <div class="full actions"><button class="btn btn-primary" type="submit"><?= $project ? 'Save project' : 'Create project' ?></button></div>
        </form>
      </section>

      <?php if ($project): ?>
        <section class="grid stats">
          <article class="card"><div class="stat-value"><?= (int)($totals['work_order_count'] ?? 0) ?></div><div class="stat-label">Work orders</div></article>
          <article class="card"><div class="stat-value"><?= $fmtMoney($totals['gross'] ?? 0) ?></div><div class="stat-label">Gross</div></article>
          <article class="card"><div class="stat-value"><?= $fmtMoney($totals['fees'] ?? 0) ?></div><div class="stat-label">Fees</div></article>
          <article class="card"><div class="stat-value"><?= $fmtMoney(($totals['mileage_cost'] ?? 0) + ($totals['material_cost'] ?? 0) + ($totals['expense_cost'] ?? 0)) ?></div><div class="stat-label">Costs</div></article>
          <article class="card"><div class="stat-value"><?= $fmtMoney($totals['estimated_net'] ?? 0) ?></div><div class="stat-label">Est. net</div></article>
          <article class="card"><div class="stat-value"><?= $fmtMoney($totals['effective_hourly'] ?? 0) ?></div><div class="stat-label">Effective hourly</div></article>
        </section>

        <section class="card">
          <h2>Add work order</h2>
          <?php if ($availableWorkOrders): ?>
            <form method="post" class="form-grid">
              <?= csrf_field() ?><input type="hidden" name="action" value="assign_work_order"><input type="hidden" name="project_id" value="<?= (int)$projectId ?>">
              <label class="full">Work order<select name="work_order_id" required><option value="">Choose ungrouped W/O</option><?php foreach ($availableWorkOrders as $wo): ?><option value="<?= (int)$wo['work_order_id'] ?>"><?= $h($wo['external_work_order_number'] ?? 'No W/O #') ?> · <?= $h($wo['title']) ?></option><?php endforeach; ?></select></label>
              <label>Sequence<input name="sequence_number" inputmode="numeric" placeholder="<?= count($members) + 1 ?>"></label>
              <label>Day / site label<input name="member_label" placeholder="Day <?= count($members) + 1 ?> · Go-live support"></label>
              <label class="full">Membership notes<textarea name="notes" placeholder="Note specific to this project placement."></textarea></label>
              <div class="full"><button class="btn btn-primary" type="submit">Add W/O to project</button></div>
            </form>
          <?php else: ?><p class="muted">Every active W/O is already assigned to a project.</p><?php endif; ?>
        </section>

        <section class="card">
          <h2>Project work orders</h2>
          <div class="table-wrap"><table><thead><tr><th>Order</th><th>W/O</th><th>Schedule</th><th>Status</th><th>Gross</th><th>Net</th><th>Time</th><th>Actions</th></tr></thead><tbody>
          <?php if (!$members): ?><tr><td colspan="8" class="muted">No W/Os in this project yet.</td></tr><?php endif; ?>
          <?php foreach ($members as $member): $memberTotals = (array)($member['totals'] ?? []); ?>
            <tr>
              <td><strong><?= $h($member['member_label'] ?? ('Item ' . (string)($member['sequence_number'] ?? '—'))) ?></strong><?php if (!empty($member['member_notes'])): ?><div class="field-hint"><?= $h($member['member_notes']) ?></div><?php endif; ?></td>
              <td><a href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$member['work_order_id'] ?>"><?= $h($member['external_work_order_number'] ?? 'Open W/O') ?></a><div class="field-hint"><?= $h($member['title']) ?></div></td>
              <td><?= empty($member['scheduled_start_at']) ? '—' : $h(field_ops_datetime_display($member['scheduled_start_at'])) ?></td>
              <td><span class="badge"><?= $h($member['status']) ?></span></td>
              <td><?= $fmtMoney($memberTotals['gross'] ?? 0) ?></td>
              <td><?= $fmtMoney($memberTotals['estimated_net'] ?? 0) ?></td>
              <td><?= number_format((float)($memberTotals['total_hours'] ?? 0), 2) ?> hrs</td>
              <td><form method="post"><input type="hidden" name="action" value="remove_work_order"><input type="hidden" name="project_id" value="<?= (int)$projectId ?>"><input type="hidden" name="work_order_id" value="<?= (int)$member['work_order_id'] ?>"><input type="hidden" name="delete_reason" value="Removed from project dashboard."><?= csrf_field() ?><button class="btn btn-danger" type="submit">Remove</button></form></td>
            </tr>
          <?php endforeach; ?>
          </tbody></table></div>
        </section>

        <section class="card">
          <h2>Remove project</h2>
          <p class="muted">This recoverably removes the project and its active memberships. Individual work orders remain untouched.</p>
          <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="remove_project"><input type="hidden" name="project_id" value="<?= (int)$projectId ?>"><label class="full">Reason<input name="delete_reason" value="Project cancelled or created in error."></label><div class="full"><button class="btn btn-danger" type="submit">Remove project</button></div></form>
        </section>
      <?php endif; ?>
    </div>
  </section>
</main>
</body>
</html>