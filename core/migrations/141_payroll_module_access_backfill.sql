-- Core migration 141 — repair payroll access for memberships created after
-- the original RBAC backfill.  Keep deliberate `none` grants intact by only
-- inserting rows that are currently absent.

INSERT IGNORE INTO membership_module_access
       (membership_id, module_key, access_level, granted_by_user_id)
SELECT tm.id,
       'payroll',
       CASE tm.persona_type
           WHEN 'master_admin' THEN 'admin'
           WHEN 'tenant_admin' THEN 'admin'
           WHEN 'admin'        THEN 'admin'
           WHEN 'manager'      THEN 'read'
       END,
       tm.invited_by_user_id
  FROM tenant_memberships tm
 WHERE tm.status IN ('active', 'pending')
   AND tm.persona_type IN ('master_admin', 'tenant_admin', 'admin', 'manager');
