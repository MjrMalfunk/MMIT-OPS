ALTER TABLE `PaymentReconciliationEvent`
  MODIFY `status` ENUM('APPLIED','REVIEW_REQUIRED','REJECTED') NOT NULL,
  ADD COLUMN `reviewedAt` DATETIME(3) NULL,
  ADD COLUMN `reviewDecisionNote` VARCHAR(255) NULL,
  ADD COLUMN `reviewedById` BIGINT UNSIGNED NULL,
  ADD INDEX `PaymentReconciliationEvent_reviewedById_reviewedAt_idx` (`reviewedById`, `reviewedAt`),
  ADD CONSTRAINT `PaymentReconciliationEvent_reviewedById_fkey` FOREIGN KEY (`reviewedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
