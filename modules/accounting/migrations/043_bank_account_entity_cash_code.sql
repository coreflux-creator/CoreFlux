-- The chart is tenant-wide, but a physical bank's cash ledger is entity-owned.
-- Keep legacy unassigned banks unique under entity key zero until reviewed.
ALTER TABLE accounting_bank_accounts
    ADD COLUMN entity_scope_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (COALESCE(entity_id, 0)) STORED AFTER entity_id,
    DROP INDEX uq_aba_tenant_gl,
    ADD UNIQUE KEY uq_aba_tenant_entity_gl (tenant_id, entity_scope_id, gl_account_code);
