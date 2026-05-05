-- AP — 015 — Bill approval comments thread + email notifications log.
-- Idempotent. Stores the conversation alongside an approval, plus a log
-- of email notifications sent so we can surface "notified at" + retries.

CREATE TABLE IF NOT EXISTS ap_bill_approval_comments (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    bill_id      INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    body         TEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apbac_bill (tenant_id, bill_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ap_bill_approval_notifications (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id    INT UNSIGNED NOT NULL,
    bill_id      INT UNSIGNED NOT NULL,
    approval_id  INT UNSIGNED NOT NULL,
    approver_user_id INT UNSIGNED NOT NULL,
    sent_to_email VARCHAR(255) NOT NULL,
    status       ENUM('sent','failed') NOT NULL DEFAULT 'sent',
    error_text   VARCHAR(500) NULL,
    sent_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_apban_bill (tenant_id, bill_id),
    INDEX idx_apban_approval (tenant_id, approval_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
