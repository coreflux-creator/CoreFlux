-- Phase 1c — Event Lineage (Live Books Rails, 2026-02-14).
--
-- Causal chain between business events:
--   contract.signed → ar.invoice.issued → ar.payment.received → ar.cash.applied
--   ap.bill.approved → ap.payment.executed → ap.payment.cleared
--   payroll.run.approved → payroll.cash.disbursed + payroll.tax_liability.paid
--
-- Many-to-many — a single child can have multiple parents (e.g. one payment
-- applied to many invoices) and one parent can spawn many children.
--
-- Idempotent.

DO 0;

CREATE TABLE IF NOT EXISTS event_lineage (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id        BIGINT UNSIGNED NOT NULL,
    parent_event_id  BIGINT UNSIGNED NOT NULL,
    child_event_id   BIGINT UNSIGNED NOT NULL,

    -- Free-form relationship label so the lineage tree is self-describing.
    -- Common values:
    --   'spawned_by'    — default; child arose because parent existed
    --   'reverses'      — child is a reversal of parent
    --   'corrects'      — child is a correction of parent
    --   'applies_to'    — child (payment) applies cash to parent (invoice)
    --   'fulfills'      — child fulfills a commitment created by parent (PO → bill)
    --   'split_of'      — child is one of many siblings sharing one parent (batch)
    relationship_type VARCHAR(40) NOT NULL DEFAULT 'spawned_by',

    created_by_user_id BIGINT UNSIGNED NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_lineage_edge (parent_event_id, child_event_id, relationship_type),
    INDEX idx_lineage_parent (tenant_id, parent_event_id),
    INDEX idx_lineage_child  (tenant_id, child_event_id),
    INDEX idx_lineage_rel    (tenant_id, relationship_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
