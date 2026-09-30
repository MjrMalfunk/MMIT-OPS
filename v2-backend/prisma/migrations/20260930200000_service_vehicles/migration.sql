-- Vehicle records are imported separately after schema verification and a backup.

CREATE TABLE `Vehicle` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `name` VARCHAR(160) NOT NULL,
  `modelYear` INTEGER NULL,
  `make` VARCHAR(100) NULL,
  `model` VARCHAR(140) NULL,
  `trimName` VARCHAR(140) NULL,
  `vin` VARCHAR(64) NULL,
  `plate` VARCHAR(40) NULL,
  `inServiceDate` DATE NULL,
  `outOfServiceDate` DATE NULL,
  `currentOdometer` DECIMAL(12,2) NULL,
  `acquisitionCost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `expectedResidualValue` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `expectedServiceMiles` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `fuelMpgEstimate` DECIMAL(8,2) NOT NULL DEFAULT 0,
  `fuelPricePerGallonEstimate` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `maintenanceReservePerMile` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `tireReservePerMile` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `repairReservePerMile` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `depreciationPerMileOverride` DECIMAL(10,4) NULL,
  `insuranceAnnualCost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `registrationAnnualCost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `otherFixedAnnualCost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `expectedAnnualBusinessMiles` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  `notes` TEXT NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `Vehicle_legacyId_key` (`legacyId`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleSettings` (
  `id` INTEGER NOT NULL,
  `defaultVehicleId` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleSettings_defaultVehicleId_key` (`defaultVehicleId`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleEvent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `vehicleId` BIGINT UNSIGNED NOT NULL,
  `eventType` VARCHAR(40) NOT NULL,
  `costTreatment` VARCHAR(40) NOT NULL DEFAULT 'NORMAL',
  `eventDate` DATE NOT NULL,
  `odometer` DECIMAL(12,2) NULL,
  `vendor` VARCHAR(180) NULL,
  `description` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(12,2) NULL,
  `verificationStatus` VARCHAR(32) NOT NULL DEFAULT 'RECORDED',
  `gallons` DECIMAL(10,3) NULL,
  `fuelPricePerGallon` DECIMAL(10,4) NULL,
  `amortizeOverMiles` DECIMAL(12,2) NULL,
  `notes` TEXT NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleEvent_legacyId_key` (`legacyId`),
  INDEX `VehicleEvent_vehicleId_eventDate_idx` (`vehicleId`, `eventDate`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleComponent` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `vehicleId` BIGINT UNSIGNED NOT NULL,
  `componentType` VARCHAR(64) NOT NULL,
  `name` VARCHAR(160) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'UNKNOWN',
  `baselineDate` DATE NULL,
  `baselineOdometer` DECIMAL(12,2) NULL,
  `baselineEventId` BIGINT UNSIGNED NULL,
  `warrantyUntilDate` DATE NULL,
  `warrantyUntilMiles` DECIMAL(12,2) NULL,
  `warrantyMileageKind` VARCHAR(32) NOT NULL DEFAULT 'UNCONFIRMED',
  `warrantyRequirements` TEXT NULL,
  `notes` TEXT NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleComponent_legacyId_key` (`legacyId`),
  INDEX `VehicleComponent_vehicleId_componentType_idx` (`vehicleId`, `componentType`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleMaintenanceItem` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `vehicleId` BIGINT UNSIGNED NOT NULL,
  `componentId` BIGINT UNSIGNED NULL,
  `name` VARCHAR(190) NOT NULL,
  `subsystem` VARCHAR(120) NULL,
  `scheduleSource` VARCHAR(64) NOT NULL DEFAULT 'MANUAL',
  `clockType` VARCHAR(64) NOT NULL DEFAULT 'VEHICLE',
  `intervalMiles` DECIMAL(12,2) NULL,
  `intervalMonths` INTEGER NULL,
  `baselineOdometer` DECIMAL(12,2) NULL,
  `baselineDate` DATE NULL,
  `lastEventId` BIGINT UNSIGNED NULL,
  `estimatedServiceCost` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `nextDueOdometer` DECIMAL(12,2) NULL,
  `nextDueDate` DATE NULL,
  `active` BOOLEAN NOT NULL DEFAULT TRUE,
  `notes` TEXT NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleMaintenanceItem_legacyId_key` (`legacyId`),
  INDEX `VehicleMaintenanceItem_vehicleId_nextDueDate_idx` (`vehicleId`, `nextDueDate`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleReceipt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `vehicleId` BIGINT UNSIGNED NOT NULL,
  `eventId` BIGINT UNSIGNED NULL,
  `category` VARCHAR(40) NOT NULL DEFAULT 'OTHER',
  `status` VARCHAR(40) NOT NULL DEFAULT 'CAPTURED',
  `receiptDate` DATE NULL,
  `vendor` VARCHAR(180) NULL,
  `amount` DECIMAL(12,2) NULL,
  `gallons` DECIMAL(10,3) NULL,
  `fuelPricePerGallon` DECIMAL(10,4) NULL,
  `odometer` DECIMAL(12,2) NULL,
  `routeTarget` VARCHAR(60) NOT NULL DEFAULT 'UNROUTED',
  `routeStatus` VARCHAR(40) NOT NULL DEFAULT 'UNROUTED',
  `routedAt` DATETIME(3) NULL,
  `routeNotes` TEXT NULL,
  `parseStatus` VARCHAR(40) NOT NULL DEFAULT 'NOT_PARSED',
  `parseConfidence` DECIMAL(5,4) NULL,
  `rawParseJson` MEDIUMTEXT NULL,
  `notes` TEXT NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleReceipt_legacyId_key` (`legacyId`),
  INDEX `VehicleReceipt_vehicleId_receiptDate_idx` (`vehicleId`, `receiptDate`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleDocument` (
  `id` CHAR(36) NOT NULL,
  `legacyKey` VARCHAR(191) NULL,
  `vehicleId` BIGINT UNSIGNED NOT NULL,
  `eventId` BIGINT UNSIGNED NULL,
  `componentId` BIGINT UNSIGNED NULL,
  `maintenanceId` BIGINT UNSIGNED NULL,
  `receiptId` BIGINT UNSIGNED NULL,
  `originalName` VARCHAR(255) NOT NULL,
  `mimeType` VARCHAR(120) NULL,
  `byteSize` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NULL,
  `storageKey` VARCHAR(191) NULL,
  `oneDriveUrl` VARCHAR(500) NULL,
  `oneDriveItemId` VARCHAR(190) NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleDocument_legacyKey_key` (`legacyKey`),
  INDEX `VehicleDocument_vehicleId_createdAt_idx` (`vehicleId`, `createdAt`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleExpenseDraft` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `legacyId` INTEGER UNSIGNED NULL,
  `receiptId` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(40) NOT NULL DEFAULT 'DRAFT',
  `expenseDate` DATE NULL,
  `vendor` VARCHAR(180) NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `category` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  `notes` TEXT NULL,
  `legacyAccountingExpenseId` VARCHAR(191) NULL,
  `receiptOneDriveUrl` VARCHAR(500) NULL,
  `receiptOneDriveItemId` VARCHAR(190) NULL,
  `legacyRecord` JSON NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,
  `deletedAt` DATETIME(3) NULL,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `VehicleExpenseDraft_legacyId_key` (`legacyId`),
  UNIQUE INDEX `VehicleExpenseDraft_receiptId_key` (`receiptId`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `VehicleMigration` (
  `id` CHAR(64) NOT NULL,
  `sourceDatabase` VARCHAR(191) NOT NULL,
  `sourceExportedAt` VARCHAR(64) NOT NULL,
  `summary` JSON NOT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE `VehicleSettings` ADD CONSTRAINT `VehicleSettings_defaultVehicleId_fkey` FOREIGN KEY (`defaultVehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleEvent` ADD CONSTRAINT `VehicleEvent_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleComponent` ADD CONSTRAINT `VehicleComponent_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleComponent` ADD CONSTRAINT `VehicleComponent_baselineEventId_fkey` FOREIGN KEY (`baselineEventId`) REFERENCES `VehicleEvent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleMaintenanceItem` ADD CONSTRAINT `VehicleMaintenanceItem_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleMaintenanceItem` ADD CONSTRAINT `VehicleMaintenanceItem_componentId_fkey` FOREIGN KEY (`componentId`) REFERENCES `VehicleComponent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleMaintenanceItem` ADD CONSTRAINT `VehicleMaintenanceItem_lastEventId_fkey` FOREIGN KEY (`lastEventId`) REFERENCES `VehicleEvent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleReceipt` ADD CONSTRAINT `VehicleReceipt_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleReceipt` ADD CONSTRAINT `VehicleReceipt_eventId_fkey` FOREIGN KEY (`eventId`) REFERENCES `VehicleEvent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleDocument` ADD CONSTRAINT `VehicleDocument_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleDocument` ADD CONSTRAINT `VehicleDocument_eventId_fkey` FOREIGN KEY (`eventId`) REFERENCES `VehicleEvent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleDocument` ADD CONSTRAINT `VehicleDocument_componentId_fkey` FOREIGN KEY (`componentId`) REFERENCES `VehicleComponent` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleDocument` ADD CONSTRAINT `VehicleDocument_maintenanceId_fkey` FOREIGN KEY (`maintenanceId`) REFERENCES `VehicleMaintenanceItem` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleDocument` ADD CONSTRAINT `VehicleDocument_receiptId_fkey` FOREIGN KEY (`receiptId`) REFERENCES `VehicleReceipt` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
ALTER TABLE `VehicleExpenseDraft` ADD CONSTRAINT `VehicleExpenseDraft_receiptId_fkey` FOREIGN KEY (`receiptId`) REFERENCES `VehicleReceipt` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

ALTER TABLE `WorkOrder`
 ADD COLUMN `vehicleId` BIGINT UNSIGNED NULL,
 ADD COLUMN `vehicleCostSnapshot` JSON NULL,
 ADD INDEX `WorkOrder_vehicleId_idx` (`vehicleId`),
 ADD CONSTRAINT `WorkOrder_vehicleId_fkey` FOREIGN KEY (`vehicleId`) REFERENCES `Vehicle` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;
INSERT INTO `VehicleSettings` (`id`, `defaultVehicleId`) VALUES (1, NULL);
