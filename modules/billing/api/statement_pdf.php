<?php
/**
 * AR Statement — PDF download.
 *
 *   GET /api/billing/statement_pdf.php?client_name=…&as_of=…
 *
 * Same renderer as the statement email, wrapped in a minimal HTML
 * document and converted to PDF via core/pdf_renderer.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/pdf_renderer.php';
require_once __DIR__ . '/../lib/statement.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
RBAC::requirePermission($user, 'billing.view');

$clientName = trim((string) ($_GET['client_name'] ?? ''));
$asOf       = (string) ($_GET['as_of'] ?? date('Y-m-d'));
$disposition= (($_GET['disposition'] ?? 'inline') === 'attachment') ? 'attachment' : 'inline';
if ($clientName === '') api_error('client_name required', 422);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);

$invoices = billingStatementOpenInvoices($tid, $clientName, $asOf);
if (empty($invoices)) api_error("Nothing outstanding for \"{$clientName}\".", 409);
$buckets  = billingStatementBucket($invoices);
$tenant   = scopedFind('SELECT name FROM tenants WHERE id = :tenant_id', []) ?: ['name' => 'CoreFlux'];
$email    = billingStatementRenderEmail((string) $tenant['name'], $clientName, $invoices, $buckets, $asOf, null, $tid);

$page  = '<!doctype html><html><head><meta charset="utf-8"><title>Statement — '
       . htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<style>body{margin:0;background:#fff}</style></head><body>'
       . $email['html'] . '</body></html>';

$tmpDir = sys_get_temp_dir() . '/cf-pdf-stmt';
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
$slug   = preg_replace('/[^a-z0-9]+/', '-', strtolower($clientName)) ?: 'client';
$outPath= "{$tmpDir}/stmt-{$tid}-{$slug}-{$asOf}-" . bin2hex(random_bytes(4)) . '.pdf';
try { cf_render_html_to_pdf($page, $outPath, ['orientation' => 'portrait']); }
catch (\Throwable $e) { api_error('PDF renderer unavailable: ' . $e->getMessage(), 503); }

header('Content-Type: application/pdf');
header('Content-Length: ' . (string) filesize($outPath));
header('Content-Disposition: ' . $disposition . '; filename="statement-' . $slug . '-' . $asOf . '.pdf"');
readfile($outPath);
@unlink($outPath);
exit;
