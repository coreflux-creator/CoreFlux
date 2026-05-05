-- 016_review_flags_recruiter.sql
--
-- ALTER the review_flags ENUM to add 'recruiter' on existing tenants
-- where 015 has already run (idempotent for fresh installs because the
-- 015 file already lists 'recruiter').

ALTER TABLE review_flags
  MODIFY COLUMN entity_type
  ENUM('placement','invoice','bill','person','recruiter') NOT NULL;
