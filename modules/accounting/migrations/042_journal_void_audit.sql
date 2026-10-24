-- Keep the reason and actor with the journal even if the auxiliary audit log fails.
ALTER TABLE accounting_journal_entries
    ADD COLUMN void_reason VARCHAR(1000) NULL AFTER reversed_by_je_id,
    ADD COLUMN voided_at DATETIME NULL AFTER void_reason,
    ADD COLUMN voided_by_user_id BIGINT UNSIGNED NULL AFTER voided_at;
