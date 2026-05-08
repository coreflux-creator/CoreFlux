-- Sprint 7a — soft-close override audit field (spec §6).
-- When a user with override permission posts into a soft_closed period,
-- they must supply a reason. Stored on the JE for full audit traceability.
--
-- Idempotent: information_schema guard.

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE() AND table_name = 'accounting_journal_entries' AND column_name = 'soft_close_override_reason');
SET @sql := IF(@c = 0,
    'ALTER TABLE accounting_journal_entries ADD COLUMN soft_close_override_reason TEXT NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
