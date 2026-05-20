<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
accounting_require_ready();

$provider = strtoupper(trim((string)($_REQUEST['provider'] ?? '')));
$status = strtoupper(trim((string)($_REQUEST['status'] ?? 'FAILED')));
if (!in_array($provider, ['', 'STRIPE'], true)) {
    $provider = '';
}
if (!in_array($status, ['', 'RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'], true)) {
    $status = 'FAILED';
}

$flash = null;
$flashType = 'ok';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'retry_one') {
        $eventId = (int)($_POST['webhook_event_id'] ?? 0);
        $result = payment_gateway_reprocess_webhook_event($eventId);
        $flash = !empty($result['ok'])
            ? (string)($result['message'] ?? 'Webhook event reprocessed successfully.')
            : implode('; ', $result['errors'] ?? ['Webhook event could not be reprocessed.']);
        $flashType = !empty($result['ok']) ? 'ok' : 'bad';
    } elseif ($action === 'retry_filtered') {
        $events = payment_gateway_recent_events(['provider' => $provider, 'status' => $status], 50);
        $attempted = 0;
        $succeeded = 0;
        $errors = [];
        foreach ($events as $event) {
            $eventStatus = strtoupper((string)($event['processing_status'] ?? ''));
            if ($eventStatus === 'PROCESSED') {
                continue;
            }
            $attempted++;
            $result = payment_gateway_reprocess_webhook_event((int)$event['webhook_event_id']);
            if (!empty($result['ok'])) {
                $succeeded++;
            } elseif (!empty($result['errors'][0])) {
                $errors[] = $result['errors'][0];
            }
        }
        $flash = 'Retried ' . $attempted . ' event(s); ' . $succeeded . ' succeeded.';
        if ($errors) {
            $flash .= ' Last issue: ' . $errors[0];
        }
        $flashType = $attempted > 0 && $attempted === $succeeded ? 'ok' : ($attempted === 0 ? 'warn' : 'bad');
    }
}

$events = payment_gateway_recent_events(['provider' => $provider, 'status' => $status], 100);
$pendingCount = 0;
foreach ($events as $event) {
    if (strtoupper((string)($event['processing_status'] ?? '')) !== 'PROCESSED') {
        $pendingCount++;
    }
}

page_header('Gateway Reconcile', 'accounting');
accounting_subnav('receivables');
?>
<style>
.reconcile-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start;}
.reconcile-filter{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end;}
.reconcile-filter select{width:100%;padding:10px 12px;}
.reconcile-meta{font-size:12px;opacity:.72;}
@media (max-width:980px){.reconcile-grid,.reconcile-filter{grid-template-columns:1fr;}}
</style>
<?php if ($flash): ?>
  <div class="card" style="margin-bottom:16px;border-color:<?= $flashType === 'ok' ? 'rgba(34,197,94,.35)' : ($flashType === 'warn' ? 'rgba(250,204,21,.35)' : 'rgba(239,68,68,.35)') ?>;">
    <?= accounting_h($flash) ?>
  </div>
<?php endif; ?>
<div class="metric-grid" style="margin-bottom:16px;">
  <div class="card metric-card"><div class="metric-label">Filtered events</div><div class="metric-value"><?= count($events) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Needs attention</div><div class="metric-value"><?= $pendingCount ?></div></div>
  <div class="card metric-card"><div class="metric-label">Provider</div><div class="metric-value" style="font-size:22px;"><?= accounting_h($provider ?: 'ALL') ?></div></div>
  <div class="card metric-card"><div class="metric-label">Status filter</div><div class="metric-value" style="font-size:22px;"><?= accounting_h($status ?: 'ALL') ?></div></div>
</div>
<div class="reconcile-grid">
  <div class="card table-shell">
    <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
      <div>
        <h2 style="margin:0 0 4px;font-size:20px;">Webhook reconcile queue</h2>
        <div class="reconcile-meta">Retry stuck webhook deliveries from the UI without hand-editing the database.</div>
      </div>
      <form method="get" class="reconcile-filter" style="margin:0;min-width:min(100%,620px);">
        <div>
          <label>Provider</label>
          <select name="provider">
            <option value="">All providers</option>
            <option value="STRIPE" <?= $provider === 'STRIPE' ? 'selected' : '' ?>>Stripe</option>
          </select>
        </div>
        <div>
          <label>Status</label>
          <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['RECEIVED','IGNORED','FAILED','PROCESSED'] as $statusOption): ?>
              <option value="<?= accounting_h($statusOption) ?>" <?= $status === $statusOption ? 'selected' : '' ?>><?= accounting_h($statusOption) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><button type="submit" class="btn btn-secondary">Filter</button></div>
      </form>
    </div>
    <table>
      <thead>
      <tr>
        <th>When</th>
        <th>Provider</th>
        <th>Event</th>
        <th>Status</th>
        <th>Linked</th>
        <th>Action</th>
      </tr>
      </thead>
      <tbody>
      <?php if (!$events): ?>
        <tr><td colspan="6" class="empty-state">No webhook events matched this filter.</td></tr>
      <?php else: ?>
        <?php foreach ($events as $event): ?>
          <?php $eventStatus = strtoupper((string)$event['processing_status']); ?>
          <tr>
            <td>
              <div><?= accounting_h((string)$event['created_at']) ?></div>
              <div class="reconcile-meta"><?= accounting_h((string)($event['processed_at'] ?: 'Pending')) ?></div>
            </td>
            <td>
              <div><?= accounting_h((string)$event['provider_name']) ?></div>
              <div class="reconcile-meta">#<?= (int)$event['webhook_event_id'] ?></div>
            </td>
            <td>
              <div><?= accounting_h((string)$event['event_type']) ?></div>
              <div class="reconcile-meta" style="word-break:break-all;"><?= accounting_h((string)$event['event_id']) ?></div>
              <?php if (!empty($event['note'])): ?><div class="reconcile-meta" style="margin-top:6px;"><?= accounting_h((string)$event['note']) ?></div><?php endif; ?>
            </td>
            <td><?= accounting_payment_status_badge_html($eventStatus) ?></td>
            <td>
              <?php if (!empty($event['invoice_id']) && !empty($event['invoice_number'])): ?>
                <div><?= accounting_invoice_link_html((int)$event['invoice_id'], (string)$event['invoice_number']) ?></div>
              <?php else: ?><div>—</div><?php endif; ?>
              <?php if (!empty($event['related_payment_id'])): ?>
                <div class="reconcile-meta"><a href="<?= accounting_h(BASE_URL) ?>/payments/view.php?id=<?= (int)$event['related_payment_id'] ?>">Payment #<?= (int)$event['related_payment_id'] ?></a></div>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($eventStatus !== 'PROCESSED'): ?>
                <form method="post" style="margin:0;display:inline;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="retry_one">
                  <input type="hidden" name="provider" value="<?= accounting_h($provider) ?>">
                  <input type="hidden" name="status" value="<?= accounting_h($status) ?>">
                  <input type="hidden" name="webhook_event_id" value="<?= (int)$event['webhook_event_id'] ?>">
                  <button type="submit" class="btn btn-secondary">Retry</button>
                </form>
              <?php else: ?>
                <span class="reconcile-meta">Already processed</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Batch retry</h2>
      <p style="margin:0 0 12px;opacity:.78;">This retries up to 50 currently filtered events except ones already marked processed.</p>
      <form method="post" style="display:grid;gap:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="retry_filtered">
        <input type="hidden" name="provider" value="<?= accounting_h($provider) ?>">
        <input type="hidden" name="status" value="<?= accounting_h($status) ?>">
        <button type="submit" class="btn btn-primary" <?= $pendingCount <= 0 ? 'disabled' : '' ?>>Retry filtered events</button>
      </form>
    </div>
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Shortcuts</h2>
      <div style="display:grid;gap:10px;">
        <a href="<?= accounting_h(BASE_URL) ?>/payments/gateway_health.php" class="btn btn-secondary" style="justify-content:flex-start;">Open gateway health</a>
        <a href="<?= accounting_h(BASE_URL) ?>/payments/index.php" class="btn btn-secondary" style="justify-content:flex-start;">Back to payment register</a>
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>
