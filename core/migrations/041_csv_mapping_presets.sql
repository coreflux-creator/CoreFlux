-- CSV mapping presets (2026-02-14)
--
-- Persists "named" header→field maps per tenant + entity so reruns of the
-- same CSV format (e.g. monthly ADP payroll export, QuickBooks vendor
-- dump) are zero-click after the first AI-assisted run. We recognise a
-- matching CSV by hashing its sorted-lowercase headers.
--
-- Lookup pattern on the import wizard:
--   SELECT * FROM csv_mapping_presets
--    WHERE tenant_id = ? AND entity = ? AND header_signature = ?
--    ORDER BY last_used_at DESC
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS csv_mapping_presets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    entity     VARCHAR(60)  NOT NULL,  -- 'people' | 'ap_vendors' | 'staffing_clients' | 'placements' | 'time' | 'ap_bills' | 'billing_invoices' | 'ap_payments' | 'billing_payments'
    name       VARCHAR(120) NOT NULL,  -- 'QuickBooks vendors', 'ADP payroll export', etc.
    -- SHA-256 of the comma-joined, lowercased, sorted header list.
    -- Lets us recognise the same CSV format on subsequent imports.
    header_signature CHAR(64) NOT NULL,
    -- Header → field_key map. Header names stored verbatim (case as in the
    -- source CSV). Values are field_key strings; nulls are skipped columns.
    column_map        JSON NOT NULL,
    -- Source headers in their original case (cached so the UI can show the
    -- exact column the preset was built from).
    source_headers    JSON NULL,

    used_count        INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_at      DATETIME NULL,

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tenant_entity_name (tenant_id, entity, name),
    KEY ix_tenant_entity_signature   (tenant_id, entity, header_signature),
    KEY ix_tenant_last_used          (tenant_id, last_used_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
