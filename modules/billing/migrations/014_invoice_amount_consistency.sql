-- Repair legacy unposted invoice lines whose stored total drifted from the
-- visible quantity, full-precision rate, subtotal, or tax. New writes are
-- normalized by the Billing API before customer delivery and GL posting.

UPDATE billing_invoice_lines AS line
INNER JOIN billing_invoices AS invoice ON invoice.id = line.invoice_id
SET line.subtotal = CASE
        WHEN line.source_type IN ('time', 'time_entry', 'economic_item')
             AND NOT (
                 ABS(line.quantity * line.unit_price) < 0.005
                 AND ABS(line.subtotal) >= 0.005
             )
            THEN ROUND(line.quantity * line.unit_price, 2)
        ELSE line.subtotal
    END,
    line.tax_amount = CASE
        WHEN line.source_type IN ('time', 'time_entry', 'economic_item')
             AND NOT (
                 ABS(line.quantity * line.unit_price) < 0.005
                 AND ABS(line.subtotal) >= 0.005
             )
            THEN ROUND(ROUND(line.quantity * line.unit_price, 2) * line.tax_rate_pct / 100, 2)
        ELSE line.tax_amount
    END
WHERE invoice.journal_entry_id IS NULL
  AND invoice.status IN ('draft', 'approved', 'sent');

UPDATE billing_invoice_lines AS line
INNER JOIN billing_invoices AS invoice ON invoice.id = line.invoice_id
SET line.total = ROUND(line.subtotal + line.tax_amount, 2)
WHERE invoice.journal_entry_id IS NULL
  AND invoice.status IN ('draft', 'approved', 'sent')
  AND ABS(line.total - ROUND(line.subtotal + line.tax_amount, 2)) >= 0.005;

UPDATE billing_invoices AS invoice
INNER JOIN (
    SELECT invoice_id,
           ROUND(SUM(subtotal), 2) AS subtotal,
           ROUND(SUM(tax_amount), 2) AS tax_total,
           ROUND(SUM(total), 2) AS total
      FROM billing_invoice_lines
     GROUP BY invoice_id
) AS amounts ON amounts.invoice_id = invoice.id
SET invoice.subtotal = amounts.subtotal,
    invoice.tax_total = amounts.tax_total,
    invoice.total = amounts.total,
    invoice.amount_due = ROUND(amounts.total - invoice.amount_paid, 2),
    invoice.updated_at = NOW()
WHERE invoice.journal_entry_id IS NULL
  AND invoice.status IN ('draft', 'approved', 'sent')
  AND (
      ABS(invoice.subtotal - amounts.subtotal) >= 0.005
      OR ABS(invoice.tax_total - amounts.tax_total) >= 0.005
      OR ABS(invoice.total - amounts.total) >= 0.005
      OR ABS(invoice.amount_due - ROUND(amounts.total - invoice.amount_paid, 2)) >= 0.005
  );
