<?php
/**
 * /api/accounting/exceptions — Unified Exception Queue API (Phase 1d).
 *
 *   GET                                      → list (latest 100)
 *   GET ?source=ai.low_confidence            → filter
 *   GET ?subject_type=accounting_event&subject_id=N
 *   GET ?feed=ai_interpretation|queue|event_error
 *   GET ?summary=1                           → counts by severity x feed
 *
 *   POST { source, title, ...args }          → open a new exception
 *   POST ?action=resolve  { queue_id, note? }
 *   POST ?action=snooze   { queue_id, until_iso }
 *   POST ?action=dismiss  { queue_id, note? }
 *   POST ?action=assign   { queue_id, user_id }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/exception_queue.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$userId   = (int) ($user['id'] ?? 0);
$tenantId = (int) (currentTenantId() ?? 0);
if (!$tenantId) api_error('No active tenant', 400);

$method = api_method();
$action = (string) api_query('action', '');

if ($method === 'GET') {
    if ((int) api_query('summary', 0)) api_ok(['summary' => exceptionSummary($tenantId)]);

    $rows = exceptionList($tenantId, [
        'source'       => api_query('source', ''),
        'severity'     => api_query('severity', ''),
        'subject_type' => api_query('subject_type', ''),
        'subject_id'   => api_query('subject_id', 0),
        'feed'         => api_query('feed', ''),
        'limit'        => (int) api_query('limit', 100),
        'offset'       => (int) api_query('offset', 0),
    ]);
    api_ok(['rows' => $rows]);
}

if ($method === 'POST' && $action === '') {
    $body   = api_json_body();
    $source = trim((string) ($body['source'] ?? ''));
    if ($source === '') api_error('source required', 422);
    try {
        $res = exceptionOpen($tenantId, $source, array_merge($body, ['opened_by_user_id' => $userId]));
        api_ok($res, 201);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

if ($method === 'POST' && $action) {
    $body  = api_json_body();
    $qid   = (int) ($body['queue_id'] ?? 0);
    if (!$qid) api_error('queue_id required', 422);
    if ($action === 'resolve') {
        api_ok(['ok' => exceptionResolve($tenantId, $qid, $userId, $body['note'] ?? null)]);
    }
    if ($action === 'snooze') {
        $until = trim((string) ($body['until_iso'] ?? ''));
        if ($until === '') api_error('until_iso required', 422);
        api_ok(['ok' => exceptionSnooze($tenantId, $qid, $userId, $until)]);
    }
    if ($action === 'dismiss') {
        api_ok(['ok' => exceptionDismiss($tenantId, $qid, $userId, $body['note'] ?? null)]);
    }
    if ($action === 'assign') {
        $a = (int) ($body['user_id'] ?? 0);
        if (!$a) api_error('user_id required', 422);
        api_ok(['ok' => exceptionAssign($tenantId, $qid, $a)]);
    }
    api_error("Unknown action: {$action}", 400);
}

api_error('Method not allowed', 405);
