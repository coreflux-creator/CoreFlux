-- Employer-reviewed onboarding; operational tenant and shared directory owners
-- are distinct. Nothing here activates a worker, placement or payroll run.
CREATE TABLE IF NOT EXISTS staffing_onboarding_cases (
    id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    people_tenant_id BIGINT UNSIGNED NOT NULL,
    person_id BIGINT UNSIGNED NOT NULL,
    placements_tenant_id BIGINT UNSIGNED NOT NULL,
    placement_id BIGINT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    plan_version INT UNSIGNED NOT NULL DEFAULT 1,
    confirmed_plan_version INT UNSIGNED NULL,
    confirmed_by_user_id BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    confirmed_source_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    tasks_json LONGTEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_onboarding_placement (tenant_id,placements_tenant_id,placement_id),
    KEY ix_onboarding_person (tenant_id,people_tenant_id,person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An event and its idempotent receipt commit in the same transaction as the
-- case revision. These rows are append-only; no endpoint edits/deletes them.
CREATE TABLE IF NOT EXISTS staffing_onboarding_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    case_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    case_version INT UNSIGNED NOT NULL,
    request_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(32) NOT NULL,
    task_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    details_json LONGTEXT NOT NULL,
    receipt_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_onboarding_request (tenant_id,request_id),
    UNIQUE KEY uq_onboarding_revision (tenant_id,case_id,case_version),
    KEY ix_onboarding_history (tenant_id,case_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
