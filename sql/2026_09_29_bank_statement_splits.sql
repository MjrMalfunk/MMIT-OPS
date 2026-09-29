-- Split reviewed bank transactions across multiple offset accounts.
-- Apply after 2026_07_21_bank_statement_posting.sql.

CREATE TABLE IF NOT EXISTS bank_import_transaction_split (
    split_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_transaction_id BIGINT UNSIGNED NOT NULL,
    split_sequence SMALLINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    split_amount DECIMAL(12,2) UNSIGNED NOT NULL,
    split_note VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (split_id),
    UNIQUE KEY uq_bank_import_transaction_split_sequence (
        bank_transaction_id,
        split_sequence
    ),
    KEY idx_bank_import_transaction_split_account (account_id),
    CONSTRAINT fk_bank_import_transaction_split_transaction
        FOREIGN KEY (bank_transaction_id)
        REFERENCES bank_import_transaction (bank_transaction_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_bank_import_transaction_split_account
        FOREIGN KEY (account_id)
        REFERENCES gl_account (account_id)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
