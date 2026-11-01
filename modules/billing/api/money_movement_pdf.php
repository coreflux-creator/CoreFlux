<?php
/**
 * Money Movement digest — PDF download.
 *
 *   GET /api/billing/money_movement_pdf.php?as_of=YYYY-MM-DD[&disposition=attachment]
 *
 * Renders the same HTML the digest email uses, then converts to PDF via
 * core/pdf_renderer.php. Inline disposition by default so the browser
 * opens it in-tab; pass disposition=attachment for a forced download.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/pdf_renderer.php';
require_once __DIR__ . '/../lib/money_movement.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'billing.view');

$asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) api_error('as_of must be YYYY-MM-DD', 422);
$disposition = (($_GET['disposition'] ?? 'inline') === 'attachment') ? 'attachment' : 'inline';

$snapshot = moneyMovementReadSnapshot($tid, $asOf) ?: moneyMovementSnapshot($tid, $asOf);
$prior    = moneyMovementGetPriorSnapshot($tid, $asOf);
$wow      = moneyMovementWowDelta($snapshot, $prior);

$tenantName = 'CoreFlux';
try {
    $tn = getDB()->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
    $tn->execute(['id' => $tid]);
    $r = $tn->fetch(\PDO::FETCH_ASSOC);
    if ($r && !empty($r['name'])) $tenantName = (string) $r['name'];
} catch (\Throwable $_) { /* shrug */ }

$email = moneyMovementRenderEmail($snapshot, $tenantName, '', null, $wow);
$page  = '<!doctype html><html><head><meta charset="utf-8"><title>Money movement — '
       . htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<style>body{margin:0;background:#fff}</style></head><body>'
       . $email['html']
       . '</body></html>';

try {
    cf_stream_html_pdf($page, 'money-movement-' . $asOf . '.pdf', ['orientation' => 'portrait'], $disposition);
} catch (\Throwable $e) {
    error_log('[billing.money_movement_pdf] PDF render failed: ' . $e->getMessage());
    api_error('PDF renderer unavailable', 503);
}
exit;
