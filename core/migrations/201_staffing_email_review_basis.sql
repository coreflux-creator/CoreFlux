-- Retain the complete facts delegated to a weekly external approver.
-- Earlier credentials have no issued basis and require a fresh request.
ALTER TABLE approval_tokens
    ADD COLUMN IF NOT EXISTS issued_review_json LONGTEXT NULL;
