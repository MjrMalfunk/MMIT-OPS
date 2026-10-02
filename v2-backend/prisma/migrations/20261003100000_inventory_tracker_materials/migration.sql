CREATE TABLE `InventoryItem` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sku` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `unit` VARCHAR(32) NOT NULL,
  `quantityOnHand` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `reorderPoint` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `unitCost` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `InventoryItem_sku_key`(`sku`),
  INDEX `InventoryItem_active_sku_idx`(`active`, `sku`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `WorkOrderMaterial`
  ADD COLUMN `inventoryItemId` BIGINT UNSIGNED NULL,
  ADD INDEX `WorkOrderMaterial_inventoryItemId_idx`(`inventoryItemId`),
  ADD CONSTRAINT `WorkOrderMaterial_inventoryItemId_fkey`
    FOREIGN KEY (`inventoryItemId`) REFERENCES `InventoryItem`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE;

CREATE TABLE `InventoryMovement` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `kind` ENUM('OPENING_BALANCE', 'RECEIPT', 'WORK_ORDER_USE', 'ADJUSTMENT') NOT NULL,
  `quantityDelta` DECIMAL(12,3) NOT NULL,
  `unitCostSnapshot` DECIMAL(10,2) NOT NULL,
  `notes` TEXT NULL,
  `occurredAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `inventoryItemId` BIGINT UNSIGNED NOT NULL,
  `workOrderMaterialId` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `InventoryMovement_workOrderMaterialId_key`(`workOrderMaterialId`),
  INDEX `InventoryMovement_inventoryItemId_occurredAt_idx`(`inventoryItemId`, `occurredAt`),
  CONSTRAINT `InventoryMovement_inventoryItemId_fkey`
    FOREIGN KEY (`inventoryItemId`) REFERENCES `InventoryItem`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `InventoryMovement_workOrderMaterialId_fkey`
    FOREIGN KEY (`workOrderMaterialId`) REFERENCES `WorkOrderMaterial`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
