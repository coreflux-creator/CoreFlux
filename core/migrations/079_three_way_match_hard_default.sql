-- Migration 079 — Flip AP 3-way match enforcement to HARD by default.
--
-- Spec re-audit decision (2026-02): "AP three-way match HARD rules.
-- Bills failing 3-way match cannot be posted/paid without explicit
-- override + reason." Previously the column defaulted to 0 (soft warn).
--
-- We flip the DEFAULT for new tenants AND bulk-update existing
-- tenants so all post-deploy approvals enforce the gate. Tenants
-- who actively rely on soft-warn must opt out by setting the column
-- back to 0 — explicit, audited, and surfaced in the Integration
-- Settings → AP tab.

ALTER TABLE tenants
    MODIFY COLUMN ap_three_way_match_enforce TINYINT(1) NOT NULL DEFAULT 1;

-- Lift every existing tenant. Re-runs are idempotent — the column
-- being non-NULL with DEFAULT 1 makes any further migration of new
-- columns inherit the right default automatically.
UPDATE tenants
   SET ap_three_way_match_enforce = 1
 WHERE ap_three_way_match_enforce = 0;
