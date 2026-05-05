-- Core — 009 — AI Confidence-Score Moat
-- Idempotent. Adds the columns and tables that let CoreFlux compound a
-- training moat: every AI suggestion records its confidence, every accept/
-- reject is captured, and a categorization-history table lets the next
-- prediction lean on historical user choices.

-- ─── 1. ai_suggestions: confidence + override tracking ─────────────────────
SELECT COUNT(*) INTO @c1 FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'ai_suggestions'
   AND column_name = 'confidence_score';
SET @sql := IF(@c1 = 0,
  'ALTER TABLE ai_suggestions
     ADD COLUMN confidence_score DECIMAL(5,4) NULL AFTER status,
     ADD COLUMN prompt_version   VARCHAR(32)  NULL AFTER confidence_score,
     ADD COLUMN model_version    VARCHAR(64)  NULL AFTER prompt_version,
     ADD COLUMN suggested_value  TEXT         NULL AFTER model_version,
     ADD COLUMN final_value      TEXT         NULL AFTER suggested_value,
     ADD COLUMN accepted_as_is   TINYINT(1)   NULL AFTER final_value,
     ADD COLUMN suggestion_source ENUM(''history'',''rules'',''llm'',''hybrid'') NULL AFTER accepted_as_is,
     ADD INDEX idx_ais_confidence (tenant_id, feature_key, confidence_score),
     ADD INDEX idx_ais_accepted   (tenant_id, feature_key, accepted_as_is, reviewed_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. ai_categorization_history: fast-lookup user training ───────────────
-- For every (tenant, feature_key, normalized_signal) → final_value the user
-- accepted, with a count. Lets the suggestor return historical winners with
-- 95%+ confidence for repeat merchants. Decoupled from ai_suggestions for
-- read-path speed.
CREATE TABLE IF NOT EXISTS ai_categorization_history (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    feature_key        VARCHAR(128) NOT NULL,
    signal_kind        VARCHAR(32)  NOT NULL,        -- 'merchant' | 'pfcategory' | 'description'
    signal_value       VARCHAR(255) NOT NULL,        -- normalized lowercase
    final_value        VARCHAR(255) NOT NULL,        -- e.g. 'account_id:42' or any string
    accept_count       INT UNSIGNED NOT NULL DEFAULT 1,
    last_accepted_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aichist (tenant_id, feature_key, signal_kind, signal_value, final_value),
    INDEX idx_aichist_lookup (tenant_id, feature_key, signal_kind, signal_value, accept_count)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── 3. ai_accuracy_daily: rolled-up dashboard metrics ────────────────────
-- Daily snapshot per (tenant, feature_key) so the dashboard renders fast
-- without aggregating ai_suggestions on every visit. Backfilled by a
-- nightly cron (or on-demand from the UI for now).
CREATE TABLE IF NOT EXISTS ai_accuracy_daily (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id          INT UNSIGNED NOT NULL,
    feature_key        VARCHAR(128) NOT NULL,
    snapshot_date      DATE         NOT NULL,
    suggestions_count  INT UNSIGNED NOT NULL DEFAULT 0,
    accepted_count     INT UNSIGNED NOT NULL DEFAULT 0,
    overridden_count   INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_count     INT UNSIGNED NOT NULL DEFAULT 0,
    avg_confidence     DECIMAL(5,4) NULL,
    avg_accepted_conf  DECIMAL(5,4) NULL,
    avg_overridden_conf DECIMAL(5,4) NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aiacc (tenant_id, feature_key, snapshot_date),
    INDEX idx_aiacc_tenant (tenant_id, snapshot_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
