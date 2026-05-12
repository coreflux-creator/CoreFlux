-- Staffing — external approver columns on staffing_timesheets.
--
-- Adds the fields needed for the one-tap external email approval flow:
-- client/customer-side managers approve a worker's week without ever
-- logging into CoreFlux. We need to track WHO approved (by email) and
-- via what channel ('internal_app' | 'external_email').
--
-- Idempotent: only adds columns/indexes if missing. api_bootstrap's
-- self-heal layer also auto-adds these at runtime if the migration was
-- skipped — belt + suspenders.

DO 0;

ALTER TABLE staffing_timesheets
    ADD COLUMN IF NOT EXISTS approved_via VARCHAR(32) NOT NULL DEFAULT 'internal_app';

ALTER TABLE staffing_timesheets
    ADD COLUMN IF NOT EXISTS external_approver_email VARCHAR(255) NULL;

ALTER TABLE staffing_timesheets
    ADD COLUMN IF NOT EXISTS external_approver_name VARCHAR(255) NULL;

ALTER TABLE staffing_timesheets
    ADD COLUMN IF NOT EXISTS approval_note VARCHAR(1000) NULL;
