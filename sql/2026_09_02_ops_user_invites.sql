CREATE TABLE IF NOT EXISTS ops_user_invite (
    invite_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    accepted_at DATETIME NULL,
    revoked_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME NULL,
    PRIMARY KEY (invite_id),
    UNIQUE KEY uq_ops_user_invite_token_hash (token_hash),
    KEY idx_ops_user_invite_email (email),
    KEY idx_ops_user_invite_state (
        accepted_at,
        revoked_at,
        expires_at
    ),
    KEY idx_ops_user_invite_created_by (created_by)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
