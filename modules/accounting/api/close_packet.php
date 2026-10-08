<?php
/**
 * Accounting API — close-packet HTML artifact.
 *
 *   GET /api/accounting/close_packet?period_id=N            → live preview
 *   GET /api/accounting/close_packet?period_id=N&action=list → saved versions
 *   GET /api/accounting/close_packet?period_id=N&packet_id=N → saved version
 *   POST /api/accounting/close_packet?period_id=N&action=record → saves a rendered version
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
$period = scopedFind('SELECT id FROM accounting_periods WHERE tenant_id = :tenant_id AND id = :id',
    ['id' => $periodId]);
if (!$period) api_error('Period not found', 404);

if ($method === 'GET') {
    rbac_legacy_require($user, 'accounting.period.view');
    if ((api_query('action') ?? '') === 'list') {
        api_ok(['period_id' => $periodId,
            'rows' => accountingListRecordedClosePackets($tenantId, $periodId)]);
    }
    $packetIdRaw = api_query('packet_id');
    $packetId = $packetIdRaw !== null ? (int) $packetIdRaw : null;
    if ($packetIdRaw !== null && $packetId <= 0) api_error('Invalid packet_id', 422);
    try {
        $saved = $packetId !== null
            ? accountingLoadRecordedClosePacket($tenantId, $periodId, $packetId) : null;
    } catch (\RuntimeException $e) {
        error_log('[accounting.close_packet] ' . $e->getMessage());
        api_error('Saved close packet failed its integrity check', 409);
    }
    if ($packetId !== null && !$saved) api_error('Saved close packet not found', 404);
    $html = $saved ? (string) $saved['html_snapshot']
        : accountingBuildClosePacketHtml($tenantId, $periodId);
    header('Cache-Control: private, no-store');
    $filename = 'close-packet-period-' . $periodId
        . ($saved ? '-version-' . $packetId : '-preview');

    $format = api_query('format') ?? '';
    if ($format === 'html' || $format === 'preview') {
        header_remove('Content-Type');
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: ' . ($format === 'preview' ? 'inline' : 'attachment')
            . '; filename="' . $filename . '.html"');
        echo $html;
        exit;
    }

    // ?format=pdf renders via cf_render_html_to_pdf so close packets can be
    // dropped straight into board decks / auditor portals.
    if ($format === 'pdf') {
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
        header('Content-Disposition: inline; filename="' . $filename . '.pdf"');
        readfile($outPath);
        @unlink($outPath);
        exit;
    }

    api_ok(['period_id' => $periodId, 'packet_id' => $packetId,
        'recorded' => $saved !== null,
        'content_sha256' => $saved['content_sha256'] ?? null,
        'html' => $html, 'length' => strlen($html)]);
}

if ($method === 'POST' && (api_query('action') ?? '') === 'record') {
    rbac_legacy_require($user, 'accounting.close_workflow.manage');
    try {
        $record = accountingRecordClosePacket($tenantId, $periodId,
            (int) ($user['id'] ?? 0) ?: null);
    } catch (\DomainException $e) {
        api_error($e->getMessage(), 409);
    } catch (\Throwable $e) {
        error_log('[accounting.close_packet] record failed: ' . $e->getMessage());
        api_error('Could not save the close packet', 500);
    }
    api_ok($record);
}

api_error('Unknown method/action', 405);
