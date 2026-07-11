<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_vehicles.php';

require_login();

field_vehicles_ensure_schema();

$h = static fn(mixed $value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$fmtMoney = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 2);

$vehicles = field_vehicles(true);

$vehicleCards = [];

foreach ($vehicles as $vehicle) {
    $vehicleId = (int)$vehicle['vehicle_id'];

    $vehicleCards[] = [
        'vehicle' => $vehicle,
        'model' => field_vehicle_cost_model($vehicleId),
        'spend' => field_vehicle_spend_summary($vehicleId),
    ];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Service Vehicles · <?= $h(APP_NAME) ?></title>
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

    a { color: inherit; }

    .page {
      width: min(1220px, calc(100% - 32px));
      margin: 0 auto;
      padding: 32px 0 64px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 20px;
      margin-bottom: 24px;
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
      font-size: clamp(34px, 5vw, 54px);
      line-height: 1;
    }

    h2 {
      margin: 0;
      font-size: 22px;
    }

    p {
      color: var(--muted);
      line-height: 1.55;
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
      min-height: 42px;
      padding: 10px 16px;
      border: 1px solid rgba(96,165,250,.48);
      border-radius: 999px;
      background: rgba(30,64,175,.2);
      color: white;
      font-weight: 850;
      text-decoration: none;
    }

    .grid {
      display: grid;
      grid-template-columns:
        repeat(auto-fit, minmax(min(100%, 360px), 1fr));
      gap: 16px;
    }

    .card {
      padding: 20px;
      border: 1px solid var(--line);
      border-radius: 18px;
      background: var(--panel);
    }

    .vehicle-card {
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .vehicle-header {
      display: flex;
      justify-content: space-between;
      gap: 16px;
      align-items: flex-start;
    }

    .vehicle-title {
      margin: 0;
      font-size: 24px;
    }

    .vehicle-meta {
      margin-top: 5px;
      color: var(--muted);
    }

    .badge-row {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 4px 9px;
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 11px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: .04em;
    }

    .badge-primary {
      color: var(--green);
      border-color: rgba(34,197,94,.5);
      background: rgba(21,128,61,.2);
    }

    .badge-inactive {
      color: var(--yellow);
      border-color: rgba(234,179,8,.45);
      background: rgba(161,98,7,.18);
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px;
    }

    .stat {
      min-width: 0;
      padding: 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      background: rgba(2,6,23,.22);
    }

    .stat-value {
      font-size: 25px;
      font-weight: 900;
      overflow-wrap: anywhere;
    }

    .stat-label {
      margin-top: 3px;
      color: var(--muted);
      font-size: 12px;
    }

    .empty {
      text-align: center;
      padding: 40px 20px;
    }

    @media (max-width: 680px) {
      .topbar {
        flex-direction: column;
      }

      .stats {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>

<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS field vehicle intelligence</div>
      <h1>Service Vehicles</h1>
      <p style="margin:0;">
        Track operating history and calculate the real internal
        cost of moving MMIT down the road.
      </p>
    </div>

    <div class="actions">
      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php"
      >
        Add vehicle
      </a>

      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_ops.php"
      >
        Back to Field Ops
      </a>
    </div>
  </div>

  <?php if (!$vehicleCards): ?>
    <section class="card empty">
      <h2>No service vehicles yet</h2>
      <p>
        Add the MMIT service vehicle to begin tracking fuel,
        maintenance, repairs, and internal cost per mile.
      </p>

      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php"
      >
        Add first vehicle
      </a>
    </section>
  <?php else: ?>
    <section class="grid" aria-label="Service vehicles">
      <?php foreach ($vehicleCards as $card):
          $vehicle = $card['vehicle'];
          $model = $card['model'];
          $spend = $card['spend'];

          $vehicleId = (int)$vehicle['vehicle_id'];

          $description = trim(
              implode(' ', array_filter([
                  $vehicle['model_year'] ?? null,
                  $vehicle['make'] ?? null,
                  $vehicle['model'] ?? null,
                  $vehicle['trim_name'] ?? null,
              ]))
          );
      ?>
        <article class="card vehicle-card">
          <div class="vehicle-header">
            <div>
              <h2 class="vehicle-title">
                <?= $h($vehicle['vehicle_name']) ?>
              </h2>

              <?php if ($description !== ''): ?>
                <div class="vehicle-meta">
                  <?= $h($description) ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="badge-row">
              <?php if (!empty($vehicle['is_primary'])): ?>
                <span class="badge badge-primary">Primary</span>
              <?php endif; ?>

              <?php if (empty($vehicle['active'])): ?>
                <span class="badge badge-inactive">Inactive</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="stats">
            <div class="stat">
              <div class="stat-value">
                <?= number_format(
                    (float)($vehicle['current_odometer'] ?? 0),
                    0
                ) ?>
              </div>
              <div class="stat-label">Current odometer</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtMoney($model['all_in_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Internal cost / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtMoney($model['fuel_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Fuel cost / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtMoney($spend['total_spend'] ?? 0) ?>
              </div>
              <div class="stat-label">
                <?= (int)($spend['year'] ?? date('Y')) ?>
                actual vehicle spend
              </div>
            </div>
          </div>

          <a
            class="btn"
            href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= $vehicleId ?>"
          >
            Open vehicle
          </a>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
</body>
</html>
