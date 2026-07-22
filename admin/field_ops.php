<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';

require_login();

field_ops_ensure_schema();
field_ops_seed_defaults();

$user = current_user() ?: [];
$h = 'field_ops_h';

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'save_buyer') {
        $result = field_ops_save_buyer($_POST);
    } elseif ($action === 'save_inventory_item') {
        $result = field_ops_save_inventory_item($_POST);
    } elseif ($action === 'save_work_order') {
        $result = field_ops_save_work_order($_POST, (int)($user['user_id'] ?? 0));
    } elseif ($action === 'discard_work_order') {
        $result = field_ops_discard_work_order((int)($_POST['work_order_id'] ?? 0), (string)($_POST['delete_reason'] ?? 'Rejected, withdrawn, or no longer needed.'));
    }

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = match ($action) {
            'save_buyer' => 'Buyer saved.',
            'save_inventory_item' => 'Inventory item added.',
            'save_work_order' => 'Work order added.',
            'discard_work_order' => 'Work order discarded.',
            default => 'Saved.',
        };

        $returnParams = [];
        $returnQuery = substr(trim((string)($_GET['q'] ?? '')), 0, 120);
        $returnFilter = strtolower((string)($_GET['work_order_filter'] ?? 'all'));

        if ($returnQuery !== '') {
            $returnParams['q'] = $returnQuery;
        }

        if ($returnFilter !== '') {
            $returnParams['work_order_filter'] = $returnFilter;
        }

        $returnUrl = BASE_URL . '/admin/field_ops.php';

        if ($returnParams) {
            $returnUrl .= '?' . http_build_query($returnParams);
        }

        $returnAnchor = in_array(
            $action,
            ['save_work_order', 'discard_work_order'],
            true
        ) ? '#work-order-filters' : '';

        header('Location: ' . $returnUrl . $returnAnchor);
        exit;
    }

    $flashError = implode(' ', (array)($result['errors'] ?? ['Save failed.']));
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$workOrderSearch = substr(trim((string)($_GET['q'] ?? '')), 0, 120);

$summary = field_ops_summary();
$buyers = field_ops_buyers();
$items = field_ops_inventory_items();
$allWorkOrders = field_ops_work_orders(100, [
    'q' => $workOrderSearch,
]);

$workOrderFilters = [
    'all' => 'All',
    'today' => 'Today',
    'upcoming' => 'Upcoming',
    'needs_attention' => 'Needs attention',
    'requested' => 'Requested',
    'assigned' => 'Assigned',
    'scheduled' => 'Scheduled',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'ready_to_submit' => 'Ready to submit',
    'awaiting_approval' => 'Awaiting approval',
    'payment_pending' => 'Payment pending',
    'payment_overdue' => 'Payment overdue',
    'unpaid' => 'Unpaid',
];

$workOrderFilter = strtolower((string)($_GET['work_order_filter'] ?? 'all'));
if (!array_key_exists($workOrderFilter, $workOrderFilters)) {
    $workOrderFilter = 'all';
}

$todayStart = new DateTimeImmutable('today');
$tomorrowStart = $todayStart->modify('+1 day');

$workOrders = array_values(array_filter($allWorkOrders, static function (array $wo) use ($workOrderFilter, $todayStart, $tomorrowStart): bool {
    $status = strtoupper((string)($wo['status'] ?? ''));
    $paymentStatus = strtoupper((string)($wo['payment_status'] ?? ''));
    $scheduledStartRaw = trim((string)($wo['scheduled_start_at'] ?? ''));
    $scheduledStart = null;

    if ($scheduledStartRaw !== '') {
        try {
            $scheduledStart = new DateTimeImmutable($scheduledStartRaw);
        } catch (Throwable $e) {
            $scheduledStart = null;
        }
    }

    $terminalStatuses = [
        'RELEASED_BY_LEAD',
        'CHECKED_OUT',
        'SUBMITTED',
        'APPROVED',
        'PAID',
        'CANCELLED',
        'DECLINED',
    ];

    $isTerminal = in_array($status, $terminalStatuses, true);
    $isToday = $scheduledStart !== null
        && $scheduledStart >= $todayStart
        && $scheduledStart < $tomorrowStart;
    $isUpcoming = $scheduledStart !== null
        && $scheduledStart >= $tomorrowStart
        && !$isTerminal;
    $needsAttention = (
        in_array($status, ['REQUESTED', 'ASSIGNED'], true)
        && $scheduledStart === null
    ) || (
        $scheduledStart !== null
        && $scheduledStart < $todayStart
        && !$isTerminal
    );

    $receivableState = (string)(
        field_ops_receivable_state($wo)['state']
        ?? 'ACTIVE_WORK'
    );

    return match ($workOrderFilter) {
        'today' => $isToday,
        'upcoming' => $isUpcoming,
        'needs_attention' => $needsAttention,
        'requested' => $status === 'REQUESTED',
        'assigned' => $status === 'ASSIGNED',
        'scheduled' => $status === 'SCHEDULED',
        'in_progress' => in_array($status, ['CHECKED_IN', 'IN_PROGRESS'], true),
        'completed' => in_array($status, ['RELEASED_BY_LEAD', 'CHECKED_OUT', 'SUBMITTED', 'APPROVED', 'PAID'], true),
        'ready_to_submit' => $receivableState === 'READY_TO_SUBMIT',
        'awaiting_approval' => $receivableState === 'AWAITING_APPROVAL',
        'payment_pending' => in_array(
            $receivableState,
            [
                'PAYMENT_TERMS_REVIEW',
                'PAYMENT_PENDING',
                'PAYMENT_DUE_SOON',
            ],
            true
        ),
        'payment_overdue' => $receivableState === 'PAYMENT_OVERDUE',
        'unpaid' => $paymentStatus !== 'PAID',
        default => true,
    };
}));

$workOrderSortMeta = static function (array $wo) use ($todayStart, $tomorrowStart): array {
    $status = strtoupper((string)($wo['status'] ?? ''));
    $scheduledStartRaw = trim((string)($wo['scheduled_start_at'] ?? ''));
    $scheduledStart = null;

    if ($scheduledStartRaw !== '') {
        try {
            $scheduledStart = new DateTimeImmutable($scheduledStartRaw);
        } catch (Throwable $e) {
            $scheduledStart = null;
        }
    }

    $terminalStatuses = [
        'RELEASED_BY_LEAD',
        'CHECKED_OUT',
        'SUBMITTED',
        'APPROVED',
        'PAID',
        'CANCELLED',
        'DECLINED',
    ];

    $isTerminal = in_array($status, $terminalStatuses, true);

    if (
        !$isTerminal
        && $scheduledStart !== null
        && $scheduledStart < $todayStart
    ) {
        $bucket = 0;
    } elseif (
        !$isTerminal
        && in_array($status, ['REQUESTED', 'ASSIGNED'], true)
        && $scheduledStart === null
    ) {
        $bucket = 1;
    } elseif (
        !$isTerminal
        && $scheduledStart !== null
        && $scheduledStart >= $todayStart
        && $scheduledStart < $tomorrowStart
    ) {
        $bucket = 2;
    } elseif (
        !$isTerminal
        && $scheduledStart !== null
        && $scheduledStart >= $tomorrowStart
    ) {
        $bucket = 3;
    } elseif (!$isTerminal) {
        $bucket = 4;
    } else {
        $bucket = 5;
    }

    $statusPriority = match ($status) {
        'ASSIGNED' => 0,
        'REQUESTED' => 1,
        default => 2,
    };

    $updatedAt = strtotime((string)($wo['updated_at'] ?? '')) ?: 0;

    return [
        'bucket' => $bucket,
        'scheduled_at' => $scheduledStart?->getTimestamp() ?? PHP_INT_MAX,
        'status_priority' => $statusPriority,
        'updated_at' => $updatedAt,
    ];
};

usort($workOrders, static function (array $leftWo, array $rightWo) use ($workOrderSortMeta): int {
    $left = $workOrderSortMeta($leftWo);
    $right = $workOrderSortMeta($rightWo);

    if ($left['bucket'] !== $right['bucket']) {
        return $left['bucket'] <=> $right['bucket'];
    }

    if (
        $left['bucket'] === 1
        && $left['status_priority'] !== $right['status_priority']
    ) {
        return $left['status_priority'] <=> $right['status_priority'];
    }

    if ($left['bucket'] === 5) {
        if ($left['updated_at'] !== $right['updated_at']) {
            return $right['updated_at'] <=> $left['updated_at'];
        }
    } elseif ($left['scheduled_at'] !== $right['scheduled_at']) {
        return $left['scheduled_at'] <=> $right['scheduled_at'];
    }

    return (int)$rightWo['work_order_id'] <=> (int)$leftWo['work_order_id'];
});

$workOrderCount = count($workOrders);
$totalWorkOrderCount = (int)(
    $summary['total_work_orders']
    ?? count($allWorkOrders)
);

$scheduleIntelligence = [
    'today' => 0,
    'upcoming' => 0,
    'needs_attention' => 0,
    'unpaid' => 0,
];

$receivableIntelligence = [
    'ready_to_submit' => 0,
    'awaiting_approval' => 0,
    'payment_pending' => 0,
    'payment_overdue' => 0,
];

foreach ($allWorkOrders as $wo) {
    $status = strtoupper((string)($wo['status'] ?? ''));
    $paymentStatus = strtoupper((string)($wo['payment_status'] ?? ''));

    $receivableState = (string)(
        field_ops_receivable_state($wo)['state']
        ?? 'ACTIVE_WORK'
    );

    $scheduledStartRaw = trim((string)($wo['scheduled_start_at'] ?? ''));
    $scheduledStart = null;

    if ($scheduledStartRaw !== '') {
        try {
            $scheduledStart = new DateTimeImmutable($scheduledStartRaw);
        } catch (Throwable $e) {
            $scheduledStart = null;
        }
    }

    $terminalStatuses = [
        'RELEASED_BY_LEAD',
        'CHECKED_OUT',
        'SUBMITTED',
        'APPROVED',
        'PAID',
        'CANCELLED',
        'DECLINED',
    ];

    $isTerminal = in_array($status, $terminalStatuses, true);

    if ($scheduledStart !== null && $scheduledStart >= $todayStart && $scheduledStart < $tomorrowStart) {
        $scheduleIntelligence['today']++;
    }

    if ($scheduledStart !== null && $scheduledStart >= $tomorrowStart && !$isTerminal) {
        $scheduleIntelligence['upcoming']++;
    }

    if (
        (
            in_array($status, ['REQUESTED', 'ASSIGNED'], true)
            && $scheduledStart === null
        )
        || (
            $scheduledStart !== null
            && $scheduledStart < $todayStart
            && !$isTerminal
        )
    ) {
        $scheduleIntelligence['needs_attention']++;
    }

    if ($paymentStatus !== 'PAID') {
        $scheduleIntelligence['unpaid']++;
    }

    if ($receivableState === 'READY_TO_SUBMIT') {
        $receivableIntelligence['ready_to_submit']++;
    } elseif ($receivableState === 'AWAITING_APPROVAL') {
        $receivableIntelligence['awaiting_approval']++;
    } elseif (
        in_array(
            $receivableState,
            [
                'PAYMENT_TERMS_REVIEW',
                'PAYMENT_PENDING',
                'PAYMENT_DUE_SOON',
            ],
            true
        )
    ) {
        $receivableIntelligence['payment_pending']++;
    } elseif ($receivableState === 'PAYMENT_OVERDUE') {
        $receivableIntelligence['payment_overdue']++;
    }
}

$filterIntelligence = (
    $scheduleIntelligence
    + $receivableIntelligence
);

$discardedWorkOrders = field_ops_discarded_work_orders(10);

$fmtMoney = static fn($value): string => '$' . number_format((float)$value, 2);
$fmtHours = static fn($value): string => number_format((float)$value, 1) . ' hrs';
$moneyInput = static fn($value = 0): string => field_ops_money_input_value($value);
$dtInput = static fn($value = ''): string => field_ops_datetime_input_value($value);
$dtDisplay = static fn($value = ''): string => field_ops_datetime_display($value);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Field Ops · <?= $h(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      --bg: #081426;
      --panel: rgba(255,255,255,.055);
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
        radial-gradient(circle at top left, rgba(96,165,250,.14), transparent 28rem),
        linear-gradient(135deg, #081426, #0f2238);
      color: var(--text);
    }
    a { color: #bfdbfe; }
    .page { width: min(100% - 2rem, 1220px); margin: 0 auto; padding: 28px 0 48px; }
    .topbar { display: flex; gap: 12px; justify-content: space-between; align-items: center; margin-bottom: 18px; }
    .eyebrow { text-transform: uppercase; letter-spacing: .14em; color: #93c5fd; font-size: 12px; font-weight: 900; }
    h1 { margin: 6px 0 8px; font-size: clamp(32px, 5vw, 52px); line-height: 1; }
    h2 { margin: 0 0 12px; font-size: 22px; }
    h3 { margin: 0 0 8px; font-size: 17px; }
    p { color: var(--muted); line-height: 1.6; }
    .grid { display: grid; gap: 16px; }
    .stats { grid-template-columns: repeat(5, minmax(0, 1fr)); margin: 18px 0; }
    .forms { grid-template-columns: minmax(0, 1.25fr) minmax(0, .9fr); align-items: start; }
    .side-stack { display: grid; gap: 16px; }
    .card {
      border: 1px solid var(--line);
      background: var(--panel);
      border-radius: 18px;
      padding: 18px;
      box-shadow: 0 20px 60px rgba(0,0,0,.22);
    }
    .stat-value { font-size: 28px; font-weight: 950; }
    .stat-label { color: var(--muted); font-size: 13px; }

    .stat-card-total {
      border-top: 3px solid rgba(96,165,250,.82);
    }

    .stat-card-active {
      border-top: 3px solid rgba(34,211,238,.82);
    }

    .stat-card-gross {
      border-top: 3px solid rgba(167,139,250,.82);
    }

    .stat-card-net {
      border-top: 3px solid rgba(74,222,128,.82);
    }

    .stat-card-stock {
      border-top: 3px solid rgba(251,146,60,.82);
    }

    .attention-grid {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin: 18px 0 0;
    }

    .attention-card {
      position: relative;
      display: grid;
      gap: 5px;
      min-width: 0;
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 16px 18px;
      background: var(--panel);
      color: var(--text);
      text-decoration: none;
      box-shadow: 0 20px 60px rgba(0,0,0,.22);
      transition:
        transform .16s ease,
        border-color .16s ease,
        background .16s ease;
    }

    .attention-card:hover,
    .attention-card:focus-visible {
      transform: translateY(-2px);
      border-color: rgba(147,197,253,.58);
      background: rgba(30,41,59,.94);
      outline: none;
    }

    .attention-card.active {
      border-color: rgba(96,165,250,.78);
      box-shadow:
        0 20px 60px rgba(0,0,0,.22),
        0 0 0 2px rgba(96,165,250,.16);
    }

    .attention-card-today {
      border-top: 3px solid rgba(96,165,250,.82);
    }

    .attention-card-upcoming {
      border-top: 3px solid rgba(167,139,250,.82);
    }

    .attention-card-needs-attention {
      border-top: 3px solid rgba(251,113,133,.88);
    }

    .attention-card-unpaid {
      border-top: 3px solid rgba(250,204,21,.84);
    }

    .receivable-grid {
      grid-template-columns: repeat(4, minmax(0, 1fr));
      margin: 0 0 18px;
    }

    .receivable-card-ready {
      border-top: 3px solid rgba(45,212,191,.84);
    }

    .receivable-card-approval {
      border-top: 3px solid rgba(167,139,250,.84);
    }

    .receivable-card-pending {
      border-top: 3px solid rgba(56,189,248,.84);
    }

    .receivable-card-overdue {
      border-top: 3px solid rgba(248,113,113,.9);
    }

    .attention-value {
      font-size: clamp(28px, 4vw, 38px);
      line-height: 1;
      font-weight: 950;
    }

    .attention-label {
      font-size: 12px;
      font-weight: 950;
      letter-spacing: .08em;
      text-transform: uppercase;
    }

    .attention-description {
      color: var(--muted);
      font-size: 12px;
      line-height: 1.35;
    }

    .flash-success, .flash-error {
      border-radius: 14px;
      padding: 12px 14px;
      margin: 12px 0;
      border: 1px solid var(--line);
    }
    .flash-success { background: rgba(34,197,94,.13); color: var(--green); }
    .flash-error { background: rgba(239,68,68,.13); color: var(--red); }
    label { display: grid; gap: 7px; font-size: 13px; color: var(--muted); font-weight: 800; }
    input, textarea, select {
      width: 100%;
      border: 1px solid var(--line);
      background: rgba(0,0,0,.22);
      color: var(--text);
      border-radius: 12px;
      padding: 11px 12px;
      font: inherit;
    }
    textarea { min-height: 88px; resize: vertical; }
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
      font-weight: 900;
      cursor: pointer;
    }
    .btn-primary {
      background: linear-gradient(135deg, #3b82f6, #60a5fa);
      border-color: rgba(147,197,253,.55);
      color: white;
    }
    .btn-danger {
      background: rgba(127,29,29,.24);
      border-color: rgba(252,165,165,.35);
      color: #fecaca;
    }
    .btn-table {
      min-height: 32px;
      padding: 6px 10px;
      font-size: 12px;
      white-space: nowrap;
    }
    .action-group {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
      align-items: center;
    }
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
      font-weight: 950;
      letter-spacing: .04em;
      background: rgba(147,197,253,.1);
    }
    .status-paid { color: var(--green); background: rgba(34,197,94,.12); }
    .status-unpaid { color: var(--yellow); background: rgba(250,204,21,.1); }

    .schedule-cue {
      display: inline-flex;
      align-items: center;
      min-height: 20px;
      margin-top: 5px;
      padding: 2px 6px;
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 10px;
      font-weight: 950;
      letter-spacing: .05em;
      white-space: nowrap;
    }
    .schedule-cue-attention {
      color: #fecdd3;
      border-color: rgba(251,113,133,.46);
      background: rgba(159,18,57,.22);
    }
    .schedule-cue-overdue {
      color: #fecaca;
      border-color: rgba(248,113,113,.5);
      background: rgba(153,27,27,.26);
    }
    .schedule-cue-today {
      color: #fed7aa;
      border-color: rgba(251,146,60,.5);
      background: rgba(194,65,12,.22);
    }
    .schedule-cue-tomorrow {
      color: #fef08a;
      border-color: rgba(250,204,21,.45);
      background: rgba(161,98,7,.2);
    }
    .schedule-cue-upcoming {
      color: #bfdbfe;
      border-color: rgba(96,165,250,.4);
      background: rgba(37,99,235,.16);
    }

    .wo-status-routed {
      color: #c4b5fd;
      border-color: rgba(196,181,253,.45);
      background: rgba(124,58,237,.18);
    }
    .wo-status-requested {
      color: #bfdbfe;
      border-color: rgba(96,165,250,.45);
      background: rgba(37,99,235,.18);
    }
    .wo-status-assigned {
      color: #a5f3fc;
      border-color: rgba(34,211,238,.42);
      background: rgba(8,145,178,.18);
    }
    .wo-status-scheduled {
      color: #fde68a;
      border-color: rgba(250,204,21,.42);
      background: rgba(202,138,4,.16);
    }
    .wo-status-checked-in {
      color: #fdba74;
      border-color: rgba(251,146,60,.45);
      background: rgba(194,65,12,.18);
    }
    .wo-status-in-progress {
      color: #fed7aa;
      border-color: rgba(249,115,22,.55);
      background: rgba(234,88,12,.24);
    }
    .wo-status-released,
    .wo-status-checked-out {
      color: #99f6e4;
      border-color: rgba(45,212,191,.42);
      background: rgba(13,148,136,.18);
    }
    .wo-status-submitted {
      color: #ddd6fe;
      border-color: rgba(167,139,250,.42);
      background: rgba(109,40,217,.18);
    }
    .wo-status-approved {
      color: #bbf7d0;
      border-color: rgba(74,222,128,.42);
      background: rgba(22,163,74,.18);
    }
    .wo-status-paid {
      color: #86efac;
      border-color: rgba(34,197,94,.55);
      background: rgba(21,128,61,.28);
    }
    .wo-status-cancelled {
      color: #cbd5e1;
      border-color: rgba(148,163,184,.32);
      background: rgba(71,85,105,.24);
    }
    .wo-status-declined {
      color: #fecaca;
      border-color: rgba(248,113,113,.45);
      background: rgba(153,27,27,.24);
    }
    .low-stock { color: var(--red); font-weight: 950; }
    .muted { color: var(--muted); }
    .field-hint { color: var(--muted); font-size: 11px; margin-top: -4px; }
    .actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
    .money-pairs {
      display: flex;
      flex-wrap: wrap;
      gap: 4px 12px;
      margin: 0;
    }
    .money-pairs > div {
      display: inline-grid;
      grid-template-columns: auto auto;
      gap: 4px;
      align-items: baseline;
      white-space: nowrap;
    }
    .money-pairs dt {
      color: var(--muted);
      font-size: 11px;
      font-weight: 800;
    }
    .money-pairs dd {
      margin: 0;
      color: var(--text);
      font-weight: 850;
    }
    .filter-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:10px 0 14px; }
    .work-order-search {
      display:grid;
      grid-template-columns:minmax(260px,1fr) auto auto;
      gap:10px;
      align-items:end;
      margin:14px 0;
    }
    .work-order-search .btn { min-height:43px; }
    .work-order-search-match td { background:rgba(34,197,94,.10); }
    .work-order-search-match td:first-child {
      box-shadow:inset 4px 0 0 #22c55e;
    }
    .filter-pill { display:inline-flex; align-items:center; justify-content:center; min-height:34px; padding:7px 12px; border-radius:999px; border:1px solid rgba(147,197,253,.28); color:#bfdbfe; background:rgba(15,23,42,.42); text-decoration:none; font-weight:950; font-size:13px; }
    .filter-pill.active { color:white; border-color:rgba(96,165,250,.7); background:linear-gradient(135deg,rgba(37,99,235,.84),rgba(96,165,250,.7)); }
    .filter-count {
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width:20px;
      min-height:20px;
      margin-left:5px;
      padding:1px 5px;
      border-radius:999px;
      background:rgba(0,0,0,.28);
      font-size:11px;
      font-weight:950;
    }
    #work-order-filters { scroll-margin-top:24px; }
    @media (max-width: 1000px) {
      .stats,
      .attention-grid,
      .receivable-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .forms, .form-grid { grid-template-columns: 1fr; }
      .work-order-search { grid-template-columns:1fr auto auto; }
      .full { grid-column: auto; }
    }
    @media (max-width: 620px) {
      .stats,
      .attention-grid,
      .receivable-grid { grid-template-columns: 1fr; }
      .topbar { align-items: flex-start; flex-direction: column; }
      .work-order-search { grid-template-columns:1fr; }
    }
  
    /* Final native dropdown readability override */
    body select,
    body select:focus {
      background-color: #0b1626 !important;
      color: #eef6ff !important;
      color-scheme: light !important;
      font-weight: 900 !important;
    }

    body select option,
    body select optgroup {
      background-color: #f8fafc !important;
      color: #0f172a !important;
      font-size: 15px !important;
      font-weight: 900 !important;
      line-height: 1.8 !important;
      text-shadow: none !important;
    }

    body select option:checked {
      background-color: #60a5fa !important;
      color: #06101f !important;
      font-weight: 950 !important;
    }

  </style>
</head>
<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS field work</div>
      <h1>Field Ops</h1>
      <p style="margin:0;">Track FieldNation-style work orders, field inventory, job costs, and real net income.</p>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_opportunities.php">FN opportunities</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_projects.php">Projects</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_vehicles.php">Service vehicles</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_rideshare.php">Lyft performance</a>
  <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_receipts.php">Receipts</a>
  <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_expense_drafts.php">Expense drafts</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/index.php">Back to admin</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <section class="grid attention-grid" aria-label="Field Ops attention summary">
    <a
      class="attention-card attention-card-today <?= $workOrderFilter === 'today' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=today#work-order-filters"
      aria-current="<?= $workOrderFilter === 'today' ? 'page' : 'false' ?>"
    >
      <span class="attention-value"><?= (int)$scheduleIntelligence['today'] ?></span>
      <span class="attention-label">Today</span>
      <span class="attention-description">Scheduled for today</span>
    </a>

    <a
      class="attention-card attention-card-upcoming <?= $workOrderFilter === 'upcoming' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=upcoming#work-order-filters"
      aria-current="<?= $workOrderFilter === 'upcoming' ? 'page' : 'false' ?>"
    >
      <span class="attention-value"><?= (int)$scheduleIntelligence['upcoming'] ?></span>
      <span class="attention-label">Upcoming</span>
      <span class="attention-description">Future scheduled work</span>
    </a>

    <a
      class="attention-card attention-card-needs-attention <?= $workOrderFilter === 'needs_attention' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=needs_attention#work-order-filters"
      aria-current="<?= $workOrderFilter === 'needs_attention' ? 'page' : 'false' ?>"
    >
      <span class="attention-value"><?= (int)$scheduleIntelligence['needs_attention'] ?></span>
      <span class="attention-label">Needs attention</span>
      <span class="attention-description">Missing schedule or overdue</span>
    </a>

    <a
      class="attention-card attention-card-unpaid <?= $workOrderFilter === 'unpaid' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=unpaid#work-order-filters"
      aria-current="<?= $workOrderFilter === 'unpaid' ? 'page' : 'false' ?>"
    >
      <span class="attention-value"><?= (int)$scheduleIntelligence['unpaid'] ?></span>
      <span class="attention-label">Unpaid</span>
      <span class="attention-description">Payment not marked paid</span>
    </a>
  </section>

  <section class="grid stats">
    <article class="card stat-card-total"><div class="stat-value"><?= (int)$summary['total_work_orders'] ?></div><div class="stat-label">Total W/O</div></article>
    <article class="card stat-card-active"><div class="stat-value"><?= (int)$summary['active_work_orders'] ?></div><div class="stat-label">Active W/O</div></article>
    <article class="card stat-card-gross"><div class="stat-value"><?= $fmtMoney($summary['gross_total']) ?></div><div class="stat-label">Gross tracked</div></article>
    <article class="card stat-card-net"><div class="stat-value"><?= $fmtMoney($summary['estimated_net_total']) ?></div><div class="stat-label">Estimated net</div></article>
    <article class="card stat-card-stock"><div class="stat-value"><?= (int)$summary['low_stock_count'] ?></div><div class="stat-label">Low stock items</div></article>
  </section>

  <section class="grid receivable-grid" aria-label="Field Ops receivable summary">
    <a
      class="attention-card receivable-card-ready <?= $workOrderFilter === 'ready_to_submit' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=ready_to_submit#work-order-filters"
    >
      <span class="attention-value"><?= (int)$receivableIntelligence['ready_to_submit'] ?></span>
      <span class="attention-label">Ready to submit</span>
      <span class="attention-description">Field work complete</span>
    </a>

    <a
      class="attention-card receivable-card-approval <?= $workOrderFilter === 'awaiting_approval' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=awaiting_approval#work-order-filters"
    >
      <span class="attention-value"><?= (int)$receivableIntelligence['awaiting_approval'] ?></span>
      <span class="attention-label">Awaiting approval</span>
      <span class="attention-description">Submitted to buyer</span>
    </a>

    <a
      class="attention-card receivable-card-pending <?= $workOrderFilter === 'payment_pending' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=payment_pending#work-order-filters"
    >
      <span class="attention-value"><?= (int)$receivableIntelligence['payment_pending'] ?></span>
      <span class="attention-label">Payment pending</span>
      <span class="attention-description">Review, scheduled, or due soon</span>
    </a>

    <a
      class="attention-card receivable-card-overdue <?= $workOrderFilter === 'payment_overdue' ? 'active' : '' ?>"
      href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=payment_overdue#work-order-filters"
    >
      <span class="attention-value"><?= (int)$receivableIntelligence['payment_overdue'] ?></span>
      <span class="attention-label">Payment overdue</span>
      <span class="attention-description">Expected payment date passed</span>
    </a>
  </section>

  <section class="grid forms">
    <article class="card">
      <h2>Add work order</h2>
      <form method="post" class="form-grid" id="field-ops-add-work-order">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_work_order">

        <label>
          Job source
          <select name="platform">
            <?php foreach (field_ops_sources() as $source): ?>
              <option value="<?= $h($source) ?>" <?= $source === 'FieldNation' ? 'selected' : '' ?>><?= $h($source) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Buyer
          <select name="buyer_id">
            <option value="">No buyer selected</option>
            <?php foreach ($buyers as $buyer): ?>
              <option value="<?= (int)$buyer['buyer_id'] ?>"><?= $h($buyer['platform']) ?> · <?= $h($buyer['buyer_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          External W/O #
          <input name="external_work_order_number" placeholder="FN work order number">
        </label>

        <label class="full">
          Title
          <input name="title" placeholder="HME cabling support · Fort Wayne" required>
        </label>

        <label>
          Work type
          <input name="work_type" value="QSR cabling / POS support">
        </label>

        <label>
          Status
          <select name="status">
            <?php foreach (field_ops_work_statuses() as $status): ?>
              <option value="<?= $h($status) ?>" <?= $status === 'REQUESTED' ? 'selected' : '' ?>><?= $h(str_replace('_', ' ', ucfirst(strtolower($status)))) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Site name
          <input name="site_name" placeholder="Store / site name">
        </label>

        <label>
          City
          <input name="city" placeholder="Fort Wayne">
        </label>

        <label>
          State
          <input name="state" value="IN">
        </label>

        <label>
          Scheduled start
          <input type="text" name="scheduled_start_at" placeholder="2026-07-06 14:00"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>
          Scheduled end
          <input type="text" name="scheduled_end_at" placeholder="2026-07-06 18:00"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>
          Gross pay
          <input name="gross_pay" inputmode="decimal" value="<?= $h($moneyInput(0)) ?>" placeholder="$325.00">
        </label>

        <label>
          Authorized hourly rate
          <input name="authorized_rate" inputmode="decimal" placeholder="$65.00">
        </label>

        <label>
          Maximum authorized hours
          <input name="authorized_hours" inputmode="decimal" placeholder="10.00">
        </label>

        <label>
          Provider fee
          <input name="platform_fee" inputmode="decimal" placeholder="Manual fee, usually $0.00">
        </label>

        <label>
          GL insurance fee
          <input name="insurance_fee" inputmode="decimal" placeholder="Auto-estimates 1.95% for FieldNation">
        </label>

        <label style="display:flex;align-items:center;gap:8px;">
          <input
            type="checkbox"
            id="add-oai-applies"
            name="oai_fee_applies"
            value="1"
            style="width:auto;"
          >
          OAI applies (0.5%)
        </label>

        <label>
          OAI fee
          <input
            id="add-oai-fee"
            name="oai_fee"
            inputmode="decimal"
            placeholder="Select OAI to calculate 0.5%"
          >
          <span class="field-hint">Calculated from gross; editable for exact FN reconciliation.</span>
        </label>

        <label>
          Mileage
          <input name="mileage" inputmode="decimal" placeholder="0">
        </label>

        <label>
          Mileage rate
          <input name="mileage_rate" inputmode="decimal" value="0.6700" placeholder="0.6700">
        </label>

        <label>
          Drive minutes
          <input name="drive_minutes" inputmode="numeric" placeholder="0">
        </label>

        <label>
          Onsite minutes
          <input name="onsite_minutes" inputmode="numeric" placeholder="0">
        </label>

        <label class="full">
          Site address
          <input name="site_address" placeholder="Address, if useful">
        </label>

        <label class="full">
          Notes
          <textarea name="notes" placeholder="Scope, lead tech, check-in/out notes, would you take this buyer again, etc."></textarea>
        </label>

        <div class="full">
          <button class="btn btn-primary" type="submit">Add work order</button>
        </div>
      </form>
    </article>

    <aside class="side-stack">
      <article class="card">
        <h2>Add inventory item</h2>
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_inventory_item">

          <label class="full">
            Item name
            <input name="item_name" placeholder="RJ45 push-through ends" required>
          </label>

          <label>
            Category
            <input name="category" value="Cable">
          </label>

          <label>
            Location
            <input name="location" value="Truck">
          </label>

          <label>
            Qty on hand
            <input name="qty_on_hand" inputmode="decimal" placeholder="100">
          </label>

          <label>
            Reorder point
            <input name="reorder_point" inputmode="decimal" placeholder="25">
          </label>

          <label>
            Unit cost
            <input name="default_unit_cost" inputmode="decimal" value="<?= $h($moneyInput(0)) ?>" placeholder="$0.35">
          </label>

          <label>
            Bill price
            <input name="default_bill_price" inputmode="decimal" value="<?= $h($moneyInput(0)) ?>" placeholder="$1.00">
          </label>

          <div class="full">
            <button class="btn btn-primary" type="submit">Add item</button>
          </div>
        </form>
      </article>

      <article class="card">
        <h2>Add buyer</h2>
        <form method="post" class="form-grid">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save_buyer">

          <label>
            Platform
            <input name="platform" value="FieldNation">
          </label>

          <label>
            Buyer name
            <input name="buyer_name" placeholder="Buyer / company name" required>
          </label>

          <label>
            Contact
            <input name="contact_name" placeholder="Lead / dispatcher">
          </label>

          <label>
            Rating
            <input name="rating_internal" inputmode="numeric" placeholder="1-5">
          </label>

          <label class="full">
            Notes
            <textarea name="notes" placeholder="Paperwork burden, repeat potential, materials policy, lead notes."></textarea>
          </label>

          <label class="full" style="display:flex;align-items:center;gap:8px;">
            <input type="checkbox" name="is_preferred" value="1" style="width:auto;">
            Preferred buyer
          </label>

          <div class="full">
            <button class="btn btn-primary" type="submit">Save buyer</button>
          </div>
        </form>
      </article>
    </aside>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Work orders</h2>
    <p class="muted" style="margin-top:-4px;">
      Showing <?= (int)$workOrderCount ?> of <?= (int)$totalWorkOrderCount ?> non-discarded work orders.
      <?php if ($workOrderSearch !== ''): ?>
        Search: <strong><?= $h($workOrderSearch) ?></strong>.
      <?php endif; ?>
    </p>

    <form method="get" class="work-order-search" role="search">
      <input type="hidden" name="work_order_filter" value="all">

      <label>
        Find work order
        <input
          type="search"
          name="q"
          value="<?= $h($workOrderSearch) ?>"
          placeholder="W/O #, buyer, title, site, city, state"
          autocomplete="off"
        >
      </label>

      <button class="btn btn-primary" type="submit">Search W/O</button>

      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_ops.php?work_order_filter=all#work-order-filters"
      >Clear</a>
    </form>

    <nav id="work-order-filters" class="filter-bar" aria-label="Work order filters">
      <?php foreach ($workOrderFilters as $filterKey => $filterLabel): ?>
        <a
          class="filter-pill <?= $workOrderFilter === $filterKey ? 'active' : '' ?>"
          href="<?= $h(BASE_URL . '/admin/field_ops.php?' . http_build_query([
              'q' => $workOrderSearch,
              'work_order_filter' => $filterKey,
          ])) ?>#work-order-filters"
          aria-current="<?= $workOrderFilter === $filterKey ? 'page' : 'false' ?>"
        >
          <?= $h($filterLabel) ?>
          <?php if (isset($filterIntelligence[$filterKey])): ?>
            <span class="filter-count"><?= (int)$filterIntelligence[$filterKey] ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>W/O</th>
            <th>Buyer</th>
            <th>Schedule</th>
            <th>Gross</th>
            <th>Fees</th>
            <th>Costs</th>
            <th>Est. net</th>
            <th>Payment</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$workOrders): ?>
          <tr>
            <td colspan="10" class="muted">
              <?php if ($workOrderSearch !== ''): ?>
                No real work orders match “<?= $h($workOrderSearch) ?>”.
              <?php elseif ($totalWorkOrderCount > 0): ?>
                No work orders match the <?= $h($workOrderFilters[$workOrderFilter] ?? 'selected') ?> filter.
              <?php else: ?>
                No field work orders yet.
              <?php endif; ?>
            </td>
          </tr>
        <?php endif; ?>
        <?php foreach ($workOrders as $wo):
          $workStatus = strtoupper((string)($wo['status'] ?? ''));
          $workStatusClass = match ($workStatus) {
              'ROUTED' => 'wo-status-routed',
              'REQUESTED' => 'wo-status-requested',
              'ASSIGNED' => 'wo-status-assigned',
              'SCHEDULED' => 'wo-status-scheduled',
              'CHECKED_IN' => 'wo-status-checked-in',
              'IN_PROGRESS' => 'wo-status-in-progress',
              'RELEASED_BY_LEAD' => 'wo-status-released',
              'CHECKED_OUT' => 'wo-status-checked-out',
              'SUBMITTED' => 'wo-status-submitted',
              'APPROVED' => 'wo-status-approved',
              'PAID' => 'wo-status-paid',
              'CANCELLED' => 'wo-status-cancelled',
              'DECLINED' => 'wo-status-declined',
              default => '',
          };

          $scheduleCueLabel = '';
          $scheduleCueClass = '';
          $scheduledStartRaw = trim((string)($wo['scheduled_start_at'] ?? ''));
          $scheduledStart = null;

          if ($scheduledStartRaw !== '') {
              try {
                  $scheduledStart = new DateTimeImmutable($scheduledStartRaw);
              } catch (Throwable $e) {
                  $scheduledStart = null;
              }
          }

          $terminalStatuses = [
              'RELEASED_BY_LEAD',
              'CHECKED_OUT',
              'SUBMITTED',
              'APPROVED',
              'PAID',
              'CANCELLED',
              'DECLINED',
          ];

          $isTerminal = in_array($workStatus, $terminalStatuses, true);

          if (
              !$isTerminal
              && in_array($workStatus, ['REQUESTED', 'ASSIGNED'], true)
              && $scheduledStart === null
          ) {
              $scheduleCueLabel = 'NEEDS SCHEDULE';
              $scheduleCueClass = 'schedule-cue-attention';
          } elseif (!$isTerminal && $scheduledStart !== null) {
              $scheduledDay = $scheduledStart->setTime(0, 0);
              $dayDelta = (int)$todayStart->diff($scheduledDay)->format('%r%a');

              if ($scheduledDay < $todayStart) {
                  $scheduleCueLabel = 'OVERDUE';
                  $scheduleCueClass = 'schedule-cue-overdue';
              } elseif ($dayDelta === 0) {
                  $scheduleCueLabel = 'TODAY';
                  $scheduleCueClass = 'schedule-cue-today';
              } elseif ($dayDelta === 1) {
                  $scheduleCueLabel = 'TOMORROW';
                  $scheduleCueClass = 'schedule-cue-tomorrow';
              } elseif ($dayDelta > 1) {
                  $scheduleCueLabel = 'IN ' . $dayDelta . ' DAYS';
                  $scheduleCueClass = 'schedule-cue-upcoming';
              }
          }

          $isExactWorkOrderMatch = $workOrderSearch !== ''
              && strcasecmp(
                  trim((string)($wo['external_work_order_number'] ?? '')),
                  $workOrderSearch
              ) === 0;
        ?>
          <tr class="<?= $isExactWorkOrderMatch ? 'work-order-search-match' : '' ?>">
            <td>
              <span class="badge <?= $h($workStatusClass) ?>">
                <?= $h(str_replace('_', ' ', $workStatus)) ?>
              </span>
            </td>
            <td>
              <strong><a href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$wo['work_order_id'] ?>"><?= $h($wo['title']) ?></a></strong><br>
              <?php if (!empty($wo['external_work_order_number'])): ?><code><?= $h($wo['external_work_order_number']) ?></code><?php endif; ?>
              <div class="muted"><?= $h(trim((string)($wo['city'] ?? '') . ', ' . (string)($wo['state'] ?? ''), ' ,')) ?></div>
            </td>
            <td>
              <?= $h($wo['buyer_name'] ?? 'Unassigned') ?><br>
              <span class="muted"><?= $h($wo['platform'] ?? 'FieldNation') ?></span>
            </td>
            <td>
              <?= $h($dtDisplay($wo['scheduled_start_at'] ?? '')) ?>
              <?php if ($scheduleCueLabel !== ''): ?>
                <div>
                  <span class="schedule-cue <?= $h($scheduleCueClass) ?>">
                    <?= $h($scheduleCueLabel) ?>
                  </span>
                </div>
              <?php endif; ?>
            </td>
            <td><?= $fmtMoney($wo['gross_pay']) ?></td>
            <td>
              <dl class="money-pairs" aria-label="Work order fees">
                <div>
                  <dt>Provider</dt>
                  <dd><?= $fmtMoney($wo['platform_fee']) ?></dd>
                </div>
                <div>
                  <dt>GL</dt>
                  <dd><?= $fmtMoney($wo['insurance_fee'] ?? 0) ?></dd>
                </div>
                <?php if ((float)($wo['oai_fee'] ?? 0) > 0): ?>
                  <div>
                    <dt>OAI</dt>
                    <dd><?= $fmtMoney($wo['oai_fee']) ?></dd>
                  </div>
                <?php endif; ?>
              </dl>
            </td>
            <td>
              <dl class="money-pairs" aria-label="Work order costs">
                <div>
                  <dt>Materials</dt>
                  <dd><?= $fmtMoney($wo['material_cost']) ?></dd>
                </div>
                <div>
                  <dt>Expenses</dt>
                  <dd><?= $fmtMoney($wo['expense_cost']) ?></dd>
                </div>
              </dl>
            </td>
            <td><strong><?= $fmtMoney($wo['estimated_net']) ?></strong></td>
            <td>
              <span class="badge <?= (string)$wo['payment_status'] === 'PAID' ? 'status-paid' : 'status-unpaid' ?>">
                <?= $h($wo['payment_status']) ?>
              </span>
            </td>
            <td>
              <form method="post" class="action-group" style="margin:0;" onsubmit="return confirm('Discard this work order from the active Field Ops view?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard_work_order">
                <input type="hidden" name="work_order_id" value="<?= (int)$wo['work_order_id'] ?>">
                <input type="hidden" name="delete_reason" value="Rejected, withdrawn, or manually discarded.">
                <a class="btn btn-table" href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$wo['work_order_id'] ?>">Open</a>
                <button class="btn btn-danger btn-table" type="submit">Discard</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <details>
      <summary style="cursor:pointer;font-weight:950;font-size:18px;">Recently discarded W/O audit</summary>
      <p class="muted">Soft-deleted work orders are hidden from active totals and the active board, but kept here for traceability.</p>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Discarded</th>
              <th>W/O</th>
              <th>Buyer</th>
              <th>Gross</th>
              <th>Reason</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$discardedWorkOrders): ?>
            <tr><td colspan="5" class="muted">No discarded work orders yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($discardedWorkOrders as $discarded): ?>
            <tr>
              <td><?= $h($dtDisplay($discarded['deleted_at'] ?? '')) ?></td>
              <td>
                <strong><?= $h($discarded['title'] ?? 'Untitled W/O') ?></strong><br>
                <?php if (!empty($discarded['external_work_order_number'])): ?><code><?= $h($discarded['external_work_order_number']) ?></code><?php endif; ?>
                <div class="muted"><?= $h(trim((string)($discarded['city'] ?? '') . ', ' . (string)($discarded['state'] ?? ''), ' ,')) ?></div>
              </td>
              <td>
                <?= $h($discarded['buyer_name'] ?? 'Unassigned') ?><br>
                <span class="muted"><?= $h($discarded['platform'] ?? 'FieldNation') ?></span>
              </td>
              <td><?= $fmtMoney($discarded['gross_pay'] ?? 0) ?></td>
              <td><?= $h($discarded['delete_reason'] ?? 'Discarded') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Inventory</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th>Category</th>
            <th>Location</th>
            <th>Qty</th>
            <th>Reorder</th>
            <th>Cost</th>
            <th>Bill price</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$items): ?>
          <tr><td colspan="7" class="muted">No inventory items yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($items as $item):
          $isLow = ((float)$item['reorder_point'] > 0 && (float)$item['qty_on_hand'] <= (float)$item['reorder_point']);
        ?>
          <tr>
            <td>
              <strong><?= $h($item['item_name']) ?></strong>
              <?php if (!empty($item['sku'])): ?><br><code><?= $h($item['sku']) ?></code><?php endif; ?>
            </td>
            <td><?= $h($item['category']) ?></td>
            <td><?= $h($item['location']) ?></td>
            <td class="<?= $isLow ? 'low-stock' : '' ?>"><?= number_format((float)$item['qty_on_hand'], 2) ?> <?= $h($item['unit']) ?></td>
            <td><?= number_format((float)$item['reorder_point'], 2) ?></td>
            <td><?= $fmtMoney($item['default_unit_cost']) ?></td>
            <td><?= $fmtMoney($item['default_bill_price']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<script>
(() => {
  const form = document.getElementById('field-ops-add-work-order');

  if (!form) {
    return;
  }

  const grossInput = form.querySelector('[name="gross_pay"]');
  const providerInput = form.querySelector('[name="platform_fee"]');
  const glInput = form.querySelector('[name="insurance_fee"]');
  const oaiApplies = document.getElementById('add-oai-applies');
  const oaiInput = document.getElementById('add-oai-fee');

  const numericValue = (input) => {
    const cleaned = String(input?.value || '').replace(/[^0-9.-]/g, '');
    const value = Number.parseFloat(cleaned);

    return Number.isFinite(value) ? value : 0;
  };

  const money = (value) => '$' + Math.max(0, value).toFixed(2);

  const recalculate = () => {
    const gross = numericValue(grossInput);

    if (gross > 0) {
      providerInput.value = money(gross * 0.10);
      glInput.value = money(gross * 0.0195);
    }

    if (oaiApplies.checked && gross > 0) {
      oaiInput.value = money(gross * 0.005);
    } else if (!oaiApplies.checked) {
      oaiInput.value = '';
    }
  };

  grossInput?.addEventListener('input', recalculate);
  oaiApplies?.addEventListener('change', recalculate);
})();
</script>
</body>
</html>
