-- Immutable V2-only operational audit ledger. No OPS v1 tables are read or changed.

CREATE TABLE `OpsAuditEvent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `action` VARCHAR(100) NOT NULL,
  `subjectType` VARCHAR(64) NOT NULL,
  `subjectId` VARCHAR(191) NOT NULL,
  `details` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `actorUserId` BIGINT UNSIGNED NOT NULL,

  INDEX `OpsAuditEvent_subjectType_subjectId_createdAt_idx`(`subjectType`, `subjectId`, `createdAt`),
  INDEX `OpsAuditEvent_actorUserId_createdAt_idx`(`actorUserId`, `createdAt`),
  INDEX `OpsAuditEvent_createdAt_idx`(`createdAt`),
  PRIMARY KEY (`id`),
  CONSTRAINT `OpsAuditEvent_actorUserId_fkey`
    FOREIGN KEY (`actorUserId`) REFERENCES `OpsUser`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
