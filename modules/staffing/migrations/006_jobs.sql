-- CoreStaffing Phase 2 -- Jobs / Roles consumer graph.
--
-- Staffing owns the role context it needs for delivery and reporting, but
-- external ATS/job-board records remain source evidence. Placements stay the
-- commercial spine; staffing_jobs is the clean role node they can reference.

CREATE TABLE IF NOT EXISTS staffing_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    client_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    title VARCHAR(255) NOT NULL,
    status ENUM('open','active','on_hold','filled','closed','cancelled') NOT NULL DEFAULT 'open',
    external_id VARCHAR(128) NULL,
    source_system ENUM('manual','jobdiva','other') NOT NULL DEFAULT 'manual',
    description TEXT NULL,
    department VARCHAR(120) NULL,
    location_city VARCHAR(120) NULL,
    location_state VARCHAR(60) NULL,
    location_country CHAR(2) NULL,
    remote_policy ENUM('onsite','hybrid','remote') NULL,
    opened_at DATE NULL,
    closed_at DATE NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sj_tenant_source_ext (tenant_id, source_system, external_id),
    INDEX idx_sj_tenant_client_status (tenant_id, client_id, status),
    INDEX idx_sj_tenant_company (tenant_id, company_id),
    INDEX idx_sj_tenant_title (tenant_id, title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @pl_exists := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'placements')
;
SET @col := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'staffing_job_id')
;
SET @sql := IF(@pl_exists = 1 AND @col = 0, 'ALTER TABLE placements ADD COLUMN staffing_job_id BIGINT UNSIGNED NULL AFTER client_id, ADD INDEX idx_pl_staffing_job (staffing_job_id)', 'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;

SET @has_sj := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'staffing_job_id')
;
SET @has_jd := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'placements' AND column_name = 'jobdiva_job_id')
;
SET @sql := IF(@pl_exists = 1 AND @has_sj = 1 AND @has_jd = 1,
  "UPDATE placements p
      JOIN staffing_jobs sj
        ON sj.tenant_id = p.tenant_id
       AND sj.source_system = 'jobdiva'
       AND sj.external_id = p.jobdiva_job_id
     SET p.staffing_job_id = sj.id
   WHERE p.staffing_job_id IS NULL
     AND p.jobdiva_job_id IS NOT NULL
     AND p.jobdiva_job_id <> ''",
  'DO 0')
;
PREPARE s FROM @sql
;
EXECUTE s
;
DEALLOCATE PREPARE s
;
