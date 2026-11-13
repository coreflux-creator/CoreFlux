-- A reviewed merge may retain either current name. Retired rows keep their
-- original name and permanent number; only active directory names are unique.
SET @present := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='active_name_key');
SET @ddl := IF(@present=0,'ALTER TABLE companies ADD COLUMN active_name_key VARCHAR(255) GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN name ELSE NULL END) STORED','DO 0');
PREPARE company_merge_ddl FROM @ddl;
EXECUTE company_merge_ddl;
DEALLOCATE PREPARE company_merge_ddl;
SET @present := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND INDEX_NAME='uq_companies_tenant_active_name');
SET @ddl := IF(@present=0,'CREATE UNIQUE INDEX uq_companies_tenant_active_name ON companies(tenant_id,active_name_key)','DO 0');
PREPARE company_merge_ddl FROM @ddl;
EXECUTE company_merge_ddl;
DEALLOCATE PREPARE company_merge_ddl;
SET @present := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND INDEX_NAME='uq_companies_tenant_name');
SET @ddl := IF(@present>0,'ALTER TABLE companies DROP INDEX uq_companies_tenant_name','DO 0');
PREPARE company_merge_ddl FROM @ddl;
EXECUTE company_merge_ddl;
DEALLOCATE PREPARE company_merge_ddl;
