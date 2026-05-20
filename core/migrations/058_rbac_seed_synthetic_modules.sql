-- =======================================================================
-- Core migration 058 — RBAC operational fix-up
-- -----------------------------------------------------------------------
-- Closes three real gaps surfaced after the B4 dual-check sweep:
--
-- 1.  users.is_global_admin = 1 was the bypass switch nobody had set,
--     so master_admin users couldn't actually act like master admins.
--     Backfill every existing master_admin (role column or user_tenants)
--     so the resolver bypass actually fires for them.
--
-- 2.  The B1 backfill seeded membership_module_access only for the nine
--     canonical operational modules (people..reports), missing the three
--     "synthetic" module_keys the B4 bridge mapping introduced:
--     `integrations`, `ai`, `staffing`.  Without rows for those, the new
--     resolver's "no access row = deny" rule + the dual-check AND meant
--     even master_admin / tenant_admin couldn't open QBO settings,
--     change AI config, or view staffing data.  Seed them now.
--
-- 3.  Some legacy roles ended up mapped to invalid persona_types ahead
--     of the enum tightening.  This migration is idempotent, INSERT IGNORE
--     where it adds new rows, UPDATE ... WHERE only where the column is
--     actually wrong, and safe to re-run.
-- =======================================================================

-- ----------------------------------------------------------------- (1) is_global_admin backfill
UPDATE users
   SET is_global_admin = 1
 WHERE is_global_admin = 0
   AND (role = 'master_admin'
        OR id IN (SELECT user_id FROM user_tenants WHERE role = 'master_admin' AND status = 'active'));

-- ----------------------------------------------------------------- (2) seed three synthetic modules

-- 2a. integrations — visible to managers (read), full control for admins.
INSERT IGNORE INTO membership_module_access
       (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id,
       'integrations',
       CASE tm.persona_type
           WHEN 'master_admin' THEN 'admin'
           WHEN 'tenant_admin' THEN 'admin'
           WHEN 'admin'        THEN 'admin'
           WHEN 'manager'      THEN 'read'
           ELSE 'none'
       END,
       tm.invited_by_user_id
  FROM tenant_memberships tm
 WHERE tm.status IN ('active', 'pending');

-- 2b. ai — admin-only by default. Managers don't get to flip model config.
INSERT IGNORE INTO membership_module_access
       (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id,
       'ai',
       CASE tm.persona_type
           WHEN 'master_admin' THEN 'admin'
           WHEN 'tenant_admin' THEN 'admin'
           WHEN 'admin'        THEN 'admin'
           ELSE 'none'
       END,
       tm.invited_by_user_id
  FROM tenant_memberships tm
 WHERE tm.status IN ('active', 'pending');

-- 2c. staffing — read for everyone non-trivial, admin for admins.
INSERT IGNORE INTO membership_module_access
       (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id,
       'staffing',
       CASE tm.persona_type
           WHEN 'master_admin' THEN 'admin'
           WHEN 'tenant_admin' THEN 'admin'
           WHEN 'admin'        THEN 'admin'
           WHEN 'manager'      THEN 'read'
           WHEN 'employee'     THEN 'read'
           WHEN 'contractor'   THEN 'read'
           ELSE 'none'
       END,
       tm.invited_by_user_id
  FROM tenant_memberships tm
 WHERE tm.status IN ('active', 'pending');
