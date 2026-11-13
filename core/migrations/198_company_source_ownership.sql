-- NULL preserves existing records and means ownership has not been reviewed.
-- Only newly created source records start with source-owned fields.
SET @present := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='companies' AND COLUMN_NAME='coreflux_source_state');
SET @ddl := IF(@present=0, 'ALTER TABLE companies ADD COLUMN coreflux_source_state JSON NULL', 'DO 0');
PREPARE company_source_ddl FROM @ddl;
EXECUTE company_source_ddl;
DEALLOCATE PREPARE company_source_ddl;
SET @present := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='company_contacts' AND COLUMN_NAME='coreflux_source_state');
SET @ddl := IF(@present=0, 'ALTER TABLE company_contacts ADD COLUMN coreflux_source_state JSON NULL', 'DO 0');
PREPARE contact_source_ddl FROM @ddl;
EXECUTE contact_source_ddl;
DEALLOCATE PREPARE contact_source_ddl;
