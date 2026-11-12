-- Recipient evidence is captured with a new native payroll obligation.
-- Historical obligations are not adopted from today's mutable participant.
CREATE TABLE IF NOT EXISTS placement_payroll_recipient_receipts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    obligation_id BIGINT UNSIGNED NOT NULL,
    employee_tenant_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    payroll_profile_id BIGINT UNSIGNED NOT NULL,
    receipt_json LONGTEXT NOT NULL,
    receipt_sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payroll_recipient_obligation (tenant_id, obligation_id),
    INDEX idx_payroll_recipient_employee (employee_tenant_id, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
