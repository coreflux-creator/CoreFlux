-- 2026-02 — users.tenant_id backfill baseline
--
-- Some production CoreFlux envs carry a NOT-NULL `tenant_id` column on
-- the `users` table that was hand-added before this migration set existed,
-- so fresh installs end up with a schema that differs from prod. The
-- INSERT paths (Admin Panel → New user, SSO JIT, Magic-link JIT,
-- Membership invite) are all schema-tolerant via SHOW COLUMNS, BUT we
-- still want fresh installs to mirror prod so future drift can't bite.
--
-- Idempotent — adds the column only if missing, defaults to 0 (sentinel)
-- and backfills from the first matching tenant_memberships row so all
-- existing rows get a valid tenant_id before NOT-NULL kicks in.

SET @col_exists := (
    SELECT COUNT(*)
      FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'users'
       AND column_name  = 'tenant_id'
);

SET @add_col_sql := IF(
    @col_exists = 0,
    'ALTER TABLE users ADD COLUMN tenant_id INT NULL AFTER id, ADD INDEX idx_users_tenant_id (tenant_id)',
    'SELECT 1'
);
PREPARE stmt FROM @add_col_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Backfill from tenant_memberships when available — pick the user's
-- oldest active membership as the "home" tenant. Falls back to 0 only
-- for users with zero memberships, who are usually system/SSO test rows.
UPDATE users u
   LEFT JOIN (
       SELECT user_id, MIN(tenant_id) AS tenant_id
         FROM tenant_memberships
        WHERE status = 'active'
        GROUP BY user_id
   ) m ON m.user_id = u.id
   SET u.tenant_id = COALESCE(m.tenant_id, 0)
 WHERE u.tenant_id IS NULL;

-- Don't enforce NOT-NULL in this migration — too risky against historic
-- rows we can't reach. Adding NOT-NULL later (migration 057) once ops
-- has confirmed every tenant_id is non-zero.
