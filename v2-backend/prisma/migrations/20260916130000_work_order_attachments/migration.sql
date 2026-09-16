-- Private V2 work-order evidence. Binaries live outside public web roots.

CREATE TABLE `WorkOrderAttachment` (
  `id` CHAR(36) NOT NULL,
  `kind` ENUM('RECEIPT', 'PHOTO', 'TRACKER_EXPORT', 'SIGNED_DOCUMENT', 'OTHER') NOT NULL DEFAULT 'OTHER',
  `originalName` VARCHAR(191) NOT NULL,
  `mediaType` VARCHAR(127) NOT NULL,
  `byteSize` INTEGER UNSIGNED NOT NULL,
  `sha256` CHAR(64) NOT NULL,
  `storageKey` VARCHAR(255) NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `deletedAt` DATETIME(3) NULL,
  `workOrderId` BIGINT UNSIGNED NOT NULL,
  `uploadedById` BIGINT UNSIGNED NOT NULL,
  `deletedById` BIGINT UNSIGNED NULL,

  UNIQUE INDEX `WorkOrderAttachment_storageKey_key`(`storageKey`),
  UNIQUE INDEX `WorkOrderAttachment_workOrderId_sha256_key`(`workOrderId`, `sha256`),
  INDEX `WorkOrderAttachment_workOrderId_deletedAt_createdAt_idx`(`workOrderId`, `deletedAt`, `createdAt`),
  INDEX `WorkOrderAttachment_uploadedById_createdAt_idx`(`uploadedById`, `createdAt`),
  PRIMARY KEY (`id`),
  CONSTRAINT `WorkOrderAttachment_workOrderId_fkey`
    FOREIGN KEY (`workOrderId`) REFERENCES `WorkOrder`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderAttachment_uploadedById_fkey`
    FOREIGN KEY (`uploadedById`) REFERENCES `OpsUser`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderAttachment_deletedById_fkey`
    FOREIGN KEY (`deletedById`) REFERENCES `OpsUser`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
