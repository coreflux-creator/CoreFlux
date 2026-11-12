-- Employment setup changes retain their original reviewed facts and replay receipt.
CREATE TABLE IF NOT EXISTS people_employment_reviews (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    review_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    review_ct MEDIUMBLOB NOT NULL,
    history_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employment_setup_review (tenant_id,review_id),
    KEY idx_employment_setup_history (tenant_id,employee_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
