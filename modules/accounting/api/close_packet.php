<?php
/**
 * Accounting API — close-packet HTML artifact.
 *
 *   GET /api/accounting/close_packet?period_id=N            → live preview
 *   GET /api/accounting/close_packet?period_id=N&action=list → saved versions
 *   GET /api/accounting/close_packet?period_id=N&packet_id=N → saved version
 *   POST /api/accounting/close_packet?period_id=N&action=record → saves a rendered version
 *
 * Saved HTML versions can also be downloaded as PDFs.
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

    if ($format === 'pdf') {
        try {
            cf_stream_html_pdf($html, $filename . '.pdf', ['paper' => 'letter']);
        } catch (\Throwable $e) {
            error_log('[accounting.close_packet] PDF render failed: ' . $e->getMessage());
            api_error('PDF renderer unavailable', 503);
        }
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
