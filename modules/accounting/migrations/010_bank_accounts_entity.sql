-- Sprint 6d — Multi-entity scope for treasury bank accounts.
--
-- Backstory: ap_bills.entity_id and billing_invoices.entity_id were added in
-- migration 007 (consolidation). The bank-account table was missed, even though
-- POST /modules/treasury/api/deposit_accounts.php has been writing
-- `entity_id` since day-one (line 107). Adding the column closes that gap and
-- lets the multi-entity header switcher actually filter the deposit-accounts
-- list for tenants running multiple legal entities.
--
-- Idempotent for tenants who already manually added the column.

ALTER TABLE accounting_bank_accounts ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER id;
ALTER TABLE accounting_bank_accounts ADD INDEX idx_bank_acc_entity (tenant_id, entity_id);
