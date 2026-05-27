-- Migration 080 — Time module: bundle correction grace period.
--
-- Spec re-audit decision (2026-02): "Time module grace period for
-- bundle corrections IS required. Reverses earlier 'no grace
-- period on consumed bundles' rule. Within window: correction
-- supersedes prior bundle accrual; beyond window: refuse / require
-- explicit override."
--
-- Default 7 days — matches the typical client billing-week window
-- where post-consume corrections are routine (late timesheet
-- submissions, manager edits). Tenants can tune per-policy.

ALTER TABLE tenants
    ADD COLUMN time_bundle_correction_grace_days INT NOT NULL DEFAULT 7
        AFTER ap_three_way_match_enforce;
