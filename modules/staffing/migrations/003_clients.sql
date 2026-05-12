-- CoreStaffing Phase 2 — Clients entity.
--
-- Currently `placements.end_client_name` is a denormalized string. This
-- migration:
--   1. Creates `staffing_clients` table.
--   2. Adds `client_id` FK column to placements.
--   3. Backfills clients from distinct end_client_name strings.
--   4. Links every placement to its client.
--
-- Idempotent — every DDL is information_schema-guarded. One PREPARE /
-- EXECUTE / DEALLOCATE per line. `DO 0` no-op fallback.

CREATE TABLE IF NOT EXISTS staffing_clients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    legal_name VARCHAR(255) NULL,
    industry VARCHAR(80) NULL,
    primary_contact_name VARCHAR(120) NULL,
    primary_contact_email VARCHAR(255) NULL,
    primary_contact_phone VARCHAR(40) NULL,
    billing_address_line1 VARCHAR(255) NULL,
    billing_address_line2 VARCHAR(255) NULL,
    billing_city VARCHAR(80) NULL,
    billing_state VARCHAR(40) NULL,
    billing_postal_code VARCHAR(20) NULL,
    billing_country VARCHAR(2) NULL DEFAULT 'US',
    payment_terms_days INT NOT NULL DEFAULT 30,
    status ENUM('active','prospect','on_hold','inactive','closed') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    msa_status ENUM('none','draft','executed','expired') NOT NULL DEFAULT 'none',
    msa_executed_at DATE NULL,
    msa_expires_at DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sc_tenant_name (tenant_id, name),
    INDEX idx_sc_tenant_status (tenant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add client_id FK column to placements.
SET @pl_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'placements')
;
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'client_id')
;
SET @sql := IF(@pl_exists = 1 AND @col = 0, 'ALTER TABLE placements ADD COLUMN client_id BIGINT UNSIGNED NULL, ADD INDEX idx_pl_client (client_id)', 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

-- Backfill: one staffing_clients row per distinct (tenant_id, end_client_name).
SET @sql := IF(@pl_exists = 1, "INSERT IGNORE INTO staffing_clients (tenant_id, name, status) SELECT tenant_id, end_client_name, 'active' FROM placements WHERE end_client_name IS NOT NULL AND TRIM(end_client_name) <> '' GROUP BY tenant_id, end_client_name", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

-- Link every placement to its client by name match.
SET @sql := IF(@pl_exists = 1, "UPDATE placements p JOIN staffing_clients c ON c.tenant_id = p.tenant_id AND c.name = p.end_client_name SET p.client_id = c.id WHERE p.client_id IS NULL AND p.end_client_name IS NOT NULL", 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
