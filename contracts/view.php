<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/syncro.php';
require_once __DIR__ . '/../inc/esignatures.php';
require_login();
accounting_require_ready();
csrf_check();

$contractId = (int)($_GET['id'] ?? $_POST['contract_id'] ?? 0);
$message = null;
$errors = [];
$flash = $_SESSION['contract_view_flash'] ?? null;
if (is_array($flash) && (int)($flash['contract_id'] ?? 0) === $contractId) {
    $message = isset($flash['message']) ? (string)$flash['message'] : null;
    $errors = array_map('strval', (array)($flash['errors'] ?? []));
    unset($_SESSION['contract_view_flash']);
}

function contract_view_redirect_after_post(int $contractId, string $anchor, array $result): void {
    $anchor = preg_replace('/[^A-Za-z0-9_-]/', '', $anchor) ?: 'onboarding-checklist';
    $_SESSION['contract_view_flash'] = [
        'contract_id' => $contractId,
        'message' => !empty($result['ok']) ? (string)($result['message'] ?? 'Contract updated.') : null,
        'errors' => !empty($result['ok']) ? [] : array_values(array_map('strval', (array)($result['errors'] ?? ['Unable to update contract.']))),
    ];
    header('Location: ' . BASE_URL . '/contracts/view.php?id=' . $contractId . '#' . $anchor, true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $contractId > 0) {
    $action = (string)($_POST['action'] ?? '');
    $redirectAnchor = null;
    if ($action === 'set_status') {
        $status = (string)($_POST['contract_status'] ?? 'DRAFT');
        if (strtoupper(trim($status)) === 'ACTIVE') {
            $redirectAnchor = 'onboarding-checklist';
        }
        $result = accounting_contract_status_update($contractId, $status, (int)(current_user()['user_id'] ?? 0));
    } elseif ($action === 'mark_pending_signature') {
        $result = accounting_contract_status_update($contractId, 'PENDING_SIGNATURE', (int)(current_user()['user_id'] ?? 0));
    } elseif ($action === 'upload_signed') {
        $result = accounting_contract_upload_signed_copy($contractId, $_FILES['signed_pdf'] ?? []);
    } elseif ($action === 'upload_audit') {
        $result = accounting_contract_upload_audit_copy($contractId, $_FILES['audit_pdf'] ?? []);
    } elseif ($action === 'toggle_task') {
        $taskId = (int)($_POST['task_id'] ?? 0);
        $redirectAnchor = $taskId > 0 ? ('checklist-item-' . $taskId) : 'onboarding-checklist';
        $complete = !empty($_POST['complete']);
        $result = accounting_contract_set_onboarding_task($taskId, $complete, (int)(current_user()['user_id'] ?? 0));
    } elseif ($action === 'complete_onboarding') {
        $redirectAnchor = 'onboarding-checklist';
        $result = accounting_contract_status_update($contractId, 'ACTIVE', (int)(current_user()['user_id'] ?? 0), [
            'go_live_at' => date('Y-m-d H:i:s'),
            'billing_start_date' => date('Y-m-d'),
        ]);
    } elseif ($action === 'send_esignatures_test') {
        $result = esignatures_send_test_contract($contractId);
    } elseif ($action === 'retry_syncro') {
        $result = syncro_retry_contract_sync($contractId);
        if (!empty($result['ok']) && empty($result['skipped'])) {
            $result['message'] = syncro_action_success_message((string)($result['action'] ?? ''));
        }
    } else {
        $result = ['ok' => false, 'errors' => ['Unknown action.']];
    }
    if ($redirectAnchor !== null) {
        contract_view_redirect_after_post($contractId, $redirectAnchor, $result);
    }
    if (!empty($result['ok'])) {
        $message = (string)($result['message'] ?? 'Contract updated.');
    } else {
        $errors = $result['errors'] ?? ['Unable to update contract.'];
    }
}

$contract = accounting_get_contract($contractId);
if (!$contract) {
    http_response_code(404);
    page_header('Contract not found', 'contracts');
    echo '<div class="flash-error">Contract not found.</div>';
    page_footer();
    exit;
}

$syncroReadiness = syncro_required_fields_status($contract);
$syncroFolderMap = !empty($contract['client_id']) ? syncro_get_client_folder_map((int)$contract['client_id']) : null;
$packages = accounting_service_packages();
$services = accounting_expand_contract_service_rows(accounting_get_contract_services($contractId));
$serviceGroups = accounting_group_contract_services($services);
$clientServices = accounting_contract_client_services($contractId);
$invoices = accounting_contract_invoices($contractId);
$onboardingTasks = accounting_contract_get_onboarding_tasks($contractId);
$onboardingProgress = accounting_contract_onboarding_progress($contractId);
$currentStatus = strtoupper((string)($contract['status'] ?? 'DRAFT'));
$hasSignedCopy = !empty($contract['signed_document_path']);
$hasAuditCopy = !empty($contract['audit_document_path']);
$onboardingCompletedAt = '';
foreach ($onboardingTasks as $onboardingTask) {
    if (!empty($onboardingTask['completed_at']) && (string)$onboardingTask['completed_at'] > $onboardingCompletedAt) {
        $onboardingCompletedAt = (string)$onboardingTask['completed_at'];
    }
}
if (!empty($contract['go_live_at'])) {
    $onboardingCompletedAt = (string)$contract['go_live_at'];
}
$onboardingCompletedLabel = '—';
if ($onboardingCompletedAt !== '') {
    $onboardingCompletedTimestamp = strtotime($onboardingCompletedAt);
    $onboardingCompletedLabel = $onboardingCompletedTimestamp !== false
        ? date('Y-m-d H:i', $onboardingCompletedTimestamp)
        : substr($onboardingCompletedAt, 0, 16);
}
$onboardingCompleteCollapsed = $currentStatus === 'ACTIVE' && !empty($onboardingProgress['all_complete']) && $onboardingTasks !== [];
$canRetrySyncro = $hasSignedCopy || in_array($currentStatus, ['ONBOARDING', 'SIGNED_PENDING_ONBOARDING', 'ACTIVE'], true);
$canCompleteOnboarding = in_array($currentStatus, ['ONBOARDING', 'SIGNED_PENDING_ONBOARDING'], true) && !empty($onboardingProgress['all_complete']);
$esignaturesLatestSend = esignatures_latest_send($contractId);
$esignaturesWebhookUrl = esignatures_webhook_url();
$esignaturesWebhookLabel = esignatures_is_staging_mode() ? 'staging/test webhook URL' : 'webhook URL';
$showEsignaturesTestButton = esignatures_is_enabled() && esignatures_test_mode();
$showUnsignedDraftPreview = !$hasSignedCopy && in_array($currentStatus, ['DRAFT','PENDING_SIGNATURE'], true);
$unsignedDraftPdfHref = BASE_URL . '/contracts/pdf.php?id=' . (int)$contract['contract_id'];
$signedDocumentHref = '';
if (!empty($contract['signed_document_path'])) {
    $signedDocumentValue = trim((string)$contract['signed_document_path']);
    $signedDocumentHref = preg_match('/^https?:\/\//i', $signedDocumentValue) ? $signedDocumentValue : (BASE_URL . '/' . ltrim($signedDocumentValue, '/'));
}
$auditDocumentHref = '';
if (!empty($contract['audit_document_path'])) {
    $auditDocumentValue = trim((string)$contract['audit_document_path']);
    $auditDocumentHref = preg_match('/^https?:\/\//i', $auditDocumentValue) ? $auditDocumentValue : (BASE_URL . '/' . ltrim($auditDocumentValue, '/'));
}
$esignaturesStatusMessages = [];
if ($esignaturesLatestSend) {
    $esignaturesStatusMessages[] = 'Sent via eSignatures TEST';
    $providerStatus = strtolower(trim((string)($esignaturesLatestSend['provider_status'] ?? $esignaturesLatestSend['status'] ?? '')));
    if ($providerStatus === '' || in_array($providerStatus, ['sent', 'pending', 'pending_signature'], true) || str_contains($providerStatus, 'pending')) {
        $esignaturesStatusMessages[] = 'Pending Signature';
    }
    if (!empty($esignaturesLatestSend['signed_at']) || str_contains($providerStatus, 'signed') || str_contains($providerStatus, 'completed') || str_contains($providerStatus, 'finalized')) {
        $esignaturesStatusMessages[] = 'Signed by eSignatures';
    }
    if ($hasSignedCopy) {
        $esignaturesStatusMessages[] = 'Signed document archived';
    } elseif ($currentStatus === 'SIGNED_PENDING_DOCUMENTS' || (string)($esignaturesLatestSend['status'] ?? '') === 'signed_pending_documents') {
        $esignaturesStatusMessages[] = 'eSignatures confirmed this contract was signed, but OPS has not retrieved the signed PDF/audit trail yet.';
    } elseif (!empty($esignaturesLatestSend['signed_document_url'])) {
        $esignaturesStatusMessages[] = 'Signed document available from eSignatures; archive recovery is available from the manual recovery tools if automation cannot attach it.';
    }
    if (in_array($currentStatus, ['ONBOARDING', 'SIGNED_PENDING_ONBOARDING', 'ACTIVE'], true)) {
        $esignaturesStatusMessages[] = 'Onboarding ready';
    }
}
$signatureStatusLabel = 'Not signed yet';
if (!empty($contract['signed_date'])) {
    $signatureStatusLabel = 'Signed ' . (string)$contract['signed_date'];
    if ($currentStatus === 'ONBOARDING') {
        $signatureStatusLabel .= ' · onboarding in progress';
    }
} elseif ($currentStatus === 'SIGNED_PENDING_DOCUMENTS') {
    $signatureStatusLabel = 'Signed · pending signed PDF/audit retrieval';
} elseif ($currentStatus === 'PENDING_SIGNATURE') {
    $signatureStatusLabel = 'Sent for signature';
} elseif ($hasSignedCopy) {
    $signatureStatusLabel = 'Signed copy uploaded';
}


$monthlyRecurringTotal = 0.0;
$billingCycle = accounting_normalize_billing_cycle((string)($contract['billing_cycle'] ?? 'MONTHLY'));
$billingCycleLabel = accounting_billing_cycle_label($billingCycle);
$cycleRecurringTotal = 0.0;
$baseServiceCode = '';
$baseService = null;
$selectedAddons = [];
$coveredServers = 0.0;
$productivitySelection = accounting_productivity_selection_details($services);
$productivityItemCodes = [];
foreach (accounting_productivity_catalog() as $platformMeta) {
    foreach ((array)($platformMeta['licenses'] ?? []) as $licenseMeta) {
        $productivityItemCodes[] = strtoupper((string)($licenseMeta['item_code'] ?? ''));
    }
}

foreach ($services as $svc) {
    $isIncluded = !empty($svc['is_included']);
    $code = strtoupper(trim((string)($svc['item_code'] ?? $svc['service_code'] ?? '')));
    if (!$isIncluded) {
        $monthlyRecurringTotal += (float)($svc['quantity'] ?? 0) * (float)($svc['unit_price'] ?? 0);
        if ($baseService === null && str_starts_with($code, 'MSP-')) {
            $baseService = $svc;
            $baseServiceCode = preg_replace('/^MSP-/', '', $code) ?? '';
        } elseif (!in_array($code, $productivityItemCodes, true)) {
            if (!str_starts_with($code, 'MSP-')) {
                $selectedAddons[] = $svc;
            }
        }
    }
    if (in_array($code, ['SRVR-MGMT', 'SRVR-BKUP', 'SRVR-BK-500'], true)) {
        $coveredServers = max($coveredServers, (float)($svc['quantity'] ?? 0));
    }
}
if ($monthlyRecurringTotal <= 0) {
    $monthlyRecurringTotal = (float)($contract['base_amount'] ?? 0) / accounting_billing_cycle_month_multiplier($billingCycle);
}
$cycleRecurringTotal = round($monthlyRecurringTotal * accounting_billing_cycle_month_multiplier($billingCycle), 2);

$servicePackage = null;
if ($baseServiceCode !== '' && isset($packages[$baseServiceCode])) {
    $servicePackage = $packages[$baseServiceCode];
}
if (!$servicePackage) {
    foreach ($packages as $pkg) {
        if (strcasecmp((string)($pkg['name'] ?? ''), (string)($contract['sla_level'] ?? '')) === 0) {
            $servicePackage = $pkg;
            break;
        }
    }
}

$includedServices = [];
if ($servicePackage) {
    $includedServices = (array)($servicePackage['included_services'] ?? []);
} else {
    foreach ($serviceGroups as $group) {
        foreach ((array)($group['included'] ?? []) as $svc) {
            $label = trim((string)($svc['service_name'] ?? $svc['description'] ?? ''));
            if ($label !== '' && !in_array($label, $includedServices, true)) {
                $includedServices[] = $label;
            }
        }
    }
}
$notIncluded = $servicePackage ? accounting_not_included_unless_selected($servicePackage, $services) : [];
$productivitySummary = ($productivitySelection['platform_code'] ?? 'NONE') === 'NONE'
    ? 'No productivity platform selected'
    : trim((string)($productivitySelection['platform_name'] ?? '') . ' · ' . (string)($productivitySelection['license_name'] ?? ''));

page_header((string)$contract['contract_number'], 'contracts');
?>
<div style="display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:16px;">
  <div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
      <h1 style="margin:0;font-size:28px;"><?= accounting_h((string)$contract['contract_number']) ?></h1>
      <?= accounting_contract_status_badge_html((string)$contract['status']) ?>
    </div>
    <div style="opacity:.82;font-size:16px;"><?= accounting_h((string)$contract['contract_name']) ?></div>
    <div style="opacity:.68;font-size:13px;">Client: <a href="<?= accounting_h(BASE_URL) ?>/clients/view.php?client_id=<?= (int)$contract['client_id'] ?>"><?= accounting_h((string)($contract['dba_name'] ?: $contract['legal_name'])) ?></a></div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php if (!empty($contract['syncro_customer_id']) && defined('SYNCRO_SUBDOMAIN') && SYNCRO_SUBDOMAIN !== ''): ?><a class="btn btn-secondary" style="width:auto;padding:10px 14px;" href="https://<?= accounting_h(syncro_normalize_subdomain((string)SYNCRO_SUBDOMAIN)) ?>.syncromsp.com/customers/<?= (int)$contract['syncro_customer_id'] ?>" target="_blank">Open in Syncro</a><?php endif; ?>
    <?php if ($canRetrySyncro): ?>
    <form method="post" style="margin:0;">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="retry_syncro">
      <button class="btn btn-secondary" style="width:auto;padding:10px 14px;" type="submit">Retry Syncro sync</button>
    </form>
    <?php else: ?>
      <span class="btn btn-secondary" style="width:auto;padding:10px 14px;opacity:.62;cursor:not-allowed;" title="eSignatures must archive the signed agreement before Syncro sync is available.">Retry Syncro sync</span>
    <?php endif; ?>
    <a class="btn btn-secondary" style="width:auto;padding:10px 14px;" href="<?= accounting_h(BASE_URL) ?>/contracts/index.php">Back to contracts</a>
  </div>
</div>

<?php if ($message): ?><div class="flash-success"><?= accounting_h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="flash-error"><?php foreach ($errors as $e): ?><div><?= accounting_h((string)$e) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if (!empty($syncroReadiness['missing'])): ?><div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(248,113,113,.28);background:rgba(127,29,29,.20);"><div style="font-weight:800;margin-bottom:8px;color:#fecaca;">Syncro readiness checklist</div><div style="opacity:.88;margin-bottom:6px;">This client still needs the following before Syncro sync will succeed:</div><div style="display:flex;gap:8px;flex-wrap:wrap;"><?php foreach ($syncroReadiness['missing'] as $label): ?><span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);font-size:12px;"><?= accounting_h((string)$label) ?></span><?php endforeach; ?></div><div style="margin-top:10px;font-size:12px;opacity:.78;">Fill in the client core info plus at least one full location, then retry Syncro sync after the signed agreement starts onboarding.</div></div><?php endif; ?>
<?php if (in_array($currentStatus, ['ONBOARDING','SIGNED_PENDING_ONBOARDING'], true)): ?><div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(14,165,233,.28);background:rgba(3,105,161,.18);"><div style="font-weight:800;margin-bottom:8px;color:#e0f2fe;">Onboarding is now the billing gate</div><div style="opacity:.88;line-height:1.5;">The signed agreement has been stored, Syncro can be pushed during onboarding, and billing will not begin until the onboarding checklist is complete and the contract is marked go-live.</div></div><?php endif; ?>
<?php if ($esignaturesStatusMessages || $esignaturesWebhookUrl !== ''): ?><div class="card" style="padding:14px;margin-bottom:16px;border:1px solid rgba(139,92,246,.28);background:rgba(76,29,149,.18);"><div style="font-weight:800;margin-bottom:8px;color:#ede9fe;">eSignatures status</div><?php if ($esignaturesStatusMessages): ?><div style="display:flex;gap:8px;flex-wrap:wrap;"><?php foreach (array_values(array_unique($esignaturesStatusMessages)) as $esignaturesStatusMessage): ?><span style="display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);font-size:12px;"><?= accounting_h($esignaturesStatusMessage) ?></span><?php endforeach; ?></div><?php endif; ?><?php if ($esignaturesWebhookUrl !== ''): ?><div style="font-size:12px;opacity:.78;margin-top:8px;word-break:break-all;">Configured eSignatures <?= accounting_h($esignaturesWebhookLabel) ?>: <?= accounting_h($esignaturesWebhookUrl) ?></div><?php endif; ?><?php if (!empty($esignaturesLatestSend['last_webhook_at'])): ?><div style="font-size:12px;opacity:.68;margin-top:8px;">Last eSignatures webhook <?= accounting_h((string)$esignaturesLatestSend['last_webhook_at']) ?></div><?php endif; ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px;">
  <div class="card" style="padding:16px;"><div style="font-size:13px;opacity:.78;">Billing-cycle total</div><div style="font-size:24px;font-weight:800;">$<?= number_format($cycleRecurringTotal, 2) ?></div><div style="font-size:12px;opacity:.68;margin-top:4px;"><?= accounting_h($billingCycleLabel) ?> · $<?= number_format($monthlyRecurringTotal, 2) ?>/mo base</div></div>
  <div class="card" style="padding:16px;"><div style="font-size:13px;opacity:.78;">Onboarding progress</div><div style="font-size:24px;font-weight:800;"><?= (int)($onboardingProgress['percent'] ?? 0) ?>%</div><div style="font-size:12px;opacity:.68;margin-top:4px;"><?= (int)($onboardingProgress['completed'] ?? 0) ?> of <?= (int)($onboardingProgress['required'] ?? 0) ?> required tasks complete</div></div>
  <div class="card" style="padding:16px;"><div style="font-size:13px;opacity:.78;">Billing start</div><div style="font-size:24px;font-weight:800;"><?= accounting_h((string)($contract['billing_start_date'] ?: 'Go-live pending')) ?></div></div>
  <div class="card" style="padding:16px;"><div style="font-size:13px;opacity:.78;">Linked services</div><div style="font-size:24px;font-weight:800;"><?= count($clientServices) ?></div></div>
</div>

<div style="display:grid;grid-template-columns:1.05fr 1.45fr;gap:16px;align-items:start;">
  <div class="card" style="padding:16px;">
    <h2 style="margin:0 0 12px;font-size:19px;">Agreement details</h2>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Service package</div><div><?= accounting_h((string)($servicePackage['name'] ?? $contract['sla_level'] ?: '-')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Billing cycle</div><div><?= accounting_h($billingCycleLabel) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Productivity platform</div><div><?= accounting_h((string)($productivitySelection['platform_name'] ?? 'No productivity platform selected')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">License level</div><div><?= accounting_h((string)($productivitySelection['license_name'] ?? 'None selected')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Covered workstations</div><div><?= number_format((float)($contract['covered_devices'] ?? 0), 0) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Covered users / seats</div><div><?= number_format((float)($contract['covered_users'] ?? 0), 0) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Covered servers</div><div><?= $coveredServers > 0 ? number_format($coveredServers, 0) : '0' ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Primary contact</div><div><?= accounting_h(trim((string)($contract['first_name'] ?? '') . ' ' . (string)($contract['last_name'] ?? ''))) ?: '—' ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Contact email</div><div><?= accounting_h((string)($contract['contact_email'] ?: $contract['client_email'] ?: '—')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Signature status</div><div><?= accounting_h($signatureStatusLabel) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Syncro status</div><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><?php $syncStatus = strtoupper((string)($contract['syncro_sync_status'] ?? 'PENDING')); echo syncro_status_badge_html($syncStatus, !empty($contract['syncro_customer_id']) ? (int)$contract['syncro_customer_id'] : null); ?></div><?php if (!empty($contract['syncro_last_sync_at'])): ?><div style="font-size:12px;opacity:.62;margin-top:4px;">Last sync <?= accounting_h((string)$contract['syncro_last_sync_at']) ?></div><?php endif; ?><?php if (!empty($contract['syncro_last_error'])): ?><div style="font-size:12px;color:#fecaca;margin-top:6px;line-height:1.45;">Last error: <?= accounting_h((string)$contract['syncro_last_error']) ?></div><?php endif; ?><?php if ($syncroFolderMap): ?><?php $folderProvisionStatus = strtoupper((string)($syncroFolderMap['provision_status'] ?? 'PENDING')); $folderProvisionWarn = in_array($folderProvisionStatus, ['PENDING','STAGING_BLOCKED','POLICY_FOLDER_PROVISION_PENDING_MANUAL'], true); ?><div style="font-size:12px;<?= $folderProvisionWarn ? 'color:#fde68a;' : 'opacity:.72;' ?>margin-top:6px;line-height:1.45;">Folder provisioning: <?= accounting_h($folderProvisionStatus) ?><?php if (!empty($syncroFolderMap['provision_message'])): ?> — <?= accounting_h((string)$syncroFolderMap['provision_message']) ?><?php endif; ?><?php if (!empty($syncroFolderMap['policy_assignment_status'])): ?> Policy assignment: <?= accounting_h((string)$syncroFolderMap['policy_assignment_status']) ?><?php endif; ?></div><?php endif; ?></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Onboarding started</div><div><?= accounting_h((string)($contract['onboarding_started_at'] ?? '—')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Go-live</div><div><?= accounting_h((string)($contract['go_live_at'] ?? '—')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Billing start date</div><div><?= accounting_h((string)($contract['billing_start_date'] ?? '—')) ?></div></div>
      <div><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Auto renew</div><div><?= !empty($contract['auto_renew']) ? 'Yes' : 'No' ?></div></div>
    </div>
    <?php if (!empty($contract['notes'])): ?><div style="margin-top:14px;"><div style="font-size:12px;opacity:.7;margin-bottom:2px;">Notes / scope</div><div><?= nl2br(accounting_h((string)$contract['notes'])) ?></div></div><?php endif; ?>
    <?php if ($signedDocumentHref !== ''): ?>
      <div style="margin-top:16px;display:flex;justify-content:center;">
        <a class="btn btn-primary" style="width:auto;padding:10px 16px;text-decoration:none;" href="<?= accounting_h($signedDocumentHref) ?>" target="_blank" rel="noopener noreferrer">Open signed copy</a>
      </div>
    <?php endif; ?>
  </div>

  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div><h2 style="margin:0;font-size:19px;">Service schedule</h2><div style="opacity:.75;">Order form summary, selected platform licensing, and optional add-ons.</div></div>
    </div>

    <div style="padding:14px;border-radius:14px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);display:grid;gap:14px;">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
        <div>
          <div style="font-size:18px;font-weight:800;"><?= accounting_h((string)($servicePackage['name'] ?? $contract['sla_level'] ?: 'Service Package')) ?></div>
          <div style="opacity:.72;font-size:13px;margin-top:4px;"><?= accounting_h((string)($servicePackage['description'] ?? $contract['notes'] ?? '')) ?></div>
        </div>
        <div style="text-align:right;min-width:180px;">
          <div style="font-size:18px;font-weight:800;">$<?= number_format($cycleRecurringTotal, 2) ?></div>
          <div style="opacity:.68;font-size:12px;"><?= accounting_h($billingCycleLabel) ?> total ($<?= number_format($monthlyRecurringTotal, 2) ?>/mo base)</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;">
        <div style="padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);"><div style="font-size:12px;opacity:.68;">Productivity lane</div><div style="font-weight:700;margin-top:4px;"><?= accounting_h($productivitySummary) ?></div></div>
        <div style="padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);"><div style="font-size:12px;opacity:.68;">Covered workstations</div><div style="font-weight:700;margin-top:4px;"><?= number_format((float)($contract['covered_devices'] ?? 0), 0) ?></div></div>
        <div style="padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);"><div style="font-size:12px;opacity:.68;">Covered users / seats</div><div style="font-weight:700;margin-top:4px;"><?= number_format((float)($contract['covered_users'] ?? 0), 0) ?></div></div>
        <div style="padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);"><div style="font-size:12px;opacity:.68;">Covered servers</div><div style="font-weight:700;margin-top:4px;"><?= $coveredServers > 0 ? number_format($coveredServers, 0) : '0' ?></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">
        <div>
          <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Included with this package</div>
          <?php if ($includedServices): ?>
            <?php foreach ($includedServices as $label): ?>
              <div style="padding:6px 9px;border-radius:10px;background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.16);margin-bottom:6px;line-height:1.28"><?= accounting_h((string)$label) ?></div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="opacity:.65;">No included services listed.</div>
          <?php endif; ?>
        </div>
        <div>
          <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Optional add-ons selected</div>
          <?php if ($selectedAddons): ?>
            <?php foreach ($selectedAddons as $svc): ?>
              <div style="padding:6px 9px;border-radius:10px;background:rgba(59,130,246,.08);border:1px solid rgba(59,130,246,.16);margin-bottom:6px;display:flex;justify-content:space-between;gap:10px;align-items:center;line-height:1.28">
                <span><?= accounting_h((string)($svc['service_name'] ?: $svc['description'])) ?></span>
                <div style="text-align:right;white-space:nowrap;">
                  <strong>$<?= number_format((float)$svc['quantity'] * accounting_cycle_unit_price((float)$svc['unit_price'], $billingCycle), 2) ?></strong>
                  <div style="opacity:.68;font-size:12px;"><?= accounting_h(accounting_pricing_model_label((string)($svc['billing_type'] ?? 'FIXED'), true)) ?> · <?= accounting_h($billingCycleLabel) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div style="opacity:.65;">No add-ons selected.</div>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <div style="font-size:12px;letter-spacing:.04em;text-transform:uppercase;opacity:.7;margin-bottom:8px;">Not included unless selected</div>
        <?php if ($notIncluded): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach ($notIncluded as $label): ?>
              <span style="display:inline-flex;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:12px;"><?= accounting_h((string)$label) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="opacity:.65;">Nothing else is currently excluded from the selected package lane.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1.25fr 1fr;gap:16px;align-items:start;margin-top:16px;">
  <div class="card" style="padding:16px;overflow:auto;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
      <div><h2 style="margin:0;font-size:19px;">Linked client services</h2><div style="opacity:.75;">These drive recurring billing and invoice generation.</div></div>
    </div>
    <?php if (!$clientServices): ?><div style="opacity:.72;">No client services linked yet.</div><?php else: ?>
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Description</th><th class="date" style="padding:10px 8px;">Next bill</th><th class="money" style="padding:10px 8px;">Value</th><th class="status" style="padding:10px 8px;">Status</th><th style="padding:10px 8px;">Open</th></tr></thead>
      <tbody><?php foreach ($clientServices as $svc): ?><tr style="border-bottom:1px solid rgba(255,255,255,.06)"><td style="padding:10px 8px;"><strong><?= accounting_h((string)$svc['description']) ?></strong><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)($svc['item_code'] ?: $svc['item_name'])) ?></div></td><td class="date" style="padding:10px 8px;"><?= accounting_h((string)$svc['next_bill_date']) ?></td><td class="money" style="padding:10px 8px;">$<?= number_format((float)$svc['quantity'] * accounting_cycle_unit_price((float)$svc['unit_price'], (string)($svc['billing_cycle'] ?? $billingCycle)), 2) ?></td><td class="status" style="padding:10px 8px;"><?= accounting_client_service_status_badge_html((string)$svc['status']) ?></td><td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/clients/service_view.php?id=<?= (int)$svc['client_service_id'] ?>">Open</a></td></tr><?php endforeach; ?></tbody>
    </table>
    <?php endif; ?>
  </div>

  <div style="display:grid;gap:16px;">
    <div class="card" style="padding:16px;">
      <h2 style="margin:0 0 12px;font-size:19px;">Workflow actions</h2>
      <div style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);font-size:13px;line-height:1.55;margin-bottom:14px;">
        eSignatures owns the normal lane: Draft → Pending Signature → Onboarding → Active. Manual status and document recovery tools are collapsed below for old contracts or failed webhook recovery.
      </div>
      <?php if ($esignaturesLatestSend): ?>
      <div style="padding:10px 12px;border-radius:12px;border:1px solid rgba(34,197,94,.22);background:rgba(34,197,94,.08);font-size:12px;line-height:1.45;margin-bottom:14px;">
        <strong>eSignatures:</strong> <?= accounting_h((string)($esignaturesLatestSend['status'] ?: 'sent')) ?><?php if (!empty($esignaturesLatestSend['esignatures_contract_id'])): ?> · ID <?= accounting_h((string)$esignaturesLatestSend['esignatures_contract_id']) ?><?php endif; ?><?php if (!empty($esignaturesLatestSend['test_mode'])): ?> · TEST<?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($showUnsignedDraftPreview): ?>
      <div style="display:grid;gap:10px;margin-bottom:14px;padding:12px 14px;border-radius:12px;border:1px solid rgba(59,130,246,.24);background:rgba(59,130,246,.08);">
        <div style="font-size:12px;opacity:.78;line-height:1.45;">Admin review only: preview or download the unsigned draft agreement generated from the current OPS contract data before sending to eSignature. The signed eSignatures copy remains the source of truth after completion.</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
          <a class="btn btn-secondary" target="_blank" rel="noopener" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= accounting_h($unsignedDraftPdfHref) ?>">Preview unsigned agreement</a>
          <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= accounting_h($unsignedDraftPdfHref . '&download=1') ?>">Download draft agreement PDF</a>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($showEsignaturesTestButton && in_array($currentStatus, ['DRAFT','PENDING_SIGNATURE'], true)): ?>
      <form method="post" style="display:grid;gap:10px;margin-bottom:14px;">
        <?= csrf_field() ?>
        <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
        <input type="hidden" name="action" value="send_esignatures_test">
        <div style="font-size:12px;opacity:.72;line-height:1.45;">Sends this packet through eSignatures in forced demo/test mode. Signed contracts move into onboarding through the automated webhook flow.</div>
        <button type="submit" class="btn btn-primary" style="width:auto;padding:10px 14px;">Send via eSignatures TEST</button>
      </form>
      <?php endif; ?>
      <?php if ($canCompleteOnboarding): ?>
      <form method="post" style="display:grid;gap:10px;margin-bottom:14px;">
        <?= csrf_field() ?>
        <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
        <input type="hidden" name="action" value="complete_onboarding">
        <div style="font-size:12px;opacity:.72;line-height:1.45;">All required onboarding checklist items are complete. Go-live activates billing and creates a draft invoice for manual review.</div>
        <button type="submit" class="btn btn-primary" style="width:auto;padding:10px 14px;">Mark onboarding complete and go live</button>
      </form>
      <?php elseif (in_array($currentStatus, ['ONBOARDING','SIGNED_PENDING_ONBOARDING'], true)): ?>
      <div style="padding:10px 12px;border-radius:12px;border:1px solid rgba(14,165,233,.22);background:rgba(14,165,233,.08);font-size:12px;line-height:1.45;margin-bottom:14px;">
        Finish the required onboarding checklist below to unlock the go-live action.
      </div>
      <?php elseif ($currentStatus === 'ACTIVE'): ?>
      <div style="padding:10px 12px;border-radius:12px;border:1px solid rgba(34,197,94,.22);background:rgba(34,197,94,.08);font-size:12px;line-height:1.45;margin-bottom:14px;">
        This contract is active. Invoices remain in draft for review until manually issued.
      </div>
      <?php endif; ?>
      <details style="margin-top:8px;border-top:1px solid rgba(255,255,255,.08);padding-top:12px;">
        <summary style="cursor:pointer;font-weight:800;color:#dbeafe;">Legacy/manual upload fallback</summary>
        <div style="font-size:12px;opacity:.72;line-height:1.45;margin:8px 0 14px;">Use only for legacy contracts or failed webhook/document recovery. These controls are intentionally hidden from the normal automated workflow.</div>
        <?php if (!in_array($currentStatus, ['ACTIVE','EXPIRED','CANCELLED'], true)): ?>
        <form method="post" style="display:grid;gap:10px;margin-bottom:14px;">
          <?= csrf_field() ?>
          <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
          <input type="hidden" name="action" value="mark_pending_signature">
          <button type="submit" class="btn btn-secondary" style="width:auto;padding:10px 14px;">Mark sent to signer</button>
        </form>
        <?php endif; ?>
        <form method="post" style="display:grid;gap:10px;margin-bottom:14px;">
          <?= csrf_field() ?>
          <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
          <input type="hidden" name="action" value="set_status">
          <label>Status</label>
          <select name="contract_status" style="width:100%;padding:10px;"><?php foreach (accounting_contract_status_options() as $value => $label): ?><?php $disableActive = $value === 'ACTIVE' && (!$hasSignedCopy || empty($onboardingProgress['all_complete'])) && $currentStatus !== 'ACTIVE'; ?><option value="<?= accounting_h($value) ?>" <?= $currentStatus === $value ? 'selected' : '' ?> <?= $disableActive ? 'disabled' : '' ?>><?= accounting_h($label) ?></option><?php endforeach; ?></select>
          <div style="font-size:12px;opacity:.72;line-height:1.45;">Fallback status override. Active remains gated by a signed agreement and completed required onboarding tasks.</div>
          <button type="submit" class="btn btn-secondary" style="width:auto;padding:10px 14px;">Update status</button>
        </form>
        <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px;">
          <?= csrf_field() ?>
          <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
          <input type="hidden" name="action" value="upload_signed">
          <label>Upload signed PDF</label>
          <input type="file" name="signed_pdf" accept="application/pdf">
          <div style="font-size:12px;opacity:.72;line-height:1.45;">Fallback only: stores the signed copy, starts onboarding, and pushes the client toward Syncro if automation did not attach the signed document.</div>
          <button type="submit" class="btn btn-secondary" style="width:auto;padding:10px 14px;">Upload signed PDF and start onboarding</button>
        </form>
        <form method="post" enctype="multipart/form-data" style="display:grid;gap:10px;margin-top:14px;">
          <?= csrf_field() ?>
          <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
          <input type="hidden" name="action" value="upload_audit">
          <label>Upload audit trail PDF</label>
          <input type="file" name="audit_pdf" accept="application/pdf">
          <div style="font-size:12px;opacity:.72;line-height:1.45;">Fallback only: stores the certificate / audit trail separately from the signed agreement.</div>
          <button type="submit" class="btn btn-secondary" style="width:auto;padding:10px 14px;">Upload audit trail PDF</button>
        </form>
      </details>
    </div>

    <div id="onboarding-checklist" class="card" style="padding:16px;scroll-margin-top:24px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;margin-bottom:12px;">
        <div><h2 style="margin:0;font-size:19px;">Onboarding checklist</h2><div style="opacity:.75;">Billing begins only after the required checklist items are done and the contract is marked go-live.</div></div>
        <div style="text-align:right;"><div style="font-size:26px;font-weight:800;"><?= (int)($onboardingProgress['percent'] ?? 0) ?>%</div><div style="opacity:.68;font-size:12px;"><?= (int)($onboardingProgress['completed'] ?? 0) ?> / <?= (int)($onboardingProgress['required'] ?? 0) ?> required</div></div>
      </div>
      <?php if (!$hasSignedCopy && !in_array($currentStatus, ['ONBOARDING','SIGNED_PENDING_ONBOARDING','ACTIVE'], true)): ?>
        <div style="opacity:.72;line-height:1.55;">eSignatures completion starts onboarding automatically. Once the signed agreement is archived, OPS creates the checklist, can push the organization to Syncro, and holds billing until go-live.</div>
      <?php elseif (!$onboardingTasks): ?>
        <div style="opacity:.72;line-height:1.55;">Onboarding checklist will appear here once the contract enters onboarding.</div>
      <?php else: ?>
        <?php if ($onboardingCompleteCollapsed): ?>
          <div style="padding:14px;border-radius:14px;border:1px solid rgba(34,197,94,.24);background:rgba(34,197,94,.08);display:flex;justify-content:space-between;gap:14px;align-items:center;flex-wrap:wrap;">
            <div>
              <div style="font-weight:800;color:#bbf7d0;">Onboarding complete</div>
              <div style="font-size:13px;opacity:.82;margin-top:4px;"><?= (int)($onboardingProgress['completed'] ?? 0) ?>/<?= (int)($onboardingProgress['required'] ?? 0) ?> tasks completed</div>
              <div style="font-size:12px;opacity:.68;margin-top:4px;">Completed <?= accounting_h($onboardingCompletedLabel) ?></div>
            </div>
          </div>
          <details style="margin-top:12px;">
            <summary style="cursor:pointer;font-weight:800;color:#dbeafe;">View onboarding details / Show checklist</summary>
            <div style="display:grid;gap:10px;margin-top:12px;">
        <?php else: ?>
          <div style="display:grid;gap:10px;">
        <?php endif; ?>
        <?php foreach ($onboardingTasks as $task): ?>
          <div id="checklist-item-<?= (int)$task['task_id'] ?>" style="padding:10px 12px;border-radius:12px;border:1px solid <?= !empty($task['is_completed']) ? 'rgba(34,197,94,.24)' : 'rgba(255,255,255,.08)' ?>;background:<?= !empty($task['is_completed']) ? 'rgba(34,197,94,.08)' : 'rgba(255,255,255,.03)' ?>;display:flex;justify-content:space-between;gap:12px;align-items:flex-start;scroll-margin-top:24px;">
            <div>
              <div style="font-weight:700;"><?= accounting_h((string)$task['task_name']) ?><?php if (!empty($task['is_required'])): ?><span style="opacity:.58;font-weight:500;"> · Required</span><?php endif; ?></div>
              <?php if (!empty($task['task_detail'])): ?><div style="font-size:12px;opacity:.72;margin-top:4px;line-height:1.45;"><?= accounting_h((string)$task['task_detail']) ?></div><?php endif; ?>
              <?php if (!empty($task['completed_at'])): ?><div style="font-size:12px;opacity:.58;margin-top:6px;">Completed <?= accounting_h((string)$task['completed_at']) ?></div><?php endif; ?>
            </div>
            <form method="post" style="margin:0;display:flex;align-items:center;">
              <?= csrf_field() ?>
              <input type="hidden" name="contract_id" value="<?= (int)$contract['contract_id'] ?>">
              <input type="hidden" name="action" value="toggle_task">
              <input type="hidden" name="task_id" value="<?= (int)$task['task_id'] ?>">
              <input type="hidden" name="complete" value="<?= !empty($task['is_completed']) ? '0' : '1' ?>">
              <button type="submit" class="btn btn-secondary" style="width:auto;padding:8px 12px;"><?= !empty($task['is_completed']) ? 'Undo' : 'Complete' ?></button>
            </form>
          </div>
        <?php endforeach; ?>
          </div>
        <?php if ($onboardingCompleteCollapsed): ?>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="card" style="padding:16px;overflow:auto;">
      <h2 style="margin:0 0 12px;font-size:19px;">Invoice history</h2>
      <?php if (!$invoices): ?><div style="opacity:.72;">No invoices generated from this contract yet.</div><?php else: ?><table style="width:100%;border-collapse:collapse;"><thead><tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.10)"><th style="padding:10px 8px;">Invoice</th><th class="money" style="padding:10px 8px;">Amount</th><th class="status" style="padding:10px 8px;">Status</th></tr></thead><tbody><?php foreach ($invoices as $inv): ?><tr style="border-bottom:1px solid rgba(255,255,255,.06)"><td style="padding:10px 8px;"><a href="<?= accounting_h(BASE_URL) ?>/accounting/invoice_view.php?id=<?= (int)$inv['invoice_id'] ?>">📄 <?= accounting_h((string)$inv['invoice_number']) ?></a><div style="opacity:.65;font-size:12px;"><?= accounting_h((string)$inv['invoice_date']) ?></div></td><td class="money" style="padding:10px 8px;">$<?= number_format((float)$inv['total_amount'], 2) ?></td><td class="status" style="padding:10px 8px;"><?= accounting_invoice_status_badge_html((string)$inv['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    </div>
  </div>
</div>
<?php page_footer(); ?>
