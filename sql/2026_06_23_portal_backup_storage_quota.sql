ALTER TABLE clients
  ADD COLUMN portal_backup_storage_quota_gb decimal(10,2) NULL DEFAULT NULL AFTER portal_backup_visibility_note;
