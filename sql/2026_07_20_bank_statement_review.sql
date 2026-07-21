-- Bank statement sequencing and transaction settlement state.
-- Apply after 2026_07_20_bank_statement_import.sql.

ALTER TABLE bank_import_batch
    ADD COLUMN statement_type
        ENUM('CLOSED', 'CURRENT')
        NOT NULL DEFAULT 'CLOSED'
        AFTER source_account_last4,
    ADD INDEX idx_bank_import_batch_statement
        (account_id, statement_type, statement_ending_date);

ALTER TABLE bank_import_transaction
    ADD COLUMN settlement_status
        ENUM('POSTED', 'PENDING')
        NOT NULL DEFAULT 'POSTED'
        AFTER direction,
    ADD INDEX idx_bank_import_transaction_settlement
        (batch_id, settlement_status);

INSERT INTO gl_account
    (account_code, account_name, account_type, detail_type,
     description, is_system, is_active)
VALUES
    ('5071', 'Bank Fees', 'EXPENSE', 'BANK_FEES',
     'Overdraft, maintenance, and other bank-imposed fees.',
     0, 1);
