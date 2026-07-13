<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_vehicles.php';
require_once __DIR__ . '/../inc/field_vehicle_receipts.php';
require_once __DIR__ . '/../inc/accounting.php';

require_login();

field_vehicles_ensure_schema();
field_vehicle_receipts_ensure_schema();
field_vehicle_receipt_expense_drafts_ensure_schema();

$h = static fn(mixed $value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$fmtMoney = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 2);

$notesForDisplay = static function (mixed $notes): string {
    $lines = preg_split('/\R/', (string)$notes) ?: [];
    $displayLines = [];

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if (
            str_starts_with($trimmed, 'Receipt OneDrive URL:')
            || str_starts_with($trimmed, 'Receipt folder:')
        ) {
            continue;
        }

        $displayLines[] = $line;
    }

    return trim(implode("\n", $displayLines));
};

$expenseStatuses = field_vehicle_receipt_expense_draft_statuses();

$user = current_user() ?: [];
$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = trim((string)($_POST['action'] ?? ''));

    $result = match ($action) {
        'update_expense_draft_status' => field_vehicle_update_expense_draft_status(
            (int)($_POST['expense_draft_id'] ?? 0),
            (string)($_POST['expense_status'] ?? ''),
            $_POST['status_notes'] ?? null
        ),

        'export_expense_draft' => field_vehicle_export_expense_draft_to_accounting(
            (int)($_POST['expense_draft_id'] ?? 0),
            isset($user['id']) ? (int)$user['id'] : null
        ),

        default => [
            'ok' => false,
            'errors' => ['Unknown expense draft action.'],
        ],
    };

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = $action === 'export_expense_draft'
            ? (!empty($result['already_exported'])
                ? 'Expense draft was already posted to accounting.'
                : 'Expense draft posted to accounting as a draft expense.')
            : 'Expense draft status updated.';

        $redirect = (string)($_POST['return_to'] ?? '');
        $fallback = BASE_URL . '/admin/field_expense_drafts.php';

        if (
            $redirect === ''
            || !str_starts_with($redirect, BASE_URL . '/admin/field_expense_drafts.php')
        ) {
            $redirect = $fallback;
        }

        header('Location: ' . $redirect);
        exit;
    }

    $flashError = implode(
        ' ',
        (array)($result['errors'] ?? ['Expense draft action failed.'])
    );
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$vehicles = field_vehicles(true);

$vehicleOptions = [
    0 => 'All vehicles',
];

foreach ($vehicles as $vehicle) {
    $vehicleOptions[(int)$vehicle['vehicle_id']] = (string)$vehicle['vehicle_name'];
}

$statusOptions = [
    '' => 'All statuses',
    ...$expenseStatuses,
];

$filters = [
    'vehicle_id' => max(0, (int)($_GET['vehicle_id'] ?? 0)),
    'expense_status' => strtoupper(trim((string)($_GET['expense_status'] ?? ''))),
    'q' => trim((string)($_GET['q'] ?? '')),
];

if (!array_key_exists($filters['expense_status'], $expenseStatuses)) {
    $filters['expense_status'] = '';
}

$where = [
    'e.deleted_at IS NULL',
    'r.deleted_at IS NULL',
];

$params = [];

if ($filters['vehicle_id'] > 0) {
    $where[] = 'e.vehicle_id = ?';
    $params[] = $filters['vehicle_id'];
}

if ($filters['expense_status'] !== '') {
    $where[] = 'e.expense_status = ?';
    $params[] = $filters['expense_status'];
}

if ($filters['q'] !== '') {
    $where[] = "(
        e.vendor LIKE ?
        OR e.description LIKE ?
        OR e.notes LIKE ?
        OR e.expense_category LIKE ?
        OR r.original_filename LIKE ?
        OR v.vehicle_name LIKE ?
    )";

    $needle = '%' . $filters['q'] . '%';
    array_push(
        $params,
        $needle,
        $needle,
        $needle,
        $needle,
        $needle,
        $needle
    );
}

$whereSql = implode("\n          AND ", $where);

$st = db()->prepare("
    SELECT
        e.*,
        r.receipt_category,
        r.receipt_status,
        r.route_target,
        r.route_status,
        r.original_filename AS receipt_original_filename,
        r.onedrive_web_url AS source_receipt_web_url,
        v.vehicle_name
    FROM field_receipt_expense_drafts e
    INNER JOIN field_vehicle_receipt_drafts r
        ON r.receipt_draft_id = e.receipt_draft_id
    INNER JOIN field_vehicles v
        ON v.vehicle_id = e.vehicle_id
    WHERE {$whereSql}
    ORDER BY COALESCE(e.expense_date, DATE(e.created_at)) DESC,
             e.expense_draft_id DESC
    LIMIT 250
");
$st->execute($params);

$drafts = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [
    'total' => 0,
    'draft' => 0,
    'ready' => 0,
    'exported' => 0,
    'void' => 0,
    'total_amount' => 0.0,
];

foreach ($drafts as $draft) {
    $summary['total']++;
    $summary['total_amount'] = round(
        $summary['total_amount'] + (float)($draft['amount'] ?? 0),
        2
    );

    $status = strtoupper(
        trim((string)($draft['expense_status'] ?? 'DRAFT'))
    ) ?: 'DRAFT';

    if ($status === 'READY') {
        $summary['ready']++;
    } elseif ($status === 'EXPORTED') {
        $summary['exported']++;
    } elseif ($status === 'VOID') {
        $summary['void']++;
    } else {
        $summary['draft']++;
    }
}

$expenseDraftUrl = static function (array $overrides = []) use ($filters): string {
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
        . '/admin/field_expense_drafts.php'
        . ($query ? '?' . http_build_query($query) : '');
};

$statusShortcuts = [
    '' => 'All',
    'DRAFT' => 'Draft',
    'READY' => 'Ready',
    'EXPORTED' => 'Exported',
    'VOID' => 'Void',
];

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Expense Draft Center · Field Ops</title>
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

    .filters {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 10px;
      align-items: end;
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

    .notes-cell {
      max-width: 440px;
      overflow-wrap: anywhere;
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

    .status-action-form {
      display: grid;
      gap: 8px;
      min-width: 220px;
      margin-top: 8px;
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

      <h1>Expense Draft Center</h1>

      <p style="margin:0;">
        Review staged business expenses created from receipt evidence.
      </p>
    </div>

    <div class="actions">
      <a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/admin/field_receipts.php">
        Receipt Center
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
          <div class="stat-label">Drafts shown</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['draft'] ?></div>
          <div class="stat-label">Draft</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['ready'] ?></div>
          <div class="stat-label">Ready</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= (int)$summary['exported'] ?></div>
          <div class="stat-label">Exported</div>
        </div>

        <div class="stat">
          <div class="stat-value"><?= $fmtMoney($summary['total_amount']) ?></div>
          <div class="stat-label">Draft value</div>
        </div>
      </div>

      <div class="shortcut-bar" aria-label="Expense draft status shortcuts">
        <?php foreach ($statusShortcuts as $shortcutStatus => $shortcutLabel): ?>
          <?php $shortcutActive = $filters['expense_status'] === (string)$shortcutStatus; ?>
          <a
            class="shortcut-link <?= $shortcutActive ? 'shortcut-link-active' : '' ?>"
            href="<?= $h($expenseDraftUrl(['expense_status' => (string)$shortcutStatus])) ?>"
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
          Status
          <select name="expense_status">
            <?php foreach ($statusOptions as $key => $label): ?>
              <option value="<?= $h($key) ?>" <?= $filters['expense_status'] === (string)$key ? 'selected' : '' ?>>
                <?= $h($label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Search
          <input name="q" value="<?= $h($filters['q']) ?>" placeholder="Vendor, note, category...">
        </label>

        <div class="actions" style="grid-column:1 / -1;">
          <button class="btn btn-primary" type="submit">Apply filters</button>
          <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_expense_drafts.php">Reset</a>
        </div>
      </form>
    </section>

    <section class="card">
      <h2>Expense drafts</h2>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Draft</th>
              <th>Source</th>
              <th>Vehicle</th>
              <th>Vendor</th>
              <th>Amount</th>
              <th>Status</th>
              <th>Accounting</th>
              <th>Category</th>
              <th>Notes</th>
              <th>Actions</th>
            </tr>
          </thead>

          <tbody>
          <?php if (!$drafts): ?>
            <tr>
              <td colspan="11" class="muted">No expense drafts found.</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($drafts as $draft): ?>
            <?php
              $expenseDraftId = (int)$draft['expense_draft_id'];
              $receiptDraftId = (int)$draft['receipt_draft_id'];
              $vehicleId = (int)$draft['vehicle_id'];
              $status = strtoupper(
                  trim((string)($draft['expense_status'] ?? 'DRAFT'))
              ) ?: 'DRAFT';

              $statusClass = match ($status) {
                  'READY',
                  'EXPORTED' => 'pill-green',
                  'VOID' => 'pill-red',
                  default => 'pill-yellow',
              };

              $date = $draft['expense_date']
                  ?: substr((string)$draft['created_at'], 0, 10);

              $receiptUrl = $draft['receipt_onedrive_web_url']
                  ?: ($draft['source_receipt_web_url'] ?? '');

              $displayNotes = $notesForDisplay($draft['notes'] ?? '');
            ?>
            <tr>
              <td><?= $h($date) ?></td>

              <td>
                <strong>#<?= $expenseDraftId ?></strong>
                <div class="muted"><?= $h($draft['description'] ?? '') ?></div>
              </td>

              <td>
                <strong>Receipt #<?= $receiptDraftId ?></strong>
                <div class="muted">
                  <?= $h($draft['receipt_original_filename'] ?? '') ?>
                </div>
              </td>

              <td>
                <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>#receipt-drafts">
                  <?= $h($draft['vehicle_name'] ?? 'Vehicle') ?>
                </a>
              </td>

              <td><?= $h($draft['vendor'] ?? '') ?></td>

              <td><strong><?= $fmtMoney($draft['amount'] ?? 0) ?></strong></td>

              <td>
                <span class="pill <?= $h($statusClass) ?>">
                  <?= $h($expenseStatuses[$status] ?? $status) ?>
                </span>
              </td>

              <td>
                <?php if (!empty($draft['accounting_expense_id'])): ?>
                  <span class="pill pill-green">
                    Expense #<?= (int)$draft['accounting_expense_id'] ?>
                  </span>
                <?php else: ?>
                  <span class="muted">Not posted</span>
                <?php endif; ?>
              </td>

              <td><?= $h($draft['expense_category'] ?? '') ?></td>

              <td class="notes-cell">
                <?php if ($displayNotes !== ''): ?>
                  <?= nl2br($h($displayNotes)) ?>
                <?php endif; ?>
              </td>

              <td>
                <div class="actions">
                  <?php if ($receiptUrl !== ''): ?>
                    <a
                      class="btn"
                      href="<?= $h($receiptUrl) ?>"
                      target="_blank"
                      rel="noopener"
                    >
                      Open receipt
                    </a>
                  <?php endif; ?>

                  <a
                    class="btn"
                    href="<?= $h(BASE_URL) ?>/admin/field_receipts.php?q=<?= urlencode('receipt #' . $receiptDraftId) ?>"
                  >
                    Receipt Center
                  </a>

                  <a
                    class="btn"
                    href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>#receipt-drafts"
                  >
                    Source vehicle
                  </a>

                  <?php if ($status === 'EXPORTED' && !empty($draft['accounting_expense_id'])): ?>
                    <a
                      class="btn"
                      href="<?= $h(BASE_URL) ?>/accounting/bill_edit.php?id=<?= (int)$draft['accounting_expense_id'] ?>"
                    >
                      Accounting draft
                    </a>
                  <?php endif; ?>
                </div>

                <?php if ($status === 'READY' && empty($draft['accounting_expense_id'])): ?>
                  <form method="post" style="margin-top:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="export_expense_draft">
                    <input type="hidden" name="expense_draft_id" value="<?= $expenseDraftId ?>">
                    <input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_expense_drafts.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">

                    <button class="btn btn-primary" type="submit">
                      Post to accounting
                    </button>
                  </form>
                <?php endif; ?>

                <?php if ($status !== 'EXPORTED'): ?>
                  <form method="post" class="status-action-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_expense_draft_status">
                    <input type="hidden" name="expense_draft_id" value="<?= $expenseDraftId ?>">
                    <input type="hidden" name="return_to" value="<?= $h(BASE_URL . '/admin/field_expense_drafts.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '')) ?>">

                    <select name="expense_status">
                      <?php foreach ($expenseStatuses as $statusKey => $statusLabel): ?>
                        <?php if ($statusKey === 'EXPORTED') { continue; } ?>
                        <option
                          value="<?= $h($statusKey) ?>"
                          <?= $status === (string)$statusKey ? 'selected' : '' ?>
                        >
                          <?= $h($statusLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>

                    <input
                      name="status_notes"
                      placeholder="Optional status note"
                    >

                    <button class="btn btn-primary" type="submit">
                      Update status
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
