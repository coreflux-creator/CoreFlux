-- AP — 014 — Purchase Orders for three-way match (PO ↔ receipt ↔ invoice).
-- Idempotent. MVP: header + lines + a receipt log against PO lines. Soft
-- warnings on bill mismatch (configurable).

CREATE TABLE IF NOT EXISTS ap_purchase_orders (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    po_number       VARCHAR(80) NOT NULL,
    vendor_name     VARCHAR(255) NOT NULL,
    vendor_id       INT UNSIGNED NULL,
    issue_date      DATE NOT NULL,
    expected_date   DATE NULL,
    currency        CHAR(3) NOT NULL DEFAULT 'USD',
    subtotal        DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_total       DECIMAL(12,2) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0,
    status          ENUM('draft','open','partially_received','received','closed','cancelled') NOT NULL DEFAULT 'open',
    notes           TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_appo_tenant_number (tenant_id, po_number),
    INDEX idx_appo_tenant_status (tenant_id, status),
    INDEX idx_appo_tenant_vendor (tenant_id, vendor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_purchase_order_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    po_id           INT UNSIGNED NOT NULL,
    line_no         INT UNSIGNED NOT NULL DEFAULT 1,
    description     VARCHAR(500) NOT NULL,
    quantity        DECIMAL(12,4) NOT NULL DEFAULT 0,
    unit            VARCHAR(40) NOT NULL DEFAULT 'each',
    unit_price      DECIMAL(12,4) NOT NULL DEFAULT 0,
    total           DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity_received DECIMAL(12,4) NOT NULL DEFAULT 0,
    quantity_billed   DECIMAL(12,4) NOT NULL DEFAULT 0,
    gl_expense_account_code VARCHAR(40) NULL,
    INDEX idx_appol_po (po_id),
    CONSTRAINT fk_appol_po FOREIGN KEY (po_id) REFERENCES ap_purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_po_receipts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    po_id           INT UNSIGNED NOT NULL,
    received_date   DATE NOT NULL,
    received_by_user_id INT UNSIGNED NULL,
    note            VARCHAR(500) NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_appor_po (tenant_id, po_id, received_date),
    CONSTRAINT fk_appor_po FOREIGN KEY (po_id) REFERENCES ap_purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_po_receipt_lines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receipt_id      INT UNSIGNED NOT NULL,
    po_line_id      INT UNSIGNED NOT NULL,
    quantity        DECIMAL(12,4) NOT NULL,
    INDEX idx_apporl_receipt (receipt_id),
    CONSTRAINT fk_apporl_receipt FOREIGN KEY (receipt_id) REFERENCES ap_po_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tenant-level config: hard-block on three-way mismatch (default false → soft warning).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tenants'
                      AND COLUMN_NAME  = 'ap_three_way_match_enforce');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN ap_three_way_match_enforce TINYINT(1) NOT NULL DEFAULT 0',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Tolerance percentage (default 5% — bills within ±5% of PO total auto-pass).
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                      AND TABLE_NAME   = 'tenants'
                      AND COLUMN_NAME  = 'ap_three_way_match_tolerance_pct');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE tenants ADD COLUMN ap_three_way_match_tolerance_pct DECIMAL(5,2) NOT NULL DEFAULT 5.00',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
