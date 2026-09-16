-- V2 client profiles preserve an optional stable Syncro customer identifier.
-- This is additive: it does not read from, write to, or alter OPS v1 records.

ALTER TABLE `Client`
  ADD COLUMN `status` ENUM('PROSPECT', 'ACTIVE', 'PAUSED', 'FORMER') NOT NULL DEFAULT 'PROSPECT',
  ADD COLUMN `serviceTier` ENUM('MANAGE', 'PROTECT', 'GOVERN') NULL,
  ADD COLUMN `syncroCustomerId` VARCHAR(64) NULL;

CREATE UNIQUE INDEX `Client_syncroCustomerId_key` ON `Client`(`syncroCustomerId`);
