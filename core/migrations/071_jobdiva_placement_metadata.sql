-- 071_jobdiva_placement_metadata.sql
--
-- JobDiva integration completeness — Slice 5b (2026-02).
--
-- Adds columns to `placements` for JobDiva data we already pull but
-- previously dropped on the floor:
--
--   jobdiva_job_id          The JobDiva *Job* entity ID (distinct from
--                           the assignment/placement external_id we already
--                           store as `external_id = "jd:<assignment_id>"`).
--                           Useful for cross-linking back to the JobDiva
--                           Job record and avoiding duplicate placements
--                           when an assignment is rebooked under the same
--                           job req.
--
--   recruiter_name          The JobDiva recruiter on the placement.
--   recruiter_email         Often the operator who needs the timesheet
--                           approval ping.
--
--   account_manager_name    The JobDiva AM / sales person responsible
--   account_manager_email   for the client relationship.
--
-- All four are nullable VARCHAR strings (no FK constraints — they're
-- denormalised snapshots from the external system, not relational
-- references inside CoreFlux). Operators can map any JobDiva payload
-- field into these columns through the tenant_integration_field_map
-- registry — see /app/core/integrations/field_map.php (entity 'placement').

ALTER TABLE placements
    ADD COLUMN jobdiva_job_id        VARCHAR(64)  NULL AFTER external_id,
    ADD COLUMN recruiter_name        VARCHAR(255) NULL AFTER notes,
    ADD COLUMN recruiter_email       VARCHAR(255) NULL AFTER recruiter_name,
    ADD COLUMN account_manager_name  VARCHAR(255) NULL AFTER recruiter_email,
    ADD COLUMN account_manager_email VARCHAR(255) NULL AFTER account_manager_name;

CREATE INDEX idx_placements_jobdiva_job_id ON placements (tenant_id, jobdiva_job_id);
