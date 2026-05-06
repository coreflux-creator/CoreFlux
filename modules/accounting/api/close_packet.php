<?php
/**
 * Accounting API — close-packet HTML artifact.
 *
 *   GET /api/accounting/close_packet?period_id=N            → returns rendered HTML (printable)
 *   POST /api/accounting/close_packet?period_id=N&action=record  → records a packet build event
 *
 * The HTML can be print-to-PDF in the browser or post-processed by dompdf
 * once that lib is wired in a later sprint. For now the HTML is the artifact.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/close.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();

$periodId = (int) (api_query('period_id') ?? 0);
if (!$periodId) api_error('period_id required', 422);

if ($method === 'GET') {
    RBAC::requirePermission($user, 'accounting.period.view');
    $html = accountingBuildClosePacketHtml($tenantId, $periodId);

    // Allow ?format=html to download as a real HTML file.
    if ((api_query('format') ?? '') === 'html') {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="close-packet-period-' . $periodId . '.html"');
        echo $html;
        exit;
    }

    api_ok(['period_id' => $periodId, 'html' => $html, 'length' => strlen($html)]);
}

if ($method === 'POST' && (api_query('action') ?? '') === 'record') {
    RBAC::requirePermission($user, 'accounting.close_workflow.manage');
    $id = scopedInsert('accounting_close_packets', [
        'period_id'         => $periodId,
        'storage_object_id' => null,
        'file_format'       => 'html',
        'summary_json'      => null,
        'built_by_user_id'  => (int) ($user['id'] ?? 0),
    ]);
    api_ok(['id' => $id]);
}

api_error('Unknown method/action', 405);
