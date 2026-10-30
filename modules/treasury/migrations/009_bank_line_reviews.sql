-- Append-only decisions about similar-looking bank lines. A decision is tied
-- to the facts reviewed, so later feed corrections return the pair to review.
CREATE TABLE IF NOT EXISTS treasury_bank_line_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    bank_account_id BIGINT UNSIGNED NOT NULL,
    first_line_id BIGINT UNSIGNED NOT NULL,
    second_line_id BIGINT UNSIGNED NOT NULL,
    fingerprint CHAR(64) NOT NULL,
    decision ENUM('distinct', 'reopened') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    evidence_ref VARCHAR(255) NULL,
    decided_by_user_id BIGINT UNSIGNED NOT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tblr_account_pair (tenant_id, bank_account_id, first_line_id, second_line_id, id),
    INDEX idx_tblr_account_time (tenant_id, bank_account_id, decided_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
