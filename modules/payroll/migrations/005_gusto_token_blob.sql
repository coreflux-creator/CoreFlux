-- =======================================================================
-- Payroll migration 005 — fix tenant_gusto_connections token column types
-- -----------------------------------------------------------------------
-- Bug: 004_gusto_oauth.sql declared access_token_ct / refresh_token_ct as
-- TEXT with utf8mb4 charset, but encryptField() returns raw AES-GCM bytes
-- (12-byte nonce + 16-byte tag + ciphertext). MySQL rejects bytes outside
-- the utf-8 range with SQLSTATE[22007] "Incorrect string value".
--
-- Fix: convert both columns to VARBINARY(2048) which is the correct type
-- for binary blobs. Idempotent — only ALTERs when current type is TEXT.
-- =======================================================================

SET @cur := (SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tenant_gusto_connections'
               AND COLUMN_NAME = 'access_token_ct');
SET @sql := IF(@cur IS NOT NULL AND LOWER(@cur) <> 'varbinary',
    'ALTER TABLE tenant_gusto_connections
        MODIFY access_token_ct  VARBINARY(2048) NOT NULL,
        MODIFY refresh_token_ct VARBINARY(2048) NOT NULL',
    'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
