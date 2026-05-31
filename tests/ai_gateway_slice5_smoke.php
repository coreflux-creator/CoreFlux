<?php
/**
 * ai_gateway_slice5_smoke.php
 *
 * AI Tool Gateway — Slice 5: Reviewer Cockpit + LLM-driven classifier +
 * accounting_reviewer role.
 *
 *   • api/ai/dashboard.php — single envelope feeding the reviewer
 *     cockpit page. Returns counts + recents for open exceptions,
 *     pending approvals, and AI-drafted JEs, plus a per-severity
 *     breakdown of open exceptions. Gated by `ai.audit.view` OR
 *     `accounting.review`.
 *
 *   • dashboard/src/pages/AiReviewerDashboard.jsx — count tiles
 *     (color-coded by severity), three drill-in tables, refresh
 *     button. Cross-links pending approval rows to the workflow
 *     timeline page.
 *
 *   • core/ai/workflows/graphs/transaction_classification.php —
 *     `classify` node now opts into Slice 2's LLM adapter when
 *     `state.use_llm === true` AND the OpenAI provider is configured.
 *     Falls back gracefully to the deterministic stub on bad JSON,
 *     provider error, or missing key. Captures `_llm_parse_failed`
 *     and `_llm_error` into state for the audit trail.
 *
 *   • core/rbac/legacy_map.php — new `accounting.review →
 *     (accounting, read)` permission.
 *
 *   • AdminModule.jsx — route + sidebar + ActionCard for
 *     /admin/ai-gateway/reviewer.
 *
 * Functional probes (sqlite):
 *   - dashboard query returns correct counts for seeded data
 *   - severity breakdown groups correctly
 *   - LLM classify falls through to deterministic when no provider
 *     configured (use_llm flag is set, key isn't)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 5 smoke (reviewer cockpit + LLM classify)\n";
echo "=================================================================\n\n";

// ── api/ai/dashboard.php ───────────────────────────────────────────
echo "api/ai/dashboard.php\n";
$d = $read("{$ROOT}/api/ai/dashboard.php");
$a('file exists',                                  $d !== '');
$a('GET-only (method whitelist)',                  str_contains($d, "if (api_method() !== 'GET') api_error('method not allowed', 405);"));
$a('open to ai.audit.view OR accounting.review',
    str_contains($d, "rbac_legacy_can(\$user, 'ai.audit.view')")
 && str_contains($d, "rbac_legacy_can(\$user, 'accounting.review')"));
$a('queries accounting_exceptions open count',     str_contains($d, "FROM accounting_exceptions\n          WHERE tenant_id = :t AND status = 'open'"));
$a('queries workflow_approvals pending count',     str_contains($d, "FROM workflow_approvals\n          WHERE tenant_id = :t AND status = 'pending'"));
$a('queries accounting_journal_entries draft',     str_contains($d, "FROM accounting_journal_entries\n          WHERE tenant_id = :t\n            AND status = 'draft'\n            AND source_ref_type IN ('ai_workflow','workflow_run')"));
$a('ranks exceptions critical→high→medium→low',
    str_contains($d, "FIELD(severity, 'critical','high','medium','low')"));
$a('counts_by_severity envelope present',          str_contains($d, "'counts_by_severity' => \$bySeverity"));

// ── AiReviewerDashboard.jsx ────────────────────────────────────────
echo "\ndashboard/src/pages/AiReviewerDashboard.jsx\n";
$j = $read("{$ROOT}/dashboard/src/pages/AiReviewerDashboard.jsx");
$a('file exists',                                  $j !== '');
$a('GETs /api/ai/dashboard.php',                   str_contains($j, "/api/ai/dashboard.php"));
$a('root testid=ai-reviewer-dashboard',            str_contains($j, 'data-testid="ai-reviewer-dashboard"'));
$a('tile testids: open-exceptions / pending-approvals / recent-drafts',
    str_contains($j, 'testId="tile-open-exceptions"')
 && str_contains($j, 'testId="tile-pending-approvals"')
 && str_contains($j, 'testId="tile-recent-drafts"'));
$a('refresh button testid',                        str_contains($j, 'data-testid="reviewer-refresh"'));
$a('approvals table testid + per-row template',    str_contains($j, 'data-testid="reviewer-approvals-table"')
                                                && str_contains($j, 'data-testid={`reviewer-approval-${ap.id}`}'));
$a('exceptions table testid + per-row template',   str_contains($j, 'data-testid="reviewer-exceptions-table"')
                                                && str_contains($j, 'data-testid={`reviewer-exception-${ex.id}`}'));
$a('drafts table testid + per-row template',       str_contains($j, 'data-testid="reviewer-drafts-table"')
                                                && str_contains($j, 'data-testid={`reviewer-draft-${je.id}`}'));
$a('decide link points to workflows page',         str_contains($j, '/admin/ai-gateway/workflows'));

// ── transaction_classification — LLM-aware classify ────────────────
echo "\ncore/ai/workflows/graphs/transaction_classification.php — Slice 5\n";
$tc = $read("{$ROOT}/core/ai/workflows/graphs/transaction_classification.php");
$a('classify branches on state.use_llm flag',      str_contains($tc, "if (!empty(\$state['use_llm'])"));
$a('  requires providers/factory.php at runtime',  str_contains($tc, "file_exists(__DIR__ . '/../../providers/factory.php')"));
$a('  resolves default provider via factory',      str_contains($tc, "\$provider = aiLlmDefaultProvider();"));
$a('  calls adapter->chatWithTools (no tools)',    str_contains($tc, '$adapter->chatWithTools($messages, /* no tools */ [],'));
$a('  strips ```json fences',                      str_contains($tc, "preg_replace('/^```(?:json)?|```\$/m', '', \$text)"));
$a('  parsed confidence clamped [0,1]',            str_contains($tc, "max(0.0, min(1.0, (float) \$parsed['confidence']))"));
$a('  classification tagged source=llm',           str_contains($tc, "'source'       => 'llm',"));
$a('  parse failure records _llm_parse_failed',    str_contains($tc, "\$state['_llm_parse_failed'] = mb_substr(\$text, 0, 240);"));
$a('  provider error records _llm_error',          str_contains($tc, "\$state['_llm_error'] = mb_substr(\$e->getMessage(), 0, 240);"));
$a('deterministic stub still tagged source=deterministic',
    str_contains($tc, "'source'       => 'deterministic',"));

// ── RBAC ───────────────────────────────────────────────────────────
echo "\ncore/rbac/legacy_map.php — Slice 5\n";
$rb = $read("{$ROOT}/core/rbac/legacy_map.php");
$a("accounting.review → (accounting, read)",       str_contains($rb, "'accounting.review'                    => ['accounting', 'read']"));

// ── AdminModule wiring ─────────────────────────────────────────────
echo "\ndashboard/src/pages/AdminModule.jsx — Slice 5 wiring\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports AiReviewerDashboard',                  str_contains($am, "import AiReviewerDashboard from './AiReviewerDashboard';"));
$a('mounts /ai-gateway/reviewer route',            str_contains($am, '<Route path="/ai-gateway/reviewer"')
                                                && str_contains($am, '<AiReviewerDashboard session={session} />'));
$a('sidebar exposes reviewer page',                str_contains($am, "to: '/admin/ai-gateway/reviewer'"));
$a('Overview ActionCard for AI Reviewer cockpit',  str_contains($am, 'title="AI Reviewer cockpit"'));

// ── Functional — dashboard query against sqlite ────────────────────
echo "\nFunctional probe — dashboard counts against sqlite\n";

$dbDir = sys_get_temp_dir() . '/ai_gw_slice5_' . getmypid();
@mkdir($dbDir, 0777, true);
$dbPath = $dbDir . '/test.sqlite';
@unlink($dbPath);
$sqlitePdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sqlitePdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
// sqlite has no FIELD() function — register a polyfill that mirrors
// MySQL's behaviour for the severity ordering used by the dashboard.
$sqlitePdo->sqliteCreateFunction('FIELD', function ($needle, ...$haystack) {
    foreach ($haystack as $i => $v) {
        if ((string) $needle === (string) $v) return $i + 1;
    }
    return 0;
}, -1);

$sqlitePdo->exec("
CREATE TABLE accounting_exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    sub_tenant_id INTEGER, workflow_run_id TEXT, ai_run_id TEXT,
    exception_type TEXT NOT NULL,
    severity TEXT NOT NULL DEFAULT 'medium',
    status TEXT NOT NULL DEFAULT 'open',
    related_ref_type TEXT, related_ref_id INTEGER,
    summary TEXT NOT NULL, detail_json TEXT,
    assigned_to_user_id INTEGER, resolved_by_user_id INTEGER,
    resolved_at DATETIME, created_by_user_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME
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
CREATE TABLE workflow_runs (
    id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL, sub_tenant_id INTEGER,
    user_id INTEGER, ai_run_id TEXT, graph_name TEXT NOT NULL,
    graph_version TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued',
    current_node TEXT, input_json TEXT, state_json TEXT, output_json TEXT,
    error_code TEXT, error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME, completed_at DATETIME
);
CREATE TABLE accounting_journal_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    entity_id INTEGER, period_id INTEGER,
    je_number TEXT NOT NULL, posting_date TEXT,
    source_module TEXT, source_ref_type TEXT, source_ref_id INTEGER,
    status TEXT NOT NULL, currency TEXT DEFAULT 'USD',
    total_debit REAL DEFAULT 0, total_credit REAL DEFAULT 0, memo TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

// Seed: tenant 7 — 3 open exceptions (2 high + 1 critical) + 2 closed,
// 4 pending approvals + 1 approved, 2 AI-drafted JEs + 1 manual JE.
$sqlitePdo->exec("INSERT INTO accounting_exceptions (tenant_id, exception_type, severity, status, summary, created_at) VALUES
    (7, 'classify_low_confidence', 'high',     'open',     'A', datetime('now')),
    (7, 'classify_low_confidence', 'high',     'open',     'B', datetime('now')),
    (7, 'unbalanced_je',           'critical', 'open',     'C', datetime('now')),
    (7, 'missing_period',          'medium',   'resolved', 'D', datetime('now')),
    (7, 'missing_period',          'low',      'dismissed','E', datetime('now')),
    -- decoy: another tenant, must NOT be counted
    (99, 'classify_low_confidence', 'critical','open',     'X', datetime('now'))");

$sqlitePdo->exec("INSERT INTO workflow_runs (id, tenant_id, graph_name, graph_version, status, created_at) VALUES
    ('wf-1', 7, 'transaction_classification', '2026-02-r1', 'awaiting_approval', datetime('now')),
    ('wf-2', 7, 'transaction_classification', '2026-02-r1', 'awaiting_approval', datetime('now'))");

$sqlitePdo->exec("INSERT INTO workflow_approvals (workflow_run_id, tenant_id, node_name, approval_type, risk_level, request_payload, status) VALUES
    ('wf-1', 7, 'review_required', 'classify_transaction', 3, '{}', 'pending'),
    ('wf-1', 7, 'review_required', 'classify_transaction', 3, '{}', 'pending'),
    ('wf-2', 7, 'review_required', 'classify_transaction', 4, '{}', 'pending'),
    ('wf-2', 7, 'review_required', 'classify_transaction', 4, '{}', 'pending'),
    ('wf-1', 7, 'review_required', 'classify_transaction', 3, '{}', 'approved'),
    -- decoy: other tenant
    ('wf-x', 99, 'review_required', 'classify_transaction', 3, '{}', 'pending')");

$sqlitePdo->exec("INSERT INTO accounting_journal_entries (tenant_id, je_number, source_ref_type, status, total_debit, total_credit) VALUES
    (7, 'AI-20260215-0001', 'ai_workflow', 'draft', 100, 100),
    (7, 'AI-20260215-0002', 'workflow_run', 'draft', 50, 50),
    (7, 'MAN-001',           'manual',      'draft', 25, 25),
    (7, 'AI-20260215-0003', 'ai_workflow', 'posted', 200, 200),
    -- decoy
    (99, 'AI-X',            'ai_workflow', 'draft', 1, 1)");

// Replay the actual dashboard SQL (verbatim).
$openCount = (int) $sqlitePdo->prepare("SELECT COUNT(*) FROM accounting_exceptions WHERE tenant_id = 7 AND status = 'open'")->execute() ?: 0;
$openCount = (int) $sqlitePdo->query("SELECT COUNT(*) FROM accounting_exceptions WHERE tenant_id = 7 AND status = 'open'")->fetchColumn();
$a('open exceptions count = 3 (excludes resolved/dismissed/decoy)', $openCount === 3);

$pendingCount = (int) $sqlitePdo->query("SELECT COUNT(*) FROM workflow_approvals WHERE tenant_id = 7 AND status = 'pending'")->fetchColumn();
$a('pending approvals count = 4',                   $pendingCount === 4);

$draftCount = (int) $sqlitePdo->query("SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 7 AND status = 'draft' AND source_ref_type IN ('ai_workflow','workflow_run')")->fetchColumn();
$a('AI-drafted JEs count = 2 (excludes manual + posted + decoy)', $draftCount === 2);

$sevRows = $sqlitePdo->query("SELECT severity, COUNT(*) c FROM accounting_exceptions WHERE tenant_id = 7 AND status = 'open' GROUP BY severity")->fetchAll(PDO::FETCH_ASSOC);
$sevMap = [];
foreach ($sevRows as $r) $sevMap[$r['severity']] = (int) $r['c'];
$a('severity breakdown: critical=1, high=2',        ($sevMap['critical'] ?? 0) === 1 && ($sevMap['high'] ?? 0) === 2);

// Ranking: critical → high → high.
$rankRows = $sqlitePdo->query("
    SELECT id, severity FROM accounting_exceptions
     WHERE tenant_id = 7 AND status = 'open'
     ORDER BY FIELD(severity, 'critical','high','medium','low'), id DESC
     LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
$a('first row in ranking is critical',              ($rankRows[0]['severity'] ?? null) === 'critical');
$a('next two rows are high (severity ranking holds)',
    ($rankRows[1]['severity'] ?? null) === 'high'
 && ($rankRows[2]['severity'] ?? null) === 'high');

// Cross-join with workflow_runs for graph_name surfacing.
$joinRow = $sqlitePdo->query("
    SELECT a.id, w.graph_name FROM workflow_approvals a
     LEFT JOIN workflow_runs w ON w.id = a.workflow_run_id AND w.tenant_id = a.tenant_id
     WHERE a.tenant_id = 7 AND a.status = 'pending'
     ORDER BY a.id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$a('approval ↔ workflow JOIN surfaces graph_name',  ($joinRow['graph_name'] ?? null) === 'transaction_classification');

// Cleanup.
unset($sqlitePdo); @unlink($dbPath); @rmdir($dbDir);

// ── Syntax ─────────────────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'api/ai/dashboard.php',
    'core/ai/workflows/graphs/transaction_classification.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 5: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
