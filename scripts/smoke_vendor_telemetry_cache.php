<?php
declare(strict_types=1);
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? getenv('MMIT_CLI_HTTP_HOST') ?: 'ops-test.midwestmanagedit.com';
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? 'on';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
$limit = 25;
echo "Vendor telemetry cache safe summary\n";
echo 'DB: ' . db()->query('SELECT DATABASE()')->fetchColumn() . "\n";
$sql = "SELECT vds.vendor_code, vds.client_id, c.legal_name, c.dba_name, vds.device_name, vds.status, vds.status_label, vds.last_seen_at, vds.synced_at FROM vendor_device_status vds INNER JOIN clients c ON c.client_id = vds.client_id ORDER BY vds.synced_at DESC LIMIT {$limit}";
foreach (db()->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
    echo json_encode(['vendor'=>$r['vendor_code'],'ops_client'=>$r['client_id'] . ' ' . ($r['legal_name'] ?: $r['dba_name']),'device_name'=>$r['device_name'],'status'=>$r['status'],'status_label'=>$r['status_label'],'last_seen_at'=>$r['last_seen_at'],'synced_at'=>$r['synced_at']], JSON_UNESCAPED_SLASHES) . "\n";
}
