-- Treasury policy defaults for reserve planning and recommendation governance.
-- Idempotent. Tenant scoped.

CREATE TABLE IF NOT EXISTS tenant_treasury_policy (
    tenant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    minimum_cash_reserve DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    payroll_reserve DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    tax_reserve DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    ap_reserve DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    operating_reserve DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    materiality_threshold DECIMAL(18,2) NOT NULL DEFAULT 10000.00,
    forecast_days INT UNSIGNED NOT NULL DEFAULT 30,
    review_cadence_days INT UNSIGNED NOT NULL DEFAULT 30,
    approval_resource VARCHAR(80) NOT NULL DEFAULT 'treasury.payment',
    approval_permission VARCHAR(120) NOT NULL DEFAULT 'treasury.approve_payment',
    execution_permission VARCHAR(120) NOT NULL DEFAULT 'treasury.execute_payment',
    effective_date DATE NOT NULL,
    policy_version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ttp_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
