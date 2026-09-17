-- V2-only, balanced accounting journals. No OPS v1 table is read or changed.

CREATE TABLE `AccountingJournal` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` ENUM('POSTED', 'VOIDED') NOT NULL DEFAULT 'POSTED',
  `sourceType` ENUM('WORK_ORDER_INVOICE', 'PAYMENT', 'ADJUSTMENT') NOT NULL,
  `sourceId` VARCHAR(64) NOT NULL,
  `memo` VARCHAR(255) NOT NULL,
  `postedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `voidedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `postedById` BIGINT UNSIGNED NOT NULL,
  `voidedById` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `AccountingJournal_sourceType_sourceId_key` (`sourceType`, `sourceId`),
  INDEX `AccountingJournal_status_postedAt_idx` (`status`, `postedAt`),
  CONSTRAINT `AccountingJournal_postedById_fkey` FOREIGN KEY (`postedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `AccountingJournal_voidedById_fkey` FOREIGN KEY (`voidedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `AccountingJournalEntry` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `accountCode` VARCHAR(16) NOT NULL,
  `debitAmount` DECIMAL(10, 2) NOT NULL,
  `creditAmount` DECIMAL(10, 2) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `journalId` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `AccountingJournalEntry_journalId_id_idx` (`journalId`, `id`),
  INDEX `AccountingJournalEntry_accountCode_createdAt_idx` (`accountCode`, `createdAt`),
  CONSTRAINT `AccountingJournalEntry_journalId_fkey` FOREIGN KEY (`journalId`) REFERENCES `AccountingJournal`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `WorkOrderInvoice`
  ADD COLUMN `accountingJournalId` BIGINT UNSIGNED NULL,
  ADD UNIQUE INDEX `WorkOrderInvoice_accountingJournalId_key` (`accountingJournalId`),
  ADD CONSTRAINT `WorkOrderInvoice_accountingJournalId_fkey` FOREIGN KEY (`accountingJournalId`) REFERENCES `AccountingJournal`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
