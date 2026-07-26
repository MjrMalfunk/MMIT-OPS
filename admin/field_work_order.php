<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/field_ops.php';
require_once __DIR__ . '/../inc/onedrive.php';

require_login();

field_ops_ensure_schema();

$h = 'field_ops_h';
$fmtMoney = static fn($value): string => '$' . number_format((float)$value, 2);
$fmtNum = static fn($value): string => number_format((float)$value, 2);
$moneyInput = static fn($value = 0): string => field_ops_money_input_value($value);
$dtInput = static fn($value = ''): string => field_ops_datetime_input_value($value);
$dtDisplay = static fn($value = ''): string => field_ops_datetime_display($value);

$workOrderId = (int)($_GET['id'] ?? $_POST['work_order_id'] ?? 0);
$wo = $workOrderId > 0 ? field_ops_find_work_order($workOrderId) : null;
$fnPacket = null;

if (
    $wo
    && strcasecmp((string)($wo['platform'] ?? ''), 'FieldNation') === 0
    && trim((string)($wo['external_work_order_number'] ?? '')) !== ''
) {
    $fnPacket = field_ops_upsert_fn_packet(
        'FieldNation',
        (string)$wo['external_work_order_number'],
        (string)($wo['status'] ?? ''),
        null,
        $workOrderId,
        $wo['source_url'] ?? null
    );
}

if (!$wo) {
    http_response_code(404);
    echo 'Work order not found.';
    exit;
}

$fnCaptureBookmarklet = null;
$fnCaptureReceiverUrl = null;
$fnCaptureSourceUrl = null;

if ($fnPacket) {
    $externalNumber = (string)$fnPacket['external_work_order_number'];
    $fnCaptureReceiverUrl = BASE_URL
        . '/admin/field_fn_packet_capture.php?'
        . http_build_query([
            'external_work_order_number' => $externalNumber,
        ]);
    $baseParts = parse_url(BASE_URL);
    $baseScheme = strtolower((string)($baseParts['scheme'] ?? ''));
    $baseHost = (string)($baseParts['host'] ?? '');
    $basePort = isset($baseParts['port'])
        ? ':' . (int)$baseParts['port']
        : '';
    $captureOrigin = $baseScheme . '://' . $baseHost . $basePort;
    $numberJson = json_encode(
        $externalNumber,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $receiverJson = json_encode(
        $fnCaptureReceiverUrl,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $originJson = json_encode(
        $captureOrigin,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    $fnCaptureBookmarklet = "javascript:(()=>{'use strict';"
        . "const n={$numberJson},r={$receiverJson},o={$originJson};"
        . "let p=null;"
        . "const l=e=>{if(e.source!==p||e.origin!==o||!e.data"
        . "||e.data.type!=='mmit-fn-capture-ready'"
        . "||String(e.data.work_order_number||'')!==n)return;"
        . "window.removeEventListener('message',l);"
        . "const a=[...document.querySelectorAll('a[href]')]"
        . ".filter(a=>a.getClientRects().length>0).slice(0,750)"
        . ".map(a=>({text:String(a.innerText||a.textContent||'')"
        . ".trim().slice(0,500),url:a.href}));"
        . "p.postMessage({type:'mmit-fn-capture-payload',"
        . "work_order_number:n,payload:{source_url:location.href,"
        . "page_title:document.title,"
        . "visible_text:String(document.body?.innerText||''),"
        . "captured_at:new Date().toISOString(),links:a}},o)};"
        . "window.addEventListener('message',l);"
        . "p=window.open(r,'mmitFnPacketCapture',"
        . "'popup,width=760,height=760,resizable=yes,scrollbars=yes');"
        . "if(!p){window.removeEventListener('message',l);"
        . "alert('Allow pop-ups for Field Nation, then run the capture again.')}})();";

    $candidateSourceUrl = trim((string)($wo['source_url'] ?? ''));
    $fnCaptureSourceUrl = field_ops_fn_packet_source_is_allowed(
        $candidateSourceUrl
    ) ? $candidateSourceUrl : null;
}

$flashSuccess = '';
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();

    $action = (string)($_POST['action'] ?? '');
    $result = ['ok' => false, 'errors' => ['Unknown action.']];

    if ($action === 'add_material') {
        $result = field_ops_add_material_pull($_POST);
    } elseif ($action === 'add_expense') {
        $result = field_ops_add_expense($_POST);
    } elseif ($action === 'add_time') {
        $result = field_ops_add_time_entry($_POST);
    } elseif ($action === 'update_time') {
        $result = field_ops_update_time_entry(
            $_POST,
            (int)(current_user()['user_id'] ?? 0)
        );
    } elseif ($action === 'remove_time') {
        $result = field_ops_remove_time_entry(
            $_POST,
            (int)(current_user()['user_id'] ?? 0)
        );
    } elseif ($action === 'update_state') {
        $result = field_ops_update_work_order_state($_POST);
    } elseif ($action === 'save_sli_terms') {
        $result = field_ops_save_sli_terms($_POST);
    } elseif ($action === 'create_pay_change') {
        $result = field_ops_create_pay_change(
            $_POST,
            (int)((current_user()['user_id'] ?? 0))
        );
    } elseif ($action === 'resolve_pay_change') {
        $result = field_ops_resolve_pay_change(
            $_POST,
            (int)((current_user()['user_id'] ?? 0))
        );
    } elseif ($action === 'create_invoice') {
        $result = field_ops_create_draft_invoice_from_work_order($_POST, (int)((current_user()['user_id'] ?? 0)));
    } elseif ($action === 'upload_attachment') {
        $result = field_ops_upload_work_order_attachment($_POST, $_FILES['attachment_file'] ?? [], (int)((current_user()['user_id'] ?? 0)));
    } elseif ($action === 'delete_attachment') {
        $result = field_ops_delete_attachment((int)($_POST['attachment_id'] ?? 0));
    } elseif ($action === 'sync_attachment_onedrive') {
        $result = field_ops_sync_attachment_to_onedrive((int)($_POST['attachment_id'] ?? 0));
    } elseif ($action === 'sync_all_attachments_onedrive') {
        $result = field_ops_sync_work_order_attachments_to_onedrive($workOrderId);
    } elseif ($action === 'assign_project') {
        $result = field_ops_assign_work_order_to_project(
            $_POST,
            (int)(current_user()['user_id'] ?? 0)
        );
    } elseif ($action === 'remove_project_membership') {
        $result = field_ops_remove_work_order_from_project(
            $_POST,
            (int)(current_user()['user_id'] ?? 0)
        );
    } elseif ($action === 'discard_work_order') {
        $result = field_ops_discard_work_order($workOrderId, (string)($_POST['delete_reason'] ?? 'Discarded from detail page.'));
    }

    if (!empty($result['ok'])) {
        $_SESSION['flash_msg'] = match ($action) {
            'add_material' => 'Material pulled and inventory updated.',
            'add_expense' => 'Expense added.',
            'add_time' => 'Time entry added.',
            'update_time' => 'Time entry updated.',
            'remove_time' => 'Time entry removed.',
            'update_state' => 'Work order updated.',
            'save_sli_terms' => 'Authorized SLI terms saved.',
            'create_pay_change' => 'Pay-change request recorded.',
            'resolve_pay_change' => 'Pay-change request resolved.',
            'create_invoice' => 'Draft invoice created from W/O.',
            'upload_attachment' => 'Attachment uploaded.',
            'delete_attachment' => 'Attachment removed.',
            'sync_attachment_onedrive' => 'Attachment synced to OneDrive.',
            'sync_all_attachments_onedrive' => 'W/O attachments synced to OneDrive.',
            'assign_project' => 'Project membership saved.',
            'remove_project_membership' => 'Work order removed from project.',
            'discard_work_order' => 'Work order discarded.',
            default => 'Saved.',
        };

        if ($action === 'discard_work_order') {
            header('Location: ' . BASE_URL . '/admin/field_ops.php');
            exit;
        }

        header('Location: ' . BASE_URL . '/admin/field_work_order.php?id=' . $workOrderId);
        exit;
    }

    $flashError = implode(' ', (array)($result['errors'] ?? ['Save failed.']));
}

if (!empty($_SESSION['flash_msg'])) {
    $flashSuccess = (string)$_SESSION['flash_msg'];
    unset($_SESSION['flash_msg']);
}

$wo = field_ops_find_work_order($workOrderId);
$materials = field_ops_work_order_materials($workOrderId);
$expenses = field_ops_work_order_expenses($workOrderId);
$timeEntries = field_ops_work_order_time_entries($workOrderId);
$payChanges = field_ops_work_order_pay_changes($workOrderId);
$pendingPayChange = field_ops_pending_pay_change($workOrderId);
$sliProjection = field_ops_sli_projection($workOrderId);
$items = field_ops_inventory_items();
$totals = field_ops_work_order_totals($workOrderId);
$attachments = field_ops_work_order_attachments($workOrderId);
$clients = field_ops_clients_for_invoice();
$linkedInvoice = field_ops_existing_invoice_for_work_order($workOrderId);
$onedriveStatus = onedrive_connection_status();
$canCreateInvoice = field_ops_can_invoice_work_order($wo);
$defaultRevenueAccountId = field_ops_default_revenue_account_id();
$projectMembership = field_ops_project_for_work_order($workOrderId);
$activeProjects = array_values(array_filter(
    field_ops_projects(200),
    static fn(array $project): bool =>
        strtoupper((string)($project['project_status'] ?? '')) === 'ACTIVE'
        || (int)($project['project_id'] ?? 0)
            === (int)($projectMembership['project_id'] ?? 0)
));

$receivable = field_ops_receivable_state($wo);

$receivableState = (string)(
    $receivable['state']
    ?? 'ACTIVE_WORK'
);

$receivableExpectedAt = field_ops_receivable_datetime(
    $receivable['expected_payment_at']
    ?? null
);

$paymentTermsDays = isset($wo['payment_terms_days'])
    ? (int)$wo['payment_terms_days']
    : null;

$estimatedApprovalDays = isset($wo['estimated_approval_days'])
    ? (int)$wo['estimated_approval_days']
    : null;

$paymentTermsLabel = match ($paymentTermsDays) {
    0 => '0-day · first Friday after approval',
    7 => '7-day · second Friday after approval',
    14 => '14-day · third Friday after approval',
    default => null,
};

$receivableStateClass = match ($receivableState) {
    'READY_TO_SUBMIT' => 'receivable-state-ready',
    'AWAITING_APPROVAL' => 'receivable-state-approval',
    'PAYMENT_TERMS_REVIEW' => 'receivable-state-review',
    'PAYMENT_PENDING' => 'receivable-state-pending',
    'PAYMENT_DUE_SOON' => 'receivable-state-due',
    'PAYMENT_OVERDUE' => 'receivable-state-overdue',
    'PAID' => 'receivable-state-paid',
    'CLOSED' => 'receivable-state-closed',
    default => 'receivable-state-active',
};

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= $h($wo['title']) ?> · Field Ops</title>
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
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background:
        radial-gradient(circle at top left, rgba(96,165,250,.14), transparent 28rem),
        linear-gradient(135deg, #081426, #0f2238);
      color: var(--text);
    }
    a { color: #bfdbfe; }
    .page { width: min(100% - 2rem, 1220px); margin: 0 auto; padding: 28px 0 48px; }
    .topbar { display:flex; gap:12px; align-items:center; justify-content:space-between; margin-bottom:18px; }
    .eyebrow { text-transform:uppercase; letter-spacing:.14em; color:#93c5fd; font-size:12px; font-weight:900; }
    h1 { margin:6px 0 8px; font-size:clamp(30px,5vw,48px); line-height:1; }
    h2 { margin:0 0 12px; font-size:22px; }
    p { color: var(--muted); line-height:1.6; }
    .grid { display:grid; gap:16px; }
    .stats { grid-template-columns: repeat(5, minmax(0,1fr)); margin:18px 0; }
    .two { grid-template-columns: minmax(0,1fr) minmax(0,1fr); align-items:start; }
    .card { border:1px solid var(--line); background:var(--panel); border-radius:18px; padding:18px; box-shadow:0 20px 60px rgba(0,0,0,.22); }
    .stat-value { font-size:28px; font-weight:950; }
    .stat-label { color:var(--muted); font-size:13px; }

    .receivable-state {
      display:grid;
      gap:5px;
      margin:0 0 18px;
      border-top:3px solid var(--line);
    }

    .receivable-state-label {
      font-size:12px;
      font-weight:950;
      letter-spacing:.08em;
      text-transform:uppercase;
    }

    .receivable-state-description {
      color:var(--muted);
      font-size:13px;
    }

    .receivable-state-meta {
      display:flex;
      gap:10px 18px;
      flex-wrap:wrap;
      margin-top:3px;
      color:var(--muted);
      font-size:12px;
    }

    .receivable-state-active {
      border-top-color:rgba(96,165,250,.82);
    }

    .receivable-state-ready {
      border-top-color:rgba(45,212,191,.84);
    }

    .receivable-state-approval {
      border-top-color:rgba(167,139,250,.84);
    }

    .receivable-state-review {
      border-top-color:rgba(250,204,21,.84);
    }

    .receivable-state-pending {
      border-top-color:rgba(56,189,248,.84);
    }

    .receivable-state-due {
      border-top-color:rgba(251,146,60,.86);
    }

    .receivable-state-overdue {
      border-top-color:rgba(248,113,113,.9);
    }

    .receivable-state-paid {
      border-top-color:rgba(74,222,128,.86);
    }

    .receivable-state-closed {
      border-top-color:rgba(148,163,184,.58);
    }

    .flash-success,.flash-error { border-radius:14px; padding:12px 14px; margin:12px 0; border:1px solid var(--line); }
    .flash-success { background:rgba(34,197,94,.13); color:var(--green); }
    .flash-error { background:rgba(239,68,68,.13); color:var(--red); }
    label { display:grid; gap:7px; font-size:13px; color:var(--muted); font-weight:800; }
    input,textarea,select { width:100%; border:1px solid var(--line); background:rgba(0,0,0,.22); color:var(--text); border-radius:12px; padding:11px 12px; font:inherit; }
    textarea { min-height:82px; resize:vertical; }
    .form-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
    .full { grid-column:1 / -1; }
    .btn { display:inline-flex; align-items:center; justify-content:center; min-height:40px; padding:10px 14px; border-radius:999px; border:1px solid rgba(147,197,253,.35); color:var(--text); background:rgba(96,165,250,.16); text-decoration:none; font-weight:900; cursor:pointer; }
    .btn-primary { background:linear-gradient(135deg,#3b82f6,#60a5fa); color:white; }
    .btn-danger { background:rgba(127,29,29,.24); border-color:rgba(252,165,165,.35); color:#fecaca; }
    .table-wrap { overflow:auto; }
    table { width:100%; border-collapse:collapse; min-width:760px; }
    th,td { padding:11px 9px; border-bottom:1px solid var(--line); text-align:left; vertical-align:top; }
    th { color:#bfdbfe; font-size:12px; text-transform:uppercase; letter-spacing:.08em; }
    code { display:inline-block; max-width:100%; overflow-wrap:anywhere; padding:4px 7px; border-radius:8px; background:rgba(0,0,0,.26); color:#dbeafe; }
    .badge { display:inline-flex; border:1px solid var(--line); border-radius:999px; padding:5px 9px; font-size:12px; font-weight:950; letter-spacing:.04em; background:rgba(147,197,253,.1); }
    .muted { color:var(--muted); }
    .field-hint { color:var(--muted); font-size:11px; margin-top:-4px; }
    .actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }

    .pay-change-card {
      margin:0 0 18px;
      border-top:3px solid rgba(250,204,21,.84);
    }

    .pay-change-card-clear {
      border-top-color:rgba(96,165,250,.82);
    }

    .pay-change-summary {
      display:grid;
      grid-template-columns:repeat(auto-fit,minmax(160px,1fr));
      gap:12px;
      margin:14px 0;
    }

    .pay-change-metric {
      border:1px solid var(--line);
      border-radius:14px;
      padding:12px;
      background:rgba(0,0,0,.18);
    }

    .pay-change-metric strong {
      display:block;
      margin-top:4px;
      font-size:22px;
    }

    .pay-change-notice {
      border:1px solid rgba(250,204,21,.34);
      border-radius:14px;
      padding:12px 14px;
      color:var(--yellow);
      background:rgba(250,204,21,.09);
    }

    .pay-change-history {
      margin-top:18px;
      padding-top:18px;
      border-top:1px solid var(--line);
    }

    .pay-change-status-pending { color:var(--yellow); }
    .pay-change-status-approved { color:var(--green); }
    .pay-change-status-partially-approved { color:#bfdbfe; }
    .pay-change-status-denied { color:var(--red); }

    @media (max-width:1000px) {
      .stats,.two,.form-grid,.pay-change-summary {
        grid-template-columns:1fr;
      }
      .full { grid-column:auto; }
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
      <div class="eyebrow">Field Ops work order</div>
      <h1><?= $h($wo['title']) ?></h1>
      <p style="margin:0;">
        <?= $h($wo['buyer_name'] ?? 'No buyer') ?>
        <?php if (!empty($wo['external_work_order_number'])): ?> · <code><?= $h($wo['external_work_order_number']) ?></code><?php endif; ?>
      </p>

      <?php if ($fnPacket): ?>
        <?php
          $packetRequirement = strtoupper(
              (string)$fnPacket['packet_requirement']
          );
          $captureStatus = strtoupper(
              (string)$fnPacket['capture_status']
          );
          $packetLabel = $captureStatus === 'CAPTURED'
              ? 'FN packet captured'
              : (
                  $packetRequirement === 'REQUIRED'
                      ? 'FN packet required'
                      : 'FN packet available'
              );
          $packetBackground = $captureStatus === 'CAPTURED'
              ? '#166534'
              : (
                  $packetRequirement === 'REQUIRED'
                      ? '#b91c1c'
                      : '#1d4ed8'
              );
        ?>
        <div style="margin-top:.65rem;">
          <span
            style="
              display:inline-flex;
              align-items:center;
              border-radius:999px;
              padding:.35rem .7rem;
              background:<?= $h($packetBackground) ?>;
              color:#fff;
              font-size:.78rem;
              font-weight:900;
              letter-spacing:.035em;
              text-transform:uppercase;
            "
          >
            <?= $h($packetLabel) ?>
          </span>
        </div>
      <?php endif; ?>
    </div>
    <div class="actions">
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_projects.php">Projects</a>
      <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Back to Field Ops</a>
    </div>
  </div>

  <?php if ($flashSuccess !== ''): ?><div class="flash-success"><?= $h($flashSuccess) ?></div><?php endif; ?>
  <?php if ($flashError !== ''): ?><div class="flash-error"><?= $h($flashError) ?></div><?php endif; ?>

  <?php if ($fnPacket && $fnCaptureBookmarklet !== null): ?>
    <?php
      $packetCaptured = strtoupper(
          (string)$fnPacket['capture_status']
      ) === 'CAPTURED';
      $packetRequired = strtoupper(
          (string)$fnPacket['packet_requirement']
      ) === 'REQUIRED';
    ?>
    <section
      class="card"
      aria-labelledby="fn-packet-capture-heading"
      style="margin-bottom:18px;border-top:3px solid <?= $packetCaptured
          ? 'rgba(74,222,128,.86)'
          : ($packetRequired
              ? 'rgba(248,113,113,.9)'
              : 'rgba(96,165,250,.82)') ?>;"
    >
      <div
        style="
          display:flex;
          justify-content:space-between;
          gap:16px;
          align-items:flex-start;
          flex-wrap:wrap;
        "
      >
        <div style="max-width:760px;">
          <div class="eyebrow">Field Nation source packet</div>
          <h2 id="fn-packet-capture-heading" style="margin-top:6px;">
            <?= $packetCaptured
                ? 'Captured and integrity checked'
                : ($packetRequired
                    ? 'Capture required for this W/O'
                    : 'Capture available') ?>
          </h2>
          <p id="fn-packet-bookmark-help" style="margin-bottom:0;">
            Drag the capture button to your bookmarks bar. Open this exact
            W/O in Field Nation, then click the bookmark. OPS receives only
            the rendered page text and visible HTTP(S) links—never your
            Field Nation credentials or cookies.
          </p>
          <?php if ($packetCaptured): ?>
            <div class="field-hint" style="margin-top:8px;">
              Captured <?= $h((string)($fnPacket['captured_at'] ?? '')) ?>
              · Recapturing atomically replaces the stored snapshot.
            </div>
          <?php endif; ?>
        </div>

        <div class="actions">
          <?php if ($packetCaptured): ?>
            <a
              class="btn btn-primary"
              href="<?= $h(BASE_URL) ?>/admin/field_fn_packet_view.php?id=<?= (int)$fnPacket['packet_id'] ?>"
              target="_blank"
              rel="noopener"
            >View captured packet</a>
          <?php endif; ?>

          <?php if ($fnCaptureSourceUrl !== null): ?>
            <a
              class="btn"
              href="<?= $h($fnCaptureSourceUrl) ?>"
              target="_blank"
              rel="noopener noreferrer"
            >Open Field Nation W/O</a>
          <?php endif; ?>
        </div>
      </div>

      <div
        style="
          margin-top:16px;
          padding:14px;
          border:1px dashed rgba(147,197,253,.5);
          border-radius:14px;
          background:rgba(0,0,0,.18);
        "
      >
        <a
          class="btn btn-primary"
          href="<?= $h($fnCaptureBookmarklet) ?>"
          aria-describedby="fn-packet-bookmark-help"
          onclick="return false;"
          title="Drag this button to the bookmarks bar"
        >Capture FN W/O <?= $h($fnPacket['external_work_order_number']) ?></a>
        <span class="field-hint" style="display:inline-block;margin:0 0 0 10px;">
          Drag to bookmarks bar—do not click it on this OPS page.
        </span>
      </div>
    </section>
  <?php endif; ?>

  <section class="card receivable-state <?= $h($receivableStateClass) ?>" aria-label="Current receivable state">
    <div class="receivable-state-label">
      <?= $h($receivable['label'] ?? $receivableState) ?>
    </div>

    <div class="receivable-state-description">
      <?= $h($receivable['description'] ?? '') ?>
    </div>

    <?php if (
        $paymentTermsLabel !== null
        || $estimatedApprovalDays !== null
        || $receivableExpectedAt !== null
        || !empty($wo['payment_terms_text'])
    ): ?>
      <div class="receivable-state-meta">
        <?php if ($paymentTermsLabel !== null): ?>
          <span>
            FN terms: <?= $h($paymentTermsLabel) ?>
          </span>
        <?php endif; ?>

        <?php if ($estimatedApprovalDays !== null): ?>
          <span>
            Est. approval after submission:
            ~<?= (int)$estimatedApprovalDays ?>
            <?= (int)$estimatedApprovalDays === 1 ? 'day' : 'days' ?>
          </span>
        <?php endif; ?>

        <?php if ($receivableExpectedAt !== null): ?>
          <span>
            Expected payment:
            <?= $h($receivableExpectedAt->format('M j, Y')) ?>
          </span>
        <?php endif; ?>

        <?php if (!empty($wo['payment_terms_text'])): ?>
          <span>
            Terms note: <?= $h($wo['payment_terms_text']) ?>
          </span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card" aria-label="Work-order project membership" style="margin-bottom:18px;">
    <div class="topbar" style="margin-bottom:12px;align-items:flex-start;">
      <div>
        <div class="eyebrow">Project / bundle</div>
        <h2 style="margin-top:6px;">
          <?php if ($projectMembership !== null): ?>
            <a href="<?= $h(BASE_URL) ?>/admin/field_projects.php?id=<?= (int)$projectMembership['project_id'] ?>">
              <?= $h($projectMembership['project_name']) ?>
            </a>
          <?php else: ?>
            Not assigned to a project
          <?php endif; ?>
        </h2>

        <?php if ($projectMembership !== null): ?>
          <p style="margin:0;">
            <?= $h($projectMembership['external_reference'] ?? 'No parent reference') ?>
            <?php if (!empty($projectMembership['member_label'])): ?>
              · <?= $h($projectMembership['member_label']) ?>
            <?php endif; ?>
            <?php if (!empty($projectMembership['sequence_number'])): ?>
              · Position <?= (int)$projectMembership['sequence_number'] ?>
            <?php endif; ?>
          </p>
        <?php else: ?>
          <p style="margin:0;">Group related days or sites while keeping this W/O's own pay, time, expenses, and status.</p>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($activeProjects): ?>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign_project">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Project
          <select name="project_id" required>
            <option value="">Choose project</option>
            <?php foreach ($activeProjects as $project): ?>
              <option
                value="<?= (int)$project['project_id'] ?>"
                <?= (int)$project['project_id'] === (int)($projectMembership['project_id'] ?? 0) ? 'selected' : '' ?>
              ><?= $h($project['project_name']) ?><?= empty($project['external_reference']) ? '' : ' · ' . $h($project['external_reference']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Sequence
          <input
            name="sequence_number"
            inputmode="numeric"
            value="<?= $h((string)($projectMembership['sequence_number'] ?? '')) ?>"
            placeholder="1"
          >
        </label>

        <label class="full">
          Day / site label
          <input
            name="member_label"
            value="<?= $h((string)($projectMembership['member_label'] ?? '')) ?>"
            placeholder="Day 1 · Prep and cutover"
          >
        </label>

        <label class="full">
          Membership notes
          <textarea name="notes" placeholder="Project-specific note for this W/O."><?= $h((string)($projectMembership['member_notes'] ?? '')) ?></textarea>
        </label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">
            <?= $projectMembership === null ? 'Add to project' : 'Save project placement' ?>
          </button>
          <a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_projects.php">Manage projects</a>
        </div>
      </form>
    <?php else: ?>
      <div class="actions">
        <a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/admin/field_projects.php">Create first project</a>
      </div>
    <?php endif; ?>

    <?php if ($projectMembership !== null): ?>
      <form method="post" class="actions" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="remove_project_membership">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
        <input type="hidden" name="project_id" value="<?= (int)$projectMembership['project_id'] ?>">
        <input type="hidden" name="delete_reason" value="Removed from W/O detail page.">
        <button class="btn btn-danger" type="submit">Remove from project</button>
        <span class="field-hint">Recoverable for audit and later restoration.</span>
      </form>
    <?php endif; ?>
  </section>

  <section class="grid stats">
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['gross'] ?? 0) ?></div><div class="stat-label">Gross</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['material_cost'] ?? 0) ?></div><div class="stat-label">Materials cost</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['expense_cost'] ?? 0) ?></div><div class="stat-label">Expenses</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['estimated_net'] ?? 0) ?></div><div class="stat-label">Estimated net</div></article>
    <article class="card"><div class="stat-value"><?= $fmtMoney($totals['effective_hourly'] ?? 0) ?></div><div class="stat-label">Effective hourly</div></article>
  </section>

  <section class="card pay-change-card <?= $pendingPayChange === null ? 'pay-change-card-clear' : '' ?>" aria-label="Pay-change tracking">
    <h2>Pay change / SLI</h2>

    <p class="muted">
      Enter the hourly rate and maximum authorized hours shown by FieldNation.
      OPS uses onsite time entries to calculate the suggested SLI request.
    </p>

    <form method="post" class="form-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_sli_terms">
      <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

      <label>
        Authorized hourly rate
        <input
          name="authorized_rate"
          inputmode="decimal"
          value="<?= empty($wo['authorized_rate'])
              ? ''
              : $h($moneyInput($wo['authorized_rate'])) ?>"
          placeholder="$65.00"
        >
      </label>

      <label>
        Maximum authorized hours
        <input
          name="authorized_hours"
          inputmode="decimal"
          value="<?= empty($wo['authorized_hours'])
              ? ''
              : $h(number_format((float)$wo['authorized_hours'], 2, '.', '')) ?>"
          placeholder="10.00"
        >
      </label>

      <div class="full actions">
        <button class="btn" type="submit">Save SLI terms</button>
      </div>
    </form>

    <?php if (!empty($sliProjection['ok'])): ?>
      <div class="pay-change-summary">
        <div class="pay-change-metric">
          <span class="muted">OPS onsite time</span>
          <strong>
            <?= number_format(
                (float)($sliProjection['suggested_billable_hours'] ?? 0),
                2
            ) ?> hrs
          </strong>
          <span class="field-hint">
            <?= (int)($sliProjection['onsite_minutes'] ?? 0) ?> minutes
          </span>
        </div>

        <div class="pay-change-metric">
          <span class="muted">Authorized limit</span>
          <strong>
            <?= number_format(
                (float)($sliProjection['authorized_hours'] ?? 0),
                2
            ) ?> hrs
          </strong>
        </div>

        <div class="pay-change-metric">
          <span class="muted">Hours beyond limit</span>
          <strong>
            <?= number_format(
                (float)($sliProjection['overage_hours'] ?? 0),
                2
            ) ?> hrs
          </strong>
        </div>

        <div class="pay-change-metric">
          <span class="muted">Suggested revised gross</span>
          <strong>
            <?= $fmtMoney(
                $sliProjection['suggested_total_gross'] ?? $wo['gross_pay']
            ) ?>
          </strong>
        </div>
      </div>

      <?php if (!empty($sliProjection['eligible'])): ?>
        <div class="pay-change-notice">
          <strong>SLI recommended</strong><br>
          OPS calculated a
          <?= $fmtMoney($sliProjection['suggested_increase']) ?>
          increase from the recorded onsite time. Confirm the hours shown by
          FieldNation before recording the pending request.
        </div>
      <?php elseif (
          empty($sliProjection['authorized_rate'])
          || empty($sliProjection['authorized_hours'])
      ): ?>
        <p class="muted">
          Save the authorized rate and maximum hours to enable automatic SLI
          calculations.
        </p>
      <?php else: ?>
        <p class="muted">
          Recorded onsite time is currently within the authorized limit.
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($pendingPayChange !== null): ?>
      <div class="pay-change-notice">
        <strong>Buyer decision pending</strong><br>
        Authorized gross remains
        <?= $fmtMoney($pendingPayChange['original_authorized_gross']) ?>
        until this request is resolved.
      </div>

      <div class="pay-change-summary">
        <div class="pay-change-metric">
          <span class="muted">Authorized gross</span>
          <strong><?= $fmtMoney($pendingPayChange['original_authorized_gross']) ?></strong>
        </div>

        <div class="pay-change-metric">
          <span class="muted">Requested revised gross</span>
          <strong><?= $fmtMoney($pendingPayChange['requested_total_gross']) ?></strong>
        </div>

        <div class="pay-change-metric">
          <span class="muted">Pending increase</span>
          <strong><?= $fmtMoney($pendingPayChange['requested_increase']) ?></strong>
        </div>
      </div>

      <p>
        <strong>Reason:</strong>
        <?= $h($pendingPayChange['reason']) ?>
      </p>

      <?php if (
          $pendingPayChange['requested_rate'] !== null
          || $pendingPayChange['requested_hours'] !== null
      ): ?>
        <p class="muted">
          <?php if ($pendingPayChange['requested_rate'] !== null): ?>
            Requested rate:
            <?= $fmtMoney($pendingPayChange['requested_rate']) ?>
          <?php endif; ?>

          <?php if ($pendingPayChange['requested_hours'] !== null): ?>
            · Requested hours:
            <?= number_format((float)$pendingPayChange['requested_hours'], 2) ?>
          <?php endif; ?>
        </p>
      <?php endif; ?>

      <form
        method="post"
        class="form-grid"
        onsubmit="return confirm('Resolve this pay-change request? Approval can update authorized gross and fees.');"
      >
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resolve_pay_change">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
        <input type="hidden" name="pay_change_id" value="<?= (int)$pendingPayChange['pay_change_id'] ?>">

        <label>
          Buyer decision
          <select name="resolution_status" required>
            <option value="">Choose outcome</option>
            <option value="APPROVED">Approved in full</option>
            <option value="PARTIALLY_APPROVED">Partially approved</option>
            <option value="DENIED">Denied</option>
          </select>
        </label>

        <label>
          Approved revised gross
          <input
            id="approved-total-gross"
            name="approved_total_gross"
            inputmode="decimal"
            value="<?= $h($moneyInput($pendingPayChange['requested_total_gross'])) ?>"
          >
          <span class="field-hint">
            Ignored when denied. Use the actual buyer-approved total.
          </span>
        </label>

        <label>
          Updated provider fee
          <input
            id="approved-provider-fee"
            name="approved_platform_fee"
            inputmode="decimal"
            value="<?= $h($moneyInput(field_ops_fn_minimum_provider_fee((float)$pendingPayChange['requested_total_gross']))) ?>"
            placeholder="Leave blank until FN shows the revised fee"
          >
        </label>

        <label>
          Updated GL insurance fee
          <input
            id="approved-gl-fee"
            name="approved_insurance_fee"
            inputmode="decimal"
            value="<?= $h($moneyInput(round((float)$pendingPayChange['requested_total_gross'] * field_ops_fn_insurance_fee_rate(), 2))) ?>"
            placeholder="Leave blank until FN shows the revised fee"
          >
        </label>

        <label>
          Updated OAI fee
          <input
            id="approved-oai-fee"
            name="approved_oai_fee"
            inputmode="decimal"
            data-oai-applies="<?= (float)($wo['oai_fee'] ?? 0) > 0 ? '1' : '0' ?>"
            value="<?= (float)($wo['oai_fee'] ?? 0) > 0 ? $h($moneyInput(field_ops_fn_oai_fee((float)$pendingPayChange['requested_total_gross']))) : '' ?>"
            placeholder="Leave blank unless FN shows an OAI charge"
          >
        </label>

        <label class="full">
          Resolution notes
          <textarea
            name="resolution_notes"
            placeholder="Approval message, partial amount explanation, denial reason, or FN reference."
          ></textarea>
        </label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">
            Resolve pay change
          </button>
        </div>
      </form>
    <?php else: ?>
      <p class="muted">
        Record a pay increase already requested on FieldNation. Pending amounts
        remain separate from authorized gross and receivable totals.
      </p>

      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_pay_change">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Current authorized gross
          <input
            value="<?= $h($moneyInput($wo['gross_pay'])) ?>"
            readonly
            aria-readonly="true"
          >
        </label>

        <label>
          Requested revised gross
          <input
            id="sli-requested-total"
            name="requested_total_gross"
            inputmode="decimal"
            value="<?= !empty($sliProjection['eligible'])
                ? $h($moneyInput($sliProjection['suggested_total_gross']))
                : '' ?>"
            placeholder="$889.85"
            required
          >
        </label>

        <label>
          Requested rate
          <input
            id="sli-requested-rate"
            name="requested_rate"
            inputmode="decimal"
            value="<?= !empty($sliProjection['authorized_rate'])
                ? $h($moneyInput($sliProjection['authorized_rate']))
                : '' ?>"
            placeholder="$65.00"
          >
        </label>

        <label>
          Requested hours
          <input
            id="sli-requested-hours"
            name="requested_hours"
            inputmode="decimal"
            value="<?= !empty($sliProjection['suggested_billable_hours'])
                ? $h(number_format(
                    (float)$sliProjection['suggested_billable_hours'],
                    2,
                    '.',
                    ''
                ))
                : '' ?>"
            placeholder="13.69"
          >
          <span class="field-hint">
            Editable—use the exact billable hours displayed by FieldNation.
          </span>
        </label>

        <label>
          Requested at
          <input
            type="text"
            name="requested_at"
            value="<?= $h(date('Y-m-d H:i')) ?>"
            placeholder="2026-07-16 22:36"
          >
          <span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label class="full">
          Reason
          <textarea
            name="reason"
            placeholder="Additional hours logged, buyer-approved scope expansion, return visit, added materials, etc."
            required
          ><?= !empty($sliProjection['suggested_reason'])
              ? $h($sliProjection['suggested_reason'])
              : '' ?></textarea>
        </label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">
            Record pending pay change
          </button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($payChanges): ?>
      <div class="pay-change-history">
        <h3>Pay-change history</h3>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Requested</th>
                <th>Status</th>
                <th>Original</th>
                <th>Requested total</th>
                <th>Increase</th>
                <th>Approved total</th>
                <th>Reason / resolution</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payChanges as $payChange):
                $payChangeStatus = strtoupper(
                    (string)($payChange['status'] ?? 'PENDING')
                );
                $payChangeStatusClass = 'pay-change-status-'
                    . strtolower(str_replace('_', '-', $payChangeStatus));
              ?>
                <tr>
                  <td><?= $h($dtDisplay($payChange['requested_at'] ?? '')) ?></td>
                  <td>
                    <strong class="<?= $h($payChangeStatusClass) ?>">
                      <?= $h(str_replace('_', ' ', $payChangeStatus)) ?>
                    </strong>
                  </td>
                  <td><?= $fmtMoney($payChange['original_authorized_gross']) ?></td>
                  <td><?= $fmtMoney($payChange['requested_total_gross']) ?></td>
                  <td><?= $fmtMoney($payChange['requested_increase']) ?></td>
                  <td>
                    <?= $payChange['approved_total_gross'] === null
                        ? '—'
                        : $fmtMoney($payChange['approved_total_gross']) ?>
                  </td>
                  <td>
                    <?= $h($payChange['reason']) ?>
                    <?php if (!empty($payChange['resolution_notes'])): ?>
                      <br>
                      <span class="muted">
                        <?= $h($payChange['resolution_notes']) ?>
                      </span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="grid two">
    <article class="card">
      <h2>Update job state</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_state">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Status
          <select name="status">
            <?php foreach (field_ops_work_statuses() as $status): ?>
              <option value="<?= $h($status) ?>" <?= (string)$wo['status'] === $status ? 'selected' : '' ?>><?= $h(str_replace('_', ' ', $status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Payment
          <select name="payment_status">
            <?php foreach (field_ops_payment_statuses() as $status): ?>
              <option value="<?= $h($status) ?>" <?= (string)$wo['payment_status'] === $status ? 'selected' : '' ?>><?= $h($status) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Scheduled start
          <input
            type="text"
            name="scheduled_start_at"
            value="<?= $h($dtInput($wo['scheduled_start_at'] ?? '')) ?>"
            placeholder="2026-07-06 14:00"
          >
          <span style="font-size:11px;color:var(--muted);margin-top:-4px;">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>
          Scheduled end
          <input
            type="text"
            name="scheduled_end_at"
            value="<?= $h($dtInput($wo['scheduled_end_at'] ?? '')) ?>"
            placeholder="2026-07-06 19:00"
          >
          <span style="font-size:11px;color:var(--muted);margin-top:-4px;">24-hour format: YYYY-MM-DD HH:MM</span>
        </label>

        <label>
          FN payment terms
          <select name="payment_terms_days">
            <option value="" <?= $wo['payment_terms_days'] === null ? 'selected' : '' ?>>
              Custom / unknown
            </option>
            <option value="0" <?= (string)($wo['payment_terms_days'] ?? '') === '0' ? 'selected' : '' ?>>
              0-day · first Friday after approval
            </option>
            <option value="7" <?= (string)($wo['payment_terms_days'] ?? '') === '7' ? 'selected' : '' ?>>
              7-day · second Friday after approval
            </option>
            <option value="14" <?= (string)($wo['payment_terms_days'] ?? '') === '14' ? 'selected' : '' ?>>
              14-day · third Friday after approval
            </option>
          </select>
          <span class="field-hint">
            FieldNation payment cycle shown on the W/O.
          </span>
        </label>

        <label>
          Estimated approval days
          <input
            name="estimated_approval_days"
            inputmode="numeric"
            value="<?= $wo['estimated_approval_days'] === null ? '' : (int)$wo['estimated_approval_days'] ?>"
            placeholder="1"
          >
          <span class="field-hint">
            Forecast only. Does not set the payment date.
          </span>
        </label>

        <label>
          Expected payment date
          <input
            type="date"
            name="expected_payment_at"
            value="<?= $h(substr((string)($wo['expected_payment_at'] ?? ''), 0, 10)) ?>"
          >
          <span class="field-hint">
            Auto-calculated after approval when FN terms are known. Manual override allowed.
          </span>
        </label>

        <label class="full">
          Payment terms / FN notes
          <textarea
            name="payment_terms_text"
            placeholder="Example: Payment processed on the third Friday after approval."
          ><?= $h($wo['payment_terms_text'] ?? '') ?></textarea>
          <span class="field-hint">
            Preserve the payment wording from the work order when available.
          </span>
        </label>

        <input type="hidden" name="oai_fee_control_present" value="1">
        <label>Gross pay <input id="job-gross-pay" name="gross_pay" inputmode="decimal" value="<?= $h($moneyInput($wo['gross_pay'])) ?>"></label>
        <label>Provider fee <input id="job-provider-fee" name="platform_fee" inputmode="decimal" value="<?= $h($moneyInput($wo['platform_fee'])) ?>"></label>
        <label>GL insurance fee <input id="job-gl-fee" name="insurance_fee" inputmode="decimal" value="<?= $h($moneyInput($wo['insurance_fee'] ?? 0)) ?>"></label>
        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" id="job-oai-applies" name="oai_fee_applies" value="1" <?= (float)($wo['oai_fee'] ?? 0) > 0 ? 'checked' : '' ?> style="width:auto;">
          OAI applies (0.5%)
        </label>
        <label>OAI fee <input id="job-oai-fee" name="oai_fee" inputmode="decimal" value="<?= $h($moneyInput($wo['oai_fee'] ?? 0)) ?>"><span class="field-hint">Calculated from gross; editable to match FN.</span></label>
        <label>Bonus pay <input name="bonus_pay" inputmode="decimal" value="<?= $h($moneyInput($wo['bonus_pay'])) ?>"></label>
        <label>Reimbursement <input name="reimbursement_amount" inputmode="decimal" value="<?= $h($moneyInput($wo['reimbursement_amount'])) ?>"></label>
        <label>Mileage <input name="mileage" value="<?= $h($wo['mileage']) ?>"></label>
        <label>Mileage rate <input name="mileage_rate" value="<?= $h($wo['mileage_rate']) ?>"></label>
        <label>Drive minutes <input name="drive_minutes" value="<?= (int)$wo['drive_minutes'] ?>"></label>
        <label>Onsite minutes <input name="onsite_minutes" value="<?= (int)$wo['onsite_minutes'] ?>"></label>
        <label>Admin minutes <input name="admin_minutes" value="<?= (int)$wo['admin_minutes'] ?>"></label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">Update job</button>
        </div>
      </form>
    </article>

    <article class="card">
      <h2>Pull material from inventory</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_material">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label class="full">
          Inventory item
          <select name="inventory_item_id">
            <option value="">Manual material line</option>
            <?php foreach ($items as $item): ?>
              <option value="<?= (int)$item['item_id'] ?>">
                <?= $h($item['item_name']) ?> · <?= $fmtNum($item['qty_on_hand']) ?> <?= $h($item['unit']) ?> · cost <?= $fmtMoney($item['default_unit_cost']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="full">Description <input name="description" placeholder="Auto-fills from item if blank"></label>
        <label>Qty used <input name="quantity_used" inputmode="decimal" placeholder="1" required></label>
        <label>Unit cost <input name="unit_cost" inputmode="decimal" placeholder="$0.00 or inventory default"></label>
        <label>Bill price <input name="bill_price" inputmode="decimal" placeholder="$0.00 or inventory default"></label>

        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="billable" value="1" style="width:auto;"> Billable</label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursable" value="1" style="width:auto;"> Reimbursable</label>

        <div class="full">
          <button class="btn btn-primary" type="submit">Pull material</button>
        </div>
      </form>
    </article>
  </section>

  <section class="grid two" style="margin-top:16px;">
    <article class="card">
      <h2>Add expense</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_expense">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>Date <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>"></label>
        <label>Vendor <input name="vendor" placeholder="Home Depot"></label>
        <label>Category <input name="category" value="Tools"></label>
        <label>Amount <input name="amount" inputmode="decimal" value="<?= $h($moneyInput(0)) ?>" placeholder="$0.00"></label>
        <label class="full">Description <input name="description" placeholder="Fish poles, labels, parking, etc." required></label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursable" value="1" style="width:auto;"> Reimbursable</label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="reimbursed" value="1" style="width:auto;"> Already reimbursed</label>
        <div class="full"><button class="btn btn-primary" type="submit">Add expense</button></div>
      </form>
    </article>

    <article class="card">
      <h2>Add time</h2>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_time">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

        <label>
          Type
          <select name="entry_type">
            <option value="drive">Drive</option>
            <option value="onsite" selected>Onsite</option>
            <option value="paperwork">Paperwork</option>
            <option value="shopping">Shopping</option>
            <option value="waiting">Waiting</option>
          </select>
        </label>
        <label>
          Minutes
          <input name="minutes" inputmode="numeric" placeholder="Calculated from times">
          <span class="field-hint">
            Optional when both started and ended times are entered.
          </span>
        </label>
        <label>Started <input type="text" name="started_at" placeholder="2026-07-02 14:00"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span></label>
        <label>Ended <input type="text" name="ended_at" placeholder="2026-07-02 19:30"><span class="field-hint">24-hour format: YYYY-MM-DD HH:MM</span></label>
        <label class="full">Notes <input name="notes" placeholder="Optional detail"></label>
        <div class="full"><button class="btn btn-primary" type="submit">Add time</button></div>
      </form>
    </article>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Materials</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Description</th><th>Qty</th><th>Unit cost</th><th>Bill price</th><th>Cost total</th><th>Billable</th></tr></thead>
        <tbody>
        <?php if (!$materials): ?><tr><td colspan="6" class="muted">No materials pulled yet.</td></tr><?php endif; ?>
        <?php foreach ($materials as $m): ?>
          <tr>
            <td><strong><?= $h($m['description']) ?></strong><br><span class="muted"><?= $h($m['item_name'] ?? '') ?></span></td>
            <td><?= $fmtNum($m['quantity_used']) ?></td>
            <td><?= $fmtMoney($m['unit_cost']) ?></td>
            <td><?= $fmtMoney($m['bill_price']) ?></td>
            <td><?= $fmtMoney((float)$m['quantity_used'] * (float)$m['unit_cost']) ?></td>
            <td><?= !empty($m['billable']) ? 'Yes' : 'No' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="grid two" style="margin-top:16px;">
    <article class="card">
      <h2>Expenses</h2>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Date</th><th>Vendor</th><th>Description</th><th>Amount</th><th>Reimb.</th></tr></thead>
          <tbody>
          <?php if (!$expenses): ?><tr><td colspan="5" class="muted">No expenses yet.</td></tr><?php endif; ?>
          <?php foreach ($expenses as $e): ?>
            <tr><td><?= $h($e['expense_date']) ?></td><td><?= $h($e['vendor'] ?? '') ?></td><td><?= $h($e['description']) ?></td><td><?= $fmtMoney($e['amount']) ?></td><td><?= !empty($e['reimbursable']) ? 'Yes' : 'No' ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>

    <article class="card">
      <h2>Time entries</h2>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Type</th>
              <th>Started</th>
              <th>Ended</th>
              <th>Minutes</th>
              <th>Notes</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$timeEntries): ?>
            <tr>
              <td colspan="6" class="muted">
                No detailed time entries yet.
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($timeEntries as $t): ?>
            <tr>
              <td>
                <span class="badge"><?= $h($t['entry_type']) ?></span>
              </td>
              <td>
                <?= empty($t['started_at'])
                    ? '—'
                    : $h($dtDisplay($t['started_at'])) ?>
              </td>
              <td>
                <?= empty($t['ended_at'])
                    ? '—'
                    : $h($dtDisplay($t['ended_at'])) ?>
              </td>
              <td><?= (int)$t['minutes'] ?></td>
              <td><?= $h($t['notes'] ?? '') ?></td>
              <td style="min-width:280px;">
                <details>
                  <summary class="btn" style="cursor:pointer;">
                    Edit
                  </summary>

                  <form
                    method="post"
                    class="form-grid"
                    style="margin-top:10px;"
                  >
                    <?= csrf_field() ?>
                    <input
                      type="hidden"
                      name="action"
                      value="update_time"
                    >
                    <input
                      type="hidden"
                      name="work_order_id"
                      value="<?= (int)$workOrderId ?>"
                    >
                    <input
                      type="hidden"
                      name="time_entry_id"
                      value="<?= (int)$t['time_entry_id'] ?>"
                    >

                    <label>
                      Type
                      <select name="entry_type">
                        <?php foreach ([
                          'drive',
                          'onsite',
                          'paperwork',
                          'shopping',
                          'waiting',
                        ] as $entryType): ?>
                          <option
                            value="<?= $h($entryType) ?>"
                            <?= $t['entry_type'] === $entryType
                                ? 'selected'
                                : '' ?>
                          ><?= $h(ucfirst($entryType)) ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>

                    <label>
                      Minutes
                      <input
                        name="minutes"
                        inputmode="numeric"
                        value="<?= (int)$t['minutes'] ?>"
                      >
                    </label>

                    <label>
                      Started
                      <input
                        name="started_at"
                        value="<?= $h($dtInput($t['started_at'] ?? '')) ?>"
                      >
                    </label>

                    <label>
                      Ended
                      <input
                        name="ended_at"
                        value="<?= $h($dtInput($t['ended_at'] ?? '')) ?>"
                      >
                    </label>

                    <label class="full">
                      Notes
                      <textarea name="notes"><?= $h($t['notes'] ?? '') ?></textarea>
                    </label>

                    <div class="full">
                      <button class="btn btn-primary" type="submit">
                        Save time entry
                      </button>
                    </div>
                  </form>

                  <form
                    method="post"
                    style="margin-top:10px;"
                    onsubmit="return confirm('Remove this time entry from totals and SLI calculations?');"
                  >
                    <?= csrf_field() ?>
                    <input
                      type="hidden"
                      name="action"
                      value="remove_time"
                    >
                    <input
                      type="hidden"
                      name="work_order_id"
                      value="<?= (int)$workOrderId ?>"
                    >
                    <input
                      type="hidden"
                      name="time_entry_id"
                      value="<?= (int)$t['time_entry_id'] ?>"
                    >
                    <input
                      name="delete_reason"
                      value="Incorrect or duplicate time entry."
                      style="margin-bottom:8px;"
                    >
                    <button class="btn btn-danger" type="submit">
                      Remove entry
                    </button>
                  </form>
                </details>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </article>
  </section>



  <section class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <div>
        <h2>Attachments</h2>
        <p style="margin-top:0;">Upload receipts, job photos, cable tester exports, signed paperwork, and lead/customer sign-off files. Files are stored privately and can also sync to OneDrive.</p>
      </div>
      <div class="actions">
        <?php if (empty($onedriveStatus['configured'])): ?>
          <span class="badge">OneDrive not configured</span>
        <?php elseif (empty($onedriveStatus['has_refresh_token'])): ?>
          <a class="btn" href="<?= $h(BASE_URL) ?>/accounting/onedrive_connect.php?return_to=<?= urlencode(BASE_URL . '/admin/field_work_order.php?id=' . (int)$workOrderId) ?>">Connect OneDrive</a>
        <?php else: ?>
          <form method="post" style="margin:0;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync_all_attachments_onedrive">
            <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
            <button class="btn btn-primary" type="submit">Sync all to OneDrive</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <form method="post" enctype="multipart/form-data" class="form-grid" style="margin-bottom:16px;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_attachment">
      <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">

      <label>
        Type
        <select name="attachment_type">
          <?php foreach (field_ops_attachment_types() as $typeCode => $typeLabel): ?>
            <option value="<?= $h($typeCode) ?>"><?= $h($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label>
        File
        <input type="file" name="attachment_file" required>
      </label>

      <label class="full">
        Description
        <input name="description" placeholder="Receipt for fish poles, before photo, signed lead release, tester export, etc.">
      </label>

      <div class="full">
        <button class="btn btn-primary" type="submit">Upload attachment</button>
      </div>
    </form>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Type</th>
            <th>File</th>
            <th>Description</th>
            <th>Size</th>
            <th>Uploaded</th>
            <th>OneDrive</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$attachments): ?>
          <tr><td colspan="7" class="muted">No attachments yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($attachments as $attachment): ?>
          <tr>
            <td><span class="badge"><?= $h(field_ops_attachment_types()[$attachment['attachment_type']] ?? $attachment['attachment_type']) ?></span></td>
            <td>
              <a href="<?= $h(BASE_URL) ?>/admin/field_attachment.php?id=<?= (int)$attachment['attachment_id'] ?>" target="_blank" rel="noopener">
                <?= $h($attachment['original_filename']) ?>
              </a>
              <div class="muted"><?= $h($attachment['mime_type'] ?? '') ?></div>
            </td>
            <td><?= $h($attachment['description'] ?? '') ?></td>
            <td><?= number_format(((int)$attachment['file_size_bytes']) / 1024, 1) ?> KB</td>
            <td><?= $h($attachment['uploaded_at']) ?></td>
            <td>
              <?php if (!empty($attachment['onedrive_web_url'])): ?>
                <a class="btn" href="<?= $h($attachment['onedrive_web_url']) ?>" target="_blank" rel="noopener" style="min-height:32px;padding:7px 10px;">Open in OneDrive</a>
              <?php elseif (!empty($onedriveStatus['has_refresh_token'])): ?>
                <form method="post" style="margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="sync_attachment_onedrive">
                  <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
                  <input type="hidden" name="attachment_id" value="<?= (int)$attachment['attachment_id'] ?>">
                  <button class="btn" type="submit" style="min-height:32px;padding:7px 10px;">Sync</button>
                </form>
              <?php else: ?>
                <span class="muted">Local only</span>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="margin:0;" onsubmit="return confirm('Remove this attachment from the W/O?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_attachment">
                <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
                <input type="hidden" name="attachment_id" value="<?= (int)$attachment['attachment_id'] ?>">
                <button class="btn btn-danger" type="submit" style="min-height:32px;padding:7px 10px;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="margin-top:16px;">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
      <div>
        <h2>Create draft invoice</h2>
        <p style="margin-top:0;">For direct, referral, QR/website, or existing-client work, create a draft invoice from W/O labor, billable materials, and reimbursable expenses. FieldNation work stays payout-only.</p>
      </div>
      <?php if ($linkedInvoice): ?>
        <a class="btn btn-primary" href="<?= $h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$linkedInvoice['invoice_id'] ?>">Open invoice</a>
      <?php endif; ?>
    </div>

    <?php if (!$canCreateInvoice): ?>
      <div class="flash-error" style="margin-bottom:0;">This W/O source is FieldNation, so OPS invoicing is disabled. Track payout, fees, materials, and net here only.</div>
    <?php elseif ($linkedInvoice): ?>
      <div class="flash-success" style="margin-bottom:0;">
        Linked invoice exists:
        <strong><?= $h($linkedInvoice['invoice_number'] ?? ('Invoice #' . (int)$linkedInvoice['invoice_id'])) ?></strong>
        · <?= $h($linkedInvoice['status'] ?? '') ?>
      </div>
    <?php else: ?>
      <form method="post" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_invoice">
        <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
        <input type="hidden" name="revenue_account_id" value="<?= (int)$defaultRevenueAccountId ?>">

        <label class="full">
          Client to invoice
          <select name="client_id" required>
            <option value="">Select client</option>
            <?php foreach ($clients as $client): ?>
              <?php $clientLabel = trim((string)($client['dba_name'] ?: $client['legal_name'])); ?>
              <option value="<?= (int)$client['client_id'] ?>" <?= (int)($wo['client_id'] ?? 0) === (int)$client['client_id'] ? 'selected' : '' ?>>
                <?= $h($clientLabel) ?><?= !empty($client['client_code']) ? ' · ' . $h($client['client_code']) : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          Invoice date
          <input type="text" name="invoice_date" value="<?= date('Y-m-d') ?>" placeholder="YYYY-MM-DD">
        </label>

        <label>
          Due date
          <input type="text" name="due_date" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" placeholder="YYYY-MM-DD">
        </label>

        <label class="full">
          Labor description
          <input name="labor_description" value="<?= $h('Field service labor - ' . (string)$wo['title']) ?>">
        </label>

        <label>
          Labor amount
          <input name="labor_amount" inputmode="decimal" value="<?= $h($moneyInput($wo['gross_pay'] ?? 0)) ?>">
        </label>

        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="include_billable_materials" value="1" checked style="width:auto;">
          Include billable materials
        </label>

        <label style="display:flex;align-items:center;gap:8px;">
          <input type="checkbox" name="include_reimbursable_expenses" value="1" checked style="width:auto;">
          Include reimbursable expenses
        </label>

        <label class="full">
          Invoice memo
          <textarea name="memo"><?= $h('Created from Field Ops W/O #' . (int)$workOrderId . ': ' . (string)$wo['title']) ?></textarea>
        </label>

        <div class="full actions">
          <button class="btn btn-primary" type="submit">Create draft invoice</button>
          <span class="muted">Creates a draft only. You still review, issue, and send it from Accounting.</span>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card" style="margin-top:16px;">
    <h2>Discard work order</h2>
    <p>Use this if FN rejects it, you withdraw, the buyer cancels, or this was only a test record. It hides the W/O from the active dashboard but keeps the audit trail.</p>
    <form method="post" class="actions" onsubmit="return confirm('Discard this work order from active Field Ops?');">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="discard_work_order">
      <input type="hidden" name="work_order_id" value="<?= (int)$workOrderId ?>">
      <input type="hidden" name="delete_reason" value="Discarded from work order detail page.">
      <button class="btn btn-danger" type="submit">Discard from active dashboard</button>
    </form>
  </section>
</main>
<script>
(() => {
  const rateInput = document.getElementById('sli-requested-rate');
  const hoursInput = document.getElementById('sli-requested-hours');
  const totalInput = document.getElementById('sli-requested-total');

  if (!rateInput || !hoursInput || !totalInput) {
    return;
  }

  const numericValue = (input) => {
    const cleaned = String(input.value || '').replace(/[^0-9.-]/g, '');
    const value = Number.parseFloat(cleaned);

    return Number.isFinite(value) ? value : 0;
  };

  const recalculate = () => {
    const rate = numericValue(rateInput);
    const hours = numericValue(hoursInput);

    if (rate > 0 && hours > 0) {
      totalInput.value = '$' + (rate * hours).toFixed(2);
    }
  };

  rateInput.addEventListener('input', recalculate);
  hoursInput.addEventListener('input', recalculate);
})();

(() => {
  const numericValue = (input) => {
    const cleaned = String(input?.value || '').replace(/[^0-9.-]/g, '');
    const value = Number.parseFloat(cleaned);

    return Number.isFinite(value) ? value : 0;
  };
  const money = (value) => '$' + Math.max(0, value).toFixed(2);

  const approvedGross = document.getElementById('approved-total-gross');
  const approvedProvider = document.getElementById('approved-provider-fee');
  const approvedGl = document.getElementById('approved-gl-fee');
  const approvedOai = document.getElementById('approved-oai-fee');

  const recalculateApprovedFees = () => {
    const gross = numericValue(approvedGross);

    if (gross <= 0) {
      return;
    }

    approvedProvider.value = money(gross * 0.10);
    approvedGl.value = money(gross * 0.0195);

    if (approvedOai?.dataset.oaiApplies === '1') {
      approvedOai.value = money(gross * 0.005);
    }
  };

  approvedGross?.addEventListener('input', recalculateApprovedFees);

  const jobGross = document.getElementById('job-gross-pay');
  const jobProvider = document.getElementById('job-provider-fee');
  const jobGl = document.getElementById('job-gl-fee');
  const jobOaiApplies = document.getElementById('job-oai-applies');
  const jobOai = document.getElementById('job-oai-fee');

  const recalculateJobFees = () => {
    const gross = numericValue(jobGross);

    if (gross > 0) {
      jobProvider.value = money(gross * 0.10);
      jobGl.value = money(gross * 0.0195);
    }

    if (jobOaiApplies?.checked && gross > 0) {
      jobOai.value = money(gross * 0.005);
    } else if (jobOai && !jobOaiApplies?.checked) {
      jobOai.value = '$0.00';
    }
  };

  jobGross?.addEventListener('input', recalculateJobFees);
  jobOaiApplies?.addEventListener('change', recalculateJobFees);
})();
</script>
</body>
</html>
