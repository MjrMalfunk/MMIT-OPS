CREATE TABLE `WorkOrderTrackerImport` (
    `id` CHAR(36) NOT NULL,
    `shiftId` CHAR(36) NOT NULL,
    `packetSchema` VARCHAR(64) NOT NULL,
    `workOrderNumber` VARCHAR(191) NOT NULL,
    `contentSha256` CHAR(64) NOT NULL,
    `rawPacket` JSON NOT NULL,
    `metrics` JSON NOT NULL,
    `warnings` JSON NOT NULL,
    `startedAt` DATETIME(3) NOT NULL,
    `completedAt` DATETIME(3) NOT NULL,
    `appliedAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `workOrderId` BIGINT UNSIGNED NOT NULL,
    `uploadedById` BIGINT UNSIGNED NOT NULL,

    UNIQUE INDEX `WorkOrderTrackerImport_shiftId_key`(`shiftId`),
    UNIQUE INDEX `WorkOrderTrackerImport_workOrderId_key`(`workOrderId`),
    INDEX `WorkOrderTrackerImport_workOrderNumber_createdAt_idx`(`workOrderNumber`, `createdAt`),
    INDEX `WorkOrderTrackerImport_uploadedById_createdAt_idx`(`uploadedById`, `createdAt`),
    PRIMARY KEY (`id`),
    CONSTRAINT `WorkOrderTrackerImport_workOrderId_fkey`
      FOREIGN KEY (`workOrderId`) REFERENCES `WorkOrder`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `WorkOrderTrackerImport_uploadedById_fkey`
      FOREIGN KEY (`uploadedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
