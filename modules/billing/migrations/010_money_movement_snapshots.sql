-- Money Movement snapshot history.
--
-- One row per (tenant, as_of) week, written by scripts/money_movement_weekly.php
-- after the digest is built. Powers:
--   • Week-over-week deltas in the digest renderer ("WoW: +12%")
--   • Public board-ready share links (renders any historical week)
--   • In-app archive page listing the last 12 weeks
--
-- The snapshot is denormalized JSON so historical accuracy is preserved
-- even if upstream queries change shape. Idempotent.

CREATE TABLE IF NOT EXISTS tenant_money_movement_snapshots (
    id           BIGINT       NOT NULL AUTO_INCREMENT,
    tenant_id    BIGINT       NOT NULL,
    as_of        DATE         NOT NULL,
    window_start DATE         NOT NULL,
    window_end   DATE         NOT NULL,
    cash_in      DECIMAL(14,2) NOT NULL DEFAULT 0,
    cash_out     DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_movement DECIMAL(14,2) NOT NULL DEFAULT 0,
    snapshot_json MEDIUMTEXT  NOT NULL  COMMENT 'Full moneyMovementSnapshot() output, frozen at write time.',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tmms (tenant_id, as_of),
    KEY        idx_tmms_tenant_date (tenant_id, as_of DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
