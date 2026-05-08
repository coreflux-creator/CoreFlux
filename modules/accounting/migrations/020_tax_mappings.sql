-- Sprint 7f.1 — Tax mappings (CoA → tax-form-line).
--
-- Powers tax-time exports: maps each postable account to a row on a
-- standard tax form so we can produce a Schedule C / 1120 / 1065 etc.
-- mapping-export with a single click.
--
-- Multi-jurisdiction by tax_form_code (e.g. 'US-1040-SCH-C', 'US-1120-S'),
-- so the same CoA can carry mappings for multiple forms simultaneously.
-- One mapping per (tenant, account, tax_form_code) — enforced via UNIQUE.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS accounting_tax_mappings (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id         BIGINT UNSIGNED NOT NULL,
    account_id        BIGINT UNSIGNED NOT NULL,
    tax_form_code     VARCHAR(64)  NOT NULL,        -- e.g. US-1040-SCH-C
    tax_form_line     VARCHAR(32)  NOT NULL,        -- e.g. 22, 8a, 24a
    tax_form_label    VARCHAR(255) DEFAULT NULL,    -- 'Supplies', 'Office expense'
    notes             TEXT         DEFAULT NULL,
    created_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    updated_by_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_tenant_acct_form (tenant_id, account_id, tax_form_code),
    KEY ix_tenant_form (tenant_id, tax_form_code),
    KEY ix_account     (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
