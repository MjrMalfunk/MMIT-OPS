-- OPS vendor telemetry cache foundation additions.
-- Safe to run repeatedly on MySQL 8+/MariaDB supporting IF NOT EXISTS.
-- No destructive changes; this only creates/extends local OPS cache tables.

CREATE TABLE IF NOT EXISTS vendor_integrations (
  vendor_code varchar(60) NOT NULL PRIMARY KEY,
  display_name varchar(160) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  status varchar(40) NOT NULL DEFAULT 'NOT_CONFIGURED',
  last_sync_at datetime NULL,
  last_error varchar(255) NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_client_links (
  link_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id int NOT NULL,
  vendor_code varchar(60) NOT NULL,
  vendor_org_id varchar(120) NOT NULL,
  vendor_org_name varchar(200) NULL,
  link_status varchar(40) NOT NULL DEFAULT 'ACTIVE',
  matched_by varchar(80) NULL,
  notes varchar(255) NULL,
  last_sync_at datetime NULL,
  last_error varchar(255) NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vendor_client (client_id, vendor_code),
  KEY idx_vendor_org (vendor_code, vendor_org_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_device_status (
  status_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id int NOT NULL,
  vendor_code varchar(60) NOT NULL,
  vendor_org_id varchar(120) NULL,
  vendor_device_id varchar(160) NOT NULL,
  syncro_customer_id int NULL,
  syncro_asset_id int NULL,
  device_name varchar(200) NOT NULL,
  username varchar(160) NULL,
  os_name varchar(200) NULL,
  normalized_device_key varchar(220) NOT NULL,
  device_role varchar(40) NULL,
  status varchar(40) NOT NULL DEFAULT 'UNKNOWN',
  status_label varchar(120) NULL,
  status_detail varchar(255) NULL,
  protection_enabled tinyint(1) NULL,
  last_seen_at datetime NULL,
  last_success_at datetime NULL,
  storage_used_bytes bigint unsigned NULL,
  storage_quota_bytes bigint unsigned NULL,
  raw_summary_json json NULL,
  raw_json json NULL,
  synced_at datetime NOT NULL,
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vendor_device (vendor_code, vendor_device_id),
  KEY idx_client_vendor (client_id, vendor_code),
  KEY idx_syncro_asset (syncro_asset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendor_sync_runs (
  run_id bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vendor_code varchar(60) NOT NULL,
  run_type varchar(60) NOT NULL DEFAULT 'manual',
  status varchar(40) NOT NULL DEFAULT 'RUNNING',
  clients_seen int NOT NULL DEFAULT 0,
  devices_seen int NOT NULL DEFAULT 0,
  devices_updated int NOT NULL DEFAULT 0,
  error_count int NOT NULL DEFAULT 0,
  message varchar(255) NULL,
  raw_json json NULL,
  started_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at datetime NULL,
  KEY idx_vendor_started (vendor_code, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE vendor_device_status
  ADD COLUMN IF NOT EXISTS syncro_customer_id int NULL AFTER vendor_device_id,
  ADD COLUMN IF NOT EXISTS username varchar(160) NULL AFTER device_name,
  ADD COLUMN IF NOT EXISTS os_name varchar(200) NULL AFTER username,
  ADD COLUMN IF NOT EXISTS protection_enabled tinyint(1) NULL AFTER status_detail,
  ADD COLUMN IF NOT EXISTS raw_summary_json json NULL AFTER storage_quota_bytes;

INSERT INTO vendor_integrations (vendor_code, display_name, enabled, status)
VALUES
  ('scoutdns', 'ScoutDNS', 1, 'READY'),
  ('huntress', 'Huntress', 1, 'READY'),
  ('cove', 'Cove', 1, 'READY'),
  ('syncro', 'Syncro', 1, 'READY')
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name), updated_at = CURRENT_TIMESTAMP;
