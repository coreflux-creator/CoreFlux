-- Sprint P2 — Auto-reversing accruals (lightweight).
--
-- Adds a single nullable column to accounting_journal_entries. When set,
-- a cron-driven worker will generate a reversing JE on or after that date
-- using the existing accountingReverseJe() helper, then null the column
-- so the same JE never reverses twice.
--
-- Cleared automatically on success; if the cron fails, last_auto_reverse_error
-- carries the error string for ops to inspect.

ALTER TABLE accounting_journal_entries
    ADD COLUMN auto_reverses_on DATE NULL DEFAULT NULL AFTER reverses_je_id,
    ADD COLUMN auto_reverse_attempted_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN auto_reverse_last_error VARCHAR(500) DEFAULT NULL,
    ADD INDEX idx_aje_auto_reverse (tenant_id, auto_reverses_on, status);
