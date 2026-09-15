<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST'] = 'ops.midwestmanagedit.com';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REQUEST_URI'] = '/';
}

require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ((string)$pdo->query('SELECT DATABASE()')->fetchColumn() !== 'mjrmstlj_mittops') {
    throw new RuntimeException('This migration is locked to the Live OPS database. Nothing was changed.');
}

foreach (['field_work_orders', 'field_work_order_time_entries', 'field_work_order_tracking_corrections'] as $table) {
    $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $statement->execute([$table]);
    if ((int)$statement->fetchColumn() !== 1) throw new RuntimeException("Required OPS table missing: {$table}");
}

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS field_tracker_imports (
  import_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  outing_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  work_order_id int(10) unsigned NOT NULL,
  content_sha256 char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  raw_json mediumtext NOT NULL,
  timezone_name varchar(100) NOT NULL,
  old_values longtext NOT NULL,
  new_values longtext NOT NULL,
  review_reason text NOT NULL,
  applied_by bigint(20) unsigned NOT NULL,
  applied_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (import_id),
  UNIQUE KEY uq_tracker_outing (outing_id),
  UNIQUE KEY uq_tracker_work_order (work_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS field_tracker_import_events (
  event_id char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  import_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY (event_id),
  KEY idx_tracker_event_import (import_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

echo "Live FieldNation tracker tables are ready.\n";
