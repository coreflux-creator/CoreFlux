<?php
/**
 * /api/accounting/ai_interpretations — read + review surface for AI
 * Interpretation Records (Phase 1b).
 *
 *   GET  ?event_id=N           → list every interpretation row for that event
 *                                (latest first) so the UI can show full history.
 *   GET  ?event_id=N&latest=1  → single latest row.
 *   GET  ?pending_review=1     → pending exception queue for this tenant.
 *   POST ?action=accept   { interpretation_id, journal_entry_id, note? }
 *   POST ?action=override { interpretation_id, corrected_je_id, note }
 *   POST ?action=reject   { interpretation_id, reason }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/ai_interpretation.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$method = api_method();
$action = (string) api_query('action', '');

if ($method === 'GET') {
    $eventId        = (int) api_query('event_id', 0);
    $pendingReview  = (int) api_query('pending_review', 0);
    $latestOnly     = (int) api_query('latest', 0);

    if ($pendingReview) {
        api_ok(['rows' => aiInterpretationListPendingReview($tenantId, 200)]);
    }
    if (!$eventId) api_error('event_id or pending_review=1 required', 422);

    if ($latestOnly) {
        $row = aiInterpretationLatestForEvent($tenantId, $eventId);
        api_ok(['row' => $row]);
    }

    $pdo  = getDB();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM accounting_ai_interpretations
              WHERE tenant_id = :t AND event_id = :e
              ORDER BY proposed_at DESC, id DESC"
        );
        $stmt->execute(['t' => $tenantId, 'e' => $eventId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $r['proposed_je'] = json_decode((string) $r['proposed_je_json'], true) ?: [];
            $r['evidence']    = json_decode((string) ($r['evidence_json'] ?? '[]'), true) ?: [];
            $rows[] = $r;
        }
        api_ok(['rows' => $rows]);
    } catch (\Throwable $_) {
        api_ok(['rows' => [], 'note' => 'accounting_ai_interpretations table not yet migrated']);
    }
}

if ($method === 'POST' && $action) {
    $body            = api_json_body();
    $interpretationId= (int) ($body['interpretation_id'] ?? 0);
    if (!$interpretationId) api_error('interpretation_id required', 422);

    if ($action === 'accept') {
        $je = (int) ($body['journal_entry_id'] ?? 0);
        if (!$je) api_error('journal_entry_id required', 422);
        $ok = aiInterpretationAccept($tenantId, $interpretationId, $userId, $je, $body['note'] ?? null);
        api_ok(['ok' => $ok]);
    }
    if ($action === 'override') {
        $je   = (int) ($body['corrected_je_id'] ?? 0);
        $note = trim((string) ($body['note'] ?? ''));
        if (!$je)            api_error('corrected_je_id required', 422);
        if ($note === '')    api_error('note required for override', 422);
        $ok = aiInterpretationOverride($tenantId, $interpretationId, $userId, $je, $note);
        api_ok(['ok' => $ok]);
    }
    if ($action === 'reject') {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') api_error('reason required', 422);
        $ok = aiInterpretationReject($tenantId, $interpretationId, $userId, $reason);
        api_ok(['ok' => $ok]);
    }
    api_error("Unknown action: {$action}", 400);
}

api_error('Method not allowed', 405);
