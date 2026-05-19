-- =======================================================================
-- Core migration 055 — RBAC foundation (B1)
-- -----------------------------------------------------------------------
-- New three-table model that supersedes the single-role `user_tenants`
-- pairing. Existing user_tenants stays in place during the cut-over and
-- is back-filled by scripts/backfill_memberships.php.
--
-- Concepts:
--   1. tenant_memberships          — one row per (user, tenant, persona).
--      Same user can have multiple personas in the same tenant (e.g.
--      "Admin" + "Employee") and the SPA tenant/persona toggle picks one.
--   2. membership_module_access    — per-module read/write/admin/none
--      grid, scoped optionally to a JSON list of sub-tenant IDs (NULL =
--      all sub-tenants of the parent tenant).
--   3. users.is_global_admin       — cross-tenant CoreFlux platform staff
--      flag. Lets ops/support fly through any tenant without a membership.
--
-- All idempotent. Safe to re-run.
-- =======================================================================

CREATE TABLE IF NOT EXISTS tenant_memberships (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,
    tenant_id           INT UNSIGNED NOT NULL,
    persona_label       VARCHAR(80)  NOT NULL DEFAULT 'Primary',  -- shown in toggle
    persona_type        ENUM('master_admin','tenant_admin','admin','manager',
                             'employee','contractor','client','vendor',
                             'platform_staff','custom') NOT NULL DEFAULT 'employee',
    -- Optional link to the entity record this persona represents
    -- (people row for employees, staffing_clients for client portal users,
    --  ap_vendors_index for vendor portal users).
    linked_entity_type  VARCHAR(40)  NULL,
    linked_entity_id    BIGINT UNSIGNED NULL,
    is_primary          TINYINT(1)   NOT NULL DEFAULT 0,          -- default on tenant switch
    status              ENUM('active','pending','suspended','revoked') NOT NULL DEFAULT 'active',
    invited_by_user_id  INT UNSIGNED NULL,
    invited_at          DATETIME NULL,
    accepted_at         DATETIME NULL,
    last_active_at      DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership (user_id, tenant_id, persona_label),
    KEY ix_user_status   (user_id, status),
    KEY ix_tenant_status (tenant_id, status),
    KEY ix_linked        (linked_entity_type, linked_entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS membership_module_access (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    membership_id       INT UNSIGNED NOT NULL,
    module_key          VARCHAR(60)  NOT NULL,
    access_level        ENUM('none','read','write','admin') NOT NULL DEFAULT 'none',
    -- Sub-tenant scope: NULL means "all sub-tenants under the membership's
    -- tenant"; a JSON array of sub_tenant IDs scopes the grant to only
    -- those branches.
    sub_tenant_scope    JSON NULL,
    granted_by_user_id  INT UNSIGNED NULL,
    granted_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_membership_module (membership_id, module_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Platform-level global admin flag on users. Cross-tenant access; no
-- per-tenant membership required (but a global_admin acting inside a
-- tenant still goes through the membership flow to pick a persona).
SET @sql := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN is_global_admin TINYINT(1) NOT NULL DEFAULT 0',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'users'
      AND column_name  = 'is_global_admin'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Per-membership audit. Append-only log of grants / revokes / persona
-- switches. Lets a tenant admin demonstrate "who-changed-what-when" for
-- compliance reviews.
CREATE TABLE IF NOT EXISTS membership_audit (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    membership_id   INT UNSIGNED NULL,
    action          VARCHAR(60)  NOT NULL,    -- created / invited / accepted / suspended / module_grant / module_revoke / persona_switched
    actor_user_id   INT UNSIGNED NULL,
    target_user_id  INT UNSIGNED NULL,
    detail          JSON NULL,
    occurred_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_mema_tenant_time (tenant_id, occurred_at),
    KEY ix_mema_membership  (membership_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
