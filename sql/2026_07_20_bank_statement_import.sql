CREATE TABLE IF NOT EXISTS bank_import_batch (
    batch_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    account_id BIGINT UNSIGNED NOT NULL,
    source_bank VARCHAR(80) NOT NULL DEFAULT 'PNC',
    source_account_last4 CHAR(4) DEFAULT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_sha256 CHAR(64) NOT NULL,
    period_start_date DATE DEFAULT NULL,
    period_end_date DATE DEFAULT NULL,
    statement_ending_date DATE DEFAULT NULL,
    opening_balance DECIMAL(12,2) DEFAULT NULL,
    ending_balance DECIMAL(12,2) DEFAULT NULL,
    credit_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    debit_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM(
        'PREVIEW',
        'READY',
        'POSTED',
        'VOID'
    ) NOT NULL DEFAULT 'PREVIEW',
    imported_by BIGINT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (batch_id),
    UNIQUE KEY uq_bank_import_batch_account_file (
        account_id,
        file_sha256
    ),
    KEY idx_bank_import_batch_period (
        account_id,
        period_end_date
    ),
    KEY idx_bank_import_batch_status (status),
    CONSTRAINT fk_bank_import_batch_account
        FOREIGN KEY (account_id)
        REFERENCES gl_account (account_id),
    CONSTRAINT fk_bank_import_batch_user
        FOREIGN KEY (imported_by)
        REFERENCES portal_user (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS bank_import_transaction (
    bank_transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    csv_row_number INT UNSIGNED NOT NULL,
    transaction_date DATE NOT NULL,
    description_raw TEXT NOT NULL,
    description_normalized VARCHAR(255) NOT NULL,
    signed_amount DECIMAL(12,2) NOT NULL,
    direction ENUM('CREDIT','DEBIT') NOT NULL,
    occurrence_ordinal INT UNSIGNED NOT NULL DEFAULT 1,
    fingerprint CHAR(64) NOT NULL,
    classification VARCHAR(50) NOT NULL DEFAULT 'UNCLASSIFIED',
    review_status ENUM(
        'UNREVIEWED',
        'READY',
        'MATCHED',
        'IGNORED',
        'POSTED'
    ) NOT NULL DEFAULT 'UNREVIEWED',
    suggested_account_id BIGINT UNSIGNED DEFAULT NULL,
    selected_account_id BIGINT UNSIGNED DEFAULT NULL,
    suggestion_reason VARCHAR(255) DEFAULT NULL,
    suggestion_confidence DECIMAL(5,4) DEFAULT NULL,
    matched_journal_line_id BIGINT UNSIGNED DEFAULT NULL,
    posted_journal_id BIGINT UNSIGNED DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (bank_transaction_id),
    UNIQUE KEY uq_bank_import_transaction_fingerprint (
        account_id,
        fingerprint
    ),
    UNIQUE KEY uq_bank_import_transaction_batch_row (
        batch_id,
        csv_row_number
    ),
    KEY idx_bank_import_transaction_batch (batch_id),
    KEY idx_bank_import_transaction_date (
        account_id,
        transaction_date
    ),
    KEY idx_bank_import_transaction_review (
        batch_id,
        review_status
    ),
    KEY idx_bank_import_transaction_suggested (
        suggested_account_id
    ),
    KEY idx_bank_import_transaction_selected (
        selected_account_id
    ),
    CONSTRAINT fk_bank_import_transaction_batch
        FOREIGN KEY (batch_id)
        REFERENCES bank_import_batch (batch_id)
        ON DELETE CASCADE,
    CONSTRAINT fk_bank_import_transaction_account
        FOREIGN KEY (account_id)
        REFERENCES gl_account (account_id),
    CONSTRAINT fk_bank_import_transaction_suggested
        FOREIGN KEY (suggested_account_id)
        REFERENCES gl_account (account_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bank_import_transaction_selected
        FOREIGN KEY (selected_account_id)
        REFERENCES gl_account (account_id)
        ON DELETE SET NULL
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_general_ci;
