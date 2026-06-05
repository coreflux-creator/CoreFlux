<?php
/**
 * Smoke — Slice D: Period-close orchestrator (2026-02).
 *
 * Locks the Phase 4 finish work from the AI-Native Extension spec:
 *   - Migration 107 — accounting_close_runs (lifecycle wrapper).
 *   - core/accounting/close_runs.php — full orchestrator surface
 *     (start / get / list / refreshProgress / buildPacket / lock /
 *      reopen / tasks).
 *   - /api/accounting/close_runs.php — 7 endpoints (list / detail /
 *     start / refresh / build_packet / lock / reopen).
 *   - dashboard CloseDashboard.jsx mounted at /modules/accounting/close.
 *
 * Locking: ensures lifecycle invariants (status enum), tenant scope,
 * idempotent start, and artifact-layer integration through
 * artifact_objects (Slice A handoff).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Migration 107 — accounting_close_runs.
// ──────────────────────────────────────────────────────────────────────
echo "\n── Migration 107 ──\n";
$mig = (string) file_get_contents('/app/core/migrations/107_accounting_close_runs.sql');
$a('migration file exists',                                  $mig !== '');
$a('CREATE TABLE IF NOT EXISTS accounting_close_runs',       $c($mig, 'CREATE TABLE IF NOT EXISTS accounting_close_runs'));
$a('status ENUM covers full lifecycle',
    $c($mig, "ENUM('initiated','in_progress','packet_built','locked','reopened')"));
$a('tracks task progress counters (total + completed)',
    $c($mig, 'total_tasks') && $c($mig, 'completed_tasks'));
$a('links to artifact_objects.id (CHAR(36))',
    $c($mig, 'packet_artifact_id    CHAR(36) NULL'));
$a('links to accounting_close_packets.id',
    $c($mig, 'packet_id             BIGINT UNSIGNED NULL'));
$a('links to workflow_runs.id (CHAR(36)) for LangGraph wiring',
    $c($mig, 'workflow_run_id       CHAR(36) NULL'));
$a('captures reopen reason + actor + timestamp',
    $c($mig, 'reopen_reason         VARCHAR(500) NULL')
    && $c($mig, 'reopened_by_user_id   BIGINT UNSIGNED NULL')
    && $c($mig, 'reopened_at           DATETIME NULL'));
$a('captures lock actor + timestamp',
    $c($mig, 'locked_by_user_id     BIGINT UNSIGNED NULL')
    && $c($mig, 'locked_at             DATETIME NULL'));
$a('has tenant-scoped indexes for dashboard queries',
    $c($mig, 'KEY ix_close_run_tenant_period')
    && $c($mig, 'KEY ix_close_run_tenant_status'));

// ──────────────────────────────────────────────────────────────────────
// 2) core/accounting/close_runs.php — orchestrator API.
// ──────────────────────────────────────────────────────────────────────
echo "\n── core/accounting/close_runs.php ──\n";
$lib = (string) file_get_contents('/app/core/accounting/close_runs.php');
$a('declares strict_types',                                  $c($lib, 'declare(strict_types=1)'));
$a('declares CLOSE_RUN_STATUSES constant for the enum',      $c($lib, "const CLOSE_RUN_STATUSES = ['initiated','in_progress','packet_built','locked','reopened']"));
$a('closeRunStart signature',                                $c($lib, 'function closeRunStart(int $tenantId, int $periodId, ?int $actorUserId = null): array'));
$a('closeRunGet signature',                                  $c($lib, 'function closeRunGet(int $tenantId, int $runId): ?array'));
$a('closeRunGetActiveByPeriod signature',                    $c($lib, 'function closeRunGetActiveByPeriod(int $tenantId, int $periodId): ?array'));
$a('closeRunList signature',                                 $c($lib, 'function closeRunList(int $tenantId, array $filters = []): array'));
$a('closeRunRefreshProgress signature',                      $c($lib, 'function closeRunRefreshProgress(int $tenantId, int $runId): array'));
$a('closeRunBuildPacket signature',                          $c($lib, 'function closeRunBuildPacket(int $tenantId, int $runId, ?int $actorUserId = null): array'));
$a('closeRunLock signature',                                 $c($lib, 'function closeRunLock(int $tenantId, int $runId, ?int $actorUserId = null): array'));
$a('closeRunReopen signature',                               $c($lib, 'function closeRunReopen(int $tenantId, int $runId, string $reason, ?int $actorUserId = null): array'));
$a('closeRunTasks signature',                                $c($lib, 'function closeRunTasks(int $tenantId, int $runId): array'));

$a('closeRunStart is idempotent (returns existing open run)',$c($lib, '$existing = closeRunGetActiveByPeriod($tenantId, $periodId)'));
$a('closeRunStart seeds the checklist',                      $c($lib, 'accountingSeedCloseChecklist($tenantId, $periodId, $actorUserId)'));
$a('closeRunRefreshProgress auto-bumps initiated → in_progress',
    $c($lib, "if (\$newStatus === 'initiated' && (\$done > 0 || \$total > 0))"));
$a('closeRunRefreshProgress stamps completed_at when all tasks done',
    $c($lib, "if (!\$completedAt && \$total > 0 && \$done === \$total"));
$a('closeRunBuildPacket refuses when locked',                $c($lib, "is locked; reopen first"));
$a('closeRunBuildPacket persists legacy accounting_close_packets row',
    $c($lib, 'INSERT INTO accounting_close_packets'));
$a('closeRunBuildPacket creates a first-class artifact_objects row',
    $c($lib, "artifactCreate(\$tenantId, 'accounting_close_packet'"));
$a('closeRunBuildPacket artifact failure does NOT block close',
    $c($lib, "error_log('[close_runs] artifactCreate failed"));
$a('closeRunLock is idempotent on already-locked',           $c($lib, "if (\$run['status'] === 'locked') return \$run;        // idempotent"));
$a('closeRunLock refuses without prior packet_built',        $c($lib, "build the packet first"));
$a('closeRunLock transitions the linked artifact → approved → final',
    $c($lib, "artifactTransition(\$tenantId, (string) \$run['packet_artifact_id'], 'approved'")
    && $c($lib, "artifactTransition(\$tenantId, (string) \$run['packet_artifact_id'], 'final'"));
$a('closeRunReopen requires non-empty reason',               $c($lib, "throw new \\InvalidArgumentException('reopen reason required')"));
$a('closeRunReopen refuses non-locked runs',                 $c($lib, "only locked runs may be reopened"));
$a('closeRunReopen flips old run to status=reopened',        $c($lib, 'status = "reopened"'));
$a('closeRunReopen returns a freshly-started new run',       $c($lib, "return closeRunStart(\$tenantId, (int) \$run['period_id'], \$actorUserId)"));

$lint = [];
exec('php -l /app/core/accounting/close_runs.php 2>&1', $lint, $rc);
$a('close_runs.php passes php -l',                          $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 3) /api/accounting/close_runs.php — REST surface.
// ──────────────────────────────────────────────────────────────────────
echo "\n── /api/accounting/close_runs.php ──\n";
$api = (string) file_get_contents('/app/api/accounting/close_runs.php');
$a('endpoint declares strict_types',                         $c($api, 'declare(strict_types=1)'));
$a('list endpoint reads status + period_id + limit filters',
    $c($api, "'status'    => \$_GET['status']") && $c($api, "'period_id'"));
$a('detail endpoint refreshes progress on view',             $c($api, "\$run   = closeRunRefreshProgress(\$tid, \$id)"));
$a('detail returns run + tasks',                             $c($api, "'run' => \$run") && $c($api, "'tasks' => \$tasks"));
$a('start endpoint validates period_id',                     $c($api, "api_error('period_id required', 422)"));
$a('start writes accounting_close_run_started audit event',  $c($api, "'accounting_close_run_started'"));
$a('build_packet writes accounting_close_packet_built audit event',
    $c($api, "'accounting_close_packet_built'"));
$a('lock writes accounting_close_run_locked audit event',    $c($api, "'accounting_close_run_locked'"));
$a('reopen writes accounting_close_run_reopened audit event',$c($api, "'accounting_close_run_reopened'"));
$a('reopen validates reason is non-empty',                   $c($api, "api_error('reason required', 422)"));
$a('lock + reopen gated on accounting.approve RBAC',         $c($api, "rbac_legacy_can(\$user, 'accounting.approve')"));
$a('start + refresh + build_packet gated on accounting.write',$c($api, "rbac_legacy_can(\$user, 'accounting.write')"));
$a('list + detail gated on accounting.read (or connection.view)',
    $c($api, "rbac_legacy_can(\$user, 'accounting.read')")
    && $c($api, "rbac_legacy_can(\$user, 'accounting.connection.view')"));

$lint2 = [];
exec('php -l /app/api/accounting/close_runs.php 2>&1', $lint2, $rc2);
$a('close_runs API passes php -l',                          $rc2 === 0);

// ──────────────────────────────────────────────────────────────────────
// 4) CloseDashboard.jsx — UI surface + testids.
// ──────────────────────────────────────────────────────────────────────
echo "\n── CloseDashboard.jsx ──\n";
$ui = (string) file_get_contents('/app/dashboard/src/pages/CloseDashboard.jsx');
$a('file exists',                                            $ui !== '');
$a('default export CloseDashboard',                          $c($ui, 'export default function CloseDashboard()'));
$a('reads list endpoint',                                    $c($ui, "/api/accounting/close_runs.php"));
$a('two-column grid layout',                                 $c($ui, "gridTemplateColumns: 'minmax(360px, 1fr) 2fr'"));
$a('renders status filter dropdown',                         $c($ui, 'close-dashboard-filter-status'));
$a('start-run input + button present',
    $c($ui, 'close-dashboard-new-period-input')
    && $c($ui, 'close-dashboard-start-run'));
$a('detail surfaces progress bar',                           $c($ui, '"close-dashboard-detail-progress-bar"'));
$a('detail surfaces action buttons (refresh / build / lock / reopen)',
    $c($ui, 'close-dashboard-detail-refresh')
    && $c($ui, 'close-dashboard-detail-build-packet')
    && $c($ui, 'close-dashboard-detail-lock')
    && $c($ui, 'close-dashboard-detail-reopen'));
$a('reopen button prompts for reason',                       $c($ui, "window.prompt('Reopen reason"));
$a('reopen disabled unless status is locked',                $c($ui, "run.status === 'locked'"));
$a('build_packet disabled unless all tasks done',
    $c($ui, "run.completed_tasks === run.total_tasks"));
$a('detail links to ArtifactsAdmin when packet exists',      $c($ui, '/admin/ai/artifacts?id=${run.packet_artifact_id}'));
$a('detail links to WorkflowTimeline when workflow_run set', $c($ui, '/admin/ai-gateway/workflows?run=${run.workflow_run_id}'));
$a('StatusChip covers all 5 lifecycle states',
    $c($ui, "initiated:")
    && $c($ui, "in_progress:")
    && $c($ui, "packet_built:")
    && $c($ui, "locked:")
    && $c($ui, "reopened:"));

// Static testids.
foreach ([
    'close-dashboard-page',
    'close-dashboard-title',
    'close-dashboard-filter-status',
    'close-dashboard-new-period-input',
    'close-dashboard-start-run',
    'close-dashboard-list-loading',
    'close-dashboard-list-empty',
    'close-dashboard-list',
    'close-dashboard-detail-placeholder',
    'close-dashboard-detail-loading',
    'close-dashboard-detail-empty',
    'close-dashboard-detail',
    'close-dashboard-detail-progress',
    'close-dashboard-detail-tasks',
    'close-dashboard-detail-refresh',
    'close-dashboard-detail-build-packet',
    'close-dashboard-detail-lock',
    'close-dashboard-detail-reopen',
] as $tid) {
    $a("testid '$tid' present", $c($ui, "data-testid=\"$tid\""));
}

// Template testids.
$a("template testid 'close-dashboard-row-\${r.id}' present",  $c($ui, 'close-dashboard-row-${r.id}'));
$a("template testid 'close-dashboard-task-\${t.id}' present", $c($ui, 'close-dashboard-task-${t.id}'));
$a("template testid 'close-dashboard-status-\${status}' present",
    $c($ui, 'close-dashboard-status-${status}'));

// ──────────────────────────────────────────────────────────────────────
// 5) AccountingModule routing — Close dashboard reachable.
// ──────────────────────────────────────────────────────────────────────
echo "\n── AccountingModule.jsx routing ──\n";
$am = (string) file_get_contents('/app/dashboard/src/modules/AccountingModule.jsx');
$a('AccountingModule imports CloseDashboard',                $c($am, "import CloseDashboard from '../pages/CloseDashboard'"));
$a('AccountingModule routes /modules/accounting/close',      $c($am, 'path="close"'));
$a('Close dashboard surfaced as ActionCard tile',            $c($am, 'href="/modules/accounting/close"'));
$a('Uses CheckSquare lucide icon for the tile',              $c($am, 'CheckSquare'));

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "Slice D smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
