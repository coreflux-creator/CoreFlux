-- CFO Dashboard — section notes, custom formula widgets, widget config on saved views.
--
-- Phase 1 of the CFO dashboard build (2026-02). Extends the existing
-- exec_dashboard_views model:
--   - widget_config_json    → per-view widget visibility / order /
--                             per-widget time-window overrides
--   - cfo_section_notes     → free-text annotations a user pins to a
--                             specific widget on a specific view
--   - cfo_custom_formulas   → user-defined "KPI = A op B" widgets (simple
--                             safe evaluator — no eval(), see api/cfo_formulas.php)
--
-- Idempotent. All DDL guarded by IF NOT EXISTS.

DO 0;

ALTER TABLE exec_dashboard_views
    ADD COLUMN IF NOT EXISTS widget_config_json TEXT NULL AFTER filters_json;

CREATE TABLE IF NOT EXISTS cfo_section_notes (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    view_id       INT UNSIGNED NULL,                   -- NULL = applies regardless of view
    widget_key    VARCHAR(80)  NOT NULL,               -- e.g. 'finance.ar_aging', 'cfo.dso'
    body          TEXT         NOT NULL,
    pinned        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_cfn_user_widget (user_id, widget_key),
    INDEX idx_cfn_tenant      (tenant_id),
    INDEX idx_cfn_view        (view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cfo_custom_formulas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id     INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    name          VARCHAR(120) NOT NULL,
    -- Simple A op B contract; the api/cfo_formulas.php evaluator validates
    -- both operand_a and operand_b against a whitelist of KPI keys, so this
    -- is NOT eval()-driven.
    operand_a     VARCHAR(80)  NOT NULL,
    operator      ENUM('+','-','*','/','pct_of') NOT NULL,
    operand_b     VARCHAR(80)  NOT NULL,
    format        ENUM('money','number','percent','ratio') NOT NULL DEFAULT 'number',
    is_shared     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ccf_user_name (user_id, name),
    INDEX idx_ccf_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
