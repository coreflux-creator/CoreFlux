-- True Sub-Tenant Provisioning + Cross-Tenant Module Scope (foundation)
-- Idempotent. utf8mb4_unicode_ci. Cloudways MySQL 5.7+ compatible.
--
-- Design decisions (per user 2026-02):
--   • Soft isolation: sub-tenants share `people`/`placements`/`companies` with
--     their parent by default; financial modules (billing/ap/accounting/
--     payroll/treasury) get isolated scope by default.
--   • Configurable per module via `tenant_module_scope`.
--   • "Master login UX = both" → login picker + header switcher + last-used
--     remembered via `user_tenants.last_active_at`.
--   • No existing data migration required (greenfield sub-tenants).

-- 1. Tag tenants as `master` or `sub`. Existing rows default to `master` so
--    nothing breaks; rows whose `parent_id IS NOT NULL` get reclassified to
--    `sub` in the backfill below.
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'tenants'
   AND column_name  = 'tenant_type';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE tenants ADD COLUMN tenant_type ENUM(''master'',''sub'') NOT NULL DEFAULT ''master'' AFTER parent_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE tenants SET tenant_type = 'sub' WHERE parent_id IS NOT NULL AND tenant_type = 'master';

-- 2. Soft-deactivate flag; many places already assume `active = 1` semantics.
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'tenants'
   AND column_name  = 'is_active';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE tenants ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER tenant_type',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Per-(user, tenant) "last accessed" timestamp powers the auto-pick on
--    login + sticky tenant dropdown. Distinct from `is_default` which is
--    user-curated.
SELECT COUNT(*) INTO @col_exists
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND table_name   = 'user_tenants'
   AND column_name  = 'last_active_at';
SET @sql := IF(@col_exists = 0,
  'ALTER TABLE user_tenants ADD COLUMN last_active_at DATETIME NULL DEFAULT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Per-(sub-tenant, module) scope config. `scope_mode='shared'` means the
--    module reads/writes against the parent tenant_id; `isolated` keeps data
--    local to the sub-tenant. Cross-tenant journal entries (intercompany)
--    always cross the sub-tenant boundary regardless of this row.
CREATE TABLE IF NOT EXISTS tenant_module_scope (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    scope_mode ENUM('shared','isolated') NOT NULL DEFAULT 'isolated',
    updated_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tms_tenant_module (tenant_id, module_key),
    INDEX idx_tms_module (module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Audit ledger for sub-tenant lifecycle (provision, scope change, deactivate).
CREATE TABLE IF NOT EXISTS tenant_provisioning_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_tenant_id INT UNSIGNED NULL,
    tenant_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    event VARCHAR(80) NOT NULL,
    detail_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tpl_tenant (tenant_id, created_at),
    INDEX idx_tpl_parent (parent_tenant_id, created_at),
    INDEX idx_tpl_event (event, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
