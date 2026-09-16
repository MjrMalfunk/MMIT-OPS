-- The first OPS v2 business domain.  Existing OPS v1 tables are not read or changed.

CREATE TABLE `Client` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(191) NOT NULL,
  `email` VARCHAR(191) NULL,
  `phone` VARCHAR(64) NULL,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,

  UNIQUE INDEX `Client_name_key`(`name`),
  PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `WorkOrder` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `source` ENUM('FIELD_NATION', 'MANUAL', 'SYNCRO', 'OTHER') NOT NULL,
  `sourceReference` VARCHAR(191) NOT NULL,
  `status` ENUM('REQUESTED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'COMPLETED', 'INVOICED', 'PAID', 'CANCELLED') NOT NULL DEFAULT 'REQUESTED',
  `title` VARCHAR(255) NOT NULL,
  `clientId` BIGINT UNSIGNED NULL,
  `scheduledAt` DATETIME(3) NULL,
  `checkInAt` DATETIME(3) NULL,
  `checkOutAt` DATETIME(3) NULL,
  `grossPay` DECIMAL(10, 2) NULL,
  `mileage` DECIMAL(10, 2) NULL,
  `driveMinutes` INTEGER UNSIGNED NOT NULL DEFAULT 0,
  `onsiteMinutes` INTEGER UNSIGNED NOT NULL DEFAULT 0,
  `adminMinutes` INTEGER UNSIGNED NOT NULL DEFAULT 0,
  `notes` TEXT NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,

  UNIQUE INDEX `WorkOrder_source_sourceReference_key`(`source`, `sourceReference`),
  INDEX `WorkOrder_status_scheduledAt_idx`(`status`, `scheduledAt`),
  INDEX `WorkOrder_clientId_idx`(`clientId`),
  PRIMARY KEY (`id`),
  CONSTRAINT `WorkOrder_clientId_fkey`
    FOREIGN KEY (`clientId`) REFERENCES `Client`(`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
