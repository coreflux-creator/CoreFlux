-- AP — 013 — Recurring bills (utilities, subscriptions, monthly retainers).
-- Idempotent. A schedule template that auto-generates draft bills on a
-- frequency.  No payment auto-issued — generated bills follow the normal
-- approval workflow.

CREATE TABLE IF NOT EXISTS ap_recurring_bills (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    vendor_name         VARCHAR(255) NOT NULL,
    vendor_id           INT UNSIGNED NULL,
    description         VARCHAR(255) NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    frequency           ENUM('weekly','biweekly','monthly','quarterly','yearly') NOT NULL DEFAULT 'monthly',
    day_of_period       INT UNSIGNED NOT NULL DEFAULT 1,
    next_bill_date      DATE NOT NULL,
    last_generated_date DATE NULL,
    last_generated_bill_id BIGINT UNSIGNED NULL,
    end_date            DATE NULL,
    gl_expense_account_code VARCHAR(40) NULL,
    is_1099_eligible    TINYINT(1) NOT NULL DEFAULT 0,
    item_type           VARCHAR(40) NOT NULL DEFAULT 'subscription',
    status              ENUM('active','paused','ended') NOT NULL DEFAULT 'active',
    created_by_user_id  INT UNSIGNED NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aprb_tenant_status (tenant_id, status, next_bill_date),
    INDEX idx_aprb_tenant_vendor (tenant_id, vendor_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
