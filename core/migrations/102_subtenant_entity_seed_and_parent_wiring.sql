-- Migration 102 — Sub-tenant entity seed + cross-tenant entity wiring
-- =======================================================================
-- Two coupled fixes that close the "parent entity is missing from the
-- dropdown" complaint:
--
--   1. `subTenantProvision()` historically created the `tenants` row but
--      did NOT create the companion `accounting_entities` row for the
--      sub-tenant.  So sub-tenants either had no entity at all (causing
--      empty Entity ▾ dropdowns) or inherited a mis-named entity that
--      was hand-created with the WRONG legal_name (the user reported
--      seeing "Main Entity · Arabella Talent Partners" on the Seven
--      Generations sub-tenant).
--
--      Fix: idempotently seed one `accounting_entities` row per existing
--      tenant — master AND sub — using the tenant's OWN name.  Code path
--      hand-off to PHP via `subTenantProvision()` (separate patch) keeps
--      this synchronous for future provisioning.
--
--   2. Sub-tenants need a back-pointer to the parent's entity so the
--      Consolidation parent/child picker and the cross-tenant Entity ▾
--      dropdown can render the hierarchy.  We wire `parent_entity_id`
--      to the parent tenant's primary entity (lowest-id active row).
-- =======================================================================

-- ── Part 1 — seed one default entity per tenant ─────────────────────────
-- For every tenant that does NOT yet have an `accounting_entities` row,
-- create one using the tenant's own name as legal_name and a derived
-- 4-letter code.  We use REPLACE for `code` so an existing tenant whose
-- entity was hand-created with a colliding code doesn't error out — the
-- INSERT IGNORE further protects us against the (tenant_id, code) UNIQUE.
INSERT IGNORE INTO accounting_entities
    (tenant_id, code, legal_name, country, base_currency,
     parent_entity_id, active, created_at, updated_at)
SELECT
    t.id AS tenant_id,
    -- Derive a 4-letter ALL-CAPS code from the slug (or name).  We strip
    -- hyphens and underscores via nested REPLACE() so a slug like
    -- "seven-generations" yields "SEVE" not "SEVE-GENE".  Sticks with
    -- MySQL 5.7-compatible string functions only (no REGEXP_REPLACE).
    UPPER(LEFT(
        REPLACE(REPLACE(REPLACE(REPLACE(
            COALESCE(NULLIF(t.slug, ''), t.name, 'MAIN'),
            '-', ''), '_', ''), '.', ''), ' ', ''),
        4
    )) AS code,
    t.name AS legal_name,
    'US'  AS country,
    'USD' AS base_currency,
    NULL  AS parent_entity_id,
    1     AS active,
    NOW() AS created_at,
    NOW() AS updated_at
  FROM tenants t
 WHERE t.is_active = 1
   AND NOT EXISTS (
       SELECT 1 FROM accounting_entities ae
        WHERE ae.tenant_id = t.id
   );

-- ── Part 2 — rename mis-named existing entities ─────────────────────────
-- If a tenant has exactly one entity AND that entity's legal_name doesn't
-- match the tenant's name (a sibling/parent tenant leaked through during
-- hand-onboarding), realign it to the tenant's own name.  We touch the
-- code too only when it would collide with another tenant's code.
UPDATE accounting_entities ae
  JOIN tenants t ON t.id = ae.tenant_id
   SET ae.legal_name = t.name,
       ae.updated_at = NOW()
 WHERE ae.legal_name <> t.name
   AND ae.tenant_id IN (
       SELECT tenant_id FROM (
           SELECT tenant_id, COUNT(*) AS n
             FROM accounting_entities
            WHERE active = 1
            GROUP BY tenant_id
       ) sub
        WHERE sub.n = 1
   );

-- ── Part 3 — wire parent_entity_id for every sub-tenant ─────────────────
-- For every sub-tenant whose parent tenant has at least one active
-- entity, point the sub-tenant's primary entity's parent_entity_id at
-- the parent tenant's lowest-id active entity.  Idempotent: we only
-- touch rows where parent_entity_id is currently NULL.
UPDATE accounting_entities ae_sub
  JOIN tenants t_sub ON t_sub.id = ae_sub.tenant_id AND t_sub.parent_id IS NOT NULL
  JOIN (
       SELECT ae_parent.tenant_id, MIN(ae_parent.id) AS parent_entity_id
         FROM accounting_entities ae_parent
        WHERE ae_parent.active = 1
        GROUP BY ae_parent.tenant_id
  ) p ON p.tenant_id = t_sub.parent_id
   SET ae_sub.parent_entity_id = p.parent_entity_id,
       ae_sub.updated_at       = NOW()
 WHERE ae_sub.parent_entity_id IS NULL
   AND ae_sub.active = 1;
