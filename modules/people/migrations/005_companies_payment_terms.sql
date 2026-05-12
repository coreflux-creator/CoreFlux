-- Phase 2 — per-counterparty payment terms.
--
-- Adds `payment_terms_days` to the unified `companies` table (used for
-- both AP vendors and legacy AR clients). The new `staffing_clients`
-- table already has its own `payment_terms_days` for the Phase 2 client
-- entity; AR resolution prefers that, then falls back to companies, then
-- to the tenant-wide default.
--
-- Idempotent — information_schema-guarded. One statement per line.
-- `DO 0` no-op fallback.

SET @c_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'companies')
;
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'companies' AND column_name = 'payment_terms_days')
;
SET @sql := IF(@c_exists = 1 AND @col = 0, 'ALTER TABLE companies ADD COLUMN payment_terms_days INT NULL', 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
