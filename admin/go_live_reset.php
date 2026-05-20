<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/layout.php';
require_once __DIR__ . '/../inc/go_live_reset.php';

require_login();
require_recent_mfa(BASE_URL . '/admin/go_live_reset.php');

if (!go_live_reset_has_internal_user()) {
    http_response_code(403);
    exit('Forbidden');
}

$result = null;
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    $phrase = trim((string)($_POST['confirm_phrase'] ?? ''));
    if ($phrase !== 'RESET BETA DATA') {
        $error = 'Confirmation phrase did not match. Type RESET BETA DATA exactly.';
    } else {
        try {
            $result = go_live_reset_execute(true);
        } catch (Throwable $e) {
            $error = 'Reset failed: ' . $e->getMessage();
        }
    }
}

$snapshot = go_live_reset_snapshot();
page_header('Go-Live Reset', 'admin');
?>
<div style="display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap;">
  <div>
    <h1 style="margin:0 0 8px;font-size:28px;">Go-live reset</h1>
    <div style="opacity:.78;max-width:880px;line-height:1.55;">
      Clears client and transactional test data while preserving your business setup, vendors, products, bundles, templates, users, security settings, and the opening-balance owner contribution journal.
    </div>
  </div>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <a class="btn btn-secondary" style="width:auto;padding:10px 14px;text-decoration:none;" href="<?= htmlspecialchars(BASE_URL) ?>/admin/index.php">Back to admin</a>
  </div>
</div>

<?php if ($error): ?>
  <div style="margin-top:18px;padding:14px 16px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:14px;">
    <?= htmlspecialchars($error) ?>
  </div>
<?php endif; ?>

<?php if ($result): ?>
  <div style="margin-top:18px;padding:16px 18px;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:16px;">
    <div style="font-weight:700;margin-bottom:6px;">Reset complete.</div>
    <div>Opening balance journals preserved: <?= htmlspecialchars(implode(', ', array_map('strval', $result['preserved_opening_journal_ids'])) ?: 'none found') ?>.</div>
    <div>Deleted files: <?= (int)count($result['deleted_files']) ?>.</div>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-top:18px;">
  <?php
  $cards = [
      'Clients' => $snapshot['clients'],
      'Contracts' => $snapshot['contracts'],
      'Invoices' => $snapshot['invoices'],
      'Payments' => $snapshot['payments'],
      'Expenses' => $snapshot['expenses'],
      'Reconciliations' => $snapshot['reconciliations'],
      'Webhook logs' => $snapshot['webhooks'],
      'Portal invites' => $snapshot['portal_invites'] ?? 0,
      'Test files' => $snapshot['test_files'],
  ];
  foreach ($cards as $label => $count):
  ?>
    <div style="padding:16px;border:1px solid rgba(255,255,255,.14);border-radius:16px;background:rgba(255,255,255,.03);">
      <div style="opacity:.7;font-size:12px;text-transform:uppercase;letter-spacing:.08em;"><?= htmlspecialchars($label) ?></div>
      <div style="font-size:28px;font-weight:700;margin-top:6px;"><?= (int)$count ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:minmax(320px,1.2fr) minmax(320px,.8fr);gap:18px;margin-top:18px;align-items:start;">
  <section style="padding:18px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(255,255,255,.03);">
    <h2 style="margin:0 0 12px;font-size:18px;">What gets removed</h2>
    <ul style="margin:0;padding-left:18px;line-height:1.7;opacity:.9;">
      <li>Clients, contacts, locations, contracts, onboarding tasks, client services, and recurring services</li>
      <li>Invoices, invoice lines, invoice delivery history, payments, payment applications, and Stripe webhook logs</li>
      <li>Bills / expenses, attachments, reconciliations, and all journals except opening-balance manual entries</li>
      <li>Customer portal users and all portal invites, so testing can be reset cleanly</li>
      <li>Files in <code>uploads/contracts</code> and local uploaded expense attachments</li>
    </ul>
    <h2 style="margin:18px 0 12px;font-size:18px;">What stays</h2>
    <ul style="margin:0;padding-left:18px;line-height:1.7;opacity:.9;">
      <li>Portal users, login history, MFA/passkeys for internal users, and security settings</li>
      <li>Chart of accounts, vendors, products, bundles, categories, templates, branding, and payment gateway wiring</li>
      <li>The opening-balance / owner-contribution journal used to seed checking</li>
    </ul>
  </section>

  <section style="padding:18px;border:1px solid rgba(255,255,255,.12);border-radius:18px;background:rgba(255,255,255,.03);">
    <h2 style="margin:0 0 12px;font-size:18px;">Run the reset</h2>
    <form method="post" style="display:grid;gap:12px;">
      <?= csrf_field() ?>
      <label style="display:grid;gap:6px;">
        <span style="font-size:13px;opacity:.78;">Type <strong>RESET BETA DATA</strong> to confirm.</span>
        <input type="text" name="confirm_phrase" value="" autocomplete="off" style="padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(0,0,0,.18);color:#fff;">
      </label>
      <button type="submit" class="btn btn-primary" style="width:auto;padding:12px 16px;">Wipe beta data, invites, and keep opening balance</button>
      <div style="font-size:12px;opacity:.68;line-height:1.55;">Use this once you have a verified backup. The action is destructive and intended for go-live prep.</div>
    </form>
  </section>
</div>
<?php page_footer(); ?>
