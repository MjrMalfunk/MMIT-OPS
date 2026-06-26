CREATE TABLE IF NOT EXISTS vendor_integrations (
  integration_id bigint unsigned NOT NULL AUTO_INCREMENT,
  vendor_code varchar(40) NOT NULL,
  display_name varchar(120) NOT NULL,
  enabled tinyint(1) NOT NULL DEFAULT 0,
  environment varchar(30) NOT NULL DEFAULT 'shared',
  base_url varchar(255) DEFAULT NULL,
  config_json longtext DEFAULT NULL,
  status varchar(40) NOT NULL DEFAULT 'NOT_CONFIGURED',
  last_sync_at datetime DEFAULT NULL,
  last_error varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (integration_id),
  UNIQUE KEY vendor_integrations_vendor_code_uq (vendor_code),
  KEY vendor_integrations_enabled_idx (enabled),
  KEY vendor_integrations_status_idx (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_client_links (
  link_id bigint unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint unsigned NOT NULL,
  vendor_code varchar(40) NOT NULL,
  vendor_org_id varchar(120) NOT NULL,
  vendor_org_name varchar(200) DEFAULT NULL,
  link_status varchar(40) NOT NULL DEFAULT 'ACTIVE',
  matched_by varchar(80) DEFAULT NULL,
  notes varchar(255) DEFAULT NULL,
  last_sync_at datetime DEFAULT NULL,
  last_error varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (link_id),
  UNIQUE KEY vendor_client_links_client_vendor_uq (client_id, vendor_code),
  KEY vendor_client_links_vendor_org_idx (vendor_code, vendor_org_id),
  KEY vendor_client_links_status_idx (link_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_device_status (
  status_id bigint unsigned NOT NULL AUTO_INCREMENT,
  client_id bigint unsigned NOT NULL,
  vendor_code varchar(40) NOT NULL,
  vendor_org_id varchar(120) DEFAULT NULL,
  vendor_device_id varchar(160) DEFAULT NULL,
  syncro_asset_id bigint unsigned DEFAULT NULL,
  device_name varchar(200) NOT NULL,
  normalized_device_key varchar(220) NOT NULL,
  device_role varchar(40) DEFAULT NULL,
  status varchar(40) NOT NULL DEFAULT 'UNKNOWN',
  status_label varchar(120) DEFAULT NULL,
  status_detail varchar(255) DEFAULT NULL,
  last_seen_at datetime DEFAULT NULL,
  last_success_at datetime DEFAULT NULL,
  storage_used_bytes bigint unsigned DEFAULT NULL,
  storage_quota_bytes bigint unsigned DEFAULT NULL,
  raw_json longtext DEFAULT NULL,
  synced_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (status_id),
  UNIQUE KEY vendor_device_status_unique_vendor_device (vendor_code, vendor_device_id),
  KEY vendor_device_status_client_vendor_idx (client_id, vendor_code),
  KEY vendor_device_status_asset_idx (syncro_asset_id),
  KEY vendor_device_status_key_idx (normalized_device_key),
  KEY vendor_device_status_status_idx (status),
  KEY vendor_device_status_synced_idx (synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_sync_runs (
  run_id bigint unsigned NOT NULL AUTO_INCREMENT,
  vendor_code varchar(40) NOT NULL,
  run_type varchar(60) NOT NULL DEFAULT 'manual',
  started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at datetime DEFAULT NULL,
  status varchar(40) NOT NULL DEFAULT 'RUNNING',
  clients_seen int unsigned NOT NULL DEFAULT 0,
  devices_seen int unsigned NOT NULL DEFAULT 0,
  devices_updated int unsigned NOT NULL DEFAULT 0,
  error_count int unsigned NOT NULL DEFAULT 0,
  message varchar(255) DEFAULT NULL,
  raw_json longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (run_id),
  KEY vendor_sync_runs_vendor_idx (vendor_code),
  KEY vendor_sync_runs_status_idx (status),
  KEY vendor_sync_runs_started_idx (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO vendor_integrations (vendor_code, display_name, enabled, status)
VALUES
  ('cove', 'N-able Cove Data Protection', 0, 'NOT_CONFIGURED'),
  ('huntress', 'Huntress', 0, 'NOT_CONFIGURED'),
  ('scoutdns', 'ScoutDNS', 0, 'NOT_CONFIGURED')
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  updated_at = CURRENT_TIMESTAMP;
