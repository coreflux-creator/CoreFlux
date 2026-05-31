<?php
/**
 * /api/ai/audit.php — AI Tool Gateway audit-event drilldown (Slice 1).
 *
 *   GET ?limit=…  — recent spec §15 AI audit events for the active
 *                   tenant. Reads from audit_log filtered to the
 *                   event types the gateway writes
 *                   (ai_run_created, ai_tool_call_requested,
 *                    ai_tool_call_executed, ai_tool_call_blocked).
 *
 *   GET ?ai_run_id=<uuid> — every audit_log row for a single run, in
 *                   order.
 *
 * RBAC: ai.audit.view.
 *
 * Trace UIs use this to render the "what happened, in order" timeline
 * for any single run, plus the global recent-events scroll.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];

if (api_method() !== 'GET') api_error('method not allowed', 405);
rbac_legacy_require($user, 'ai.audit.view');

$AI_EVENTS = ['ai_run_created','ai_tool_call_requested','ai_tool_call_executed','ai_tool_call_blocked'];
$runId = (string) ($_GET['ai_run_id'] ?? '');

if ($runId !== '') {
    // Per-run drilldown: filter audit_log by meta_json.ai_run_id.
    // JSON_EXTRACT works on MySQL 5.7+ (Cloudways baseline) and on
    // the older deployments still on InnoDB JSON.
    $stmt = getDB()->prepare(
        "SELECT id, event, actor_user_id, meta_json, created_at
           FROM audit_log
          WHERE tenant_id = :t
            AND event IN ('ai_run_created','ai_tool_call_requested','ai_tool_call_executed','ai_tool_call_blocked')
            AND JSON_UNQUOTE(JSON_EXTRACT(meta_json, '$.ai_run_id')) = :rid
          ORDER BY id ASC
          LIMIT 500"
    );
    $stmt->execute(['t' => $tid, 'rid' => $runId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} else {
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $stmt = getDB()->prepare(
        "SELECT id, event, actor_user_id, meta_json, created_at
           FROM audit_log
          WHERE tenant_id = :t
            AND event IN ('ai_run_created','ai_tool_call_requested','ai_tool_call_executed','ai_tool_call_blocked')
          ORDER BY id DESC
          LIMIT {$limit}"
    );
    $stmt->execute(['t' => $tid]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

foreach ($rows as &$r) {
    $r['id']            = (int) $r['id'];
    $r['actor_user_id'] = $r['actor_user_id'] !== null ? (int) $r['actor_user_id'] : null;
    if (is_string($r['meta_json']) && $r['meta_json'] !== '') {
        $decoded = json_decode($r['meta_json'], true);
        if ($decoded !== null) $r['meta_json'] = $decoded;
    }
}
unset($r);

api_ok(['events' => $rows, 'count' => count($rows)]);
