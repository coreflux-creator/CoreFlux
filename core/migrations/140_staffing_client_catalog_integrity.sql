-- Restore the placement -> staffing client convenience pointer from the
-- canonical company identity. JobDiva-specific source validation and cleanup
-- run in scripts/repair_staffing_client_catalog.php after migrations.

UPDATE placements p
JOIN staffing_clients canonical
  ON canonical.tenant_id = p.tenant_id
 AND canonical.company_id = p.end_client_company_id
LEFT JOIN staffing_clients current_client
  ON current_client.tenant_id = p.tenant_id
 AND current_client.id = p.client_id
   SET p.client_id = canonical.id,
       p.end_client_name = canonical.name,
       p.updated_at = NOW()
 WHERE p.deleted_at IS NULL
   AND p.end_client_company_id IS NOT NULL
   AND (
        p.client_id IS NULL
     OR current_client.id IS NULL
     OR current_client.company_id IS NULL
     OR current_client.company_id <> p.end_client_company_id
   );
