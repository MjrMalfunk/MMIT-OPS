<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_rideshare.php';

require_login();

field_vehicles_ensure_schema();
field_rideshare_ensure_schema();

$user = current_user() ?: [];

$h = static fn(mixed $value): string => htmlspecialchars(
    (string)$value,
    ENT_QUOTES,
    'UTF-8'
);

$money = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 2);

$hours = static function (int $minutes): string {
    if ($minutes <= 0) {
        return '0h';
    }

    $wholeHours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    if ($wholeHours === 0) {
        return $remainingMinutes . 'm';
    }

    if ($remainingMinutes === 0) {
        return $wholeHours . 'h';
    }

    return $wholeHours . 'h ' . $remainingMinutes . 'm';
};

$dateInput = static function (mixed $value): string {
    $raw = trim((string)$value);

    if ($raw === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($raw, 0, 16));
};

$today = new DateTimeImmutable('today');
$range = strtolower(trim((string)($_GET['range'] ?? 'week')));

if (!in_array($range, ['today', 'week', 'month', 'custom'], true)) {
    $range = 'week';
}

$dateFrom = match ($range) {
    'today' => $today,
    'month' => $today->modify('first day of this month'),
    'custom' => null,
    default => $today->modify('monday this week'),
};

$dateTo = match ($range) {
    'today', 'week', 'month' => $today,
    default => null,
};

if ($range === 'custom') {
    $fromRaw = trim((string)($_GET['from'] ?? ''));
    $toRaw = trim((string)($_GET['to'] ?? ''));

    $parsedFrom = DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
    $parsedTo = DateTimeImmutable::createFromFormat('Y-m-d', $toRaw);

    $dateFrom = $parsedFrom instanceof DateTimeImmutable
        && $parsedFrom->format('Y-m-d') === $fromRaw
            ? $parsedFrom
            : $today->modify('-6 days');

    $dateTo = $parsedTo instanceof DateTimeImmutable
        && $parsedTo->format('Y-m-d') === $toRaw
            ? $parsedTo
            : $today;
}

if (
    $dateFrom instanceof DateTimeImmutable
    && $dateTo instanceof DateTimeImmutable
    && $dateFrom > $dateTo
) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$rangeQuery = [
    'range' => $range,
];

if ($range === 'custom') {
    $rangeQuery['from'] = $dateFrom?->format('Y-m-d') ?? '';
    $rangeQuery['to'] = $dateTo?->format('Y-m-d') ?? '';
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    try {
        if (
            (string)($_POST['action'] ?? 'save_shift')
            === 'post_accounting'
        ) {
            $postResult = field_rideshare_post_shift_to_accounting(
                (int)($_POST['shift_id'] ?? 0),
                (int)(current_user()['user_id'] ?? 0)
            );

            if (empty($postResult['ok'])) {
                throw new RuntimeException(
                    implode(
                        ' ',
                        $postResult['errors']
                            ?? ['Unable to post shift to Accounting.']
                    )
                );
            }

            $_SESSION['flash_msg'] = !empty(
                $postResult['already_posted']
            )
                ? 'Shift was already posted to Accounting.'
                : 'Shift posted to Accounting.';

            header(
                'Location: '
                . BASE_URL
                . '/admin/field_rideshare.php'
            );
            exit;
        }
        field_rideshare_save_shift(
            $_POST,
            (int)($user['user_id'] ?? 0)
        );

        $_SESSION['flash_msg'] = !empty($_POST['shift_id'])
            ? 'Lyft shift updated.'
            : 'Lyft shift saved.';

        $returnQuery = [
            'range' => trim((string)($_POST['return_range'] ?? 'week')),
        ];

        if ($returnQuery['range'] === 'custom') {
            $returnQuery['from'] = trim(
                (string)($_POST['return_from'] ?? '')
            );
            $returnQuery['to'] = trim(
                (string)($_POST['return_to'] ?? '')
            );
        }

        header(
            'Location: '
            . BASE_URL
            . '/admin/field_rideshare.php?'
            . http_build_query($returnQuery)
        );
        exit;
    } catch (Throwable $exception) {
        $flashError = $exception->getMessage();
    }
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$vehicles = field_vehicles();
$primaryVehicle = field_vehicle_primary();

$vehicleCpms = [];

foreach ($vehicles as $vehicle) {
    $model = field_vehicle_cost_model((int)$vehicle['vehicle_id']);

    $vehicleCpms[(int)$vehicle['vehicle_id']] =
        !empty($model['ok'])
        && isset($model['all_in_cpm'])
            ? (float)$model['all_in_cpm']
            : 0.0;
}

$editShiftId = max(0, (int)($_GET['edit'] ?? 0));
$editShift = $editShiftId > 0
    ? field_rideshare_find_shift($editShiftId)
    : null;

$form = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $_POST
    : ($editShift ?: []);

$form += [
    'shift_id' => 0,
    'vehicle_id' => (int)($primaryVehicle['vehicle_id'] ?? 0),
    'platform' => 'LYFT',
    'shift_date' => $today->format('Y-m-d'),
    'started_at' => '',
    'ended_at' => '',
    'odometer_start' => '',
    'odometer_end' => '',
    'deadhead_miles' => '',
    'deadhead_minutes' => '',
    'online_minutes' => '',
    'booked_minutes' => '',
    'passenger_minutes' => '',
    'booked_miles' => '',
    'passenger_miles' => '',
    'base_ride_earnings' => '',
    'tips' => '',
    'bonuses' => '',
    'adjustments' => '',
    'toll_reimbursements' => '',
    'platform_fees' => '',
    'direct_trip_costs' => '',
    'payout_destination' => 'LYFT_DIRECT',
    'notes' => '',
];

$breakRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedStarts = $_POST['break_started_at'] ?? [];
    $submittedEnds = $_POST['break_ended_at'] ?? [];

    if (is_array($submittedStarts) && is_array($submittedEnds)) {
        $breakRowCount = max(
            count($submittedStarts),
            count($submittedEnds)
        );

        for ($index = 0; $index < $breakRowCount; $index++) {
            $breakRows[] = [
                'started_at' =>
                    (string)($submittedStarts[$index] ?? ''),
                'ended_at' =>
                    (string)($submittedEnds[$index] ?? ''),
            ];
        }
    }
} elseif ($editShiftId > 0) {
    $breakRows = field_rideshare_breaks($editShiftId);
}

if ($breakRows === []) {
    $breakRows[] = [
        'started_at' => '',
        'ended_at' => '',
    ];
}

$from = $dateFrom?->format('Y-m-d');
$to = $dateTo?->format('Y-m-d');

$summary = field_rideshare_summary($from, $to);
$shifts = field_rideshare_shifts($from, $to);

$utilization = (int)$summary['online_minutes'] > 0
    ? round(
        ((int)$summary['booked_minutes']
        / (int)$summary['online_minutes']) * 100,
        1
    )
    : null;

$rangeLabel = match ($range) {
    'today' => 'Today',
    'month' => 'This month',
    'custom' => ($from ?? '') . ' through ' . ($to ?? ''),
    default => 'This week',
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Lyft Performance · <?= $h(APP_NAME) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    :root {
      color-scheme: dark;
      --bg: #081426;
      --panel: rgba(255,255,255,.055);
      --panel-strong: rgba(255,255,255,.085);
      --line: rgba(255,255,255,.12);
      --text: #eef6ff;
      --muted: rgba(238,246,255,.7);
      --blue: #60a5fa;
      --green: #86efac;
      --purple: #c4b5fd;
      --yellow: #fde68a;
      --red: #fecaca;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system,
        BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(
          circle at top left,
          rgba(168,85,247,.15),
          transparent 30rem
        ),
        linear-gradient(135deg, #081426, #0f2238);
      color: var(--text);
    }

    a { color: #bfdbfe; }

    .page {
      width: min(100% - 2rem, 1220px);
      margin: 0 auto;
      padding: 28px 0 56px;
    }

    .topbar,
    .section-heading,
    .history-heading {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
    }

    .topbar { margin-bottom: 20px; }

    .eyebrow {
      color: #c4b5fd;
      font-size: 12px;
      font-weight: 900;
      letter-spacing: .14em;
      text-transform: uppercase;
    }

    h1 {
      margin: 6px 0 8px;
      font-size: clamp(32px, 6vw, 52px);
      line-height: 1;
    }

    h2 { margin: 0; font-size: 22px; }
    h3 { margin: 0; font-size: 17px; }

    p {
      color: var(--muted);
      line-height: 1.55;
    }

    .actions,
    .range-tabs,
    .form-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .btn,
    button {
      min-height: 42px;
      border: 1px solid var(--line);
      border-radius: 11px;
      padding: 10px 14px;
      background: rgba(255,255,255,.06);
      color: var(--text);
      font: inherit;
      font-weight: 850;
      text-decoration: none;
      cursor: pointer;
    }

    .btn:hover,
    button:hover,
    .btn.active {
      border-color: rgba(96,165,250,.72);
      background: rgba(96,165,250,.17);
    }

    .btn-primary {
      border-color: rgba(134,239,172,.5);
      background: rgba(34,197,94,.16);
    }

    .card {
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--panel);
      padding: 18px;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
    }

    .range-card { margin-bottom: 16px; }

    .custom-range {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 190px)) auto;
      gap: 10px;
      align-items: end;
      margin-top: 14px;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 14px;
      margin-bottom: 16px;
    }

    .stat {
      border-top: 3px solid var(--blue);
    }

    .stat.profit { border-top-color: var(--green); }
    .stat.cost { border-top-color: var(--yellow); }
    .stat.rate { border-top-color: var(--purple); }

    .stat-value {
      margin-top: 8px;
      font-size: clamp(25px, 4vw, 34px);
      font-weight: 950;
      letter-spacing: -.03em;
    }

    .stat-label {
      color: var(--muted);
      font-size: 13px;
      font-weight: 750;
    }

    .stat-detail {
      margin-top: 7px;
      color: var(--muted);
      font-size: 12px;
    }

    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
      gap: 16px;
      align-items: start;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }

    .full { grid-column: 1 / -1; }

    label {
      display: grid;
      gap: 7px;
      color: var(--muted);
      font-size: 13px;
      font-weight: 800;
    }

    input,
    select,
    textarea {
      width: 100%;
      min-height: 44px;
      border: 1px solid var(--line);
      border-radius: 10px;
      padding: 10px 11px;
      background: rgba(4,12,24,.78);
      color: var(--text);
      font: inherit;
    }

    input:focus,
    select:focus,
    textarea:focus {
      outline: 3px solid rgba(96,165,250,.25);
      border-color: var(--blue);
    }

    textarea {
      min-height: 92px;
      resize: vertical;
    }

    select option {
      background: #f8fafc;
      color: #0f172a;
      font-weight: 800;
    }

    fieldset {
      grid-column: 1 / -1;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
      margin: 0;
      border: 1px solid var(--line);
      border-radius: 13px;
      padding: 14px;
    }

    legend {
      padding: 0 7px;
      color: #bfdbfe;
      font-weight: 900;
    }

    .break-fieldset {
      display: block;
    }

    .break-help {
      margin: 0 0 12px;
      font-size: 13px;
    }

    .break-list {
      display: grid;
      gap: 12px;
    }

    .break-row {
      display: grid;
      grid-template-columns:
        repeat(2, minmax(0, 1fr)) minmax(100px, auto);
      gap: 12px;
      align-items: end;
      border: 1px solid var(--line);
      border-radius: 11px;
      padding: 12px;
      background: rgba(255,255,255,.025);
    }

    .break-remove {
      min-height: 44px;
      color: var(--red);
    }

    .break-actions {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 12px;
      margin-top: 12px;
    }

    .break-summary {
      color: var(--muted);
      font-size: 13px;
      font-weight: 800;
    }

    .preview {
      position: sticky;
      top: 16px;
    }

    .preview-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      margin-top: 14px;
    }

    .preview-item {
      border: 1px solid var(--line);
      border-radius: 12px;
      background: rgba(255,255,255,.035);
      padding: 13px;
    }

    .preview-value {
      display: block;
      margin-top: 5px;
      font-size: 21px;
      font-weight: 950;
    }

    .preview-label {
      color: var(--muted);
      font-size: 12px;
    }

    .formula {
      margin-top: 14px;
      border-left: 3px solid var(--purple);
      padding-left: 12px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.55;
    }

    .history { margin-top: 16px; }

    .shift-list {
      display: grid;
      gap: 12px;
      margin-top: 14px;
    }

    .shift {
      display: grid;
      grid-template-columns: 1.1fr repeat(4, minmax(100px, .7fr)) auto;
      gap: 12px;
      align-items: center;
      border: 1px solid var(--line);
      border-radius: 13px;
      background: rgba(255,255,255,.035);
      padding: 14px;
    }

    .shift-date {
      font-weight: 950;
      font-size: 16px;
    }

    .shift-meta,
    .shift-metric span {
      color: var(--muted);
      font-size: 12px;
    }

    .shift-metric strong {
      display: block;
      margin-top: 3px;
      font-size: 15px;
    }

    .positive { color: var(--green); }
    .negative { color: var(--red); }

    .empty {
      border: 1px dashed var(--line);
      border-radius: 13px;
      padding: 28px;
      color: var(--muted);
      text-align: center;
    }

    .flash-success,
    .flash-error {
      margin-bottom: 16px;
      border: 1px solid;
      border-radius: 12px;
      padding: 12px 14px;
      font-weight: 800;
    }

    .flash-success {
      border-color: rgba(134,239,172,.4);
      background: rgba(34,197,94,.12);
      color: var(--green);
    }

    .flash-error {
      border-color: rgba(254,202,202,.4);
      background: rgba(239,68,68,.12);
      color: var(--red);
    }

    @media (max-width: 900px) {
      .topbar,
      .section-heading {
        align-items: flex-start;
        flex-direction: column;
      }

      .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
      .layout { grid-template-columns: 1fr; }
      .preview { position: static; }

      .shift {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }

      .shift > :first-child,
      .shift > :last-child {
        grid-column: 1 / -1;
      }
    }

    @media (max-width: 600px) {
      .page {
        width: min(100% - 1rem, 1220px);
        padding-top: 16px;
      }

      .stats,
      .form-grid,
      fieldset,
      .preview-grid,
      .custom-range,
      .break-row {
        grid-template-columns: 1fr;
      }

      .break-remove {
        width: 100%;
      }

      .full { grid-column: auto; }

      .topbar .actions,
      .form-actions {
        width: 100%;
      }

      .topbar .btn,
      .form-actions .btn,
      .form-actions button {
        flex: 1 1 auto;
        text-align: center;
      }

      .shift { grid-template-columns: 1fr 1fr; }

      input,
      select,
      textarea {
        font-size: 16px;
      }
    }
  </style>
</head>
<body>
<main class="page">
  <header class="topbar">
    <div>
      <div class="eyebrow">OPS income intelligence</div>
      <h1>Lyft Performance</h1>
      <p style="margin:0;">
        Measure cash earned against the Tucson’s real operating cost.
      </p>
    </div>

    <nav class="actions" aria-label="Field operations navigation">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">
        Field Ops
      </a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_vehicles.php">
        Service vehicles
      </a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/index.php">
        Admin
      </a>
    </nav>
  </header>

  <?php if ($flashSuccess !== ''): ?>
    <div class="flash-success" role="status">
      <?= $h($flashSuccess) ?>
    </div>
  <?php endif; ?>

  <?php if ($flashError !== ''): ?>
    <div class="flash-error" role="alert">
      <?= $h($flashError) ?>
    </div>
  <?php endif; ?>

  <section class="card range-card" aria-labelledby="range-heading">
    <div class="section-heading">
      <div>
        <h2 id="range-heading"><?= $h($rangeLabel) ?></h2>
        <p style="margin:5px 0 0;">
          <?= (int)$summary['shift_count'] ?> recorded shift<?= (int)$summary['shift_count'] === 1 ? '' : 's' ?>
        </p>
      </div>

      <nav class="range-tabs" aria-label="Performance period">
        <?php foreach ([
            'today' => 'Today',
            'week' => 'Week',
            'month' => 'Month',
            'custom' => 'Custom',
        ] as $key => $label): ?>
          <a
            class="btn <?= $range === $key ? 'active' : '' ?>"
            href="<?= $h(BASE_URL) ?>/admin/field_rideshare.php?range=<?= $h($key) ?>"
            <?= $range === $key ? 'aria-current="page"' : '' ?>
          >
            <?= $h($label) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>

    <?php if ($range === 'custom'): ?>
      <form class="custom-range" method="get">
        <input type="hidden" name="range" value="custom">

        <label>
          From
          <input
            type="date"
            name="from"
            value="<?= $h($from) ?>"
            required
          >
        </label>

        <label>
          Through
          <input
            type="date"
            name="to"
            value="<?= $h($to) ?>"
            required
          >
        </label>

        <button type="submit">Apply range</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="stats" aria-label="Lyft performance summary">
    <article class="card stat">
      <div class="stat-label">Recognized revenue</div>
      <div class="stat-value">
        <?= $h($money($summary['recognized_revenue'])) ?>
      </div>
      <div class="stat-detail">Earnings, tips, bonuses, adjustments and tolls</div>
    </article>

    <article class="card stat profit">
      <div class="stat-label">True operating profit</div>
      <div class="stat-value">
        <?= $h($money($summary['true_operating_profit'])) ?>
      </div>
      <div class="stat-detail">After vehicle and direct trip costs</div>
    </article>

    <article class="card stat cost">
      <div class="stat-label">Vehicle cost</div>
      <div class="stat-value">
        <?= $h($money($summary['vehicle_cost'])) ?>
      </div>
      <div class="stat-detail">
        <?= $h(number_format((float)$summary['total_business_miles'], 1)) ?>
        outing miles ·
        <?= $h(number_format((float)$summary['deadhead_miles'], 1)) ?>
        deadhead
      </div>
    </article>

    <article class="card stat rate">
      <div class="stat-label">Profit per door-to-door hour</div>
      <div class="stat-value">
        <?= $summary['profit_per_total_hour'] !== null
            ? $h($money($summary['profit_per_total_hour']))
            : '—' ?>
      </div>
      <div class="stat-detail">
        Gross:
        <?= $summary['gross_per_total_hour'] !== null
            ? $h($money($summary['gross_per_total_hour']))
            : '—' ?>
      </div>
    </article>

    <article class="card stat">
      <div class="stat-label">Door-to-door time</div>
      <div class="stat-value">
        <?= $h($hours((int)$summary['total_work_minutes'])) ?>
      </div>
      <div class="stat-detail">
        <?= $h($hours((int)$summary['online_minutes'])) ?> online ·
        <?= $h($hours((int)$summary['deadhead_minutes'])) ?> deadhead
      </div>
    </article>

    <article class="card stat">
      <div class="stat-label">Booked utilization</div>
      <div class="stat-value">
        <?= $utilization !== null ? $h(number_format($utilization, 1)) . '%' : '—' ?>
      </div>
      <div class="stat-detail">Booked time divided by online time</div>
    </article>

    <article class="card stat rate">
      <div class="stat-label">Profit per outing mile</div>
      <div class="stat-value">
        <?= $summary['profit_per_total_business_mile'] !== null
            ? $h($money($summary['profit_per_total_business_mile']))
            : '—' ?>
      </div>
      <div class="stat-detail">
        Gross:
        <?= $summary['gross_per_total_business_mile'] !== null
            ? $h($money($summary['gross_per_total_business_mile']))
            : '—' ?>
      </div>
    </article>

    <article class="card stat cost">
      <div class="stat-label">Direct trip costs</div>
      <div class="stat-value">
        <?= $h($money($summary['direct_trip_costs'])) ?>
      </div>
      <div class="stat-detail">Parking, car washes and other shift costs</div>
    </article>
  </section>

  <section class="layout">
    <article class="card">
      <div class="section-heading">
        <div>
          <h2><?= $editShift ? 'Edit Lyft shift' : 'Record Lyft shift' ?></h2>
          <p style="margin:5px 0 16px;">
            Enter the figures from Lyft and your beginning/ending odometer.
          </p>
        </div>
      </div>

      <form method="post" id="shift-form">
        <?php
        if (function_exists('csrf_field')) {
            echo csrf_field();
        } elseif (function_exists('csrf_input')) {
            echo csrf_input();
        } else {
            ?>
            <input
              type="hidden"
              name="csrf_token"
              value="<?= $h(csrf_token()) ?>"
            >
            <?php
        }
        ?>

        <input
          type="hidden"
          name="shift_id"
          value="<?= (int)$form['shift_id'] ?>"
        >
        <input type="hidden" name="return_range" value="<?= $h($range) ?>">
        <input type="hidden" name="return_from" value="<?= $h($from) ?>">
        <input type="hidden" name="return_to" value="<?= $h($to) ?>">

        <div class="form-grid">
          <label>
            Vehicle
            <select name="vehicle_id" id="vehicle_id" required>
              <?php foreach ($vehicles as $vehicle): ?>
                <?php $vehicleId = (int)$vehicle['vehicle_id']; ?>
                <option
                  value="<?= $vehicleId ?>"
                  data-cpm="<?= $h($vehicleCpms[$vehicleId] ?? 0) ?>"
                  <?= (int)$form['vehicle_id'] === $vehicleId ? 'selected' : '' ?>
                >
                  <?= $h($vehicle['vehicle_name']) ?>
                  · <?= $h(number_format(
                      (float)($vehicleCpms[$vehicleId] ?? 0),
                      4
                  )) ?>/mi
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Shift date
            <input
              type="date"
              name="shift_date"
              value="<?= $h($form['shift_date']) ?>"
              required
            >
          </label>

          <label>
            Went online
            <input
              type="datetime-local"
              name="started_at"
              id="started_at"
              value="<?= $h($dateInput($form['started_at'])) ?>"
            >
          </label>

          <label>
            Went offline
            <input
              type="datetime-local"
              name="ended_at"
              id="ended_at"
              value="<?= $h($dateInput($form['ended_at'])) ?>"
            >
          </label>

          <fieldset class="break-fieldset">
            <legend>Off-app breaks</legend>

            <p class="break-help" id="break-help">
              Waiting for rides still counts as online time. Record only
              meal, errand, or other off-duty stops.
            </p>

            <div class="break-list" id="break-list">
              <?php foreach ($breakRows as $index => $break): ?>
                <div class="break-row" data-break-row>
                  <label>
                    Break start
                    <input
                      type="datetime-local"
                      name="break_started_at[]"
                      value="<?= $h($dateInput(
                          $break['started_at'] ?? ''
                      )) ?>"
                      data-break-start
                      aria-describedby="break-help"
                    >
                  </label>

                  <label>
                    Break end
                    <input
                      type="datetime-local"
                      name="break_ended_at[]"
                      value="<?= $h($dateInput(
                          $break['ended_at'] ?? ''
                      )) ?>"
                      data-break-end
                      aria-describedby="break-help"
                    >
                  </label>

                  <button
                    class="break-remove"
                    type="button"
                    data-remove-break
                    aria-label="Remove break <?= $index + 1 ?>"
                  >
                    Remove
                  </button>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="break-actions">
              <button type="button" id="add-break">
                Add break
              </button>

              <span
                class="break-summary"
                id="break-summary"
                aria-live="polite"
              ></span>
            </div>

            <template id="break-row-template">
              <div class="break-row" data-break-row>
                <label>
                  Break start
                  <input
                    type="datetime-local"
                    name="break_started_at[]"
                    data-break-start
                    aria-describedby="break-help"
                  >
                </label>

                <label>
                  Break end
                  <input
                    type="datetime-local"
                    name="break_ended_at[]"
                    data-break-end
                    aria-describedby="break-help"
                  >
                </label>

                <button
                  class="break-remove"
                  type="button"
                  data-remove-break
                >
                  Remove
                </button>
              </div>
            </template>
          </fieldset>

          <label>
            Starting odometer
            <input
              type="number"
              name="odometer_start"
              id="odometer_start"
              step="0.1"
              min="0"
              inputmode="decimal"
              value="<?= $h($form['odometer_start']) ?>"
              required
            >
          </label>

          <label>
            Ending odometer
            <input
              type="number"
              name="odometer_end"
              id="odometer_end"
              step="0.1"
              min="0"
              inputmode="decimal"
              value="<?= $h($form['odometer_end']) ?>"
              required
            >
          </label>

          <fieldset>
            <legend>Off-app deadhead / repositioning</legend>

            <label>
              Business miles driven offline
              <input
                type="number"
                name="deadhead_miles"
                id="deadhead_miles"
                step="0.1"
                min="0"
                inputmode="decimal"
                value="<?= $h($form['deadhead_miles']) ?>"
              >
            </label>

            <label>
              Offline business minutes
              <input
                type="number"
                name="deadhead_minutes"
                id="deadhead_minutes"
                step="1"
                min="0"
                inputmode="numeric"
                value="<?= $h($form['deadhead_minutes']) ?>"
              >
            </label>

            <p class="full" style="margin:0;">
              Include return-home or repositioning travel directly tied
              to this outing after you went offline.
            </p>
          </fieldset>

          <fieldset>
            <legend>Time and utilization</legend>

            <label>
              Online minutes
              <input
                type="number"
                name="online_minutes"
                id="online_minutes"
                min="0"
                inputmode="numeric"
                value="<?= $h($form['online_minutes']) ?>"
              >
            </label>

            <label>
              Booked minutes
              <input
                type="number"
                name="booked_minutes"
                min="0"
                inputmode="numeric"
                value="<?= $h($form['booked_minutes']) ?>"
              >
            </label>

            <label>
              Passenger minutes
              <input
                type="number"
                name="passenger_minutes"
                min="0"
                inputmode="numeric"
                value="<?= $h($form['passenger_minutes']) ?>"
              >
            </label>

            <label>
              Booked miles
              <input
                type="number"
                name="booked_miles"
                step="0.01"
                min="0"
                inputmode="decimal"
                value="<?= $h($form['booked_miles']) ?>"
              >
            </label>

            <label>
              Passenger miles
              <input
                type="number"
                name="passenger_miles"
                step="0.01"
                min="0"
                inputmode="decimal"
                value="<?= $h($form['passenger_miles']) ?>"
              >
            </label>
          </fieldset>

          <fieldset>
            <legend>Earnings and costs</legend>

            <?php foreach ([
                'base_ride_earnings' => 'Base ride earnings',
                'tips' => 'Tips',
                'bonuses' => 'Bonuses',
                'adjustments' => 'Adjustments',
                'toll_reimbursements' => 'Toll reimbursements',
                'platform_fees' => 'Lyft platform fees',
                'direct_trip_costs' => 'Direct trip costs',
            ] as $name => $label): ?>
              <label>
                <?= $h($label) ?>
                <input
                  type="number"
                  name="<?= $h($name) ?>"
                  id="<?= $h($name) ?>"
                  step="0.01"
                  inputmode="decimal"
                  <?= in_array($name, [
                      'platform_fees',
                      'direct_trip_costs',
                  ], true) ? 'min="0"' : '' ?>
                  value="<?= $h($form[$name]) ?>"
                >
              </label>
            <?php endforeach; ?>
          </fieldset>

          <label>
            Payout destination
            <select name="payout_destination">
              <?php foreach ([
                  'LYFT_DIRECT' => 'Lyft Direct',
                  'PNC' => 'PNC',
                  'OTHER' => 'Other',
              ] as $value => $label): ?>
                <option
                  value="<?= $h($value) ?>"
                  <?= $form['payout_destination'] === $value ? 'selected' : '' ?>
                >
                  <?= $h($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label class="full">
            Notes
            <textarea
              name="notes"
              placeholder="Shift conditions, events, bonuses or unusual costs"
            ><?= $h($form['notes']) ?></textarea>
          </label>

          <div class="form-actions full">
            <button class="btn-primary" type="submit">
              <?= $editShift ? 'Update shift' : 'Save shift' ?>
            </button>

            <?php if ($editShift): ?>
              <a
                class="btn"
                href="<?= $h(BASE_URL) ?>/admin/field_rideshare.php?<?= $h(http_build_query($rangeQuery)) ?>"
              >
                Cancel edit
              </a>
            <?php endif; ?>
          </div>
        </div>
      </form>
      <?php if ($editShift): ?>
        <div style="margin-top:16px;">
          <?php if (!empty($editShift['accounting_journal_id'])): ?>
            <p style="margin:0;">
              <strong>Posted to Accounting</strong><br>
              <span class="muted">
                Journal #<?= (int)$editShift['accounting_journal_id'] ?>
              </span>
            </p>
          <?php elseif ((float)$editShift['recognized_revenue'] > 0): ?>
            <form method="post">
              <?= csrf_field() ?>
              <input
                type="hidden"
                name="action"
                value="post_accounting"
              >
              <input
                type="hidden"
                name="shift_id"
                value="<?= (int)$editShift['shift_id'] ?>"
              >
              <button class="btn" type="submit">
                Post to Accounting
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </article>

    <aside class="card preview" aria-labelledby="preview-heading">
      <div class="eyebrow">Live estimate</div>
      <h2 id="preview-heading" style="margin-top:6px;">Shift profitability</h2>

      <div class="preview-grid">
        <div class="preview-item">
          <span class="preview-label">Online miles</span>
          <strong class="preview-value" id="preview-online-miles">0.0</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Deadhead miles</span>
          <strong class="preview-value" id="preview-deadhead-miles">0.0</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Total outing miles</span>
          <strong class="preview-value" id="preview-total-miles">0.0</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Vehicle CPM</span>
          <strong class="preview-value" id="preview-cpm">$0.0000</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Recognized revenue</span>
          <strong class="preview-value" id="preview-revenue">$0.00</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Vehicle cost</span>
          <strong class="preview-value" id="preview-vehicle-cost">$0.00</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">True profit</span>
          <strong class="preview-value" id="preview-profit">$0.00</strong>
        </div>

        <div class="preview-item">
          <span class="preview-label">Profit / door-to-door hour</span>
          <strong class="preview-value" id="preview-hourly">—</strong>
        </div>
      </div>

      <div class="formula">
        True profit = recognized revenue − vehicle cost across online
        and deadhead miles − direct trip costs. Door-to-door hourly adds
        off-app business time. Lyft platform fees remain informational
        because base earnings already represent your driver earnings.
      </div>
    </aside>
  </section>

  <section class="card history" aria-labelledby="history-heading">
    <div class="history-heading">
      <div>
        <h2 id="history-heading">Shift history</h2>
        <p style="margin:5px 0 0;"><?= $h($rangeLabel) ?></p>
      </div>
    </div>

    <?php if (!$shifts): ?>
      <div class="empty">
        No Lyft shifts are recorded for this period.
      </div>
    <?php else: ?>
      <div class="shift-list">
        <?php foreach ($shifts as $shift): ?>
          <?php
          $onlineMinutes = (int)$shift['online_minutes'];
          $deadheadMinutes = (int)($shift['deadhead_minutes'] ?? 0);
          $totalWorkMinutes = $onlineMinutes + $deadheadMinutes;
          $deadheadMiles = (float)($shift['deadhead_miles'] ?? 0);
          $totalMiles = (float)$shift['business_miles'] + $deadheadMiles;
          $grossHourly = $totalWorkMinutes > 0
              ? (float)$shift['recognized_revenue']
                  / ($totalWorkMinutes / 60)
              : null;

          $profitHourly = $totalWorkMinutes > 0
              ? (float)$shift['true_operating_profit']
                  / ($totalWorkMinutes / 60)
              : null;
          ?>
          <article class="shift">
            <div>
              <div class="shift-date">
                <?= $h(date(
                    'D, M j, Y',
                    strtotime((string)$shift['shift_date'])
                )) ?>
              </div>
              <div class="shift-meta">
                <?= $h($hours($totalWorkMinutes)) ?> door-to-door ·
                <?= $h(number_format(
                    $totalMiles,
                    1
                )) ?> miles
                <?php if ($deadheadMiles > 0 || $deadheadMinutes > 0): ?>
                  · <?= $h(number_format($deadheadMiles, 1)) ?> mi /
                  <?= $h($hours($deadheadMinutes)) ?> deadhead
                <?php endif; ?>
              </div>
            </div>

            <div class="shift-metric">
              <span>Revenue</span>
              <strong><?= $h($money($shift['recognized_revenue'])) ?></strong>
            </div>

            <div class="shift-metric">
              <span>Vehicle cost</span>
              <strong><?= $h($money($shift['vehicle_cost'])) ?></strong>
            </div>

            <div class="shift-metric">
              <span>True profit</span>
              <strong class="<?= (float)$shift['true_operating_profit'] >= 0 ? 'positive' : 'negative' ?>">
                <?= $h($money($shift['true_operating_profit'])) ?>
              </strong>
            </div>

            <div class="shift-metric">
              <span>Gross / profit door-to-door</span>
              <strong>
                <?= $grossHourly !== null ? $h($money($grossHourly)) : '—' ?>
                /
                <?= $profitHourly !== null ? $h($money($profitHourly)) : '—' ?>
              </strong>
            </div>

            <a
              class="btn"
              href="<?= $h(BASE_URL) ?>/admin/field_rideshare.php?<?= $h(http_build_query([
                  ...$rangeQuery,
                  'edit' => (int)$shift['shift_id'],
              ])) ?>"
            >
              Edit
            </a>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</main>

<script>
(() => {
  const form = document.getElementById('shift-form');

  if (!form) {
    return;
  }

  const numberValue = (id) => {
    const element = document.getElementById(id);
    const value = Number.parseFloat(element?.value ?? '0');

    return Number.isFinite(value) ? value : 0;
  };

  const money = (value) => new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD'
  }).format(value);

  const breakList = document.getElementById('break-list');
  const breakTemplate = document.getElementById('break-row-template');
  const breakSummary = document.getElementById('break-summary');
  const addBreakButton = document.getElementById('add-break');

  const renumberBreakRows = () => {
    breakList?.querySelectorAll('[data-break-row]').forEach(
      (row, index) => {
        const removeButton = row.querySelector('[data-remove-break]');

        removeButton?.setAttribute(
          'aria-label',
          `Remove break ${index + 1}`
        );
      }
    );
  };

  const totalBreakMinutes = () => {
    let minutes = 0;

    breakList?.querySelectorAll('[data-break-row]').forEach((row) => {
      const start = row.querySelector('[data-break-start]');
      const end = row.querySelector('[data-break-end]');

      if (!start?.value || !end?.value) {
        return;
      }

      const startTime = new Date(start.value).getTime();
      const endTime = new Date(end.value).getTime();

      if (
        Number.isFinite(startTime)
        && Number.isFinite(endTime)
        && endTime > startTime
      ) {
        minutes += Math.round((endTime - startTime) / 60000);
      }
    });

    return minutes;
  };

  const updateOnlineMinutes = () => {
    const start = document.getElementById('started_at');
    const end = document.getElementById('ended_at');
    const online = document.getElementById('online_minutes');
    const breakMinutes = totalBreakMinutes();

    if (breakSummary) {
      breakSummary.textContent = breakMinutes > 0
        ? `${breakMinutes} break minutes excluded`
        : 'No break time excluded';
    }

    if (!start?.value || !end?.value || !online) {
      return;
    }

    const startTime = new Date(start.value).getTime();
    const endTime = new Date(end.value).getTime();

    if (
      Number.isFinite(startTime)
      && Number.isFinite(endTime)
      && endTime >= startTime
    ) {
      const shiftMinutes = Math.round(
        (endTime - startTime) / 60000
      );

      online.value = String(
        Math.max(0, shiftMinutes - breakMinutes)
      );
    }
  };

  const addBreakRow = () => {
    if (!breakList || !breakTemplate) {
      return;
    }

    breakList.append(breakTemplate.content.cloneNode(true));
    renumberBreakRows();

    const rows = breakList.querySelectorAll('[data-break-row]');
    const lastRow = rows[rows.length - 1];

    lastRow?.querySelector('[data-break-start]')?.focus();
  };

  addBreakButton?.addEventListener('click', addBreakRow);

  breakList?.addEventListener('click', (event) => {
    const removeButton = event.target.closest('[data-remove-break]');

    if (!removeButton) {
      return;
    }

    const row = removeButton.closest('[data-break-row]');
    const rows = breakList.querySelectorAll('[data-break-row]');

    if (rows.length > 1) {
      row?.remove();
    } else {
      row?.querySelectorAll('input').forEach((input) => {
        input.value = '';
      });
    }

    renumberBreakRows();
    updateOnlineMinutes();
    updatePreview();
  });

  const updatePreview = () => {
    const vehicle = document.getElementById('vehicle_id');
    const selected = vehicle?.options[vehicle.selectedIndex];
    const cpm = Number.parseFloat(selected?.dataset.cpm ?? '0') || 0;

    const onlineMiles = Math.max(
      0,
      numberValue('odometer_end') - numberValue('odometer_start')
    );
    const deadheadMiles = Math.max(0, numberValue('deadhead_miles'));
    const totalMiles = onlineMiles + deadheadMiles;

    const revenue =
      numberValue('base_ride_earnings')
      + numberValue('tips')
      + numberValue('bonuses')
      + numberValue('adjustments')
      + numberValue('toll_reimbursements');

    const vehicleCost = totalMiles * cpm;
    const directCosts = numberValue('direct_trip_costs');
    const profit = revenue - vehicleCost - directCosts;
    const onlineMinutes = numberValue('online_minutes');
    const deadheadMinutes = Math.max(0, numberValue('deadhead_minutes'));
    const totalWorkMinutes = onlineMinutes + deadheadMinutes;
    const profitHourly = totalWorkMinutes > 0
      ? profit / (totalWorkMinutes / 60)
      : null;

    document.getElementById('preview-online-miles').textContent =
      onlineMiles.toFixed(1);

    document.getElementById('preview-deadhead-miles').textContent =
      deadheadMiles.toFixed(1);

    document.getElementById('preview-total-miles').textContent =
      totalMiles.toFixed(1);

    document.getElementById('preview-cpm').textContent =
      money(cpm);

    document.getElementById('preview-revenue').textContent =
      money(revenue);

    document.getElementById('preview-vehicle-cost').textContent =
      money(vehicleCost);

    const profitElement = document.getElementById('preview-profit');
    profitElement.textContent = money(profit);
    profitElement.classList.toggle('positive', profit >= 0);
    profitElement.classList.toggle('negative', profit < 0);

    document.getElementById('preview-hourly').textContent =
      profitHourly === null ? '—' : money(profitHourly);
  };

  const affectsWorkingTime = (target) =>
    target.id === 'started_at'
    || target.id === 'ended_at'
    || target.matches('[data-break-start], [data-break-end]');

  form.addEventListener('input', (event) => {
    if (affectsWorkingTime(event.target)) {
      updateOnlineMinutes();
    }

    updatePreview();
  });

  form.addEventListener('change', (event) => {
    if (affectsWorkingTime(event.target)) {
      updateOnlineMinutes();
    }

    updatePreview();
  });

  renumberBreakRows();
  updateOnlineMinutes();
  updatePreview();
})();
</script>
</body>
</html>