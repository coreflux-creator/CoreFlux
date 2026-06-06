-- ============================================================
-- 102_outbox_posted_unverified.sql
--
-- Adds `posted_unverified` to accounting_outbox_events.status so
-- the Command Service can distinguish "create succeeded + verified
-- in downstream" from "create succeeded but downstream state isn't
-- what we expected" (the silent-failure mode that hid CF JEs in
-- Jaz's Drafts queue).
--
-- New value is opt-in; the worker treats it identically to 'posted'
-- (no retry), but the outbox UI surfaces it as a warning so the
-- operator can drill in.
-- ============================================================

ALTER TABLE accounting_outbox_events
    MODIFY COLUMN status
        ENUM('queued','processing','posted','posted_unverified','failed','retrying','dead_letter')
        NOT NULL DEFAULT 'queued';
