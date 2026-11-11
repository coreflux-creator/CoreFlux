-- Explicit captured-assignment routes can overlap; legacy rows retain an empty key.
SET @present := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='quanta_time_routes' AND column_name='quanta_assignment_id');
SET @sql := IF(@present=0, 'ALTER TABLE quanta_time_routes ADD COLUMN quanta_assignment_id VARCHAR(68) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT ''''', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @present := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='quanta_time_routes' AND index_name='uq_quanta_route_assignment_start');
SET @sql := IF(@present=0, 'ALTER TABLE quanta_time_routes ADD UNIQUE KEY uq_quanta_route_assignment_start (tenant_id,worker_id,worksite_id,dimension_key,effective_from,quanta_assignment_id)', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @present := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='quanta_time_routes' AND index_name='uq_quanta_route_start');
SET @sql := IF(@present>0, 'ALTER TABLE quanta_time_routes DROP INDEX uq_quanta_route_start', 'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
