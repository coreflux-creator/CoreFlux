-- Rebuild the staffing client directory from the canonical company/placement
-- graph. Placements and companies are shared catalogs for sub-tenants, so the
-- consumer row must live beside them rather than in an isolated financial
-- tenant. Idempotent: existing client details are preserved.

INSERT IGNORE INTO companies (tenant_id, name)
SELECT DISTINCT p.tenant_id, TRIM(p.end_client_name)
  FROM placements p
 WHERE p.end_client_name IS NOT NULL
   AND TRIM(p.end_client_name) <> ''
   AND NOT EXISTS (
       SELECT 1
         FROM companies c
        WHERE c.tenant_id = p.tenant_id
          AND c.name = TRIM(p.end_client_name)
          AND c.deleted_at IS NULL
   );

INSERT IGNORE INTO company_roles (company_id, role)
SELECT DISTINCT c.id, 'client'
  FROM companies c
  JOIN placements p
    ON p.tenant_id = c.tenant_id
   AND c.name = TRIM(p.end_client_name)
 WHERE c.deleted_at IS NULL
   AND p.end_client_name IS NOT NULL
   AND TRIM(p.end_client_name) <> '';

UPDATE placements p
JOIN companies c
  ON c.tenant_id = p.tenant_id
 AND c.name = TRIM(p.end_client_name)
 AND c.deleted_at IS NULL
   SET p.end_client_company_id = c.id
 WHERE p.end_client_company_id IS NULL
   AND p.end_client_name IS NOT NULL
   AND TRIM(p.end_client_name) <> '';

UPDATE staffing_clients sc
JOIN companies c
  ON c.tenant_id = sc.tenant_id
 AND c.name = sc.name
 AND c.deleted_at IS NULL
   SET sc.company_id = c.id
 WHERE sc.company_id IS NULL
    OR sc.company_id <> c.id;

INSERT IGNORE INTO staffing_clients
    (tenant_id, company_id, name, legal_name, primary_contact_name,
     primary_contact_email, primary_contact_phone, billing_address_line1,
     billing_address_line2, billing_city, billing_state, billing_postal_code,
     billing_country, status)
SELECT c.tenant_id, c.id, c.name, c.legal_name, c.primary_contact_name,
       c.primary_contact_email, c.primary_contact_phone, c.address_line1,
       c.address_line2, c.city, c.state, c.postal_code, c.country, 'active'
  FROM companies c
 WHERE c.deleted_at IS NULL
   AND EXISTS (
       SELECT 1
         FROM placements p
        WHERE p.tenant_id = c.tenant_id
          AND p.end_client_company_id = c.id
   )
   AND NOT EXISTS (
       SELECT 1
         FROM staffing_clients sc
        WHERE sc.tenant_id = c.tenant_id
          AND (sc.company_id = c.id OR sc.name = c.name)
   );

UPDATE placements p
JOIN staffing_clients sc
  ON sc.tenant_id = p.tenant_id
 AND sc.company_id = p.end_client_company_id
   SET p.client_id = sc.id
 WHERE p.end_client_company_id IS NOT NULL
   AND (p.client_id IS NULL OR p.client_id <> sc.id);

UPDATE placements p
JOIN staffing_clients sc
  ON sc.tenant_id = p.tenant_id
 AND sc.name = TRIM(p.end_client_name)
   SET p.client_id = sc.id
 WHERE p.client_id IS NULL
   AND p.end_client_name IS NOT NULL
   AND TRIM(p.end_client_name) <> '';
