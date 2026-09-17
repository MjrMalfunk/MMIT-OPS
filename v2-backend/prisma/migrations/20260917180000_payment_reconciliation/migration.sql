ALTER TABLE `WorkOrderInvoice`
  MODIFY `status` ENUM('DRAFT','ISSUED','PAID','VOIDED') NOT NULL DEFAULT 'DRAFT';

CREATE TABLE `PaymentReconciliationEvent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `status` ENUM('APPLIED','REVIEW_REQUIRED') NOT NULL,
  `processorEventId` VARCHAR(191) NOT NULL,
  `balanceTransactionId` VARCHAR(191) NOT NULL,
  `payoutReference` VARCHAR(191) NULL,
  `processorReference` VARCHAR(191) NOT NULL,
  `actualMethod` ENUM('ACH_BANK','CARD') NOT NULL,
  `grossAmount` DECIMAL(10,2) NOT NULL,
  `feeAmount` DECIMAL(10,2) NOT NULL,
  `netAmount` DECIMAL(10,2) NOT NULL,
  `settledAt` DATETIME(3) NOT NULL,
  `reviewReason` VARCHAR(255) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `paymentId` BIGINT UNSIGNED NOT NULL,
  `recordedById` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `PaymentReconciliationEvent_processorEventId_key` (`processorEventId`),
  UNIQUE INDEX `PaymentReconciliationEvent_balanceTransactionId_key` (`balanceTransactionId`),
  INDEX `PaymentReconciliationEvent_paymentId_createdAt_idx` (`paymentId`, `createdAt`),
  INDEX `PaymentReconciliationEvent_status_createdAt_idx` (`status`, `createdAt`),
  CONSTRAINT `PaymentReconciliationEvent_paymentId_fkey` FOREIGN KEY (`paymentId`) REFERENCES `InvoicePayment`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `PaymentReconciliationEvent_recordedById_fkey` FOREIGN KEY (`recordedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
