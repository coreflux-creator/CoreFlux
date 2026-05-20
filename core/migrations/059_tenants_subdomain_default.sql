-- =======================================================================
-- Core migration 059 — Backfill default for tenants.subdomain
-- -----------------------------------------------------------------------
-- The legacy schema declares `tenants.subdomain` as NOT NULL with no
-- default. On any code path that forgets to supply it the INSERT fails
-- with:
--
--   SQLSTATE[HY000]: General error: 1364 Field 'subdomain' doesn't have
--   a default value
--
-- This recurred in the SubTenantWizard "Finish & provision" flow even
-- after /core/sub_tenants.php was patched to include the column,
-- because some deploys still serve cached opcache bytecode or run an
-- older revision of the file. Adding a DB-level default is the only
-- defense-in-depth fix that survives stale code.
--
-- Idempotent: ALTER only fires if the column currently has no default
-- (information_schema.COLUMN_DEFAULT IS NULL AND IS_NULLABLE = 'NO').
-- We pick empty-string as the default — application code is still
-- responsible for setting a meaningful subdomain when one is desired
-- (the wizard auto-derives from slug; bare API creates can leave it
-- empty without crashing).
-- =======================================================================

SET @sql := (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE tenants MODIFY COLUMN subdomain VARCHAR(255) NOT NULL DEFAULT ""',
        'DO 0'
    )
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name   = 'tenants'
      AND column_name  = 'subdomain'
      AND is_nullable  = 'NO'
      AND column_default IS NULL
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
