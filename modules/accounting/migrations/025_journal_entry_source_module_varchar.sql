-- Journal-entry provenance is module-defined, so an ENUM is too restrictive.
-- The original schema only allowed manual/ap/billing/payroll/revrec/system/
-- reversal, causing Treasury posts to fail under MySQL strict mode with:
--   Data truncated for column 'source_module'
-- Converting to VARCHAR preserves every existing value and allows stable
-- identifiers such as treasury_feed, recurring_je, and intercompany sources.

ALTER TABLE accounting_journal_entries
    MODIFY COLUMN source_module VARCHAR(64) NOT NULL DEFAULT 'manual';
