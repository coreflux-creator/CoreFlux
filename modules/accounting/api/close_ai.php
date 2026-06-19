<?php
/**
 * Period Close Readiness AI narrative (Sprint 6d).
 *
 *   POST /api/accounting/close_ai.php?action=readiness&period_id=N
 *     → { period_id, summary: "1-2 paragraph 'here is what's blocking close'
 *         narrative" }
 *
 * Pure narrative advisory per /app/AI_INTEGRATION_RULES.md — never emits
 * values or decisions. Reads strictly from system state (open close
 * tasks, pending-review timesheets, posting status of period JEs) so
 * the model has grounded inputs and can't hallucinate blockers that
 * don't exist.
 *
 * Best-effort: any failure (missing key, rate limit, AI disabled)
 * returns 200 + empty summary so the UI degrades silently.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/ai_service.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method   = api_method();
$action   = (string) (api_query('action') ?? '');

if ($method !== 'POST' || $action !== 'readiness') {
    api_error('Unknown method/action', 405);
}
rbac_legacy_require($user, 'accounting.period.view');
rbac_legacy_require($user, 'ai.use');

$periodId = (int) (api_query('period_id') ?? 0);
if (!$periodId) api_error('period_id required', 422);

$pdo = getDB();
if (!$pdo) api_error('No DB', 500);

// 1) Period meta.
$pStmt = $pdo->prepare(
    "SELECT id, period_number, start_date, end_date, status
       FROM accounting_periods
      WHERE tenant_id = :t AND id = :id"
);
$pStmt->execute(['t' => $tenantId, 'id' => $periodId]);
$period = $pStmt->fetch(PDO::FETCH_ASSOC);
if (!$period) api_error('Period not found', 404);

// 2) Close-task stats.
$tStmt = $pdo->prepare(
    "SELECT
        SUM(status = 'pending')     AS pending,
        SUM(status = 'in_progress') AS in_progress,
        SUM(status = 'blocked')     AS blocked,
        SUM(status = 'done')        AS done,
        COUNT(*)                    AS total
       FROM accounting_close_tasks
      WHERE tenant_id = :t AND period_id = :pid"
);
$tStmt->execute(['t' => $tenantId, 'pid' => $periodId]);
$tStats = $tStmt->fetch(PDO::FETCH_ASSOC) ?: [];

// 3) Top blocked / in-progress task titles (max 5) so the model has concrete
//    references for the narrative without leaking notes content.
$openStmt = $pdo->prepare(
    "SELECT title, status
       FROM accounting_close_tasks
      WHERE tenant_id = :t AND period_id = :pid AND status IN ('pending','in_progress','blocked')
      ORDER BY (status = 'blocked') DESC, sort_order ASC
      LIMIT 5"
);
$openStmt->execute(['t' => $tenantId, 'pid' => $periodId]);
$openTasks = $openStmt->fetchAll(PDO::FETCH_ASSOC);

// 4) Unposted journal entries within the period date range.
$jeStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM accounting_journal_entries
      WHERE tenant_id = :t AND status = 'draft'
        AND entry_date BETWEEN :sd AND :ed"
);
$jeStmt->execute(['t' => $tenantId, 'sd' => $period['start_date'], 'ed' => $period['end_date']]);
$unpostedJEs = (int) $jeStmt->fetchColumn();

// 5) Pending-review timesheets within the period (best-effort; some
//    tenants may not have time module enabled).
$tsCount = 0;
try {
    $tsStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM time_entries
          WHERE tenant_id = :t AND status = 'pending_review'
            AND work_date BETWEEN :sd AND :ed"
    );
    $tsStmt->execute(['t' => $tenantId, 'sd' => $period['start_date'], 'ed' => $period['end_date']]);
    $tsCount = (int) $tsStmt->fetchColumn();
} catch (\Throwable $_) { /* time module not present */ }

$context = [
    'period'                   => $period,
    'task_stats'               => $tStats,
    'open_tasks'               => $openTasks,
    'unposted_journal_entries' => $unpostedJEs,
    'pending_review_timesheets'=> $tsCount,
];

$prompt = "You are a finance close coordinator. In 2-4 sentences, summarise "
        . "what is blocking the close of this period and what the team should "
        . "tackle next, in priority order. Refer to volumes qualitatively "
        . "(e.g. 'a handful of journal entries are still in draft') rather than "
        . "restating exact numbers — the user can read those from the dashboard. "
        . "If everything looks done, say so plainly. Do not invent blockers not "
        . "present in the context.";

$summary = '';
try {
    $r = aiAsk([
        'feature_class'     => 'narrative',
        'kind'              => 'narrative',
        'feature_key'       => 'accounting.period_close.readiness',
        'prompt'            => $prompt,
        'context'           => $context,
        'max_output_tokens' => 220,
    ]);
    $summary = trim((string) ($r['content'] ?? ''));
} catch (\Throwable $_) {
    $summary = '';  // graceful degrade
}

api_ok([
    'period_id' => $periodId,
    'summary'   => $summary,
    'signals'   => [
        'open_tasks'                => count($openTasks),
        'blocked_tasks'             => (int) ($tStats['blocked'] ?? 0),
        'unposted_journal_entries'  => $unpostedJEs,
        'pending_review_timesheets' => $tsCount,
    ],
]);
