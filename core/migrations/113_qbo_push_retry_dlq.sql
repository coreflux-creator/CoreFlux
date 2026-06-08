-- Migration 113 — QBO push retry + dead-letter queue
--
-- Charter "QBO push retry + dead-letter queue" follow-up. Tracks
-- per-entity push attempts so the QBO cron syncers (sync_je / sync_bills
-- / sync_invoices) can:
--
--   1. Skip entities that are still in their backoff window
--      (next_retry_at > NOW()), preventing tight-loop hammering of QBO
--      when validation errors fail consistently.
--
--   2. Stop retrying after `max_attempts` reaches the cap and flip
--      `status` to `dead_letter` — at which point the row is excluded
--      from auto-retries and lands in the dead-letter admin UI where
--      an operator can fix the source data and manually requeue.
--
-- Schema mirrors `accounting_outbox_events` (the Jaz/adapter equivalent)
-- but is scoped per QBO entity tuple so it integrates naturally with
-- the procedural QBO push code without forcing a refactor.

CREATE TABLE qbo_push_failures (
    id                  BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    tenant_id           INT UNSIGNED     NOT NULL,
    sub_tenant_id       INT UNSIGNED     NULL,
    entity_type         VARCHAR(32)      NOT NULL,         -- journal_entry | bill | invoice
    source_id           BIGINT UNSIGNED  NOT NULL,         -- CoreFlux primary key
    attempts            INT UNSIGNED     NOT NULL DEFAULT 0,
    max_attempts        INT UNSIGNED     NOT NULL DEFAULT 5,
    status              ENUM('retrying','dead_letter')
                                          NOT NULL DEFAULT 'retrying',
    last_error_code     VARCHAR(64)      NULL,             -- QboApiException::errorCode
    last_error_message  VARCHAR(500)     NULL,             -- truncated message
    vendor_raw          MEDIUMTEXT       NULL,             -- raw vendor body (charter primitive #6)
    last_http_status    SMALLINT         NULL,
    next_retry_at       DATETIME         NULL,
    first_failed_at     DATETIME         NOT NULL,
    last_failed_at      DATETIME         NOT NULL,
    cleared_at          DATETIME         NULL,             -- set when push succeeds
    created_at          DATETIME         NOT NULL,
    updated_at          DATETIME         NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_tenant_entity (tenant_id, sub_tenant_id, entity_type, source_id),
    KEY idx_retry_due (status, next_retry_at),
    KEY idx_dead_letter (status, tenant_id),
    KEY idx_lookup_tenant_entity (tenant_id, entity_type, source_id, status)
);
