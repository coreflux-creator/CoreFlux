-- CoreFlux-owned work routing for Connecteam time.
--
-- Connecteam jobs are operating categories (client/site/internal work), not
-- staffing placements.  These tables preserve that distinction and provide
-- an effective-dated bridge from a linked Connecteam user and source job to
-- either a real CoreFlux placement or an overhead destination.

CREATE TABLE IF NOT EXISTS connecteam_overhead_categories (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    code                     VARCHAR(64) NOT NULL,
    name                     VARCHAR(160) NOT NULL,
    department               VARCHAR(120) NULL,
    accounting_account_id    BIGINT UNSIGNED NULL,
    time_category            ENUM('regular_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom')
                             NOT NULL DEFAULT 'regular_nonbillable',
    active                   TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id       BIGINT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_connecteam_overhead_code (tenant_id, code),
    KEY ix_connecteam_overhead_active (tenant_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connecteam_work_routes (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    source_job_id            VARCHAR(128) NOT NULL,
    source_sub_job_id        VARCHAR(128) NOT NULL DEFAULT '',
    source_job_name          VARCHAR(255) NULL,
    source_user_id           VARCHAR(128) NOT NULL DEFAULT '',
    source_user_name         VARCHAR(255) NULL,
    person_id                BIGINT UNSIGNED NULL,
    destination_type         ENUM('placement','overhead') NOT NULL,
    placement_id             BIGINT UNSIGNED NULL,
    overhead_category_id     BIGINT UNSIGNED NULL,
    time_category            ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable',
                                 'holiday','vacation','sick','bereavement','unpaid_leave','custom')
                             NOT NULL DEFAULT 'regular_billable',
    effective_from           DATE NOT NULL,
    effective_to             DATE NULL,
    priority                 SMALLINT NOT NULL DEFAULT 100,
    active                   TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id       BIGINT UNSIGNED NULL,
    created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_connecteam_work_route_start
        (tenant_id, source_job_id, source_sub_job_id, source_user_id, effective_from),
    KEY ix_connecteam_work_route_lookup
        (tenant_id, source_job_id, source_sub_job_id, source_user_id, active, effective_from, effective_to),
    KEY ix_connecteam_work_route_person (tenant_id, person_id, active),
    KEY ix_connecteam_work_route_placement (tenant_id, placement_id, active),
    KEY ix_connecteam_work_route_overhead (tenant_id, overhead_category_id, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS connecteam_time_staging (
    id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id                BIGINT UNSIGNED NOT NULL,
    time_clock_id            VARCHAR(128) NOT NULL,
    source_activity_id       VARCHAR(128) NOT NULL,
    source_user_id           VARCHAR(128) NOT NULL,
    source_job_id            VARCHAR(128) NOT NULL DEFAULT '',
    source_sub_job_id        VARCHAR(128) NOT NULL DEFAULT '',
    person_id                BIGINT UNSIGNED NULL,
    work_route_id            BIGINT UNSIGNED NULL,
    work_date                DATE NOT NULL,
    hours                    DECIMAL(8,4) NOT NULL,
    source_approved          TINYINT(1) NOT NULL DEFAULT 0,
    source_submitted         TINYINT(1) NOT NULL DEFAULT 0,
    routing_status           ENUM('ready','unlinked_person','unmapped_work','ambiguous_route','invalid_route','unapproved')
                             NOT NULL,
    payload_snapshot         JSON NULL,
    content_hash             CHAR(64) NULL,
    imported_time_entry_id   BIGINT UNSIGNED NULL,
    first_seen_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_connecteam_time_activity (tenant_id, time_clock_id, source_activity_id),
    KEY ix_connecteam_time_queue (tenant_id, routing_status, work_date),
    KEY ix_connecteam_time_person (tenant_id, person_id, work_date),
    KEY ix_connecteam_time_route (tenant_id, work_route_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
