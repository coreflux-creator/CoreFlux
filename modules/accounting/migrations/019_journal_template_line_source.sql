-- Sprint 7e — passthrough line source on journal templates.
-- A bill / invoice can have N expense lines + 1 control-account line where
-- N is set per-record. Templates with fixed line_no rows can't natively
-- model that. line_source='payload' tells the rendering engine to read
-- payload.lines[] as-is (already balanced by the emitter). The default
-- 'template' behaviour is unchanged for the existing seed pack.
--
-- Idempotent.

SET @c := (SELECT COUNT(*) FROM information_schema.columns
           WHERE table_schema = DATABASE()
             AND table_name = 'accounting_journal_templates'
             AND column_name = 'line_source');
SET @sql := IF(@c = 0,
    "ALTER TABLE accounting_journal_templates
        ADD COLUMN line_source ENUM('template','payload') NOT NULL DEFAULT 'template' AFTER currency_source",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
