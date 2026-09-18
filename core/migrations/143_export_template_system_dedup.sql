-- Remove duplicate active system templates left by replayed legacy seed SQL.
-- Keep the oldest row for each scope / tenant / dataset / name tuple so saved
-- links remain stable and tenant-authored templates are never touched.

UPDATE export_templates duplicate_template
JOIN export_templates canonical_template
  ON canonical_template.id < duplicate_template.id
 AND canonical_template.scope = duplicate_template.scope
 AND COALESCE(canonical_template.tenant_id, 0) = COALESCE(duplicate_template.tenant_id, 0)
 AND canonical_template.dataset = duplicate_template.dataset
 AND canonical_template.name = duplicate_template.name
 AND canonical_template.is_system = 1
 AND canonical_template.is_active = 1
SET duplicate_template.is_active = 0,
    duplicate_template.updated_at = NOW()
WHERE duplicate_template.is_system = 1
  AND duplicate_template.is_active = 1;
