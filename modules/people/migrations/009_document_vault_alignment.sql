-- Align the legacy employee-document table with the existing person vault API.
-- 001 and 003 both used CREATE TABLE IF NOT EXISTS, so 003 could not add its
-- person/storage columns on an earlier table. Preserve every legacy field/row.
-- Do NOT equate employee_id with person_id or trust an opaque legacy file path.
-- Unresolved legacy rows retain NULL canonical references for separate review.
-- Nullable legacy-only columns permit new canonical rows without invented data.

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='person_id');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN person_id BIGINT UNSIGNED NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='doc_type');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN doc_type ENUM(''resume'',''offer'',''i9'',''w4'',''w9'',''nda'',''contract'',''passport'',''visa'',''license'',''other'') NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='storage_object_id');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN storage_object_id BIGINT UNSIGNED NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='file_name');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN file_name VARCHAR(255) NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='signed');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN signed TINYINT(1) NOT NULL DEFAULT 0','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='expires_at');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN expires_at DATETIME NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='uploaded_by_user_id');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN uploaded_by_user_id BIGINT UNSIGNED NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='created_at');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='deleted_at');
SET @doc_sql := IF(@doc_col=0,'ALTER TABLE people_documents ADD COLUMN deleted_at DATETIME NULL','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN employee_id ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='employee_id' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN kind ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='kind' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN filename ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='filename' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN mime_type ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='mime_type' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN size_bytes ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='size_bytes' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_sql := COALESCE((SELECT CONCAT('ALTER TABLE people_documents MODIFY COLUMN storage_path ',COLUMN_TYPE,IF(COLLATION_NAME IS NULL,'',CONCAT(' COLLATE ',COLLATION_NAME)),' NULL') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND COLUMN_NAME='storage_path' AND IS_NULLABLE='NO'),'DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_index := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND INDEX_NAME='idx_pd_person');
SET @doc_sql := IF(@doc_index=0,'CREATE INDEX idx_pd_person ON people_documents (tenant_id,person_id)','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_index := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND INDEX_NAME='idx_pd_tenant_type');
SET @doc_sql := IF(@doc_index=0,'CREATE INDEX idx_pd_tenant_type ON people_documents (tenant_id,doc_type)','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;

SET @doc_index := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='people_documents' AND INDEX_NAME='idx_pd_expires');
SET @doc_sql := IF(@doc_index=0,'CREATE INDEX idx_pd_expires ON people_documents (tenant_id,expires_at)','DO 0');
PREPARE doc_stmt FROM @doc_sql; EXECUTE doc_stmt; DEALLOCATE PREPARE doc_stmt;
