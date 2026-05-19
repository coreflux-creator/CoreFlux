-- =======================================================================
-- Core migration 053 — QBO Slice 5 (conflict log) + Slice 4a alerts
-- -----------------------------------------------------------------------
-- 1. qbo_conflict_log captures rows where a `two_way` sync detected
--    divergent updates on both sides since the last successful pull/push.
--    The driver picks a winner per the configured rule (default:
--    last-write-wins by updated_at) and persists the loser snapshot so a
--    controller can replay or undo.
--
-- 2. qbo_health_alerts dedupes status-flip notifications so we email a
--    tenant admin exactly once per worsening transition (green/yellow→red,
--    or green→yellow) until the status improves back to green.
-- =======================================================================

CREATE TABLE IF NOT EXISTS qbo_conflict_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    entity_type     VARCHAR(40) NOT NULL,                  -- customer / vendor / invoice / bill / account ...
    internal_id     BIGINT UNSIGNED NULL,
    external_id     VARCHAR(80) NULL,                      -- QBO Id
    rule_applied    VARCHAR(40) NOT NULL DEFAULT 'last_write_wins',
    winner          ENUM('coreflux','quickbooks','tie') NOT NULL,
    coreflux_updated_at DATETIME NULL,
    qbo_updated_at      DATETIME NULL,
    coreflux_snapshot   JSON NULL,
    qbo_snapshot        JSON NULL,
    notes           VARCHAR(500) NULL,
    detected_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_qbo_conflict_tenant_time (tenant_id, detected_at),
    KEY ix_qbo_conflict_entity      (tenant_id, entity_type, internal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS qbo_health_alerts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    status_before   VARCHAR(20) NOT NULL,
    status_after    VARCHAR(20) NOT NULL,
    reasons         JSON NULL,
    notified_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recipient_email VARCHAR(255) NULL,
    sent_ok         TINYINT(1) NOT NULL DEFAULT 0,
    send_error      VARCHAR(500) NULL,
    KEY ix_qbo_alerts_tenant_time (tenant_id, notified_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
