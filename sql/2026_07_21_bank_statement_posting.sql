-- Transaction-safe bank-statement approval and posting schema.
-- Apply after 2026_07_20_bank_statement_review.sql.

-- Journal lines must participate in the same transaction as journal headers.
ALTER TABLE gl_journal_line
    ENGINE=InnoDB,
    CONVERT TO CHARACTER SET utf8mb4
    COLLATE utf8mb4_general_ci;

-- Identify journals created from reviewed bank-statement transactions.
ALTER TABLE gl_journal
    MODIFY source_type ENUM(
        'EXPENSE',
        'INVOICE',
        'PAYMENT',
        'MANUAL',
        'ADJUSTMENT',
        'INVOICE_VOID',
        'PAYMENT_VOID',
        'PAYMENT_REFUND',
        'BANK_IMPORT'
    ) NOT NULL;

-- Record who approved and posted each batch.
ALTER TABLE bank_import_batch
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER status,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN posted_by BIGINT UNSIGNED NULL AFTER approved_at,
    ADD COLUMN posted_at DATETIME NULL AFTER posted_by,
    ADD KEY idx_bank_import_batch_approved_by (approved_by),
    ADD KEY idx_bank_import_batch_posted_by (posted_by),
    ADD CONSTRAINT fk_bank_import_batch_approved_by
        FOREIGN KEY (approved_by)
        REFERENCES portal_user (user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_bank_import_batch_posted_by
        FOREIGN KEY (posted_by)
        REFERENCES portal_user (user_id)
        ON DELETE SET NULL;

-- Enforce one valid journal link per imported bank transaction.
ALTER TABLE bank_import_transaction
    ADD UNIQUE KEY uq_bank_import_transaction_posted_journal
        (posted_journal_id),
    ADD CONSTRAINT fk_bank_import_transaction_posted_journal
        FOREIGN KEY (posted_journal_id)
        REFERENCES gl_journal (journal_id);
