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
require_once __DIR__ . '/../../../core/pdf_renderer.php';
require_once __DIR__ . '/../lib/close.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();

$periodId = (int) (api_query('period_id') ?? 0);
if (!$periodId) api_error('period_id required', 422);

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.period.view');
    $period = scopedFind('SELECT id FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $periodId]);
    if (!$period) api_error('Period not found', 404);
    $html = accountingBuildClosePacketHtml($tenantId, $periodId);

    // Allow ?format=html to download as a real HTML file.
    if ((api_query('format') ?? '') === 'html') {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="close-packet-period-' . $periodId . '.html"');
        echo $html;
        exit;
    }

    // ?format=pdf renders via cf_render_html_to_pdf so close packets can be
    // dropped straight into board decks / auditor portals.
    if ((api_query('format') ?? '') === 'pdf') {
        $page = '<!doctype html><html><head><meta charset="utf-8"><title>Close packet — period '
              . (int) $periodId . '</title><style>body{margin:0;background:#fff;font-family:system-ui}</style></head>'
              . '<body>' . $html . '</body></html>';
        $tmpDir = sys_get_temp_dir() . '/cf-pdf-close';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
        $outPath = "{$tmpDir}/close-packet-{$tenantId}-{$periodId}-" . bin2hex(random_bytes(4)) . '.pdf';
        try { cf_render_html_to_pdf($page, $outPath, ['orientation' => 'portrait']); }
        catch (\Throwable $e) { api_error('PDF renderer unavailable: ' . $e->getMessage(), 503); }
        header_remove('Content-Type');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . (string) filesize($outPath));
        header('Content-Disposition: inline; filename="close-packet-period-' . $periodId . '.pdf"');
        readfile($outPath);
        @unlink($outPath);
        exit;
    }

    api_ok(['period_id' => $periodId, 'html' => $html, 'length' => strlen($html)]);
}

if ($method === 'POST' && (api_query('action') ?? '') === 'record') {
    rbac_legacy_require($user, 'accounting.close_workflow.manage');
    $period = scopedFind('SELECT id FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id',
        ['id' => $periodId]);
    if (!$period) api_error('Period not found', 404);
    $pdo = getDB();
    $pdo->prepare('INSERT INTO accounting_close_packets
        (tenant_id, period_id, file_format, built_by_user_id)
        VALUES (:tenant_id, :period_id, "html", :user_id)')
        ->execute(['tenant_id' => $tenantId, 'period_id' => $periodId,
            'user_id' => (int) ($user['id'] ?? 0) ?: null]);
    $id = (int) $pdo->lastInsertId();
    api_ok(['id' => $id]);
}

api_error('Unknown method/action', 405);
