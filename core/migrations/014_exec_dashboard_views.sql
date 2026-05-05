-- 014_exec_dashboard_views.sql
--
-- Saved-view bookmarks for the executive dashboard. Each view captures a
-- (window + client + recruiter + placement_type + worksite_state) tuple
-- under a friendly name + URL slug.
--
-- Visibility model (mirrors how `tenant_mail_settings` and `placements`
-- treat per-tenant + per-user records):
--   - `is_shared = 0`  → only the owner sees it
--   - `is_shared = 1`  → everyone in the tenant sees it (tenant_admin
--                        and master_admin can edit / delete shared views;
--                        anyone can read)
--
-- `is_default = 1` means the owning user lands on this view automatically
-- when they hit /exec without a ?view= query string. Per-user, not global.
--
-- Idempotent. utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS exec_dashboard_views (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NOT NULL,
    name            VARCHAR(120) NOT NULL,
    slug            VARCHAR(100) NOT NULL,
    filters_json    TEXT NOT NULL,
    is_default      TINYINT(1)   NOT NULL DEFAULT 0,
    is_shared       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_edv_user_slug (user_id, slug),
    INDEX idx_edv_tenant_shared (tenant_id, is_shared),
    INDEX idx_edv_tenant_user   (tenant_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
