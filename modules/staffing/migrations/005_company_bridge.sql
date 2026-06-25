-- Staffing clients consume the canonical People/Companies organization graph.
-- This adds a durable bridge from staffing_clients -> companies and backfills
-- existing rows by exact tenant/name match. Idempotent, MySQL 5.7+ compatible.

SET @col := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'staffing_clients'
     AND column_name = 'company_id'
);
SET @sql := IF(@col = 0,
  'ALTER TABLE staffing_clients ADD COLUMN company_id BIGINT UNSIGNED NULL AFTER tenant_id',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE staffing_clients sc
JOIN companies c
  ON c.tenant_id = sc.tenant_id
 AND c.name = sc.name
 AND c.deleted_at IS NULL
   SET sc.company_id = c.id
 WHERE sc.company_id IS NULL;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'staffing_clients'
     AND index_name = 'idx_sc_company'
);
SET @sql := IF(@idx = 0,
  'CREATE INDEX idx_sc_company ON staffing_clients (tenant_id, company_id)',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @pl_idx := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'placements'
     AND index_name = 'idx_pl_ap_cycle'
);
SET @sql := IF(@pl_idx = 0,
  'CREATE INDEX idx_pl_ap_cycle ON placements (tenant_id, ap_cycle_id)',
  'DO 0'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
