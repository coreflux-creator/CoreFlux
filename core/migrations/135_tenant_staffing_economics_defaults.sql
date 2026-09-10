-- Tenant W-2 employer-cost defaults. Placement rate columns remain nullable:
-- NULL inherits these values, while zero is an explicit placement override.
CREATE TABLE IF NOT EXISTS tenant_staffing_economics_defaults (
    tenant_id                  BIGINT UNSIGNED NOT NULL,
    w2_payroll_load_pct        DECIMAL(8,6) NOT NULL DEFAULT 0,
    w2_workers_comp_pct        DECIMAL(8,6) NOT NULL DEFAULT 0,
    w2_benefits_load_pct       DECIMAL(8,6) NOT NULL DEFAULT 0,
    updated_by_user_id         BIGINT UNSIGNED NULL,
    created_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

