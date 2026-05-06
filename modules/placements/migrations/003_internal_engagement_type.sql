-- Sprint 6j — add 'internal' to engagement_type enum so the agency can place
-- its own internal employees (admins, recruiters, accountants) as first-class
-- placements rather than the "Internal as end-client" hack.
--
-- Idempotent — Duplicate column name / no-op when already converged.
ALTER TABLE placements
    MODIFY COLUMN engagement_type
    ENUM('w2','1099','c2c','temp_to_perm','direct_hire','internal')
    NOT NULL DEFAULT 'w2';
