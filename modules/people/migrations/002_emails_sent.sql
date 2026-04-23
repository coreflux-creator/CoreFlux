-- People module — Email send log
-- Adds tracking for any outgoing email triggered by People features (setup,
-- onboarding, offer-letter delivery, etc.). Append-only audit.

CREATE TABLE IF NOT EXISTS people_emails_sent (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id       INT UNSIGNED NOT NULL,
    user_id         INT UNSIGNED NULL,              -- who initiated
    employee_id     INT UNSIGNED NOT NULL,          -- target employee
    kind            VARCHAR(64)  NOT NULL,          -- 'setup_email','offer_letter','reset', ...
    suggestion_id   BIGINT UNSIGNED NULL,           -- FK -> ai_suggestions.id (may be null if no AI draft)
    to_email        VARCHAR(255) NOT NULL,
    subject         VARCHAR(255) NOT NULL,
    body_hash       CHAR(64)     NOT NULL,          -- sha256 of body_text; full body NOT stored
    smtp_message_id VARCHAR(255) NULL,
    status          ENUM('sent','failed') NOT NULL,
    error           TEXT NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pes_tenant_emp_time (tenant_id, employee_id, created_at),
    INDEX idx_pes_tenant_kind (tenant_id, kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
