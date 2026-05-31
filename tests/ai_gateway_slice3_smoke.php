<?php
/**
 * ai_gateway_slice3_smoke.php
 *
 * AI Tool Gateway — Slice 3: durable workflow runtime + reference
 * transaction-classification graph. Spec §6 (LangGraph MVP).
 *
 *   core/migrations/092_workflow_runtime.sql — workflow_runs,
 *     workflow_checkpoints, workflow_approvals.
 *
 *   core/ai/workflows/engine.php — PHP-native runtime:
 *     workflowRegisterGraph / workflowStart / workflowResume /
 *     workflowGet / workflowList / workflowRequireApproval /
 *     workflowDecideApproval. State-machine semantics with checkpoint
 *     persistence and pause-for-approval interrupts.
 *
 *   core/ai/workflows/graphs/transaction_classification.php — reads
 *     vendor → retrieves prior classifications → classifies (Slice 3
 *     deterministic) → confidence_gate routes to auto_suggest OR
 *     review_required (parks via workflowRequireApproval).
 *
 *   api/ai/workflows.php — start / resume / decide_approval surface
 *     plus list + detail. RBAC: ai.use (mutations) / ai.audit.view
 *     (reads + decide).
 *
 *   dashboard/src/pages/WorkflowTimeline.jsx — admin timeline UI
 *     (graph filter, per-node timeline, paused approvals with
 *     Approve / Reject buttons, output viewer).
 *
 *   AdminModule.jsx — route + sidebar link + ActionCard at
 *     /admin/ai-gateway/workflows.
 *
 * Plus an in-memory functional probe that exercises the engine end-
 * to-end via a tiny mock graph (no DB needed — uses sqlite via the
 * existing CLI smoke harness). The probe asserts:
 *   - high-confidence path completes in one drive
 *   - low-confidence path parks for approval
 *   - decideApproval + resume drives the workflow to completion
 *   - reject path marks the run failed
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 3 smoke (workflow runtime)\n";
echo "==================================================\n\n";

// ── migration 092 ──────────────────────────────────────────────────
echo "core/migrations/092_workflow_runtime.sql\n";
$m = $read("{$ROOT}/core/migrations/092_workflow_runtime.sql");
$a('file exists',                                  $m !== '');
$a('CREATE TABLE workflow_runs',                   str_contains($m, 'CREATE TABLE IF NOT EXISTS workflow_runs'));
$a('  id is CHAR(36) UUID PK',                     str_contains($m, 'id                  CHAR(36) NOT NULL PRIMARY KEY'));
$a('  status enum covers all spec values',         str_contains($m, "ENUM('queued','running','awaiting_approval','completed','failed','cancelled')"));
$a('  ai_run_id forward-link to Slice-1 envelope', str_contains($m, 'ai_run_id           CHAR(36) NULL'));
$a('  graph_name + graph_version persisted',       str_contains($m, 'graph_name          VARCHAR(80) NOT NULL')
                                                && str_contains($m, 'graph_version       VARCHAR(40) NOT NULL'));
$a('  current_node + state_json + output_json',    str_contains($m, 'current_node        VARCHAR(80) NULL')
                                                && str_contains($m, 'state_json          JSON NULL')
                                                && str_contains($m, 'output_json         JSON NULL'));

$a('CREATE TABLE workflow_checkpoints',            str_contains($m, 'CREATE TABLE IF NOT EXISTS workflow_checkpoints'));
$a('  status enum entered/completed/skipped/failed/paused',
    str_contains($m, "ENUM('entered','completed','skipped','failed','paused')"));
$a('  state_hash sha256 column',                   str_contains($m, 'state_hash          CHAR(64) NOT NULL'));
$a('  tenant_id denormalised for sentry',          str_contains($m, 'tenant_id           INT UNSIGNED NOT NULL,                   -- denormalised for tenant-leak sentry'));

$a('CREATE TABLE workflow_approvals',              str_contains($m, 'CREATE TABLE IF NOT EXISTS workflow_approvals'));
$a('  status enum pending/approved/rejected/expired/cancelled',
    str_contains($m, "ENUM('pending','approved','rejected','expired','cancelled')"));
$a('  risk_level 1..5',                            str_contains($m, 'risk_level          TINYINT UNSIGNED NOT NULL DEFAULT 3'));

// ── engine.php ─────────────────────────────────────────────────────
echo "\ncore/ai/workflows/engine.php\n";
$e = $read("{$ROOT}/core/ai/workflows/engine.php");
$a('file exists',                                  $e !== '');
$a('class WorkflowAwaitingApproval',               str_contains($e, 'class WorkflowAwaitingApproval extends \\RuntimeException'));
$a('  constructor signature (type, risk, payload, role)',
    str_contains($e, 'public function __construct(string $type, int $risk, array $payload, ?string $role = null)'));

$a('workflowRegisterGraph declared',               str_contains($e, 'function workflowRegisterGraph(array $definition): void'));
$a('  requires name/version/entry/nodes/edges',    str_contains($e, "foreach (['name','version','entry','nodes','edges'] as \$req)"));
$a('workflowGraphs lists registry',                str_contains($e, 'function workflowGraphs(): array'));

$a('workflowStart declared',                       str_contains($e, 'function workflowStart('));
$a('  INSERT INTO workflow_runs',                  str_contains($e, 'INSERT INTO workflow_runs'));
$a('workflowResume declared',                      str_contains($e, 'function workflowResume('));
$a('  requires status=awaiting_approval to resume',str_contains($e, "if (\$run['status'] !== 'awaiting_approval')"));
$a('  rejected approval → run failed',             str_contains($e, "if (\$appr['status'] === 'rejected') {")
                                                && str_contains($e, 'error_code = "approval_rejected"'));
$a('  exposes _approval payload to next node',     str_contains($e, "\$state['_approval'] = ["));

$a('_workflowDrive defensive max-nodes cap',       str_contains($e, '$maxNodes = 30;'));
$a('  catches WorkflowAwaitingApproval',           str_contains($e, '} catch (WorkflowAwaitingApproval $w) {'));
$a('  catches \\Throwable → failed',                str_contains($e, '} catch (\\Throwable $e) {'));
$a('  writes paused checkpoint on approval',       str_contains($e, "_workflowCheckpoint(\$tenantId, \$runId, \$currentNode, 'paused'"));
$a('  status flips to awaiting_approval',          str_contains($e, 'SET status = "awaiting_approval"'));

$a('_workflowCheckpoint writes state_hash',        str_contains($e, "'h'   => hash('sha256', \$json)"));
$a('_workflowCompleteRun writes output_json',      str_contains($e, "SET status       = \"completed\"")
                                                && str_contains($e, 'output_json  = :oj'));

$a('workflowGet returns run+checkpoints+approvals',str_contains($e, "return ['run' => \$run, 'checkpoints' => \$ckpts, 'approvals' => \$apprs];"));
$a('workflowList limit clamped [1,500]',           str_contains($e, '$limit = max(1, min(500, $limit));'));
$a('workflowList status whitelist',                str_contains($e, "in_array(\$filters['status'], ['queued','running','awaiting_approval','completed','failed','cancelled'], true)"));

$a('workflowRequireApproval throws sentinel',      str_contains($e, 'throw new WorkflowAwaitingApproval($approvalType, $riskLevel, $requestPayload, $assignedRole);'));
$a('workflowDecideApproval rejects non-pending',   str_contains($e, "throw new \\InvalidArgumentException(\"approval already {\$row['status']}\");"));
$a('workflowDecideApproval whitelists decisions',  str_contains($e, "in_array(\$decision, ['approved','rejected','cancelled'], true)"));

// ── transaction_classification graph ───────────────────────────────
echo "\ncore/ai/workflows/graphs/transaction_classification.php\n";
$tc = $read("{$ROOT}/core/ai/workflows/graphs/transaction_classification.php");
$a('file exists',                                  $tc !== '');
$a('requires engine.php',                          str_contains($tc, "require_once __DIR__ . '/../engine.php';"));
$a('registers transaction_classification graph',   str_contains($tc, "'name'    => 'transaction_classification',"));
$a('  version 2026-02-r1',                         str_contains($tc, "'version' => '2026-02-r1',"));
$a('  entry node = vendor_resolution',             str_contains($tc, "'entry'   => 'vendor_resolution',"));
$a('  6 nodes registered',                         str_contains($tc, "'vendor_resolution' =>")
                                                && str_contains($tc, "'retrieval' =>")
                                                && str_contains($tc, "'classify' =>")
                                                && str_contains($tc, "'confidence_gate' =>")
                                                && str_contains($tc, "'auto_suggest' =>")
                                                && str_contains($tc, "'review_required' =>")
                                                && str_contains($tc, "'apply_review_decision' =>"));
$a('  conditional edge after confidence_gate',     str_contains($tc, "'confidence_gate'   => function (array \$state): string {"));
$a('  review_required parks via workflowRequireApproval',
    str_contains($tc, "workflowRequireApproval(\n                'classify_transaction',"));
$a('  high-confidence threshold ≥ 0.85',           str_contains($tc, "\$conf >= 0.85"));
$a('  apply_review_decision merges overrides',     str_contains($tc, "if (!empty(\$override[\$k])) \$cls[\$k] = (string) \$override[\$k];"));

// ── api/ai/workflows.php ───────────────────────────────────────────
echo "\napi/ai/workflows.php\n";
$ap = $read("{$ROOT}/api/ai/workflows.php");
$a('file exists',                                  $ap !== '');
$a('requires engine + classification graph',       str_contains($ap, "require_once __DIR__ . '/../../core/ai/workflows/engine.php';")
                                                && str_contains($ap, "require_once __DIR__ . '/../../core/ai/workflows/graphs/transaction_classification.php';"));
$a('GET / lists registered graphs',                str_contains($ap, "api_ok(['graphs' => workflowGraphs()]);"));
$a('GET ?action=list returns workflowList',        str_contains($ap, 'workflowList($tid, $filters, $limit)'));
$a('GET ?id returns workflowGet',                  str_contains($ap, '$rec = workflowGet($tid, (string) $_GET[\'id\']);'));
$a('POST ?action=start gated by ai.use',           str_contains($ap, "if (\$method === 'POST' && \$action === 'start')")
                                                && str_contains($ap, "rbac_legacy_require(\$user, 'ai.use');"));
$a('POST ?action=start calls workflowStart',       str_contains($ap, '$res = workflowStart($tid, (int) ($user[\'id\'] ?? 0) ?: null, $graph, $input, $nodeCtx);'));
$a('POST ?action=resume calls workflowResume',     str_contains($ap, '$res = workflowResume($tid, $wfId, $nodeCtx);'));
$a('POST ?action=decide_approval calls decide',    str_contains($ap, '$res = workflowDecideApproval($tid, $apprId, $decision,'));
$a('unknown action → 400 fallthrough',             str_contains($ap, "api_error(\"unknown action '{\$action}' or wrong HTTP method\", 400);"));

// ── WorkflowTimeline.jsx ───────────────────────────────────────────
echo "\ndashboard/src/pages/WorkflowTimeline.jsx\n";
$wt = $read("{$ROOT}/dashboard/src/pages/WorkflowTimeline.jsx");
$a('file exists',                                  $wt !== '');
$a('GETs /api/ai/workflows.php?action=list',       str_contains($wt, '/api/ai/workflows.php?${qs.toString()}'));
$a('GETs /api/ai/workflows.php (graphs catalog)',  str_contains($wt, "api.get('/api/ai/workflows.php')"));
$a('GETs /api/ai/workflows.php?id detail',         str_contains($wt, '/api/ai/workflows.php?id=$'));
$a('POSTs decide_approval then resume',            str_contains($wt, '/api/ai/workflows.php?action=decide_approval')
                                                && str_contains($wt, '/api/ai/workflows.php?action=resume'));
$a('root testid=workflow-timeline',                str_contains($wt, 'data-testid="workflow-timeline"'));
$a('graph filter testid',                          str_contains($wt, 'data-testid="workflow-filter-graph"'));
$a('status filter testid',                         str_contains($wt, 'data-testid="workflow-filter-status"'));
$a('list table testid',                            str_contains($wt, 'data-testid="workflow-list-table"'));
$a('per-row testid template',                      str_contains($wt, 'data-testid={`workflow-row-${r.id}`}'));
$a('checkpoints list testid',                      str_contains($wt, 'data-testid="workflow-checkpoints"'));
$a('approvals list testid',                        str_contains($wt, 'data-testid="workflow-approvals"'));
$a('per-checkpoint testid template',               str_contains($wt, 'data-testid={`workflow-checkpoint-${c.id}`}'));
$a('per-approval approve button testid',           str_contains($wt, 'data-testid={`workflow-approval-approve-${ap.id}`}'));
$a('per-approval reject button testid',            str_contains($wt, 'data-testid={`workflow-approval-reject-${ap.id}`}'));
$a('reject confirms before posting',               str_contains($wt, "confirm('Reject this approval?"));

// ── AdminModule wiring ─────────────────────────────────────────────
echo "\ndashboard/src/pages/AdminModule.jsx — Slice-3 wiring\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports WorkflowTimeline',                     str_contains($am, "import WorkflowTimeline from './WorkflowTimeline';"));
$a('mounts /ai-gateway/workflows route',           str_contains($am, '<Route path="/ai-gateway/workflows"')
                                                && str_contains($am, '<WorkflowTimeline session={session} />'));
$a('sidebar exposes workflows page',               str_contains($am, "to: '/admin/ai-gateway/workflows'"));
$a('Overview ActionCard for Workflow runtime',     str_contains($am, 'title="Workflow runtime"'));

// ── Functional probe — engine end-to-end ───────────────────────────
echo "\nFunctional probe — engine end-to-end (in-memory)\n";

// Use the existing CLI sqlite harness for the engine test. We can't
// run the production schema against MySQL here, but we can mock a
// minimal DB by stubbing getDB() with sqlite. The simpler path:
// register a tiny test graph and drive it through workflowStart
// against an actual sqlite-backed DB.
$dbDir = sys_get_temp_dir() . '/ai_gw_slice3_' . getmypid();
@mkdir($dbDir, 0777, true);
$dbPath = $dbDir . '/test.sqlite';
@unlink($dbPath);
$sqlitePdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Register a NOW() function so the engine's MySQL-flavoured SQL
// works against sqlite for the in-memory probe.
$sqlitePdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
$sqlitePdo->exec("
CREATE TABLE workflow_runs (
    id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL, sub_tenant_id INTEGER,
    user_id INTEGER, ai_run_id TEXT, graph_name TEXT NOT NULL,
    graph_version TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued',
    current_node TEXT, input_json TEXT, state_json TEXT, output_json TEXT,
    error_code TEXT, error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME, completed_at DATETIME
);
CREATE TABLE workflow_checkpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_run_id TEXT NOT NULL,
    tenant_id INTEGER NOT NULL, node_name TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'entered',
    state_hash TEXT NOT NULL, state_json TEXT, duration_ms INTEGER,
    error_code TEXT, error_message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE workflow_approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_run_id TEXT NOT NULL,
    tenant_id INTEGER NOT NULL, node_name TEXT NOT NULL,
    approval_type TEXT NOT NULL, risk_level INTEGER NOT NULL DEFAULT 3,
    assigned_to_role TEXT, request_payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', decision_payload TEXT,
    decided_by_user_id INTEGER, decided_at DATETIME, expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Inject our test PDO. The engine calls getDB() (defined by
// core/db.php) which reads from the global $pdo. db.php's load
// would null out a same-named var, so we kept ours in $sqlitePdo
// and assign $GLOBALS['pdo'] AFTER both files load.
require_once "{$ROOT}/core/db.php";
require_once "{$ROOT}/core/ai/workflows/engine.php";
$GLOBALS['pdo'] = $sqlitePdo;

// Register a tiny 4-node test graph: enter → branch → (low|high) → end
workflowRegisterGraph([
    'name'    => 'unit_test_graph',
    'version' => 'smoke-r1',
    'entry'   => 'enter',
    'nodes'   => [
        'enter' => function (array $s, array $c): array {
            $s['enter_seen'] = true; return $s;
        },
        'branch' => function (array $s, array $c): array {
            $s['route'] = ($s['conf'] ?? 0.0) >= 0.85 ? 'high' : 'low';
            return $s;
        },
        'high' => function (array $s, array $c): array {
            $s['_output'] = ['answer' => 'high-confidence', 'conf' => $s['conf']];
            return $s;
        },
        'low' => function (array $s, array $c): array {
            workflowRequireApproval('test_low_confidence', 2,
                ['conf' => $s['conf']], 'tester');
            return $s; // @codeCoverageIgnore
        },
        'after_low' => function (array $s, array $c): array {
            $apr = $s['_approval']['decision_payload'] ?? [];
            $s['_output'] = [
                'answer'   => 'reviewed',
                'override' => $apr['override'] ?? null,
            ];
            return $s;
        },
    ],
    'edges'   => [
        'enter'  => 'branch',
        'branch' => fn (array $s) => $s['route'] === 'high' ? 'high' : 'low',
        'high'   => '__end__',
        'low'    => 'after_low',
        'after_low' => '__end__',
    ],
]);

// High-confidence path completes immediately.
$res = workflowStart(7, 42, 'unit_test_graph', ['conf' => 0.9]);
$a('high-confidence run completes',                $res['status'] === 'completed');
$a('  output has correct answer',                  ($res['output']['answer'] ?? null) === 'high-confidence');
$a('  enter_seen was set by enter node',           ($res['state']['enter_seen'] ?? null) === true);

// Workflow row persisted.
$row = $sqlitePdo->query("SELECT * FROM workflow_runs WHERE id = '{$res['workflow_run_id']}'")->fetch(PDO::FETCH_ASSOC);
$a('workflow_runs row persisted with completed',    $row && $row['status'] === 'completed');

// Checkpoints written.
$ckCount = (int) $sqlitePdo->query("SELECT COUNT(*) c FROM workflow_checkpoints WHERE workflow_run_id = '{$res['workflow_run_id']}'")->fetchColumn();
$a('checkpoints persisted for completed run',       $ckCount >= 3);   // enter, branch, high

// Low-confidence parks for approval.
$res2 = workflowStart(7, 42, 'unit_test_graph', ['conf' => 0.4]);
$a('low-confidence run parks awaiting_approval',    $res2['status'] === 'awaiting_approval');
$a('  pending_approval_id is set',                  is_int($res2['pending_approval_id']) && $res2['pending_approval_id'] > 0);
$a('  current_node = low',                          ($res2['current_node'] ?? null) === 'low');

// Decide approve, then resume.
workflowDecideApproval(7, $res2['pending_approval_id'], 'approved', 99, ['override' => 'gl-12345']);
$res3 = workflowResume(7, $res2['workflow_run_id']);
$a('approved + resumed run completes',              $res3['status'] === 'completed');
$a('  output reflects reviewer override',           ($res3['output']['override'] ?? null) === 'gl-12345');
$a('  output kind = reviewed',                      ($res3['output']['answer']   ?? null) === 'reviewed');

// Reject path.
$res4 = workflowStart(7, 42, 'unit_test_graph', ['conf' => 0.3]);
$a('second low-confidence parks',                   $res4['status'] === 'awaiting_approval');
workflowDecideApproval(7, $res4['pending_approval_id'], 'rejected', 99);
$res5 = workflowResume(7, $res4['workflow_run_id']);
$a('rejected approval terminates run failed',       $res5['status'] === 'failed');
$rowFailed = $sqlitePdo->query("SELECT error_code FROM workflow_runs WHERE id = '{$res4['workflow_run_id']}'")->fetchColumn();
$a('  error_code = approval_rejected',              $rowFailed === 'approval_rejected');

// Unknown graph rejected.
$threw = false;
try { workflowStart(7, 42, 'no-such-graph', []); }
catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), "unknown graph"); }
$a('unknown graph throws InvalidArgumentException',$threw);

// Resume on non-paused workflow rejected.
$threw = false;
try { workflowResume(7, $res['workflow_run_id']); }
catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'not in awaiting_approval'); }
$a('resume on completed run rejected',              $threw);

// Decide on non-pending rejected.
$threw = false;
try { workflowDecideApproval(7, $res2['pending_approval_id'], 'approved', 99); }
catch (\InvalidArgumentException $e) { $threw = str_contains($e->getMessage(), 'approval already'); }
$a('decide on non-pending approval rejected',       $threw);

// Cleanup.
unset($sqlitePdo); @unlink($dbPath); @rmdir($dbDir);

// ── Syntax ─────────────────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'core/ai/workflows/engine.php',
    'core/ai/workflows/graphs/transaction_classification.php',
    'api/ai/workflows.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 3: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
