-- Existing clients retain their Core values until explicitly reviewed.
SET @present := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='staffing_clients' AND COLUMN_NAME='coreflux_source_state');
SET @ddl := IF(@present=0,'ALTER TABLE staffing_clients ADD COLUMN coreflux_source_state JSON NULL','DO 0');
PREPARE client_source_ddl FROM @ddl;
EXECUTE client_source_ddl;
DEALLOCATE PREPARE client_source_ddl;
