-- Preserve two-eye accountability for bills prepared before issuer attribution.
-- Only the credential linked to the exact CoreOne source mapping may supply the creator.
UPDATE ap_bills b
JOIN coreone_document_requests d
  ON d.tenant_id = b.tenant_id AND d.entity_id = b.entity_id
 AND d.source_type = 'ap.bill' AND d.target_id = b.id
JOIN coreone_accounting_credentials c
  ON c.tenant_id = d.tenant_id AND c.id = d.credential_id
JOIN users u
  ON u.tenant_id = d.tenant_id AND u.id = c.created_by_user_id
SET b.created_by_user_id = u.id
WHERE b.created_by_user_id IS NULL;
