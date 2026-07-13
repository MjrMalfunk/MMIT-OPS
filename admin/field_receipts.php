<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_vehicles.php';
require_once __DIR__ . '/../inc/field_vehicle_receipts.php';

require_login();

field_vehicles_ensure_schema();
field_vehicle_receipts_ensure_schema();
field_vehicle_receipt_expense_drafts_ensure_schema();

$h = static fn(mixed $value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$fmtMoney = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 2);

$categories = field_vehicle_receipt_categories();
$routeTargets = field_vehicle_receipt_route_targets();
$routeStatuses = field_vehicle_receipt_route_statuses();

$user = current_user() ?: [];
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = trim((string)($_POST['action'] ?? ''));

    $result = match ($action) {
        'route_receipt_draft' => field_vehicle_route_receipt_draft(
            (int)($_POST['receipt_draft_id'] ?? 0),
            (string)($_POST['route_target'] ?? ''),
            $_POST['route_notes'] ?? null,
            isset($user['id']) ? (int)$user['id'] : null
        ),

        'create_expense_draft' => field_vehicle_create_expense_draft_from_receipt(
            (int)($_POST['receipt_draft_id'] ?? 0),
            isset($user['id']) ? (int)$user['id'] : null
        ),

        default => [
            'ok' => false,
            'errors' => ['Unknown receipt action.'],
        ],
    };

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = $action === 'create_expense_draft'
            ? (!empty($result['already_exists'])
                ? 'Expense draft already exists for this receipt.'
                : 'Expense draft created from receipt.')
            : 'Receipt routed and marked reviewed.';

        $redirect = (string)($_POST['return_to'] ?? '');
        $fallback = BASE_URL . '/admin/field_receipts.php';

        if (
            $redirect === ''
            || !str_starts_with($redirect, BASE_URL . '/admin/field_receipts.php')
        ) {
            $redirect = $fallback;
        }

        header('Location: ' . $redirect);
        exit;
    }

    $flashError = implode(
        ' ',
        (array)($result['errors'] ?? ['Receipt action failed.'])
    );
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$receiptStatuses = [
    '' => 'All receipt statuses',
    'CAPTURED' => 'Captured',
    'REVIEWED' => 'Reviewed',
    'LINKED' => 'Linked',
];

$vehicles = field_vehicles(true);

$vehicleOptions = [
    0 => 'All vehicles',
];

foreach ($vehicles as $vehicle) {
    $vehicleOptions[(int)$vehicle['vehicle_id']] = (string)$vehicle['vehicle_name'];
}

$rawReceiptCategory = trim((string)($_GET['receipt_category'] ?? ''));

$filters = [
    'vehicle_id' => max(0, (int)($_GET['vehicle_id'] ?? 0)),
    'receipt_category' => $rawReceiptCategory !== ''
        ? field_vehicle_receipt_clean_category($rawReceiptCategory)
        : '',
    'receipt_status' => strtoupper(trim((string)($_GET['receipt_status'] ?? ''))),
    'route_target' => strtoupper(trim((string)($_GET['route_target'] ?? ''))),
    'route_status' => strtoupper(trim((string)($_GET['route_status'] ?? ''))),
    'q' => trim((string)($_GET['q'] ?? '')),
];

if (!array_key_exists($filters['receipt_category'], $categories)) {
    $filters['receipt_category'] = '';
}

if (!isset($receiptStatuses[$filters['receipt_status']])) {
    $filters['receipt_status'] = '';
}

if (!array_key_exists($filters['route_target'], $routeTargets)) {
    $filters['route_target'] = '';
}

if (!array_key_exists($filters['route_status'], $routeStatuses)) {
    $filters['route_status'] = '';
}

$where = [
    'd.deleted_at IS NULL',
];

$params = [];

if ($filters['vehicle_id'] > 0) {
    $where[] = 'd.vehicle_id = ?';
    $params[] = $filters['vehicle_id'];
}

if ($filters['receipt_category'] !== '') {
    $where[] = 'd.receipt_category = ?';
    $params[] = $filters['receipt_category'];
}

if ($filters['receipt_status'] !== '') {
    $where[] = 'd.receipt_status = ?';
    $params[] = $filters['receipt_status'];
}

if ($filters['route_target'] !== '') {
    $where[] = "COALESCE(d.route_target, 'UNROUTED') = ?";
    $params[] = $filters['route_target'];
}

if ($filters['route_status'] !== '') {
    $where[] = "COALESCE(d.route_status, 'UNROUTED') = ?";
    $params[] = $filters['route_status'];
}

if ($filters['q'] !== '') {
    $where[] = "(
        d.vendor LIKE ?
        OR d.notes LIKE ?
        OR d.route_notes LIKE ?
        OR d.original_filename LIKE ?
        OR v.vehicle_name LIKE ?
    )";

    $needle = '%' . $filters['q'] . '%';
    array_push($params, $needle, $needle, $needle, $needle, $needle);
}

$whereSql = implode("\n          AND ", $where);

$st = db()->prepare("
    SELECT
        d.*,
        v.vehicle_name
    FROM field_vehicle_receipt_drafts d
    INNER JOIN field_vehicles v ON v.vehicle_id = d.vehicle_id
    WHERE {$whereSql}
    ORDER BY COALESCE(d.receipt_date, DATE(d.created_at)) DESC,
             d.receipt_draft_id DESC
    LIMIT 250
");
$st->execute($params);

$receipts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$expenseDraftsByReceipt = field_vehicle_receipt_expense_drafts_by_receipt_ids(
    array_map(
        static fn(array $receipt): int =>
            (int)($receipt['receipt_draft_id'] ?? 0),
        $receipts
    )
);

$summary = [
    'total' => 0,
    'unrouted' => 0,
    'reviewed' => 0,
    'linked' => 0,
    'ignored' => 0,
    'captured_value' => 0.0,
];

foreach ($receipts as $receipt) {
    $summary['total']++;
    $summary['captured_value'] = round(
        $summary['captured_value'] + (float)($receipt['amount'] ?? 0),
        2
    );

    $routeStatus = strtoupper(
        trim((string)($receipt['route_status'] ?? 'UNROUTED'))
    ) ?: 'UNROUTED';

    $receiptStatus = strtoupper(
        trim((string)($receipt['receipt_status'] ?? 'CAPTURED'))
    ) ?: 'CAPTURED';

    if ($receiptStatus === 'LINKED' && $routeStatus === 'UNROUTED') {
        $routeStatus = 'LINKED';
    }

    if ($routeStatus === 'REVIEWED') {
        $summary['reviewed']++;
    } elseif ($routeStatus === 'LINKED') {
        $summary['linked']++;
    } elseif ($routeStatus === 'IGNORED') {
        $summary['ignored']++;
    } else {
        $summary['unrouted']++;
    }
}

$categoryOptions = [
    '' => 'All categories',
    ...$categories,
];

$routeTargetOptions = [
    '' => 'All route targets',
    ...$routeTargets,
];

$routeStatusOptions = [
    '' => 'All route statuses',
    ...$routeStatuses,
];

$routeStatusShortcuts = [
    '' => 'All',
    'UNROUTED' => 'Unrouted',
    'REVIEWED' => 'Reviewed',
    'LINKED' => 'Linked',
    'IGNORED' => 'Ignored',
];

$receiptCenterUrl = static function (array $overrides = []) use ($filters): string {
    $query = [];

    foreach ($filters as $key => $value) {
        if ($key === 'vehicle_id') {
            if ((int)$value > 0) {
                $query[$key] = (int)$value;
            }

            continue;
        }

        if ((string)$value !== '') {
            $query[$key] = (string)$value;
        }
    }

    foreach ($overrides as $key => $value) {
        if ($key === 'vehicle_id') {
            if ((int)$value > 0) {
                $query[$key] = (int)$value;
            } else {
                unset($query[$key]);
            }

            continue;
        }

        if ((string)$value === '') {
            unset($query[$key]);
        } else {
            $query[$key] = (string)$value;
        }
    }

    return BASE_URL
        . '/admin/field_receipts.php'
        . ($query ? '?' . http_build_query($query) : '');
};

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Receipt Center · Field Ops</title>
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
      background: var(--bg);
      color: var(--text);
      font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;
    }

    .page {
      width: min(1380px, calc(100% - 32px));
      margin: 0 auto;
      padding: 32px 0 72px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      margin-bottom: 20px;
    }

    .eyebrow {
      color: var(--blue);
      font-size: 12px;
      font-weight: 900;
      letter-spacing: .12em;
      text-transform: uppercase;
    }

    h1 {
      margin: 6px 0 6px;
      font-size: clamp(34px, 5vw, 52px);
      line-height: 1;
    }

    h2 {
      margin: 0 0 16px;
      font-size: 23px;
    }

    p,
    .muted {
      color: var(--muted);
      line-height: 1.5;
    }

    .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 38px;
      padding: 8px 13px;
      border: 1px solid rgba(96,165,250,.48);
      border-radius: 999px;
      background: rgba(30,64,175,.2);
      color: white;
      font-size: 12px;
      font-weight: 850;
      text-decoration: none;
      cursor: pointer;
      white-space: nowrap;
    }

    .btn-primary {
      border-color: rgba(96,165,250,.8);
      background: #3b82f6;
    }

    .card {
      padding: 20px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--panel);
    }

    .flash-success,
    .flash-error {
      margin-bottom: 16px;
      padding: 13px 16px;
      border-radius: 14px;
      font-weight: 800;
    }

    .flash-success {
      color: var(--green);
      border: 1px solid rgba(34,197,94,.4);
      background: rgba(21,128,61,.18);
    }

    .flash-error {
      color: var(--red);
      border: 1px solid rgba(248,113,113,.4);
      background: rgba(153,27,27,.2);
    }

    .stack {
      display: grid;
      gap: 16px;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(5, minmax(0, 1fr));
      gap: 10px;
    }

    .stat {
      min-width: 0;
      padding: 15px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(2,6,23,.25);
    }

    .stat-value {
      font-size: 26px;
      font-weight: 900;
      overflow-wrap: anywhere;
    }

    .stat-label {
      margin-top: 4px;
      color: var(--muted);
      font-size: 12px;
    }

    .filters {
      display: grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 10px;
      align-items: end;
    }

    .shortcut-bar {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-top: 16px;
    }

    .shortcut-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 34px;
      padding: 7px 12px;
      border: 1px solid var(--line);
      border-radius: 999px;
      background: rgba(2,6,23,.24);
      color: var(--muted);
      font-size: 12px;
      font-weight: 900;
      text-decoration: none;
      white-space: nowrap;
    }

    .shortcut-link-active {
      border-color: rgba(96,165,250,.82);
      background: rgba(37,99,235,.25);
      color: white;
    }

    label {
      display: grid;
      gap: 6px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 850;
    }

    input,
    select {
      width: 100%;
      min-height: 43px;
      padding: 10px 12px;
      border: 1px solid var(--line);
      border-radius: 12px;
      background: rgba(2,6,23,.42);
      color: var(--text);
      font: inherit;
      font-weight: 750;
    }

    select { color-scheme: light; }

    select option {
      background: #eff6ff;
      color: #082f49;
      font-weight: 800;
    }

    .table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      min-width: 1120px;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 11px 9px;
      border-bottom: 1px solid var(--line);
      text-align: left;
      vertical-align: top;
    }

    th {
      color: #bfdbfe;
      font-size: 11px;
      letter-spacing: .05em;
      text-transform: uppercase;
    }

    td {
      color: var(--muted);
      font-size: 13px;
    }

    td strong {
      color: var(--text);
    }

    .pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 8px;
      border: 1px solid var(--line);
      border-radius: 999px;
      color: white;
      font-size: 10px;
      font-weight: 900;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .pill-green {
      color: var(--green);
      background: rgba(22,163,74,.12);
    }

    .pill-yellow {
      color: var(--yellow);
      background: rgba(234,179,8,.12);
    }

    .pill-red {
      color: var(--red);
      background: rgba(239,68,68,.12);
    }

    .quick-route-form {
      display: grid;
      gap: 8px;
      min-width: 220px;
    }

    @media (max-width: 1100px) {
      .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 680px) {
      .topbar {
        flex-direction: column;
      }

      .stats,
      .filters {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">Field Ops</div>

      <h1>Receipt Center</h1>

      <p style="margin:0;">
        Global receipt evidence, routing, and OneDrive proof links.
      </p>
    </div>

    <div class="actions">
      <a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)(field_vehicle_primary()['vehicle_id'] ?? 0) ?>#receipt-drafts">
        Capture receipt
      </a>

      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_vehicles.php">
        Service vehicles
      </a>

      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">
        Back to Field Ops
      </a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?>
    <div class="flash-success"><?= $h($flashSuccess) ?></div>
  <?php endif; ?>

  <?php if ($flashError !== ''): ?>
    <div class="flash-error"><?= $h($flashError) ?></div>
  <?php endif; ?>

  <div class="stack">
    <section class="card">
      <div class="stats">
        <div class="stat">
          <div class="stat-value"><?= (int)$summary['total'] ?></div>
          <div class="stat-label">Receipts shown</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['unrouted'] ?></div>
          <div class="stat-label">Unrouted</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['reviewed'] ?></div>
          <div class="stat-label">Reviewed</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['linked'] ?></div>
          <div class="stat-label">Linked</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= $fmtMoney($summary['captured_value']) ?></div>
          <div class="stat-label">Captured value</div>
        </div>
      </div>

      <div class="shortcut-bar" aria-label="Route status shortcuts">
        <?php foreach ($routeStatusShortcuts as $shortcutStatus => $shortcutLabel): ?>
          <?php $shortcutActive = $filters['route_status'] === (string)$shortcutStatus; ?>
          <a
            class="shortcut-link <?= $shortcutActive ? 'shortcut-link-active' : '' ?>"
            href="<?= $h($receiptCenterUrl(['route_status' => (string)$shortcutStatus])) ?>"
            <?= $shortcutActive ? 'aria-current="page"' : '' ?>
          >
            <?= $h($shortcutLabel) ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Filters</h2>

      <form class="filters" method="get">
        <label>
          Vehicle
          <select name="vehicle_id">
            <?php foreach ($vehicleOptions as $id => $label): ?>
              <option value="<?= (int)$id ?>" <?= $filters['vehicle_id'] === (int)$id ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Category
          <select name="receipt_category">
            <?php foreach ($categoryOptions as $key => $label): ?>
              <option value="<?= $h($key) ?>" <?= $filters['receipt_category'] === (string)$key ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Receipt status
          <select name="receipt_status">
            <?php foreach ($receiptStatuses as $key => $label): ?>
              <option value="<?= $h($key) ?>" <?= $filters['receipt_status'] === (string)$key ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Route target
          <select name="route_target">
            <?php foreach ($routeTargetOptions as $key => $label): ?>
              <option value="<?= $h($key) ?>" <?= $filters['route_target'] === (string)$key ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Route status
          <select name="route_status">
            <?php foreach ($routeStatusOptions as $key => $label): ?>
              <option value="<?= $h($key) ?>" <?= $filters['route_status'] === (string)$key ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Search
          <input name="q" value="<?= $h($filters['q']) ?>" placeholder="Vendor, note, filename...">
        </label>

        <div class="actions" style="grid-column:1 / -1;">
          <button class="btn btn-primary" type="submit">Apply filters</button>
          <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_receipts.php">Reset</a>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>Receipts</h2>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Receipt</th>
              <th>Vehicle</th>
              <th>Vendor</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Route</th>
              <th>Notes</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$receipts): ?>
            <tr>
              <td colspan="9" class="muted">No receipts found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($receipts as $receipt): ?>
            <?php
              $receiptId = (int)$receipt['receipt_draft_id'];
              $vehicleId = (int)$receipt['vehicle_id'];
              $category = (string)($receipt['receipt_category'] ?? 'OTHER');
              $receiptStatus = (string)($receipt['receipt_status'] ?? 'CAPTURED');
              $routeTarget = (string)($receipt['route_target'] ?? 'UNROUTED');
              $routeStatus = (string)($receipt['route_status'] ?? 'UNROUTED');

              if ($receiptStatus === 'LINKED' && $routeStatus === 'UNROUTED') {
                  $routeStatus = 'LINKED';
              }

              if ($receiptStatus === 'LINKED' && $routeTarget === 'UNROUTED') {
                  $routeTarget = 'VEHICLE_EVENT';
              }

              $isVehicleEventCategory = isset(
                  field_vehicle_receipt_vehicle_event_category_map()[$category]
              );

              $expenseDraft = $expenseDraftsByReceipt[$receiptId] ?? null;

              $canQuickRoute = empty($receipt['vehicle_event_id'])
                  && !$isVehicleEventCategory;

              $canCreateExpenseDraft = empty($expenseDraft)
                  && empty($receipt['vehicle_event_id'])
                  && (
                      $category === 'BUSINESS_EXPENSE'
                      || $routeTarget === 'BUSINESS_EXPENSE'
                  );

              $defaultRouteTarget = $routeTarget !== 'UNROUTED'
                  ? $routeTarget
                  : field_vehicle_receipt_default_route_target($category);

              $routeClass = match ($routeStatus) {
                  'LINKED',
                  'REVIEWED' => 'pill-green',
                  'IGNORED' => 'pill-red',
                  default => 'pill-yellow',
              };

              $date = $receipt['receipt_date']
                  ?: substr((string)$receipt['created_at'], 0, 10);
            ?>
            <tr>
              <td><?= $h($date) ?></td>

              <td>
                <strong>#<?= $receiptId ?> <?= $h($categories[$category] ?? $category) ?></strong>
                <div class="muted">
                  <?= $h($receipt['original_filename'] ?? '') ?>
                </div>
              </td>

              <td>
                <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>#receipt-drafts">
                  <?= $h($receipt['vehicle_name'] ?? 'Vehicle') ?>
                </a>
              </td>

              <td><?= $h($receipt['vendor'] ?? '') ?></td>

              <td>
                <strong><?= $fmtMoney($receipt['amount'] ?? 0) ?></strong>
              </td>

              <td>
                <span class="pill"><?= $h($receiptStatus) ?></span>
              </td>

              <td>
                <span class="pill <?= $h($routeClass) ?>">
                  <?= $h($routeStatuses[$routeStatus] ?? $routeStatus) ?>
                </span>
                <div class="muted">
                  <?= $h($routeTargets[$routeTarget] ?? $routeTarget) ?>
                </div>
              </td>

              <td>
                <?php if (!empty($receipt['notes'])): ?>
                  <div><?= nl2br($h($receipt['notes'])) ?></div>
                <?php endif; ?>

                <?php if (!empty($receipt['route_notes'])): ?>
                  <div class="muted">
                    Route: <?= nl2br($h($receipt['route_notes'])) ?>
                  </div>
                <?php endif; ?>
              </td>

              <td>
                <div class="actions">
                  <?php if (!empty($receipt['onedrive_web_url'])): ?>
                    <a
                      class="btn"
                      href="<?= $h($receipt['onedrive_web_url']) ?>"
                      target="_blank"
                      rel="noopener"
                    >
                      Open receipt
                    </a>
                  <?php endif; ?>

                  <?php if (!empty($receipt['vehicle_event_id'])): ?>
                    <a
                      class="btn"
                      href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>&edit_event=<?= (int)$receipt['vehicle_event_id'] ?>#vehicle-event-form"
                    >
                      Linked event
                    </a>
                  <?php endif; ?>

                  <a
                    class="btn"
                    href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>#receipt-drafts"
                  >
                    Review
                  </a>
                </div>

                <?php if ($expenseDraft): ?>
                  <div style="margin-top:8px;">
                    <span class="pill pill-green">
                      Expense draft #<?= (int)$expenseDraft['expense_draft_id'] ?>
                    </span>
                  </div>
                <?php elseif ($canCreateExpenseDraft): ?>
                  <form method="post" style="margin-top:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_expense_draft">
                    <input type="hidden" name="receipt_draft_id" value="<?= $receiptId ?>">
                    <input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_receipts.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">

                    <button class="btn btn-primary" type="submit">
                      Create expense draft
                    </button>
                  </form>
                <?php endif; ?>

                <?php if ($canQuickRoute): ?>
                  <form method="post" class="quick-route-form" style="margin-top:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="route_receipt_draft">
                    <input type="hidden" name="receipt_draft_id" value="<?= $receiptId ?>">
                    <input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_receipts.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">

                    <select name="route_target">
                      <?php foreach ($routeTargets as $targetKey => $targetLabel): ?>
                        <?php if (in_array($targetKey, ['UNROUTED', 'VEHICLE_EVENT'], true)) { continue; } ?>
                        <option
                          value="<?= $h($targetKey) ?>"
                          <?= $defaultRouteTarget === (string)$targetKey ? 'selected' : '' ?>
                        >
                          <?= $h($targetLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                    <input
                      name="route_notes"
                      value="<?= $h($receipt['route_notes'] ?? '') ?>"
                      placeholder="Optional route note"
                    >

                    <button class="btn btn-primary" type="submit">
                      Quick route
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</main>
</body>
</html>
