ALTER TABLE `WorkOrder`
  ADD COLUMN `payType` ENUM('FIXED','HOURLY','BLENDED') NOT NULL DEFAULT 'FIXED',
  ADD COLUMN `payBaseAmount` DECIMAL(10,2) NULL,
  ADD COLUMN `payBaseHours` DECIMAL(8,2) NULL,
  ADD COLUMN `payHourlyRate` DECIMAL(10,2) NULL,
  ADD COLUMN `payHoursCap` DECIMAL(8,2) NULL,
  ADD COLUMN `actualGrossPay` DECIMAL(10,2) NULL,
  ADD COLUMN `payCalculatedAt` DATETIME(3) NULL,
  ADD COLUMN `payCalculation` JSON NULL;

UPDATE `WorkOrder`
SET `payBaseAmount` = `grossPay`
WHERE `grossPay` IS NOT NULL;

ALTER TABLE `FieldNationImport`
  ADD COLUMN `payType` ENUM('FIXED','HOURLY','BLENDED') NOT NULL DEFAULT 'FIXED',
  ADD COLUMN `payBaseAmount` DECIMAL(10,2) NULL,
  ADD COLUMN `payBaseHours` DECIMAL(8,2) NULL,
  ADD COLUMN `payHourlyRate` DECIMAL(10,2) NULL,
  ADD COLUMN `payHoursCap` DECIMAL(8,2) NULL;

UPDATE `FieldNationImport`
SET `payBaseAmount` = `grossPay`
WHERE `grossPay` IS NOT NULL;
