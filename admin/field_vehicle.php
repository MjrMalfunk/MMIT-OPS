<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_vehicles.php';
require_once __DIR__ . '/../inc/field_vehicle_maintenance.php';

require_login();

field_vehicles_ensure_schema();
field_vehicle_maintenance_ensure_schema();

$h = static fn(mixed $value): string =>
    htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');

$fmtMoney = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 2);

$fmtCpm = static fn(mixed $value): string =>
    '$' . number_format((float)$value, 4);

$user = current_user() ?: [];

$vehicleId = (int)(
    $_GET['id']
    ?? $_POST['vehicle_id']
    ?? 0
);

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = trim((string)($_POST['action'] ?? ''));

    $result = match ($action) {
        'save_vehicle' => field_vehicle_save($_POST),

        'save_event' => field_vehicle_save_event(
            $_POST,
            isset($user['id']) ? (int)$user['id'] : null
        ),

        'save_component' => (static function (): array {
            try {
                return [
                    'ok' => true,
                    ...field_vehicle_component_save($_POST),
                ];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [$e->getMessage()],
                ];
            }
        })(),

        'save_maintenance_item' => (static function (): array {
            try {
                return [
                    'ok' => true,
                    ...field_vehicle_maintenance_item_save($_POST),
                ];
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'errors' => [$e->getMessage()],
                ];
            }
        })(),

        default => [
            'ok' => false,
            'errors' => ['Unknown vehicle action.'],
        ],
    };

    if (!empty($result['ok'])) {
        $vehicleId = (int)(
            $result['vehicle_id']
            ?? $vehicleId
        );

        $_SESSION['flash_msg'] = match ($action) {
            'save_vehicle' => 'Vehicle saved.',
            'save_event' => !empty($result['updated'])
                ? 'Vehicle event updated.'
                : 'Vehicle event added.',
            'save_component' => !empty($result['updated'])
                ? 'Component baseline updated.'
                : 'Component baseline added.',
            'save_maintenance_item' => !empty($result['updated'])
                ? 'Maintenance item updated.'
                : 'Maintenance item added.',
            default => 'Saved.',
        };

        header(
            'Location: '
            . BASE_URL
            . '/admin/field_vehicle.php?id='
            . $vehicleId
        );
        exit;
    }

    $flashError = implode(
        ' ',
        (array)($result['errors'] ?? ['Save failed.'])
    );
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$vehicle = $vehicleId > 0
    ? field_vehicle_find($vehicleId)
    : null;

$editEventId = (int)($_GET['edit_event'] ?? 0);

$editEvent = $editEventId > 0
    ? field_vehicle_find_event($editEventId)
    : null;

if (
    $editEvent
    && (int)$editEvent['vehicle_id'] !== $vehicleId
) {
    $editEvent = null;
    $editEventId = 0;
}

$editComponentId = (int)($_GET['edit_component'] ?? 0);

$editComponent = $editComponentId > 0
    ? field_vehicle_component_find($editComponentId)
    : null;

if (
    $editComponent
    && (int)$editComponent['vehicle_id'] !== $vehicleId
) {
    $editComponent = null;
    $editComponentId = 0;
}

$editMaintenanceItemId = (int)($_GET['edit_maintenance_item'] ?? 0);

$editMaintenanceItem = $editMaintenanceItemId > 0
    ? field_vehicle_maintenance_item_find($editMaintenanceItemId)
    : null;

if (
    $editMaintenanceItem
    && (int)$editMaintenanceItem['vehicle_id'] !== $vehicleId
) {
    $editMaintenanceItem = null;
    $editMaintenanceItemId = 0;
}

$isQuickFuel = $vehicle
    && !$editEvent
    && strtolower(trim((string)($_GET['quick'] ?? ''))) === 'fuel';

$model = $vehicle
    ? field_vehicle_cost_model($vehicleId)
    : null;

$events = $vehicle
    ? field_vehicle_events($vehicleId)
    : [];

$lastFuelVendor = '';

foreach ($events as $existingEvent) {
    if (
        (string)($existingEvent['event_type'] ?? '') === 'FUEL'
        && trim((string)($existingEvent['vendor'] ?? '')) !== ''
    ) {
        $lastFuelVendor = trim((string)$existingEvent['vendor']);
        break;
    }
}

$eventFormDefaults = [
    'event_type' => (string)(
        $editEvent['event_type']
        ?? ($isQuickFuel ? 'FUEL' : 'FUEL')
    ),
    'cost_treatment' => (string)(
        $editEvent['cost_treatment']
        ?? 'NORMAL'
    ),
    'event_date' => (string)(
        $editEvent['event_date']
        ?? date('Y-m-d')
    ),
    'odometer' => (string)(
        $editEvent['odometer']
        ?? ($vehicle['current_odometer'] ?? '')
    ),
    'description' => (string)(
        $editEvent['description']
        ?? ($isQuickFuel ? 'Fuel fill-up' : '')
    ),
    'vendor' => (string)(
        $editEvent['vendor']
        ?? ($isQuickFuel ? $lastFuelVendor : '')
    ),
    'amount' => $editEvent['amount'] ?? '',
    'gallons' => $editEvent['gallons'] ?? '',
    'fuel_price_per_gallon' => $editEvent['fuel_price_per_gallon'] ?? '',
    'amortize_over_miles' => $editEvent['amortize_over_miles'] ?? '',
    'notes' => (string)($editEvent['notes'] ?? ''),
];

$spend = $vehicle
    ? field_vehicle_spend_summary($vehicleId)
    : null;

$eventTypes = field_vehicle_event_types();
$costTreatments = field_vehicle_cost_treatments();

$componentTypes = field_vehicle_component_types();
$componentStatuses = field_vehicle_component_statuses();
$maintenanceSources = field_vehicle_maintenance_sources();
$maintenanceClockTypes = field_vehicle_maintenance_clock_types();
$maintenanceStatuses = field_vehicle_maintenance_statuses();

$components = $vehicle
    ? field_vehicle_components($vehicleId)
    : [];

$componentDefaultType = array_key_first($componentTypes) ?: 'OTHER';

$componentFormDefaults = [
    'component_type' => (string)(
        $editComponent['component_type']
        ?? $componentDefaultType
    ),
    'status' => (string)(
        $editComponent['status']
        ?? 'BASELINE_NEEDED'
    ),
    'component_name' => (string)(
        $editComponent['component_name']
        ?? ''
    ),
    'baseline_date' => (string)(
        $editComponent['baseline_date']
        ?? ''
    ),
    'baseline_odometer' => (string)(
        $editComponent['baseline_odometer']
        ?? ($vehicle['current_odometer'] ?? '')
    ),
    'warranty_until_date' => (string)(
        $editComponent['warranty_until_date']
        ?? ''
    ),
    'warranty_until_miles' => (string)(
        $editComponent['warranty_until_miles']
        ?? ''
    ),
    'notes' => (string)(
        $editComponent['notes']
        ?? ''
    ),
];

$maintenanceItems = $vehicle
    ? field_vehicle_maintenance_items($vehicleId, true)
    : [];

$maintenanceFormDefaults = [
    'item_name' => (string)(
        $editMaintenanceItem['item_name']
        ?? ''
    ),
    'subsystem' => (string)(
        $editMaintenanceItem['subsystem']
        ?? ''
    ),
    'component_id' => (string)(
        $editMaintenanceItem['component_id']
        ?? ''
    ),
    'schedule_source' => (string)(
        $editMaintenanceItem['schedule_source']
        ?? 'MANUAL'
    ),
    'clock_type' => (string)(
        $editMaintenanceItem['clock_type']
        ?? 'VEHICLE'
    ),
    'interval_miles' => (string)(
        $editMaintenanceItem['interval_miles']
        ?? ''
    ),
    'interval_months' => (string)(
        $editMaintenanceItem['interval_months']
        ?? ''
    ),
    'baseline_odometer' => (string)(
        $editMaintenanceItem['baseline_odometer']
        ?? ($vehicle['current_odometer'] ?? '')
    ),
    'baseline_date' => (string)(
        $editMaintenanceItem['baseline_date']
        ?? ''
    ),
    'estimated_service_cost' => (string)(
        $editMaintenanceItem['estimated_service_cost']
        ?? ''
    ),
    'status' => (string)(
        $editMaintenanceItem['status']
        ?? ''
    ),
    'active' => $editMaintenanceItem
        ? !empty($editMaintenanceItem['active'])
        : true,
    'notes' => (string)(
        $editMaintenanceItem['notes']
        ?? ''
    ),
];

$maintenanceSummary = $vehicle
    ? field_vehicle_maintenance_summary($vehicleId)
    : null;

$vehicleValue = static function (
    ?array $vehicle,
    string $key,
    mixed $default = ''
): mixed {
    if (!$vehicle || !array_key_exists($key, $vehicle)) {
        return $default;
    }

    return $vehicle[$key];
};
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>
    <?= $h($vehicle['vehicle_name'] ?? 'Add Service Vehicle') ?>
    · Field Ops
  </title>
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
      width: min(1320px, calc(100% - 32px));
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
      min-height: 42px;
      padding: 10px 16px;
      border: 1px solid rgba(96,165,250,.48);
      border-radius: 999px;
      background: rgba(30,64,175,.2);
      color: white;
      font-weight: 850;
      text-decoration: none;
      cursor: pointer;
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

    .layout {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(360px, .85fr);
      gap: 16px;
      align-items: start;
    }

    .layout-new {
      grid-template-columns: minmax(0, 980px);
      justify-content: center;
    }

    .layout-new > .stack {
      width: 100%;
    }

    .layout > *,
    .stack,
    .card,
    .form-grid,
    .stats {
      min-width: 0;
    }

    .stack {
      display: grid;
      gap: 16px;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 13px;
    }

    .full {
      grid-column: 1 / -1;
    }

    .form-section {
      grid-column: 1 / -1;
      margin-top: 8px;
      padding-top: 18px;
      border-top: 1px solid var(--line);
    }

    .form-section-first {
      margin-top: 0;
      padding-top: 0;
      border-top: 0;
    }

    .form-section-title {
      color: #bfdbfe;
      font-size: 12px;
      font-weight: 950;
      letter-spacing: .09em;
      text-transform: uppercase;
    }

    .form-section-copy {
      margin-top: 4px;
      color: var(--muted);
      font-size: 12px;
      line-height: 1.5;
    }

    label {
      display: grid;
      gap: 6px;
      color: var(--muted);
      font-size: 12px;
      font-weight: 850;
    }

    input,
    select,
    textarea {
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

    textarea {
      min-height: 100px;
      resize: vertical;
    }

    select {
      color-scheme: light;
    }

    select option {
      background: #eff6ff;
      color: #082f49;
      font-weight: 800;
    }

    select option:checked,
    select option:hover {
      background: #bfdbfe;
      color: #082f49;
    }

    .checkbox {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .checkbox input {
      width: auto;
      min-height: 0;
    }

    .stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
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

    .event-table-wrap {
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 920px;
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

    .event-type {
      display: inline-flex;
      padding: 4px 8px;
      border: 1px solid var(--line);
      border-radius: 999px;
      color: white;
      font-size: 10px;
      font-weight: 900;
      text-transform: uppercase;
    }

    .btn-table {
      min-height: 32px;
      padding: 6px 10px;
      font-size: 11px;
      white-space: nowrap;
    }

    .edit-state {
      margin-bottom: 14px;
      padding: 10px 12px;
      border: 1px solid rgba(96,165,250,.4);
      border-radius: 12px;
      background: rgba(30,64,175,.18);
      color: #bfdbfe;
      font-size: 12px;
      font-weight: 800;
    }

    .quick-state {
      margin-bottom: 14px;
      padding: 10px 12px;
      border: 1px solid rgba(134,239,172,.35);
      border-radius: 12px;
      background: rgba(22,163,74,.12);
      color: #bbf7d0;
      font-size: 12px;
      font-weight: 800;
      line-height: 1.45;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 8px;
      border: 1px solid var(--line);
      border-radius: 999px;
      font-size: 10px;
      font-weight: 900;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .status-current,
    .status-reset,
    .status-serviced,
    .status-inspected {
      color: var(--green);
      background: rgba(22,163,74,.12);
    }

    .status-due-soon,
    .status-watch,
    .status-baseline-needed,
    .status-unknown {
      color: var(--yellow);
      background: rgba(234,179,8,.12);
    }

    .status-due,
    .status-overdue,
    .status-failed {
      color: var(--red);
      background: rgba(239,68,68,.12);
    }

    .section-copy {
      margin: -4px 0 16px;
      color: var(--muted);
      font-size: 13px;
      line-height: 1.5;
    }

    .mini-table td,
    .mini-table th {
      vertical-align: top;
    }

    @media (max-width: 980px) {
      .layout {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .topbar {
        flex-direction: column;
      }

      .form-grid,
      .stats {
        grid-template-columns: 1fr;
      }

      .full {
        grid-column: auto;
      }
    }
  </style>
</head>

<body>
<main class="page">
  <div class="topbar">
    <div>
      <div class="eyebrow">OPS service vehicle</div>

      <h1>
        <?= $h($vehicle['vehicle_name'] ?? 'Add Service Vehicle') ?>
      </h1>

      <p style="margin:0;">
        Vehicle history, operating assumptions, and internal
        cost-per-mile intelligence.
      </p>
    </div>

    <div class="actions">
      <?php if ($vehicle): ?>
        <a
          class="btn btn-primary"
          href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>&quick=fuel#vehicle-event-form"
        >
          Quick Fuel
        </a>
      <?php endif; ?>

      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_vehicles.php"
      >
        Service Vehicles
      </a>

      <a
        class="btn"
        href="<?= $h(BASE_URL) ?>/admin/field_ops.php"
      >
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

  <div class="layout <?= $vehicle ? 'layout-saved' : 'layout-new' ?>">
    <div class="stack">
      <section class="card">
        <h2>Vehicle profile</h2>

        <form method="post" class="form-grid">
          <?= csrf_field() ?>

          <input
            type="hidden"
            name="action"
            value="save_vehicle"
          >

          <input
            type="hidden"
            name="vehicle_id"
            value="<?= (int)$vehicleId ?>"
          >

          <div class="form-section form-section-first">
            <div class="form-section-title">Vehicle identity</div>
            <div class="form-section-copy">
              Identify the vehicle and establish its current service baseline.
            </div>
          </div>

          <label class="full">
            Vehicle name
            <input
              name="vehicle_name"
              required
              value="<?= $h($vehicleValue($vehicle, 'vehicle_name')) ?>"
              placeholder="MMIT Service Vehicle"
            >
          </label>

          <label>
            Model year
            <input
              name="model_year"
              inputmode="numeric"
              value="<?= $h($vehicleValue($vehicle, 'model_year')) ?>"
            >
          </label>

          <label>
            Make
            <input
              name="make"
              value="<?= $h($vehicleValue($vehicle, 'make')) ?>"
            >
          </label>

          <label>
            Model
            <input
              name="model"
              value="<?= $h($vehicleValue($vehicle, 'model')) ?>"
            >
          </label>

          <label>
            Trim
            <input
              name="trim_name"
              value="<?= $h($vehicleValue($vehicle, 'trim_name')) ?>"
            >
          </label>

          <label>
            VIN
            <input
              name="vin"
              value="<?= $h($vehicleValue($vehicle, 'vin')) ?>"
            >
          </label>

          <label>
            Plate
            <input
              name="plate"
              value="<?= $h($vehicleValue($vehicle, 'plate')) ?>"
            >
          </label>

          <label>
            In service date
            <input
              type="date"
              name="in_service_date"
              value="<?= $h($vehicleValue($vehicle, 'in_service_date')) ?>"
            >
          </label>

          <label>
            Current odometer
            <input
              name="current_odometer"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'current_odometer')) ?>"
            >
          </label>

          <label>
            Acquisition cost
            <input
              name="acquisition_cost"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'acquisition_cost', '0.00')) ?>"
            >
          </label>

          <label>
            Expected residual value
            <input
              name="expected_residual_value"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'expected_residual_value', '0.00')) ?>"
            >
          </label>

          <label>
            Expected service miles
            <input
              name="expected_service_miles"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'expected_service_miles', '0')) ?>"
            >
          </label>

          <div class="form-section">
            <div class="form-section-title">Operating model</div>
            <div class="form-section-copy">
              Forward-looking assumptions used to calculate MMIT's internal
              operating cost per mile.
            </div>
          </div>

          <label>
            Estimated MPG
            <input
              name="fuel_mpg_estimate"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'fuel_mpg_estimate', '0.00')) ?>"
            >
          </label>

          <label>
            Estimated fuel price / gallon
            <input
              name="fuel_price_per_gallon_estimate"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'fuel_price_per_gallon_estimate', '0.000')) ?>"
            >
          </label>

          <label>
            Maintenance reserve / mile
            <input
              name="maintenance_reserve_per_mile"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'maintenance_reserve_per_mile', '0.0000')) ?>"
            >
          </label>

          <label>
            Tire reserve / mile
            <input
              name="tire_reserve_per_mile"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'tire_reserve_per_mile', '0.0000')) ?>"
            >
          </label>

          <label>
            Repair reserve / mile
            <input
              name="repair_reserve_per_mile"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'repair_reserve_per_mile', '0.0000')) ?>"
            >
          </label>

          <label>
            Depreciation / mile override
            <input
              name="depreciation_per_mile_override"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'depreciation_per_mile_override')) ?>"
              placeholder="Blank = calculate from basis"
            >
          </label>

          <div class="form-section">
            <div class="form-section-title">Annual fixed costs</div>
            <div class="form-section-copy">
              Costs allocated across expected annual business mileage.
            </div>
          </div>

          <label>
            Annual insurance cost
            <input
              name="insurance_annual_cost"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'insurance_annual_cost', '0.00')) ?>"
            >
          </label>

          <label>
            Annual registration cost
            <input
              name="registration_annual_cost"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'registration_annual_cost', '0.00')) ?>"
            >
          </label>

          <label>
            Other annual fixed cost
            <input
              name="other_fixed_annual_cost"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'other_fixed_annual_cost', '0.00')) ?>"
            >
          </label>

          <label>
            Expected annual business miles
            <input
              name="expected_annual_business_miles"
              inputmode="decimal"
              value="<?= $h($vehicleValue($vehicle, 'expected_annual_business_miles', '0')) ?>"
            >
          </label>

          <label class="checkbox">
            <input
              type="checkbox"
              name="is_primary"
              value="1"
              <?= !empty($vehicle['is_primary']) ? 'checked' : '' ?>
            >
            Primary service vehicle
          </label>

          <label class="checkbox">
            <input
              type="checkbox"
              name="active"
              value="1"
              <?= !$vehicle || !empty($vehicle['active']) ? 'checked' : '' ?>
            >
            Active
          </label>

          <label class="full">
            Notes
            <textarea name="notes"><?= $h($vehicleValue($vehicle, 'notes')) ?></textarea>
          </label>

          <div class="full">
            <button class="btn btn-primary" type="submit">
              Save vehicle
            </button>
          </div>
        </form>
      </section>

      <?php if ($vehicle): ?>
        <section class="card">
          <h2>Vehicle event history</h2>

          <div class="event-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Type</th>
                  <th>Description</th>
                  <th>Vendor</th>
                  <th>Odometer</th>
                  <th>Amount</th>
                  <th>Treatment</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$events): ?>
                <tr>
                  <td colspan="8" class="muted">
                    No vehicle events yet.
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($events as $event): ?>
                <tr>
                  <td><?= $h($event['event_date']) ?></td>

                  <td>
                    <span class="event-type">
                      <?= $h(
                          $eventTypes[
                              (string)$event['event_type']
                          ]
                          ?? $event['event_type']
                      ) ?>
                    </span>
                  </td>

                  <td>
                    <strong><?= $h($event['description']) ?></strong>

                    <?php if (!empty($event['notes'])): ?>
                      <div class="muted">
                        <?= nl2br($h($event['notes'])) ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td><?= $h($event['vendor'] ?? '') ?></td>

                  <td>
                    <?= $event['odometer'] !== null
                        ? number_format(
                            (float)$event['odometer'],
                            0
                        )
                        : ''
                    ?>
                  </td>

                  <td>
                    <strong>
                      <?= $fmtMoney($event['amount']) ?>
                    </strong>
                  </td>

                  <td>
                    <?= $h(
                        $costTreatments[
                            (string)$event['cost_treatment']
                        ]
                        ?? $event['cost_treatment']
                    ) ?>
                  </td>

                  <td>
                    <a
                      class="btn btn-table"
                      href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>&edit_event=<?= (int)$event['vehicle_event_id'] ?>"
                    >
                      Edit
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="card">
          <h2>Maintenance health</h2>

          <div class="stats">
            <div class="stat">
              <div class="stat-value">
                <?= (int)($maintenanceSummary['components_baseline_needed'] ?? 0) ?>
              </div>
              <div class="stat-label">Component baselines needed</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= (int)($maintenanceSummary['maintenance_due'] ?? 0) ?>
              </div>
              <div class="stat-label">Maintenance due</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= (int)($maintenanceSummary['maintenance_due_soon'] ?? 0) ?>
              </div>
              <div class="stat-label">Due soon</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($maintenanceSummary['estimated_scheduled_reserve_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Scheduled reserve / mile</div>
            </div>
          </div>
        </section>

        <section class="card">
          <h2>Component baselines</h2>

          <p class="section-copy">
            Tracks which parts are reset, serviced, unknown, or still need a
            baseline inspection. This keeps the replacement engine clock
            separate from the older chassis clock.
          </p>

          <div class="event-table-wrap">
            <table class="mini-table">
              <thead>
                <tr>
                  <th>Component</th>
                  <th>Status</th>
                  <th>Baseline</th>
                  <th>Warranty</th>
                  <th>Notes</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$components): ?>
                <tr>
                  <td colspan="6" class="muted">
                    No component baselines yet.
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($components as $component): ?>
                <?php
                  $componentStatus = (string)$component['status'];
                  $componentClass = strtolower(str_replace('_', '-', $componentStatus));
                ?>
                <tr>
                  <td>
                    <strong><?= $h($component['component_name']) ?></strong>
                    <div class="muted">
                      <?= $h($componentTypes[(string)$component['component_type']] ?? $component['component_type']) ?>
                    </div>
                  </td>

                  <td>
                    <span class="status-pill status-<?= $h($componentClass) ?>">
                      <?= $h($componentStatuses[$componentStatus] ?? $componentStatus) ?>
                    </span>
                  </td>

                  <td>
                    <?php if (!empty($component['baseline_date'])): ?>
                      <strong><?= $h($component['baseline_date']) ?></strong>
                    <?php endif; ?>

                    <?php if ($component['baseline_odometer'] !== null): ?>
                      <div class="muted">
                        <?= number_format((float)$component['baseline_odometer'], 0) ?> mi
                      </div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if (!empty($component['warranty_until_date'])): ?>
                      <strong><?= $h($component['warranty_until_date']) ?></strong>
                    <?php endif; ?>

                    <?php if ($component['warranty_until_miles'] !== null): ?>
                      <div class="muted">
                        <?= number_format((float)$component['warranty_until_miles'], 0) ?> mi
                      </div>
                    <?php endif; ?>
                  </td>

                  <td><?= nl2br($h($component['notes'] ?? '')) ?></td>

                  <td>
                    <a
                      class="btn btn-table"
                      href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>&edit_component=<?= (int)$component['component_id'] ?>#component-baseline-form"
                    >
                      Edit
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="card">
          <h2>Maintenance plan</h2>

          <p class="section-copy">
            Scheduled work with mileage/date intervals, due status, and
            estimated reserve impact.
          </p>

          <div class="event-table-wrap">
            <table class="mini-table">
              <thead>
                <tr>
                  <th>Item</th>
                  <th>Status</th>
                  <th>Clock</th>
                  <th>Interval</th>
                  <th>Next due</th>
                  <th>Reserve</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
              <?php if (!$maintenanceItems): ?>
                <tr>
                  <td colspan="7" class="muted">
                    No maintenance items yet.
                  </td>
                </tr>
              <?php endif; ?>

              <?php foreach ($maintenanceItems as $item): ?>
                <?php
                  $itemStatus = (string)$item['status'];
                  $itemClass = strtolower(str_replace('_', '-', $itemStatus));
                  $itemIntervalMiles = (float)($item['interval_miles'] ?? 0);
                  $itemCost = (float)($item['estimated_service_cost'] ?? 0);
                  $itemReserveCpm = $itemIntervalMiles > 0
                      ? $itemCost / $itemIntervalMiles
                      : 0;
                ?>
                <tr>
                  <td>
                    <strong><?= $h($item['item_name']) ?></strong>

                    <?php if (!empty($item['subsystem'])): ?>
                      <div class="muted"><?= $h($item['subsystem']) ?></div>
                    <?php endif; ?>

                    <?php if (!empty($item['component_name'])): ?>
                      <div class="muted">
                        Component: <?= $h($item['component_name']) ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <span class="status-pill status-<?= $h($itemClass) ?>">
                      <?= $h($maintenanceStatuses[$itemStatus] ?? $itemStatus) ?>
                    </span>
                  </td>

                  <td>
                    <?= $h($maintenanceClockTypes[(string)$item['clock_type']] ?? $item['clock_type']) ?>
                    <div class="muted">
                      <?= $h($maintenanceSources[(string)$item['schedule_source']] ?? $item['schedule_source']) ?>
                    </div>
                  </td>

                  <td>
                    <?php if ($item['interval_miles'] !== null): ?>
                      <strong>
                        <?= number_format((float)$item['interval_miles'], 0) ?> mi
                      </strong>
                    <?php endif; ?>

                    <?php if ($item['interval_months'] !== null): ?>
                      <div class="muted">
                        <?= (int)$item['interval_months'] ?> months
                      </div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <?php if ($item['next_due_odometer'] !== null): ?>
                      <strong>
                        <?= number_format((float)$item['next_due_odometer'], 0) ?> mi
                      </strong>
                    <?php endif; ?>

                    <?php if (!empty($item['next_due_date'])): ?>
                      <div class="muted">
                        <?= $h($item['next_due_date']) ?>
                      </div>
                    <?php endif; ?>
                  </td>

                  <td>
                    <strong><?= $fmtMoney($itemCost) ?></strong>
                    <div class="muted">
                      <?= $fmtCpm($itemReserveCpm) ?>/mi
                    </div>
                  </td>

                  <td>
                    <a
                      class="btn btn-table"
                      href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>&edit_maintenance_item=<?= (int)$item['maintenance_item_id'] ?>#maintenance-item-form"
                    >
                      Edit
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </div>

    <div class="stack">
      <?php if ($vehicle && $model): ?>
        <section class="card">
          <h2>Internal cost model</h2>

          <div class="stats">
            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['all_in_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">All-in cost / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['fuel_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Fuel / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['maintenance_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Maintenance / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['tire_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Tires / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['repair_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Repair reserve / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['depreciation_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Depreciation / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtCpm($model['fixed_cpm'] ?? 0) ?>
              </div>
              <div class="stat-label">Fixed cost / mile</div>
            </div>

            <div class="stat">
              <div class="stat-value">
                <?= $fmtMoney($spend['total_spend'] ?? 0) ?>
              </div>
              <div class="stat-label">
                <?= (int)($spend['year'] ?? date('Y')) ?>
                actual spend
              </div>
            </div>
          </div>

          <p class="muted">
            Fuel price source:
            <?= $h($model['fuel_price_source'] ?? 'PROFILE') ?>.
            Model version:
            <?= (int)($model['model_version'] ?? 1) ?>.
          </p>
        </section>

        <section class="card" id="component-baseline-form">
          <h2>
            <?= $editComponent ? 'Edit component baseline' : 'Add component baseline' ?>
          </h2>

          <p class="section-copy">
            Use this for reset/replaced parts, unknown chassis systems, and
            inspection baselines.
          </p>

          <?php if ($editComponent): ?>
            <div class="edit-state">
              Editing component baseline #<?= (int)$editComponentId ?>.
              Save changes or cancel to return to normal entry.
            </div>
          <?php endif; ?>

          <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>

            <input type="hidden" name="action" value="save_component">
            <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleId ?>">
            <input type="hidden" name="component_id" value="<?= (int)$editComponentId ?>">

            <label>
              Component type
              <select name="component_type">
                <?php foreach ($componentTypes as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $componentFormDefaults['component_type'] === (string)$key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Status
              <select name="status">
                <?php foreach ($componentStatuses as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $componentFormDefaults['status'] === (string)$key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="full">
              Component name
              <input
                name="component_name"
                value="<?= $h($componentFormDefaults['component_name']) ?>"
                placeholder="Replacement engine, battery, front brakes..."
              >
            </label>

            <label>
              Baseline date
              <input
                type="date"
                name="baseline_date"
                value="<?= $h($componentFormDefaults['baseline_date']) ?>"
              >
            </label>

            <label>
              Baseline odometer
              <input
                name="baseline_odometer"
                inputmode="decimal"
                value="<?= $h($componentFormDefaults['baseline_odometer']) ?>"
              >
            </label>

            <label>
              Warranty until date
              <input
                type="date"
                name="warranty_until_date"
                value="<?= $h($componentFormDefaults['warranty_until_date']) ?>"
              >
            </label>

            <label>
              Warranty until miles
              <input
                name="warranty_until_miles"
                inputmode="decimal"
                value="<?= $h($componentFormDefaults['warranty_until_miles']) ?>"
              >
            </label>

            <label class="full">
              Notes
              <textarea
                name="notes"
                placeholder="What was replaced, inspected, reset, or still unknown?"
              ><?= $h($componentFormDefaults['notes']) ?></textarea>
            </label>

            <div class="full">
              <button class="btn btn-primary" type="submit">
                <?= $editComponent ? 'Save component baseline' : 'Add component baseline' ?>
              </button>

              <?php if ($editComponent): ?>
                <a
                  class="btn"
                  href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>"
                >
                  Cancel
                </a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <section class="card" id="maintenance-item-form">
          <h2>
            <?= $editMaintenanceItem ? 'Edit maintenance item' : 'Add maintenance item' ?>
          </h2>

          <p class="section-copy">
            Add scheduled services. AFEE calculates next due mileage/date and
            scheduled reserve per mile from cost ÷ interval.
          </p>

          <?php if ($editMaintenanceItem): ?>
            <div class="edit-state">
              Editing maintenance item #<?= (int)$editMaintenanceItemId ?>.
              Save changes or cancel to return to normal entry.
            </div>
          <?php endif; ?>

          <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>

            <input type="hidden" name="action" value="save_maintenance_item">
            <input type="hidden" name="vehicle_id" value="<?= (int)$vehicleId ?>">
            <input type="hidden" name="maintenance_item_id" value="<?= (int)$editMaintenanceItemId ?>">

            <label class="full">
              Maintenance item
              <input
                name="item_name"
                required
                value="<?= $h($maintenanceFormDefaults['item_name']) ?>"
                placeholder="Oil and filter service"
              >
            </label>

            <label>
              Subsystem
              <input
                name="subsystem"
                value="<?= $h($maintenanceFormDefaults['subsystem']) ?>"
                placeholder="Engine, tires, brakes..."
              >
            </label>

            <label>
              Component baseline
              <select name="component_id">
                <option value="">None</option>
                <?php foreach ($components as $component): ?>
                  <option
                    value="<?= (int)$component['component_id'] ?>"
                    <?= $maintenanceFormDefaults['component_id'] === (string)$component['component_id'] ? 'selected' : '' ?>
                  >
                    <?= $h($component['component_name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Schedule source
              <select name="schedule_source">
                <?php foreach ($maintenanceSources as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $maintenanceFormDefaults['schedule_source'] === (string)$key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Clock type
              <select name="clock_type">
                <?php foreach ($maintenanceClockTypes as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $maintenanceFormDefaults['clock_type'] === (string)$key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Interval miles
              <input
                name="interval_miles"
                inputmode="decimal"
                value="<?= $h($maintenanceFormDefaults['interval_miles']) ?>"
                placeholder="5000"
              >
            </label>

            <label>
              Interval months
              <input
                name="interval_months"
                inputmode="numeric"
                value="<?= $h($maintenanceFormDefaults['interval_months']) ?>"
                placeholder="6"
              >
            </label>

            <label>
              Baseline odometer
              <input
                name="baseline_odometer"
                inputmode="decimal"
                value="<?= $h($maintenanceFormDefaults['baseline_odometer']) ?>"
              >
            </label>

            <label>
              Baseline date
              <input
                type="date"
                name="baseline_date"
                value="<?= $h($maintenanceFormDefaults['baseline_date']) ?>"
              >
            </label>

            <label>
              Estimated service cost
              <input
                name="estimated_service_cost"
                inputmode="decimal"
                value="<?= $h($maintenanceFormDefaults['estimated_service_cost']) ?>"
                placeholder="107.42"
              >
            </label>

            <label>
              Status override
              <select name="status">
                <option value="">Auto-calculate</option>
                <?php foreach ($maintenanceStatuses as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $maintenanceFormDefaults['status'] === (string)$key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label class="checkbox">
              <input
                type="checkbox"
                name="active"
                value="1"
                <?= !empty($maintenanceFormDefaults['active']) ? 'checked' : '' ?>
              >
              Active maintenance item
            </label>

            <label class="full">
              Notes
              <textarea
                name="notes"
                placeholder="Manufacturer interval, warranty requirement, or shop recommendation."
              ><?= $h($maintenanceFormDefaults['notes']) ?></textarea>
            </label>

            <div class="full">
              <button class="btn btn-primary" type="submit">
                <?= $editMaintenanceItem ? 'Save maintenance item' : 'Add maintenance item' ?>
              </button>

              <?php if ($editMaintenanceItem): ?>
                <a
                  class="btn"
                  href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>"
                >
                  Cancel
                </a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <section class="card" id="vehicle-event-form">
          <h2>
            <?php if ($editEvent): ?>
              Edit vehicle event
            <?php elseif ($isQuickFuel): ?>
              Quick fuel entry
            <?php else: ?>
              Add vehicle event
            <?php endif; ?>
          </h2>

          <?php if ($editEvent): ?>
            <div class="edit-state">
              Editing existing vehicle event #<?= (int)$editEventId ?>.
              Save changes or cancel to return to normal entry.
            </div>
          <?php elseif ($isQuickFuel): ?>
            <div class="quick-state">
              Pump-mode shortcut: enter odometer, total, gallons, and
              price per gallon. Use notes when the receipt is missing or
              values are reconstructed.
            </div>
          <?php endif; ?>

          <form method="post" class="form-grid" autocomplete="off">
            <?= csrf_field() ?>

            <input
              type="hidden"
              name="action"
              value="save_event"
            >

            <input
              type="hidden"
              name="vehicle_id"
              value="<?= (int)$vehicleId ?>"
            >

            <input
              type="hidden"
              name="vehicle_event_id"
              value="<?= (int)$editEventId ?>"
            >

            <label>
              Event type
              <select name="event_type">
                <?php foreach ($eventTypes as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $eventFormDefaults['event_type'] === $key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Cost treatment
              <select name="cost_treatment">
                <?php foreach ($costTreatments as $key => $label): ?>
                  <option
                    value="<?= $h($key) ?>"
                    <?= $eventFormDefaults['cost_treatment'] === $key ? 'selected' : '' ?>
                  >
                    <?= $h($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>

            <label>
              Event date
              <input
                type="date"
                name="event_date"
                required
                value="<?= $h($eventFormDefaults['event_date']) ?>"
              >
            </label>

            <label>
              Odometer
              <input
                name="odometer"
                inputmode="decimal"
                value="<?= $h($eventFormDefaults['odometer']) ?>"
              >
            </label>

            <label class="full">
              Description
              <input
                name="description"
                required
                value="<?= $h($eventFormDefaults['description']) ?>"
                placeholder="Fuel fill-up, oil change, repair..."
              >
            </label>

            <label>
              Vendor
              <input
                name="vendor"
                value="<?= $h($eventFormDefaults['vendor']) ?>"
                placeholder="Costco, Kroger, Glenbrook Hyundai..."
              >
            </label>

            <label>
              Amount
              <input
                name="amount"
                inputmode="decimal"
                value="<?= $h($eventFormDefaults['amount']) ?>"
                placeholder="33.50"
              >
            </label>

            <label>
              Gallons
              <input
                name="gallons"
                inputmode="decimal"
                value="<?= $h($eventFormDefaults['gallons']) ?>"
                placeholder="Fuel events only"
              >
            </label>

            <label>
              Fuel price / gallon
              <input
                name="fuel_price_per_gallon"
                inputmode="decimal"
                value="<?= $h($eventFormDefaults['fuel_price_per_gallon']) ?>"
                placeholder="Auto-calculated if blank"
              >
            </label>

            <label class="full">
              Amortize over miles
              <input
                name="amortize_over_miles"
                inputmode="decimal"
                value="<?= $h($eventFormDefaults['amortize_over_miles']) ?>"
                placeholder="Used for AMORTIZED treatment"
              >
            </label>

            <label class="full">
              Notes
              <textarea
                name="notes"
                placeholder="Receipt status, warranty notes, inspection findings, parts, follow-up, etc."
              ><?= $h($eventFormDefaults['notes']) ?></textarea>
            </label>

            <div class="full">
              <button class="btn btn-primary" type="submit">
                <?php if ($editEvent): ?>
                  Save event changes
                <?php elseif ($isQuickFuel): ?>
                  Save fuel entry
                <?php else: ?>
                  Add vehicle event
                <?php endif; ?>
              </button>

              <?php if ($editEvent || $isQuickFuel): ?>
                <a
                  class="btn"
                  href="<?= $h(BASE_URL) ?>/admin/field_vehicle.php?id=<?= (int)$vehicleId ?>"
                >
                  Cancel
                </a>
              <?php endif; ?>
            </div>
          </form>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>
</body>
</html>
