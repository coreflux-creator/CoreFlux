-- =======================================================================
-- Core migration 065 — Per-purpose tenant mail sender overrides.
-- -----------------------------------------------------------------------
-- Extends the existing per-tenant `tenants.mail_from_name_override` /
-- `tenants.mail_reply_to` columns with a per-purpose layer so a single
-- tenant can present distinct sender identities for different functions:
--
--   Acme Staffing Timesheets <no-reply@mail.corefluxapp.com>   (Reply-To: ts-replies@acme.com)
--   Acme Staffing AP         <no-reply@mail.corefluxapp.com>   (Reply-To: ap@acme.com)
--   Acme Staffing CFO        <no-reply@mail.corefluxapp.com>   (Reply-To: cfo@acme.com)
--
-- Resolution precedence (cf_tenant_mail_sender):
--   1. tenant_mail_senders row matching (tenant_id, purpose) with enabled=1
--   2. tenants.mail_from_name_override / mail_reply_to (legacy single override)
--   3. RESEND_FROM_NAME / null reply-to (platform default)
--
-- `enabled=0` is a hard mute — mailerSend returns
-- {ok:false, driver:'disabled'} without dispatching to Resend so an
-- entire purpose (e.g. CFO weekly memo) can be paused tenant-by-tenant.
--
-- Idempotent. Cloudways MySQL 5.7+ compatible. utf8mb4_unicode_ci.
-- =======================================================================

CREATE TABLE IF NOT EXISTS tenant_mail_senders (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id           INT UNSIGNED NOT NULL,
    purpose             VARCHAR(40)  NOT NULL,
    from_name           VARCHAR(120) NULL,
    reply_to            VARCHAR(255) NULL,
    enabled             TINYINT(1)   NOT NULL DEFAULT 1,
    updated_by_user_id  INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tms_tenant_purpose (tenant_id, purpose),
    KEY idx_tms_tenant (tenant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
