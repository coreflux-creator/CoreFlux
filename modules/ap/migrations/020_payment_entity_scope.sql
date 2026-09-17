-- Keep payment runs, bank clearing, and journal entries inside one entity.
ALTER TABLE ap_payments
    ADD COLUMN entity_id BIGINT UNSIGNED NULL AFTER tenant_id,
    ADD INDEX idx_app_tenant_entity (tenant_id, entity_id);

-- Best-effort backfill from the payment's allocated bills. Rows spanning
-- multiple entities remain NULL and must be reviewed instead of guessed.
UPDATE ap_payments p
JOIN (
    SELECT a.payment_id,
           CASE WHEN COUNT(DISTINCT b.entity_id) = 1 THEN MAX(b.entity_id) ELSE NULL END AS entity_id
      FROM ap_payment_allocations a
      JOIN ap_bills b ON b.id = a.bill_id
     WHERE b.entity_id IS NOT NULL
     GROUP BY a.payment_id
) inferred ON inferred.payment_id = p.id
SET p.entity_id = inferred.entity_id
WHERE p.entity_id IS NULL;
