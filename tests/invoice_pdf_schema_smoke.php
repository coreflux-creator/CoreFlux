<?php

$source = file_get_contents(__DIR__ . '/../modules/billing/lib/invoice_pdf.php');

function assertInvoicePdf(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

assertInvoicePdf(str_contains($source, 'JOIN billing_invoices i ON i.id = l.invoice_id'),
    'invoice lines are tenant-scoped through their parent invoice');
assertInvoicePdf(str_contains($source, 'l.invoice_id = :id AND i.tenant_id = :t'),
    'invoice ID and tenant are both required');
assertInvoicePdf(!str_contains($source, 'WHERE invoice_id = :id AND tenant_id = :t'),
    'removed reference to nonexistent billing_invoice_lines.tenant_id');

fwrite(STDOUT, "Invoice PDF schema smoke passed.\n");
