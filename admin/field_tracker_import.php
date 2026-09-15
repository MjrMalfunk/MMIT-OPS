<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
require_once __DIR__ . '/../inc/field_tracker_import.php';
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pdo = db();
$userId = (int)(current_user()['user_id'] ?? 0);
$timezone = date_default_timezone_get();
$error = ''; $ready = false; $parsed = null; $preview = null; $history = [];
$expectedId = max(0, (int)($_GET['work_order_id'] ?? 0));
try { field_tracker_ready($pdo); $ready = true; }
catch (Throwable $e) {
    $error = $e instanceof RuntimeException && !$e instanceof PDOException
        ? $e->getMessage() : 'Tracker tables are not ready. Run the staging migration first.';
}
if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate_or_die();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'preview') {
            unset($_SESSION['field_tracker_preview']);
            $file = $_FILES['tracker_file'] ?? [];
            if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
                throw new InvalidArgumentException('Choose a JSON export. If upload failed, check the server upload limit.');
            }
            $raw = file_get_contents($file['tmp_name'], false, null, 0, FIELD_TRACKER_MAX_BYTES + 1);
            if (!is_string($raw)) throw new InvalidArgumentException('Unable to read the uploaded file.');
            $parsed = field_tracker_parse($raw, $timezone);
            $preview = field_tracker_preview($pdo, $parsed);
            if ($expectedId > 0 && (int)($preview['work_order']['work_order_id'] ?? 0) !== $expectedId) {
                $preview['errors'][] = 'This export does not match the work order you opened. Choose the correct job.';
            }
            if ($preview['errors'] === []) $_SESSION['field_tracker_preview'] = ['raw' => $raw, 'timezone' => $timezone,
                'fingerprint' => $preview['fingerprint'], 'user_id' => $userId, 'expires' => time() + 1800,
                'token' => bin2hex(random_bytes(24))];
        } elseif ($action === 'apply') {
            $ticket = $_SESSION['field_tracker_preview'] ?? null;
            $token = $_POST['preview_token'] ?? null;
            if (!is_array($ticket) || !is_string($token) || !hash_equals($ticket['token'], $token)) {
                throw new InvalidArgumentException('No matching preview. Upload the export again.');
            }
            if (($_POST['confirm_review'] ?? '') !== '1') throw new InvalidArgumentException('Confirm that you reviewed the job, times, mileage, and warnings.');
            $reason = $_POST['review_reason'] ?? '';
            if (!is_string($reason)) throw new InvalidArgumentException('Enter a review reason.');
            $importId = field_tracker_apply($pdo, $ticket, $userId, $reason);
            unset($_SESSION['field_tracker_preview']);
            $_SESSION['field_tracker_flash'] = 'Tracker import #' . $importId . ' applied. Pay, fees, job status, and accounting were unchanged.';
            header('Location: ' . BASE_URL . '/admin/field_tracker_import.php'); exit;
        } else throw new InvalidArgumentException('Choose Preview or Apply.');
    } catch (InvalidArgumentException | JsonException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) {
        error_log('Tracker import failed: ' . get_class($e) . ' ' . $e->getCode());
        $error = 'Import could not finish. No partial tracker changes were retained. Upload again; contact support if it repeats.';
    }
}
if ($ready && $parsed === null && isset($_SESSION['field_tracker_preview'])) {
    $ticket = $_SESSION['field_tracker_preview'];
    if (($ticket['expires'] ?? 0) < time() || ($ticket['user_id'] ?? null) !== $userId) unset($_SESSION['field_tracker_preview']);
    else {
        try {
            $parsed = field_tracker_parse($ticket['raw'], $ticket['timezone']);
            $preview = field_tracker_preview($pdo, $parsed);
            if (!hash_equals($ticket['fingerprint'], $preview['fingerprint'])) $preview['errors'][] = 'The work order changed after preview. Upload again before applying.';
            if ($expectedId > 0 && (int)($preview['work_order']['work_order_id'] ?? 0) !== $expectedId) $preview['errors'][] = 'The current preview belongs to a different work order. Upload the correct export.';
        } catch (Throwable $e) {
            unset($_SESSION['field_tracker_preview']); $error = 'The prior preview is no longer available. Upload again.';
        }
    }
}
if ($ready) $history = field_tracker_rows($pdo, 'SELECT i.import_id, i.applied_at, i.work_order_id,
    w.external_work_order_number, w.title FROM field_tracker_imports i
    LEFT JOIN field_work_orders w ON w.work_order_id = i.work_order_id ORDER BY i.import_id DESC LIMIT 20');
$notice = (string)($_SESSION['field_tracker_flash'] ?? ''); unset($_SESSION['field_tracker_flash']);
$labels = ['checked_in_at'=>'Check-in', 'checked_out_at'=>'Checkout', 'actual_left_site_at'=>'Left site (checkout tap)',
    'mileage'=>'Total outing miles', 'drive_minutes'=>'Drive minutes', 'onsite_minutes'=>'Onsite minutes', 'admin_minutes'=>'Admin / waiting minutes'];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Tracker import · MMIT OPS</title>
<style>
:root{color-scheme:dark;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#091626;color:#edf4ff;font-size:16px}
*{box-sizing:border-box}body{margin:0}.page{max-width:1160px;margin:0 auto;padding:28px 20px 60px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
h1{font-size:2rem;margin:.4rem 0 1rem}h2{font-size:1.25rem;margin:0 0 16px}h3{font-size:1rem}p{line-height:1.6;margin:10px 0}
.muted{color:#b5c9e2}.eyebrow{color:#85bdff;font-weight:750;font-size:.875rem;letter-spacing:.08em}
.card{background:#172537;border:1px solid #34465f;border-radius:16px;padding:22px;margin-top:18px}.upload{border-top:3px solid #60a5fa}.preview{border-top:3px solid #53d7bc}
.btn,button{display:inline-block;border:1px solid #5b799e;background:#203853;color:#f5f8ff;border-radius:9px;padding:12px 17px;font:inherit;font-weight:650;text-decoration:none;cursor:pointer}.primary{background:#2563a5}.btn:hover,button:hover{filter:brightness(1.15)}
label{display:block;font-weight:600;margin:14px 0 8px}input,textarea{font:inherit;color:inherit;background:#0b1829;border:1px solid #6280a1;border-radius:8px;padding:12px;max-width:100%}input[type=file],textarea{width:100%}textarea{min-height:85px}input[type=checkbox]{width:20px;height:20px;flex:none}.check{display:flex;align-items:flex-start;gap:12px;font-weight:400;line-height:1.5}
.alert{padding:16px;border-radius:10px;margin:16px 0;line-height:1.6;border:1px solid #cd8f38;background:#332918;color:#ffe0a6}.error{border-color:#dd7474;background:#351f28;color:#ffc9c9}.success{background:#13352e;border-color:#4fa78e;color:#bef5dd}
.scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:left;font-size:.9375rem}th,td{padding:12px 10px;border-bottom:1px solid #34465f;vertical-align:top}th{color:#b8d3f5}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.metric{padding:14px;background:#0e1c2e;border-radius:10px}.metric strong{display:block;font-size:1.35rem;margin-top:6px}a{color:#9fcfff}code{overflow-wrap:anywhere}.small{font-size:.875rem}ul{padding-left:24px;line-height:1.7}
:focus-visible{outline:3px solid #f9d36a;outline-offset:3px}@media(max-width:680px){.grid{grid-template-columns:1fr}.page{padding:20px 12px}.card{padding:16px}h1{font-size:1.7rem}}
</style></head><body><main class="page">
<div class="topbar"><div><div class="eyebrow">LIVE · FIELDNATION</div><h1>Import work tracker data</h1></div><a class="btn" href="<?= $h(BASE_URL) ?>/admin/field_ops.php">Back to Field Ops</a></div>
<p class="muted">Upload a completed outing, review its match, then apply the tracking values. No FieldNation check-in, submission, or payment action is sent.</p>
<?php if ($error !== ''): ?><div class="alert error" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="alert success" role="status"><?= $h($notice) ?></div><?php endif; ?>
<?php if ($ready): ?>
<section class="card upload"><h2>1. Choose an app export</h2><form method="post" enctype="multipart/form-data">
<?= csrf_field() ?><input type="hidden" name="action" value="preview"><input type="hidden" name="MAX_FILE_SIZE" value="<?= FIELD_TRACKER_MAX_BYTES ?>">
<label for="tracker-file">MMIT Work Tracker JSON · maximum 4 MiB</label><input id="tracker-file" type="file" name="tracker_file" accept=".json,application/json" required>
<p class="muted small">Preview does not change the database. The applied export is stored privately with its audit record. Preview expires after 30 minutes.</p><button class="primary" type="submit">Preview export</button></form></section>
<?php if ($parsed !== null && $preview !== null): $wo = $preview['work_order']; ?>
<section class="card preview"><h2>2. Review the outing</h2><p><strong>FN #<?= $h($parsed['number']) ?></strong> · <?= $parsed['round_trip'] ? 'Round trip' : 'One way / next stop' ?></p>
<?php if ($wo): ?><p>Matched OPS job: <a href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$wo['work_order_id'] ?>"><?= $h($wo['title']) ?></a> · <?= $h($wo['status']) ?></p><?php endif; ?>
<p class="muted small">Outing <code><?= $h($parsed['id']) ?></code><br>Times shown and stored in <?= $h($parsed['timezone']) ?>. Each segment is rounded up to a whole minute, matching OPS.</p>
<?php foreach ($preview['errors'] as $message): ?><div class="alert error"><?= $h($message) ?></div><?php endforeach; ?>
<?php if ($preview['warnings'] !== []): ?><div class="alert"><strong>Review before applying</strong><ul><?php foreach ($preview['warnings'] as $message): ?><li><?= $h($message) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!empty($parsed['closing_note'])): ?><div class="alert success"><strong>Tracker closing note</strong><br><?= nl2br($h($parsed['closing_note'])) ?></div><?php endif; ?>
<div class="grid"><div class="metric">Odometer miles<strong><?= $h(number_format($parsed['mileage'], 2)) ?></strong><span class="small"><?= $h($parsed['start_odometer']) ?> → <?= $h($parsed['end_odometer']) ?></span></div>
<div class="metric">Recorded GPS segments<strong><?= $parsed['gps_miles'] === null ? 'Unavailable' : $h(number_format($parsed['gps_miles'], 2)) . ' mi' ?></strong><span class="small"><?= (int)$parsed['gps_points'] ?> points · comparison only</span></div>
<div class="metric">Hands-on / wrap-up<strong><?= $h(number_format($parsed['work_minutes_exact'], 1)) ?> / <?= $h(number_format($parsed['wrap_minutes_exact'], 1)) ?> min</strong><span class="small">Both included in onsite time</span></div></div>
<h3>Time entries to add</h3><div class="scroll"><table><thead><tr><th>Segment</th><th>Start</th><th>End</th><th>Minutes</th></tr></thead><tbody>
<?php foreach ($parsed['segments'] as $segment): ?><tr><td><?= $h($segment['label']) ?></td><td><?= $h($segment['started_at']) ?></td><td><?= $h($segment['ended_at']) ?></td><td><?= (int)$segment['minutes'] ?></td></tr><?php endforeach; ?>
<?php if ($wo && (int)$wo['admin_minutes'] > 0): ?><tr><td>Existing OPS admin time carried forward</td><td>Not app-measured</td><td>—</td><td><?= (int)$wo['admin_minutes'] ?></td></tr><?php endif; ?>
</tbody></table></div>
<?php if (!$parsed['round_trip']): ?><p class="muted small">Unassigned time after checkout: <?= $h(number_format($parsed['post_checkout_minutes'], 1)) ?> minutes (not added to this job).</p><?php endif; ?>
<?php if ($wo): ?><h3>Work-order values</h3><div class="scroll"><table><thead><tr><th>Field</th><th>Current OPS</th><th>After import</th></tr></thead><tbody>
<?php foreach ($preview['changes'] as $field => $value): ?><tr><td><?= $h($labels[$field]) ?></td><td><?= $h($wo[$field] ?? 'Not set') ?></td><td><?= $h($value) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<p class="muted">Checkout is used as the departure tap. Arrival waiting is admin time, not billable onsite time. This pilot cannot merge existing detailed entries or add a second outing to the same job.</p>
<?php if ($preview['errors'] === [] && isset($_SESSION['field_tracker_preview'])): ?>
<h2>3. Apply reviewed tracking</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="apply"><input type="hidden" name="preview_token" value="<?= $h($_SESSION['field_tracker_preview']['token']) ?>">
<label for="review-reason">Review / correction reason</label><textarea id="review-reason" name="review_reason" maxlength="1000" required placeholder="Example: Recorded with the tracker; checked times and odometer against this work order."></textarea>
<label class="check"><input type="checkbox" name="confirm_review" value="1" required><span>I reviewed the exact job, timestamps, odometer readings, and warnings. Apply the displayed changes and time entries.</span></label>
<p class="muted small">Gross pay, fees, mileage rate, payment dates, job status, and accounting journals stay unchanged. Operational profit/hour and SLI suggestions may change with corrected tracking.</p>
<button class="primary" type="submit">Apply tracking to FN #<?= $h($parsed['number']) ?></button></form><?php endif; ?></section><?php endif; ?>
<section class="card"><h2>Recent applied imports</h2><?php if ($history === []): ?><p class="muted">No tracker imports applied yet.</p><?php else: ?><div class="scroll"><table><thead><tr><th>Import</th><th>Work order</th><th>Applied (OPS time)</th></tr></thead><tbody>
<?php foreach ($history as $item): ?><tr><td>#<?= (int)$item['import_id'] ?></td><td><a href="<?= $h(BASE_URL) ?>/admin/field_work_order.php?id=<?= (int)$item['work_order_id'] ?>"><?= $h($item['external_work_order_number']) ?> · <?= $h($item['title']) ?></a></td><td><?= $h($item['applied_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section><?php endif; ?>
</main></body></html>
