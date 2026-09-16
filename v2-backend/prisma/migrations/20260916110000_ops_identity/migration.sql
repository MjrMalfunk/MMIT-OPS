-- V2-only OPS identity. Passwords are scrypt hashes, session/invite/recovery
-- tokens are hashed, and TOTP secrets are encrypted by the application.

CREATE TABLE `OpsUser` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(191) NOT NULL,
  `displayName` VARCHAR(191) NOT NULL,
  `role` ENUM('OWNER', 'ADMIN', 'OPERATOR', 'VIEWER') NOT NULL DEFAULT 'OPERATOR',
  `status` ENUM('PENDING_MFA', 'ACTIVE', 'DISABLED') NOT NULL DEFAULT 'PENDING_MFA',
  `passwordHash` VARCHAR(255) NOT NULL,
  `totpSecretCiphertext` TEXT NOT NULL,
  `mfaVerifiedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updatedAt` DATETIME(3) NOT NULL,

  UNIQUE INDEX `OpsUser_email_key`(`email`),
  PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `OpsInvite` (
  `id` CHAR(36) NOT NULL,
  `email` VARCHAR(191) NOT NULL,
  `role` ENUM('OWNER', 'ADMIN', 'OPERATOR', 'VIEWER') NOT NULL DEFAULT 'OPERATOR',
  `tokenHash` CHAR(64) NOT NULL,
  `expiresAt` DATETIME(3) NOT NULL,
  `acceptedAt` DATETIME(3) NULL,
  `revokedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `invitedById` BIGINT UNSIGNED NOT NULL,

  UNIQUE INDEX `OpsInvite_tokenHash_key`(`tokenHash`),
  INDEX `OpsInvite_expiresAt_idx`(`expiresAt`),
  INDEX `OpsInvite_email_idx`(`email`),
  PRIMARY KEY (`id`),
  CONSTRAINT `OpsInvite_invitedById_fkey`
    FOREIGN KEY (`invitedById`) REFERENCES `OpsUser`(`id`)
    ON DELETE RESTRICT ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `OpsSession` (
  `id` CHAR(36) NOT NULL,
  `tokenHash` CHAR(64) NOT NULL,
  `expiresAt` DATETIME(3) NOT NULL,
  `revokedAt` DATETIME(3) NULL,
  `lastSeenAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `userId` BIGINT UNSIGNED NOT NULL,

  UNIQUE INDEX `OpsSession_tokenHash_key`(`tokenHash`),
  INDEX `OpsSession_userId_expiresAt_idx`(`userId`, `expiresAt`),
  PRIMARY KEY (`id`),
  CONSTRAINT `OpsSession_userId_fkey`
    FOREIGN KEY (`userId`) REFERENCES `OpsUser`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE `OpsRecoveryCode` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codeHash` CHAR(64) NOT NULL,
  `usedAt` DATETIME(3) NULL,
  `createdAt` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `userId` BIGINT UNSIGNED NOT NULL,

  UNIQUE INDEX `OpsRecoveryCode_codeHash_key`(`codeHash`),
  INDEX `OpsRecoveryCode_userId_usedAt_idx`(`userId`, `usedAt`),
  PRIMARY KEY (`id`),
  CONSTRAINT `OpsRecoveryCode_userId_fkey`
    FOREIGN KEY (`userId`) REFERENCES `OpsUser`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
