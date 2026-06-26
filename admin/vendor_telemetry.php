<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/vendor_integrations.php';

require_login();

$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

$fmtDate = static function (mixed $value): string {
    $value = trim((string) $value);
    if ($value === '') {
        return '—';
    }

    try {
        return (new DateTimeImmutable($value))->format('M d, Y g:i A');
    } catch (Throwable) {
        return $value;
    }
};

$statusBadge = static function (mixed $status): string {
    $status = strtoupper(trim((string) $status));
    $palette = match ($status) {
        'OK', 'READY', 'ACTIVE', 'COMPLETE', 'COMPLETED', 'SUCCESS', 'SYNCED', 'REPORTED' => ['#dcfce7', '#166534', '#86efac'],
        'RUNNING', 'PENDING', 'NOT_CONFIGURED' => ['#fef3c7', '#92400e', '#fcd34d'],
        'ERROR', 'FAILED', 'DISABLED' => ['#fee2e2', '#991b1b', '#fca5a5'],
        default => ['#e0f2fe', '#075985', '#7dd3fc'],
    };

    return '<span style="display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:800;background:'
        . $palette[0] . ';color:' . $palette[1] . ';border:1px solid ' . $palette[2] . ';">'
        . htmlspecialchars($status !== '' ? $status : 'UNKNOWN', ENT_QUOTES, 'UTF-8')
        . '</span>';
};

$snapshot = vendor_telemetry_dashboard_snapshot();
$summary = (array) ($snapshot['summary'] ?? []);
$integrations = (array) ($snapshot['integrations'] ?? []);
$clientLinks = (array) ($snapshot['client_links'] ?? []);
$deviceStatuses = (array) ($snapshot['device_statuses'] ?? []);
$syncRuns = (array) ($snapshot['sync_runs'] ?? []);

page_header('Vendor Telemetry', 'admin');
?>

<div style="display:grid;gap:18px;">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="margin:0 0 8px;">Vendor telemetry</h1>
      <p style="margin:0;opacity:.82;line-height:1.65;max-width:920px;">
        Read-only visibility into vendor integrations, client mappings, sync runs, and cached device status exposed to the client portal.
      </p>
    </div>
    <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= $h(BASE_URL) ?>/admin/index.php">Back to admin</a>
  </div>

  <section style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;">
    <article class="card" style="padding:16px;">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Integrations</div>
      <strong style="font-size:28px;display:block;margin-top:8px;"><?= (int) ($summary['integrations'] ?? 0) ?></strong>
      <p style="margin:6px 0 0;opacity:.76;">Configured vendor rows.</p>
    </article>

    <article class="card" style="padding:16px;">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Client links</div>
      <strong style="font-size:28px;display:block;margin-top:8px;"><?= (int) ($summary['client_links'] ?? 0) ?></strong>
      <p style="margin:6px 0 0;opacity:.76;">Mapped client-to-vendor accounts.</p>
    </article>

    <article class="card" style="padding:16px;">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Cached devices</div>
      <strong style="font-size:28px;display:block;margin-top:8px;"><?= (int) ($summary['cached_devices'] ?? 0) ?></strong>
      <p style="margin:6px 0 0;opacity:.76;"><?= (int) ($summary['healthy_devices'] ?? 0) ?> healthy · <?= (int) ($summary['attention_devices'] ?? 0) ?> attention.</p>
    </article>

    <article class="card" style="padding:16px;">
      <div style="opacity:.72;text-transform:uppercase;letter-spacing:.08em;font-size:12px;">Protected storage</div>
      <strong style="font-size:28px;display:block;margin-top:8px;"><?= $h($summary['storage_used_label'] ?? '0 B') ?></strong>
      <p style="margin:6px 0 0;opacity:.76;">Total cached vendor-reported storage.</p>
    </article>
  </section>

  <section class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:20px;">Integrations</h2>
    <table style="width:100%;border-collapse:collapse;min-width:860px;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.12);">
          <th style="padding:10px 8px;">Vendor</th>
          <th style="padding:10px 8px;">Status</th>
          <th style="padding:10px 8px;">Environment</th>
          <th style="padding:10px 8px;text-align:right;">Links</th>
          <th style="padding:10px 8px;text-align:right;">Devices</th>
          <th style="padding:10px 8px;">Last sync</th>
          <th style="padding:10px 8px;">Latest run</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$integrations): ?>
          <tr><td colspan="7" style="padding:14px 8px;opacity:.72;">No vendor integrations configured yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($integrations as $row): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.08);">
            <td style="padding:10px 8px;">
              <strong><?= $h($row['display_name'] ?? $row['vendor_code'] ?? '') ?></strong>
              <div style="opacity:.68;font-size:12px;"><?= $h($row['vendor_code'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;"><?= $statusBadge($row['status'] ?? '') ?></td>
            <td style="padding:10px 8px;"><?= $h($row['environment'] ?? '') ?></td>
            <td style="padding:10px 8px;text-align:right;"><?= (int) ($row['linked_clients'] ?? 0) ?></td>
            <td style="padding:10px 8px;text-align:right;"><?= (int) ($row['cached_devices'] ?? 0) ?></td>
            <td style="padding:10px 8px;"><?= $h($fmtDate($row['last_sync_at'] ?? null)) ?></td>
            <td style="padding:10px 8px;">
              <?= $statusBadge($row['latest_run_status'] ?? '') ?>
              <div style="opacity:.68;font-size:12px;margin-top:4px;"><?= $h($row['latest_run_message'] ?? '') ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:20px;">Client vendor links</h2>
    <table style="width:100%;border-collapse:collapse;min-width:940px;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.12);">
          <th style="padding:10px 8px;">Client</th>
          <th style="padding:10px 8px;">Vendor</th>
          <th style="padding:10px 8px;">Vendor account</th>
          <th style="padding:10px 8px;">Link</th>
          <th style="padding:10px 8px;text-align:right;">Devices</th>
          <th style="padding:10px 8px;">Storage</th>
          <th style="padding:10px 8px;">Last cache sync</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$clientLinks): ?>
          <tr><td colspan="7" style="padding:14px 8px;opacity:.72;">No client vendor links yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($clientLinks as $row): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.08);">
            <td style="padding:10px 8px;">
              <a href="<?= $h(BASE_URL) ?>/clients/view.php?client_id=<?= (int) ($row['client_id'] ?? 0) ?>" style="color:inherit;font-weight:800;"><?= $h($row['legal_name'] ?? '') ?></a>
              <div style="opacity:.68;font-size:12px;"><?= $h($row['client_code'] ?? '') ?> · <?= $h($row['client_status'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;"><?= $h($row['vendor_code'] ?? '') ?></td>
            <td style="padding:10px 8px;">
              <strong><?= $h($row['vendor_org_name'] ?? '') ?></strong>
              <div style="opacity:.68;font-size:12px;">ID <?= $h($row['vendor_org_id'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;">
              <?= $statusBadge($row['link_status'] ?? '') ?>
              <div style="opacity:.68;font-size:12px;margin-top:4px;"><?= $h($row['matched_by'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;text-align:right;"><?= (int) ($row['cached_devices'] ?? 0) ?></td>
            <td style="padding:10px 8px;"><?= $h(vendor_telemetry_bytes_label((int) ($row['storage_used_bytes'] ?? 0))) ?></td>
            <td style="padding:10px 8px;"><?= $h($fmtDate($row['latest_synced_at'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:20px;">Cached device status</h2>
    <table style="width:100%;border-collapse:collapse;min-width:980px;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.12);">
          <th style="padding:10px 8px;">Device</th>
          <th style="padding:10px 8px;">Client</th>
          <th style="padding:10px 8px;">Vendor</th>
          <th style="padding:10px 8px;">Status</th>
          <th style="padding:10px 8px;">Role</th>
          <th style="padding:10px 8px;">Storage</th>
          <th style="padding:10px 8px;">Last success</th>
          <th style="padding:10px 8px;">Synced</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$deviceStatuses): ?>
          <tr><td colspan="8" style="padding:14px 8px;opacity:.72;">No cached vendor device statuses yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($deviceStatuses as $row): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.08);">
            <td style="padding:10px 8px;">
              <strong><?= $h($row['device_name'] ?? '') ?></strong>
              <div style="opacity:.68;font-size:12px;">Vendor device <?= $h($row['vendor_device_id'] ?? '—') ?></div>
            </td>
            <td style="padding:10px 8px;">
              <a href="<?= $h(BASE_URL) ?>/clients/view.php?client_id=<?= (int) ($row['client_id'] ?? 0) ?>" style="color:inherit;font-weight:800;"><?= $h($row['legal_name'] ?? '') ?></a>
              <div style="opacity:.68;font-size:12px;"><?= $h($row['client_code'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;"><?= $h($row['vendor_display_name'] ?? $row['vendor_code'] ?? '') ?></td>
            <td style="padding:10px 8px;">
              <?= $statusBadge($row['status'] ?? '') ?>
              <div style="opacity:.68;font-size:12px;margin-top:4px;"><?= $h($row['status_label'] ?? '') ?></div>
            </td>
            <td style="padding:10px 8px;"><?= $h($row['device_role'] ?: 'unknown') ?></td>
            <td style="padding:10px 8px;"><?= $h(vendor_telemetry_bytes_label((int) ($row['storage_used_bytes'] ?? 0))) ?></td>
            <td style="padding:10px 8px;"><?= $h($fmtDate($row['last_success_at'] ?? null)) ?></td>
            <td style="padding:10px 8px;"><?= $h($fmtDate($row['synced_at'] ?? null)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>

  <section class="card" style="padding:16px;overflow:auto;">
    <h2 style="margin:0 0 12px;font-size:20px;">Recent sync runs</h2>
    <table style="width:100%;border-collapse:collapse;min-width:880px;">
      <thead>
        <tr style="text-align:left;border-bottom:1px solid rgba(255,255,255,.12);">
          <th style="padding:10px 8px;">Run</th>
          <th style="padding:10px 8px;">Vendor</th>
          <th style="padding:10px 8px;">Type</th>
          <th style="padding:10px 8px;">Status</th>
          <th style="padding:10px 8px;text-align:right;">Clients</th>
          <th style="padding:10px 8px;text-align:right;">Devices</th>
          <th style="padding:10px 8px;">Started</th>
          <th style="padding:10px 8px;">Message</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$syncRuns): ?>
          <tr><td colspan="8" style="padding:14px 8px;opacity:.72;">No vendor sync runs logged yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($syncRuns as $row): ?>
          <tr style="border-bottom:1px solid rgba(255,255,255,.08);">
            <td style="padding:10px 8px;">#<?= (int) ($row['run_id'] ?? 0) ?></td>
            <td style="padding:10px 8px;"><?= $h($row['vendor_display_name'] ?? $row['vendor_code'] ?? '') ?></td>
            <td style="padding:10px 8px;"><?= $h($row['run_type'] ?? '') ?></td>
            <td style="padding:10px 8px;"><?= $statusBadge($row['status'] ?? '') ?></td>
            <td style="padding:10px 8px;text-align:right;"><?= (int) ($row['clients_seen'] ?? 0) ?></td>
            <td style="padding:10px 8px;text-align:right;"><?= (int) ($row['devices_seen'] ?? 0) ?> seen · <?= (int) ($row['devices_updated'] ?? 0) ?> updated</td>
            <td style="padding:10px 8px;"><?= $h($fmtDate($row['started_at'] ?? null)) ?></td>
            <td style="padding:10px 8px;"><?= $h($row['message'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </section>
</div>

<?php page_footer(); ?>
