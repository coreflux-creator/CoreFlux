-- Tenant-authoritative bank-account ownership.
--
-- Plaid-created accounting_bank_accounts historically omitted entity_id.
-- Backfill only tenants with exactly one active accounting entity; choosing
-- silently would be unsafe for a genuine multi-entity tenant.

UPDATE accounting_bank_accounts ba
JOIN (
    SELECT tenant_id, MIN(id) AS entity_id
      FROM accounting_entities
     WHERE active = 1
     GROUP BY tenant_id
    HAVING COUNT(*) = 1
) single_entity ON single_entity.tenant_id = ba.tenant_id
SET ba.entity_id = single_entity.entity_id
WHERE ba.entity_id IS NULL;
