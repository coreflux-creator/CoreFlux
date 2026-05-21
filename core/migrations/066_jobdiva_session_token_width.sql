-- =======================================================================
-- Core migration 066 — Widen jobdiva_connections.session_token_enc to
-- fit real JobDiva JWTs.
-- -----------------------------------------------------------------------
-- The original migration 021 sized this column at VARBINARY(1024). That
-- was sufficient while we were stuck on the old wrong path (`/api/jobdiva/
-- authenticate`) because authentication never succeeded — JobDiva returns
-- 401 immediately and we never wrote a token. After fixing the path to
-- `/apiv2/v2/authenticate` (V2 JwtV2Response) JobDiva returns a full JWT,
-- which after AES-256-GCM (12-byte nonce + 16-byte tag + ct) routinely
-- exceeds 1024 bytes, surfacing as:
--
--   SQLSTATE[22001]: String data, right truncated: 1406
--   Data too long for column 'session_token_enc' at row 1
--
-- VARBINARY(4096) keeps the column inline (no off-page TEXT/BLOB write)
-- while leaving roughly 4× headroom over any token JobDiva has ever
-- issued (typical JWT ~ 600–1600 bytes).
--
-- Cloudways MySQL 5.7+ compatible. Idempotent: re-running ALTER TABLE
-- on an already-widened column is a metadata-only no-op.
-- =======================================================================

ALTER TABLE jobdiva_connections
    MODIFY COLUMN session_token_enc VARBINARY(4096) DEFAULT NULL;
