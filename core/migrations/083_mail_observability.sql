-- 083_mail_observability.sql
--
-- Slice: Resend webhook receiver + tenant-scoped recipient suppression list.
--
-- mail_webhook_events
--   Raw audit of every webhook event we receive from Resend (and any
--   future provider). Persisted regardless of signature verification so
--   we can debug a bad secret without losing payloads.
--
-- mail_recipient_suppressions
--   Per-tenant suppression list. mailerSend() filters recipients on this
--   list before delivery. Auto-populated by `bounced` / `complained`
--   webhook events; manually maintainable from Mail Settings.

CREATE TABLE IF NOT EXISTS mail_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Where the webhook came from. Today: 'resend'. Future-proofed for
    -- 'sendgrid' / 'postmark' if we ever multi-source.
    provider VARCHAR(20) NOT NULL DEFAULT 'resend',

    -- Event type as reported by the provider, e.g. 'email.sent',
    -- 'email.delivered', 'email.bounced', 'email.complained',
    -- 'email.delivery_delayed', 'email.opened', 'email.clicked'.
    event_type VARCHAR(60) NOT NULL,

    -- The provider message id we hand back to operators (matches
    -- mail_outbox.provider_message_id).
    message_id VARCHAR(255) NULL,

    -- Tenant resolved by joining message_id against mail_outbox, if we
    -- can. Null if we receive a webhook for an unknown id (Resend
    -- replay, test events, etc).
    tenant_id BIGINT UNSIGNED NULL,

    -- mail_outbox.id (FK-ish, no constraint to keep webhook receive
    -- path liberal — we never want a missing outbox row to drop a
    -- webhook).
    mail_outbox_id BIGINT UNSIGNED NULL,

    -- One canonical recipient extracted from the payload for fast
    -- lookups when auto-suppressing on bounce/complaint.
    recipient_email VARCHAR(255) NULL,

    -- Raw event payload as Resend sent it. JSON.
    payload_json MEDIUMTEXT NOT NULL,

    -- Did the Svix-style signature header verify against our shared
    -- secret? If false we still persist for debugging but skip side-
    -- effects (no outbox mutation, no suppression).
    signature_verified TINYINT(1) NOT NULL DEFAULT 0,

    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_mwe_provider_evt (provider, event_type),
    INDEX idx_mwe_msgid        (message_id),
    INDEX idx_mwe_tenant_evt   (tenant_id, event_type),
    INDEX idx_mwe_received     (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_recipient_suppressions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,

    -- Lower-cased + trimmed email. The active uniqueness key with
    -- removed_at means we can re-suppress an address after un-
    -- suppression without primary key conflicts.
    email_normalized VARCHAR(255) NOT NULL,

    -- Why was this address suppressed?
    --   'bounce'    — hard bounce from provider webhook
    --   'complaint' — spam complaint from provider webhook
    --   'manual'    — admin clicked "suppress" in Mail Settings
    --   'api'       — programmatic add via /api/admin/mail_suppressions
    reason VARCHAR(20) NOT NULL DEFAULT 'manual',

    -- Where the suppression came from. 'resend' / 'admin_ui' /
    -- 'admin_api' / 'system'. Free-form for forensic value.
    source VARCHAR(40) NOT NULL DEFAULT 'admin_ui',

    -- Last webhook id that contributed to this suppression. Helps
    -- operators jump from the suppression UI back into the raw event.
    last_webhook_event_id BIGINT UNSIGNED NULL,

    -- Operator note added in the UI.
    notes VARCHAR(500) NULL,

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by_user_id BIGINT UNSIGNED NULL,

    -- Soft-delete column so we can audit how an address came off the
    -- list. NULL = active, NOT NULL = removed.
    removed_at DATETIME NULL,
    removed_by_user_id BIGINT UNSIGNED NULL,
    removed_reason VARCHAR(120) NULL,

    UNIQUE KEY ux_mrs_active (tenant_id, email_normalized, removed_at),
    INDEX idx_mrs_tenant_active (tenant_id, removed_at),
    INDEX idx_mrs_reason        (reason)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
