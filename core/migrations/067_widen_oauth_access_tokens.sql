-- =======================================================================
-- Core migration 067 — Proactively widen borderline encrypted-credential
-- columns across all integrations.
-- -----------------------------------------------------------------------
-- After the JobDiva session_token_enc near-miss (migration 066), we
-- audited every encrypted credential column across every integration
-- against the worst-case plaintext size + AES-256-GCM overhead
-- (`12-byte nonce + 16-byte tag = 28 bytes`).
--
-- Most columns are already comfortable. The borderline cases:
--
--   • qbo_connections.access_token_ct      VARBINARY(2048)
--     → Intuit OAuth access tokens are documented at 1024–1536 chars;
--       a 1500-char token wraps to 1528 bytes which leaves only 520
--       bytes of headroom. Real-world tokens that hit the upper bound
--       would still fit, but the margin is too thin for safety.
--
--   • mail_oauth.oauth_access_token_ct     VARBINARY(2048)
--     → Microsoft Graph + Google OAuth2 access tokens can reach 2 KB
--       raw → 2028 bytes encrypted — well within today's column but
--       only ~20 bytes of headroom. Any provider rotation that bumps
--       token size truncates silently.
--
-- Widening both to 4096 gives 2× headroom over the largest token
-- either provider has issued and keeps the columns inline (still
-- under InnoDB's 8KB row threshold). Idempotent — ALTER on
-- already-widened columns is a metadata-only no-op.
--
-- Cloudways MySQL 5.7+ compatible. ENGINE/CHARSET unchanged.
-- =======================================================================

ALTER TABLE qbo_connections
    MODIFY COLUMN access_token_ct VARBINARY(4096) NOT NULL;

ALTER TABLE mail_oauth
    MODIFY COLUMN oauth_access_token_ct VARBINARY(4096) NULL;
