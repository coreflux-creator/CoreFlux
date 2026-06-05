-- 112_je_post_approval_gates.sql
--
-- P2 — JE drafts post-approval gate hardening (Slice C follow-up).
--
-- Adds the schema surface the tool_gateway risk-4 path needs to enforce
-- the 6 gating rules:
--   1. Approval ↔ JE binding   (request_payload.je_id matches arg)
--   2. Single-use               (workflow_approvals.consumed_at)
--   3. SoD self-approval        (decided_by_user_id != created_by_user_id)
--   4. expires_at honored       (gate refuses past-expiry approvals)
--   5. JE audit trail           (accounting_journal_entries.approval_id)
--   6. Draft-mutation guard     (accounting_journal_entries.draft_hash
--                                snapshot recorded in request_payload at
--                                approval-request time, re-checked at
--                                promotion)
--
-- Idempotent: every column adds via "ADD COLUMN IF NOT EXISTS"-style
-- prepared INFORMATION_SCHEMA probes so re-running the migration is a
-- no-op.  This file uses native ALTER TABLE statements that MySQL 8
-- accepts via `IF NOT EXISTS`.

ALTER TABLE accounting_journal_entries
    ADD COLUMN IF NOT EXISTS approval_id BIGINT UNSIGNED NULL
        COMMENT 'workflow_approvals.id that authorized the promotion (NULL = no AI gate)';

ALTER TABLE accounting_journal_entries
    ADD COLUMN IF NOT EXISTS draft_hash CHAR(64) NULL
        COMMENT 'sha256 of the canonical draft body — recorded so the gate can detect post-approval mutation';

ALTER TABLE accounting_journal_entries
    ADD INDEX IF NOT EXISTS ix_aje_tenant_approval (tenant_id, approval_id);

ALTER TABLE workflow_approvals
    ADD COLUMN IF NOT EXISTS consumed_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'set when the approval is paired with a successful state mutation; single-use guard';

ALTER TABLE workflow_approvals
    ADD COLUMN IF NOT EXISTS consumed_by_je_id BIGINT UNSIGNED NULL
        COMMENT 'accounting_journal_entries.id this approval was consumed against (when applicable)';

ALTER TABLE workflow_approvals
    ADD INDEX IF NOT EXISTS ix_wfa_consumed (tenant_id, consumed_at);
