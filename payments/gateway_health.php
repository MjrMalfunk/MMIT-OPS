<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/accounting.php';
require_once __DIR__ . '/../inc/payment_gateway.php';
require_login();
accounting_require_ready();

$provider = strtoupper(trim((string)($_GET['provider'] ?? '')));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
if (!in_array($provider, ['', 'STRIPE'], true)) {
    $provider = '';
}
if (!in_array($status, ['', 'RECEIVED', 'PROCESSED', 'IGNORED', 'FAILED'], true)) {
    $status = '';
}

$gatewayStatus = payment_gateway_gateway_status();
$stripeMode = payment_gateway_stripe_mode();
$totals = payment_gateway_webhook_totals();
$stats7 = payment_gateway_webhook_stats(7);
$recentEvents = payment_gateway_recent_events(['provider' => $provider, 'status' => $status], 100);

page_header('Gateway Health', 'accounting');
accounting_subnav('payments_gateway');
?>
<style>
.gateway-grid{display:grid;grid-template-columns:minmax(0,1.3fr) 380px;gap:18px;align-items:start;}
.gateway-grid .card{padding:18px;}
.gateway-status-grid,.gateway-metric-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;}
.gateway-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid rgba(255,255,255,.12);}
.gateway-pill.ok{background:rgba(34,197,94,.14);border-color:rgba(34,197,94,.30);color:#bbf7d0;}
.gateway-pill.warn{background:rgba(250,204,21,.12);border-color:rgba(250,204,21,.28);color:#fde68a;}
.gateway-pill.bad{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.28);color:#fecaca;}
.gateway-filter-form{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:end;}
.gateway-filter-form select{width:100%;padding:10px 12px;}
.gateway-endpoint{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;word-break:break-all;opacity:.82;}
.gateway-mini{font-size:12px;opacity:.72;}
@media (max-width:980px){.gateway-grid,.gateway-status-grid,.gateway-metric-grid,.gateway-filter-form{grid-template-columns:1fr;}}
</style>
<?php
$badge = static function (bool $ok, string $okText = 'Ready', string $badText = 'Missing'): string {
    $class = $ok ? 'ok' : 'bad';
    $text = $ok ? $okText : $badText;
    return '<span class="gateway-pill ' . $class . '">' . accounting_h($text) . '</span>';
};
?>
<div class="metric-grid" style="margin-bottom:16px;">
  <div class="card metric-card"><div class="metric-label">Webhook events</div><div class="metric-value"><?= (int)($totals['total_count'] ?? 0) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Processed</div><div class="metric-value"><?= (int)($totals['processed_count'] ?? 0) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Failed</div><div class="metric-value"><?= (int)($totals['failed_count'] ?? 0) ?></div></div>
  <div class="card metric-card"><div class="metric-label">Last delivery</div><div class="metric-value" style="font-size:20px;"><?= accounting_h((string)($totals['last_received_at'] ?: '—')) ?></div></div>
</div>

<div class="gateway-grid">
  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <h2 style="margin:0 0 14px;font-size:20px;">Gateway readiness</h2>
      <div class="gateway-status-grid">
        <div style="border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
            <strong>Stripe</strong>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <?= $badge((bool)$gatewayStatus['stripe']['enabled'], 'Enabled', 'Disabled') ?>
              <?php if ($stripeMode === 'live'): ?><?= $badge(true, 'Live mode', 'Live mode') ?><?php elseif ($stripeMode === 'test'): ?> <span class="gateway-pill warn">Test mode</span><?php elseif ($stripeMode === 'unknown'): ?> <span class="gateway-pill warn">Unknown key mode</span><?php endif; ?>
            </div>
          </div>
          <div style="display:grid;gap:10px;margin-top:12px;">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;"><span>Secret key</span><?= $badge((bool)$gatewayStatus['stripe']['secret_key_present']) ?></div>
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;"><span>Webhook secret</span><?= $badge((bool)$gatewayStatus['stripe']['webhook_secret_present']) ?></div>
            <div>
              <div class="gateway-mini">Webhook endpoint</div>
              <div class="gateway-endpoint"><?= accounting_h((string)$gatewayStatus['stripe']['webhook_url']) ?></div>
            </div>
          </div>
        </div>
      </div>
      <div style="margin-top:14px;display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
        <div>
          <div class="gateway-mini">Webhook event table</div>
          <?= $badge((bool)$gatewayStatus['webhook_table_ready'], 'Installed', 'Not installed') ?>
        </div>
        <div class="gateway-mini">Use this page after checkout tests to confirm the gateway posted, ignored, or failed a webhook. Use the reconcile queue for quick retries.</div>
      </div>
      <?php if ($stripeMode === 'test'): ?>
      <div style="margin-top:12px;padding:12px 14px;border-radius:14px;border:1px solid rgba(250,204,21,.28);background:rgba(250,204,21,.08);color:#fde68a;">Stripe is currently configured with a test secret key. Checkout and webhook flow can be verified here, but live client payments will not settle until live keys are installed.</div>
      <?php endif; ?>
      <div style="margin-top:12px;display:flex;justify-content:flex-end;"><a href="<?= accounting_h(BASE_URL) ?>/payments/gateway_reconcile.php" class="btn btn-secondary">Open reconcile queue</a></div>
    </div>

    <div class="card table-shell click-table">
      <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap;margin-bottom:12px;">
        <div>
          <h2 style="margin:0 0 4px;font-size:20px;">Recent webhook events</h2>
          <div class="gateway-mini">Newest deliveries first. Click through to linked payment or invoice when available.</div>
        </div>
        <form method="get" class="gateway-filter-form" style="margin:0;min-width:min(100%,620px);">
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
              <?php foreach (['RECEIVED','PROCESSED','IGNORED','FAILED'] as $statusOption): ?>
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
            <th>Invoice / payment</th>
            <th>Note</th>
                      </tr>
        </thead>
        <tbody>
          <?php if (!$recentEvents): ?>
            <tr><td colspan="6" class="empty-state">No webhook deliveries have been logged yet.</td></tr>
          <?php else: ?>
            <?php foreach ($recentEvents as $event): ?>
              <tr>
                <td>
                  <div><?= accounting_h((string)$event['created_at']) ?></div>
                  <div class="gateway-mini"><?= accounting_h((string)($event['processed_at'] ?: 'Pending processing')) ?></div>
                </td>
                <td>
                  <div><?= accounting_h((string)$event['provider_name']) ?></div>
                  <div class="gateway-mini"><?= accounting_h((string)($event['delivery_id'] ?: '—')) ?></div>
                </td>
                <td>
                  <div><?= accounting_h((string)$event['event_type']) ?></div>
                  <div class="gateway-endpoint" style="max-width:280px;"><?= accounting_h((string)$event['event_id']) ?></div>
                </td>
                <td><?= accounting_payment_status_badge_html((string)$event['processing_status']) ?></td>
                <td>
                  <?php if (!empty($event['invoice_id']) && !empty($event['invoice_number'])): ?>
                    <div><?= accounting_invoice_link_html((int)$event['invoice_id'], (string)$event['invoice_number']) ?></div>
                    <div class="gateway-mini"><?= accounting_h((string)($event['dba_name'] ?: $event['legal_name'] ?: '')) ?></div>
                  <?php else: ?>
                    <div>—</div>
                  <?php endif; ?>
                  <?php if (!empty($event['related_payment_id'])): ?>
                    <div class="gateway-mini"><a href="<?= accounting_h(BASE_URL) ?>/payments/view.php?id=<?= (int)$event['related_payment_id'] ?>">Payment #<?= (int)$event['related_payment_id'] ?></a><?= !empty($event['processor_txn_id']) ? ' · ' . accounting_h((string)$event['processor_txn_id']) : '' ?></div>
                  <?php endif; ?>
                </td>
                <td><?= accounting_h((string)($event['note'] ?: '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div style="display:grid;gap:16px;min-width:0;">
    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Last 7 days</h2>
      <div class="gateway-metric-grid">
        <?php if (!$stats7): ?>
          <div style="grid-column:1 / -1;opacity:.75;">No deliveries recorded in the last 7 days.</div>
        <?php else: ?>
          <?php foreach ($stats7 as $row): ?>
            <div style="border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:14px;">
              <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;">
                <strong><?= accounting_h((string)$row['provider_name']) ?></strong>
                <span class="gateway-pill <?= (int)$row['failed_count'] > 0 ? 'bad' : 'ok' ?>"><?= (int)$row['total_count'] ?> events</span>
              </div>
              <div style="display:grid;gap:8px;margin-top:12px;">
                <div style="display:flex;justify-content:space-between;gap:8px;"><span>Processed</span><strong><?= (int)$row['processed_count'] ?></strong></div>
                <div style="display:flex;justify-content:space-between;gap:8px;"><span>Ignored</span><strong><?= (int)$row['ignored_count'] ?></strong></div>
                <div style="display:flex;justify-content:space-between;gap:8px;"><span>Failed</span><strong><?= (int)$row['failed_count'] ?></strong></div>
                <div style="display:flex;justify-content:space-between;gap:8px;"><span>Last received</span><strong><?= accounting_h((string)($row['last_received_at'] ?: '—')) ?></strong></div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 12px;font-size:18px;">Endpoint checklist</h2>
      <div style="display:grid;gap:12px;">
        <div>
          <div style="font-weight:700;">Stripe</div>
          <div class="gateway-endpoint"><?= accounting_h((string)$gatewayStatus['stripe']['webhook_url']) ?></div>
          <div class="gateway-mini">Register this in the Stripe dashboard and use the webhook signing secret in config.</div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php page_footer(); ?>
