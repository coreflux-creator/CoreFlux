-- Existing employment identities are reviewed explicitly; no name/email backfill.
-- Unknown employment facts remain unknown. Existing values are preserved.
ALTER TABLE people_employees
    MODIFY employment_type ENUM('full_time','part_time','contractor','intern','temp') NULL DEFAULT NULL,
    MODIFY flsa_class ENUM('exempt','non_exempt') NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS people_employment_links (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    review_id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    origin VARCHAR(32) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    review_ct MEDIUMBLOB NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_employment_person (tenant_id,person_id),
    UNIQUE KEY uq_employment_employee (tenant_id,employee_id),
    UNIQUE KEY uq_employment_review (tenant_id,review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
