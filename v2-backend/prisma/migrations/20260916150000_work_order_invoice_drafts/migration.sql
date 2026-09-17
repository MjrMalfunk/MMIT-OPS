-- V2-only direct-work invoice snapshots. FieldNation work remains payout-only.

CREATE TABLE `WorkOrderInvoice` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` ENUM('DRAFT', 'ISSUED', 'VOIDED') NOT NULL DEFAULT 'DRAFT',
  `clientName` VARCHAR(191) NOT NULL,
  `clientEmail` VARCHAR(191) NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'USD',
  `totalAmount` DECIMAL(10, 2) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `issuedAt` DATETIME(3) NULL,
  `voidedAt` DATETIME(3) NULL,
  `workOrderId` BIGINT UNSIGNED NOT NULL,
  `createdById` BIGINT UNSIGNED NOT NULL,
  `issuedById` BIGINT UNSIGNED NULL,
  `voidedById` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  INDEX `WorkOrderInvoice_workOrderId_status_createdAt_idx` (`workOrderId`, `status`, `createdAt`),
  INDEX `WorkOrderInvoice_status_issuedAt_idx` (`status`, `issuedAt`),
  CONSTRAINT `WorkOrderInvoice_workOrderId_fkey` FOREIGN KEY (`workOrderId`) REFERENCES `WorkOrder`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderInvoice_createdById_fkey` FOREIGN KEY (`createdById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderInvoice_issuedById_fkey` FOREIGN KEY (`issuedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderInvoice_voidedById_fkey` FOREIGN KEY (`voidedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `WorkOrderInvoiceLine` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lineType` ENUM('LABOR', 'MATERIAL', 'EXPENSE') NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10, 3) NOT NULL,
  `unitAmount` DECIMAL(10, 2) NOT NULL,
  `totalAmount` DECIMAL(10, 2) NOT NULL,
  `sourceRecordId` VARCHAR(64) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `invoiceId` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  INDEX `WorkOrderInvoiceLine_invoiceId_id_idx` (`invoiceId`, `id`),
  CONSTRAINT `WorkOrderInvoiceLine_invoiceId_fkey` FOREIGN KEY (`invoiceId`) REFERENCES `WorkOrderInvoice`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
