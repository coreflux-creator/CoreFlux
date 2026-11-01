-- Rename only the untouched invoice-issuance default. A tenant-edited rule or
-- template keeps its original name; the seeder recognizes that legacy alias.
UPDATE accounting_posting_rules AS r
JOIN accounting_journal_templates AS t
  ON t.tenant_id = r.tenant_id AND t.id = r.journal_template_id
LEFT JOIN accounting_posting_rules AS newer_rule
  ON newer_rule.tenant_id = r.tenant_id
 AND newer_rule.event_type = 'ar.invoice.issued'
 AND newer_rule.name = 'AR invoice issued — passthrough'
LEFT JOIN accounting_journal_templates AS newer_template
  ON newer_template.tenant_id = t.tenant_id
 AND newer_template.name = 'AR invoice issued — passthrough'
SET r.name = 'AR invoice issued — passthrough',
    t.name = 'AR invoice issued — passthrough'
WHERE r.event_type = 'ar.invoice.issued'
  AND r.name = 'AR invoice sent — passthrough'
  AND r.description = 'Default seed pack — Sprint 7c.1. Customisable.'
  AND r.created_by_user_id IS NULL
  AND r.entity_id IS NULL
  AND r.priority = 100
  AND r.status = 'active'
  AND t.name = 'AR invoice sent — passthrough'
  AND t.memo_template = 'Invoice {payload.invoice_number} / {payload.client_name}'
  AND t.line_source = 'payload'
  AND t.created_by_user_id IS NULL
  AND newer_rule.id IS NULL
  AND newer_template.id IS NULL;
