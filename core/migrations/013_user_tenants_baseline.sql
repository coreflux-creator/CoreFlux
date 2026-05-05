-- 013_user_tenants_baseline.sql
--
-- Bring the legacy `users` + `user_tenants` schema into the migrations
-- catalog so the schema-contract gate can see the columns. The tables
-- are bootstrapped by the original CoreFlux installer (pre-migration
-- runner) and exist on every production tenant; this migration is
-- idempotent and safe to apply repeatedly.
--
-- 2026-02 — pulled in for Sprint 2 (`/api/users.php`) so the contract
-- gate stops flagging `ut.user_id`, `ut.role`, `ut.is_default`, etc.

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150) NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password        VARCHAR(255) NULL,
    password_hash   VARCHAR(255) NULL,
    role            VARCHAR(40) NOT NULL DEFAULT 'employee',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    avatar          VARCHAR(255) NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_tenants (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    tenant_id       INT UNSIGNED NOT NULL,
    role            VARCHAR(40) NOT NULL DEFAULT 'employee',
    is_default      TINYINT(1)   NOT NULL DEFAULT 0,
    status          ENUM('active','inactive','pending') NOT NULL DEFAULT 'active',
    last_active_at  DATETIME NULL DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_tenants_pair (user_id, tenant_id),
    INDEX idx_ut_tenant (tenant_id),
    INDEX idx_ut_user_active (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant_modules baseline (the existing legacy installer ships this table
-- but we re-declare it here for the contract gate).
CREATE TABLE IF NOT EXISTS tenant_modules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    module_key      VARCHAR(64) NOT NULL,
    is_enabled      TINYINT(1)   NOT NULL DEFAULT 1,
    enabled_at      DATETIME NULL DEFAULT NULL,
    disabled_at     DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_tenant_modules (tenant_id, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
