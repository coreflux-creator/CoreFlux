<?php
/**
 * GET /api/billing/invoices/pdf?id=N
 *
 * Streams the invoice PDF inline (Content-Disposition: inline so it
 * opens in the browser; the React UI also offers a "Download" button
 * that hits the same URL with ?download=1 to force attachment).
 *
 * Permissions: any tenant user with billing.invoices.view.
 * Tenant guard: invoice must belong to the active tenant.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/invoice_pdf.php';
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$ctx      = api_require_auth();
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

rbac_legacy_require($ctx, 'billing.invoices.view');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) api_error('id required', 422);

// Tenant guard.
$pdo = getDB();
$st = $pdo->prepare('SELECT id, invoice_number, tenant_id FROM billing_invoices WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$row = $st->fetch();
if (!$row || (int) $row['tenant_id'] !== $tenantId) api_error('Invoice not found', 404);

try {
    $path = invoiceRenderPdf($id);
} catch (\Throwable $e) {
    api_error('PDF render failed: ' . $e->getMessage(), 500);
}

if (!is_file($path)) api_error('PDF not produced', 500);

$disposition = !empty($_GET['download']) ? 'attachment' : 'inline';
$filename = 'invoice-' . ($row['invoice_number'] ?? $id) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '', $filename) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;
