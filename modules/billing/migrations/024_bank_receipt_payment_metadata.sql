-- Bank-line receipts already have a posted journal and allocation. Complete the
-- payment's bank/posted metadata only when its bank line and journal agree.
UPDATE billing_payments p
JOIN accounting_journal_entries je
  ON je.tenant_id = p.tenant_id
 AND je.id = p.journal_entry_id
 AND je.status = 'posted'
 AND je.source_module = 'billing'
 AND je.source_ref_type = 'bank_statement_line'
JOIN accounting_bank_statement_lines bl
  ON bl.tenant_id = p.tenant_id
 AND bl.id = je.source_ref_id
 AND bl.matched_je_id = je.id
 AND bl.match_status = 'matched'
SET p.bank_account_id = bl.bank_account_id,
    p.posted_at = COALESCE(p.posted_at, je.posted_at, je.created_at)
WHERE p.source_system = 'manual'
  AND p.voided_at IS NULL
  AND (p.external_id = CONCAT('bank-line:', bl.id)
       OR p.external_id LIKE CONCAT('bank-line:', bl.id, ':%'))
  AND (p.bank_account_id IS NULL OR p.bank_account_id = bl.bank_account_id)
  AND (p.bank_account_id IS NULL OR p.posted_at IS NULL);
