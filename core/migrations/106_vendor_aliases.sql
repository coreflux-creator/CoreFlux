-- 106_vendor_aliases.sql
--
-- Slice B (Phase 2 finish) — Vendor alias resolution.
--
-- Spec §11: "vendor_alias table + `resolveVendorAlias` tool".
-- The Classification Graph (core/ai/workflows/graphs/transaction_classification.php)
-- asks "is this bank-feed payee the SAME vendor as something we've seen
-- before?" — today it has no persistent place to record the answer, so
-- every new bank feed import re-classifies from scratch.  This table
-- gives the workflow a stable name→canonical-vendor map per tenant.
--
-- An alias points to EITHER a CoreFlux `vendors` row OR a free-form
-- canonical_label when no master vendor row exists (e.g. for one-off
-- payees we don't want to clutter the vendor list with).  The PHP
-- helper enforces that exactly one of the two is set.
--
-- Idempotent. MySQL 5.7+, utf8mb4_unicode_ci.

CREATE TABLE IF NOT EXISTS vendor_aliases (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id            INT UNSIGNED NOT NULL,
    sub_tenant_id        INT UNSIGNED NULL,
    -- The raw payee string from a bank feed, CSV import, etc.
    -- Normalised on write (uppercase + collapsed whitespace) so
    -- "ACME Co.", "ACME  CO" and "acme co." all collide.
    alias_normalized     VARCHAR(180) NOT NULL,
    -- Verbatim payee as we first saw it (for the UI to render).
    alias_raw            VARCHAR(255) NOT NULL,
    -- Resolution target — exactly ONE of {canonical_vendor_id} or
    -- {canonical_label} should be populated. Enforced by the helper.
    canonical_vendor_id  INT UNSIGNED NULL,
    canonical_label      VARCHAR(180) NULL,
    -- Where the alias was first proposed (AI vs. human).
    source               ENUM('ai_suggestion','manual','imported') NOT NULL DEFAULT 'ai_suggestion',
    confidence           DECIMAL(4,3) NULL,   -- AI confidence at proposal time
    -- Provenance.
    created_by_user_id   INT UNSIGNED NULL,
    created_by_ai_run    CHAR(36) NULL,
    -- Operator can pin an alias so future AI re-classification doesn't
    -- silently overwrite it.
    pinned               TINYINT(1) NOT NULL DEFAULT 0,
    hits                 INT UNSIGNED NOT NULL DEFAULT 0,
    last_hit_at          TIMESTAMP NULL DEFAULT NULL,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_va_tenant_alias (tenant_id, alias_normalized),
    KEY ix_va_canonical_vendor   (tenant_id, canonical_vendor_id),
    KEY ix_va_hits               (tenant_id, hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
