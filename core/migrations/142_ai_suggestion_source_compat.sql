-- Keep advisory AI provenance forward-compatible. An enum mismatch here once
-- prevented the Treasury activity page from loading any bank transactions.
ALTER TABLE ai_suggestions
    MODIFY COLUMN suggestion_source VARCHAR(32) NULL;
