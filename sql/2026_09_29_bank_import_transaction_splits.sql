-- Split accounting support for imported bank transactions.
-- Apply after 2026_07_21_bank_statement_posting.sql.
--
-- A bank transaction remains a single reconciliation source transaction.
-- These rows define multiple offsetting GL allocations when one bank
-- transaction contains purchases belonging to different accounts.

CREATE TABLE IF NOT EXISTS bank_import_transaction_split (
    split_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bank_transaction_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    split_amount DECIMAL(12,2) NOT NULL,
    memo VARCHAR(255) DEFAULT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (split_id),

    UNIQUE KEY uq_bank_import_transaction_split_order (
        bank_transaction_id,
        sort_order
    ),

    KEY idx_bank_import_transaction_split_transaction (
        bank_transaction_id
    ),

    KEY idx_bank_import_transaction_split_account (
        account_id
    ),

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
