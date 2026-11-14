-- Existing jobs retain Core values until a reviewer selects source ownership.
SET @present := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staffing_jobs' AND COLUMN_NAME='coreflux_source_state');
SET @ddl := IF(@present=0,'ALTER TABLE staffing_jobs ADD COLUMN coreflux_source_state JSON NULL','DO 0');
PREPARE job_source_ddl FROM @ddl;
EXECUTE job_source_ddl;
DEALLOCATE PREPARE job_source_ddl;
