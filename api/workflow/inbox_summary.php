<?php
/**
 * GET /api/workflow/inbox_summary.php
 *
 *   resp: {
 *     pending_total:    int,
 *     ap_pending:       int,
 *     workflow_pending: int,
 *     cleared_today:    int,    // (yours, last 24h)
 *     eta_minutes:      int,    // heuristic: pending * 1.5 min, capped @ 120
 *     progress_pct:     int,    // cleared_today / (cleared_today + pending_total) * 100
 *   }
 *
 * Drives the "X pending — finish in ~Y min" header badge on /workflow.
 * Schema-tolerant (returns zeros if the workflow tables aren't installed
 * on this tenant yet).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/workflow_engine.php';
require_once __DIR__ . '/../../core/ai_agents.php';

if (api_method() !== 'GET') api_error('Method not allowed', 405);

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$userId   = (int) ($user['id'] ?? 0);
if (!$tenantId || !$userId) api_error('No active session', 400);

$email = strtolower(trim((string) ($user['email'] ?? '')));
$counts = $email !== ''
    ? aiAgentDigestRecipientCounts($tenantId, $email)
    : ['ap_approvals_pending' => 0, 'workflow_pending' => 0, 'pending_total' => 0];

$pending = (int) $counts['pending_total'];

// Cleared in the last 24h by this user.
$cleared = 0;
try {
    $pdo = getDB();
    $st = $pdo->prepare(
        "SELECT COUNT(*) c FROM workflow_step_actions
          WHERE tenant_id = :t
            AND actor_user_id = :u
            AND acted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    );
    $st->execute(['t' => $tenantId, 'u' => $userId]);
    $cleared = (int) ($st->fetch()['c'] ?? 0);
} catch (\Throwable $_) { /* legacy schema */ }

// Heuristic ETA: ~1.5 minutes per pending item; capped at 2 hours.
$eta = $pending > 0 ? min(120, (int) ceil($pending * 1.5)) : 0;

// Progress bar shows momentum: what fraction of today's queue (pending +
// already cleared) is already done.
$denom = $pending + $cleared;
$progressPct = $denom > 0 ? (int) round(($cleared / $denom) * 100) : 0;

api_ok([
    'pending_total'    => $pending,
    'ap_pending'       => (int) $counts['ap_approvals_pending'],
    'workflow_pending' => (int) $counts['workflow_pending'],
    'cleared_today'    => $cleared,
    'eta_minutes'      => $eta,
    'progress_pct'     => $progressPct,
]);
