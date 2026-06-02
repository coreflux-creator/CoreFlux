-- Migration 104 — Cross-tenant intercompany approval queue
-- =======================================================================
-- Today's `accountingPostCrossTenantIntercompany()` posts both legs
-- atomically on the source tenant's authority — the FROM tenant's
-- treasury admin alone can move money on the books of the TO tenant
-- (with a compensating reverse if the second post fails).
--
-- Per Kunal's direction (2026-02): cross-tenant intercompany entries
-- should require the COUNTERPARTY's approval before the to-leg posts.
-- That gives each entity's CFO/AP control over what's allowed onto
-- their own books, mirrors the segregation-of-duties model the rest
-- of CoreFlux runs on, and is a hard requirement for multi-entity
-- groups where the entities are run by different teams.
--
-- Workflow:
--   1. Source admin "proposes" — from-leg posts immediately on source's
--      books; a pending row lands in this queue, referencing the
--      from-leg's JE_id and the proposed to-leg's account codes.
--   2. Target admin opens their Intercompany inbox, sees the pending
--      row, and either Approves or Declines.
--      · Approve → to-leg posts on target's books, queue row → approved,
--        target_je_id stamped.
--      · Decline → source-leg is reversed (compensating JE), queue
--        row → declined, decline_reason captured.
--   3. Expired (TTL > 14 days unhandled) reverses on a daily cron pass.
-- =======================================================================

CREATE TABLE IF NOT EXISTS intercompany_xtenant_queue (
    id                          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Shared identifier across both tenants' books, mirrors the
    -- accounting_journal_entries.intercompany_group_id semantics.
    intercompany_ref            VARCHAR(64) NOT NULL,

    -- Source side (posted immediately at propose-time).
    source_tenant_id            INT  UNSIGNED NOT NULL,
    source_entity_id            INT  UNSIGNED NULL,
    source_je_id                BIGINT UNSIGNED NOT NULL,
    source_account_code         VARCHAR(32) NOT NULL,
    source_offset_code          VARCHAR(32) NOT NULL,

    -- Target side (only posted on approve).
    target_tenant_id            INT  UNSIGNED NOT NULL,
    target_entity_id            INT  UNSIGNED NULL,
    target_je_id                BIGINT UNSIGNED NULL,
    target_account_code         VARCHAR(32) NOT NULL,
    target_offset_code          VARCHAR(32) NOT NULL,

    -- Money + provenance.
    amount                      DECIMAL(18, 2) NOT NULL,
    currency                    VARCHAR(3)    NOT NULL DEFAULT 'USD',
    fx_rate                     DECIMAL(18, 8) NOT NULL DEFAULT 1.0,
    target_amount               DECIMAL(18, 2) NOT NULL,
    target_currency             VARCHAR(3)    NOT NULL DEFAULT 'USD',
    memo                        TEXT          NULL,
    posting_date                DATE          NOT NULL,

    -- Workflow state.
    status                      ENUM('pending','approved','declined','expired','reversed')
                                NOT NULL DEFAULT 'pending',
    requested_by_user_id        INT  UNSIGNED NULL,
    requested_at                DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_by_user_id          INT  UNSIGNED NULL,
    decided_at                  DATETIME      NULL,
    decline_reason              TEXT          NULL,

    -- Lifecycle clock — the daily cron consumes rows past this date
    -- and marks them `expired` (which triggers a compensating reverse
    -- on the source side).  Default = 14 days.
    expires_at                  DATETIME      NULL,

    created_at                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_xtenant_ref           (intercompany_ref),
    KEY        ix_xtenant_target_status (target_tenant_id, status, requested_at),
    KEY        ix_xtenant_source_status (source_tenant_id, status, requested_at),
    KEY        ix_xtenant_expires       (status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
