-- Keep the existing Prisma V2 naming convention (PascalCase models/camelCase
-- columns). This migration has no OPS v1 dependency.

CREATE TABLE `WorkOrderExpense` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category` ENUM('MATERIAL', 'TRAVEL', 'PARKING_TOLL', 'EQUIPMENT_RENTAL', 'OTHER') NOT NULL DEFAULT 'OTHER',
  `description` VARCHAR(255) NOT NULL,
  `costAmount` DECIMAL(10, 2) NOT NULL,
  `billAmount` DECIMAL(10, 2) NULL,
  `occurredAt` DATETIME(3) NULL,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `voidedAt` DATETIME(3) NULL,
  `workOrderId` BIGINT UNSIGNED NOT NULL,
  `createdById` BIGINT UNSIGNED NOT NULL,
  `voidedById` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  INDEX `WorkOrderExpense_workOrderId_voidedAt_occurredAt_idx` (`workOrderId`, `voidedAt`, `occurredAt`),
  INDEX `WorkOrderExpense_createdById_createdAt_idx` (`createdById`, `createdAt`),
  CONSTRAINT `WorkOrderExpense_workOrderId_fkey` FOREIGN KEY (`workOrderId`) REFERENCES `WorkOrder`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderExpense_createdById_fkey` FOREIGN KEY (`createdById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderExpense_voidedById_fkey` FOREIGN KEY (`voidedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `WorkOrderMaterial` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` ENUM('PURCHASE', 'INVENTORY_PULL', 'OTHER') NOT NULL DEFAULT 'PURCHASE',
  `description` VARCHAR(255) NOT NULL,
  `sku` VARCHAR(100) NULL,
  `quantity` DECIMAL(10, 3) NOT NULL,
  `unitCost` DECIMAL(10, 2) NOT NULL,
  `unitPrice` DECIMAL(10, 2) NULL,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `voidedAt` DATETIME(3) NULL,
  `workOrderId` BIGINT UNSIGNED NOT NULL,
  `createdById` BIGINT UNSIGNED NOT NULL,
  `voidedById` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  INDEX `WorkOrderMaterial_workOrderId_voidedAt_createdAt_idx` (`workOrderId`, `voidedAt`, `createdAt`),
  INDEX `WorkOrderMaterial_createdById_createdAt_idx` (`createdById`, `createdAt`),
  CONSTRAINT `WorkOrderMaterial_workOrderId_fkey` FOREIGN KEY (`workOrderId`) REFERENCES `WorkOrder`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderMaterial_createdById_fkey` FOREIGN KEY (`createdById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `WorkOrderMaterial_voidedById_fkey` FOREIGN KEY (`voidedById`) REFERENCES `OpsUser`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
