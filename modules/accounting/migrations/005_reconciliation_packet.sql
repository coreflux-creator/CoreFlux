-- =======================================================================
-- Accounting Phase 2 Sprint A.4 — Reconciliation Packet workflow columns
-- -----------------------------------------------------------------------
-- Adds open/close/reopen audit columns + AI narrative to the existing
-- accounting_reconciliations table so the packet view can render a
-- full audit trail.
--
-- All ALTERs idempotent via information_schema. utf8mb4_unicode_ci.
-- =======================================================================

-- opened_at
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'opened_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN opened_at DATETIME NULL AFTER status',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- opened_by_user_id
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'opened_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN opened_by_user_id INT UNSIGNED NULL AFTER opened_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reopened_at
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'reopened_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN reopened_at DATETIME NULL AFTER closed_by_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reopened_by_user_id
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'reopened_by_user_id');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN reopened_by_user_id INT UNSIGNED NULL AFTER reopened_at',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- reopen_reason
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'reopen_reason');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN reopen_reason VARCHAR(500) NULL AFTER reopened_by_user_id',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_narrative (for packet AI summary, human-reviewed)
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'ai_narrative');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN ai_narrative TEXT NULL AFTER notes',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ai_narrative_generated_at
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'accounting_reconciliations'
               AND COLUMN_NAME  = 'ai_narrative_generated_at');
SET @sql := IF(@col = 0,
    'ALTER TABLE accounting_reconciliations ADD COLUMN ai_narrative_generated_at DATETIME NULL AFTER ai_narrative',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
