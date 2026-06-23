ALTER TABLE clients
  ADD COLUMN portal_backup_visibility_mode enum('contract','enabled','disabled') NOT NULL DEFAULT 'contract' AFTER notes,
  ADD COLUMN portal_backup_visibility_note varchar(255) NULL DEFAULT NULL AFTER portal_backup_visibility_mode,
  ADD COLUMN portal_backup_visibility_updated_at datetime NULL DEFAULT NULL AFTER portal_backup_visibility_note;
