<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/billing/lib/invoice_pdf.php';

$options = getopt('', ['tenant:', 'invoice:']);
$tenantId = (int) ($options['tenant'] ?? 0);
$invoiceId = (int) ($options['invoice'] ?? 0);
if ($tenantId <= 0 || $invoiceId <= 0) {
    fwrite(STDERR, "Usage: php sim/check_invoice_pdf.php --tenant=ID --invoice=ID\n");
    exit(2);
}
$pdo = getDB();
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to inspect a non-simulation tenant.\n");
    exit(3);
}
$invoice = $pdo->prepare('SELECT id FROM billing_invoices WHERE tenant_id = :tenant_id AND id = :id');
$invoice->execute(['tenant_id' => $tenantId, 'id' => $invoiceId]);
if (!$invoice->fetchColumn()) throw new RuntimeException('Invoice not found in simulation tenant');

$path = tempnam(sys_get_temp_dir(), 'coreflux-invoice-check-');
if ($path === false) throw new RuntimeException('Could not reserve a temporary PDF path');
try {
    cf_render_html_to_pdf(invoiceBuildPdfHtmlFinal($invoiceId), $path, ['paper' => 'letter']);
    $handle = fopen($path, 'rb');
    $magic = $handle ? fread($handle, 5) : '';
    if ($handle) fclose($handle);
    $size = filesize($path);
    $checks = [
        'valid_pdf' => $magic === '%PDF-',
        'nonempty_pdf' => $size > 1000,
    ];
} finally {
    @unlink($path);
}
echo json_encode(['tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
    'bytes' => $size, 'checks' => $checks], JSON_PRETTY_PRINT) . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
