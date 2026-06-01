<?php
/**
 * ai_gateway_slice6_smoke.php
 *
 * AI Tool Gateway — Slice 6: auto-trigger cron + resolve-exception
 * flow + classifier eval harness.
 *
 *   • core/migrations/094_ai_classify_idempotency.sql — adds
 *     ai_classified_at + ai_workflow_run_id columns to BOTH
 *     accounting_bank_statement_lines + mercury_transactions, plus
 *     "pending" indexes.
 *
 *   • cron/ai_classify_bank_transactions.php — picks up unclassified
 *     rows per tenant, starts a workflow per row, stamps the
 *     idempotency markers. Defensive per-tenant + per-row try/catch.
 *
 *   • api/ai/exceptions.php — GET list (status filter) + POST
 *     resolve / dismiss / assign with audit-log events
 *     (ai_exception_resolved / dismissed / assigned).
 *
 *   • dashboard/src/pages/AiReviewerDashboard.jsx — Resolve / Dismiss
 *     buttons per exception row, with a prompt for the resolution
 *     note. Hits the new API and refreshes.
 *
 *   • Eval harness (embedded): replays a small golden set of
 *     transactions through the workflow against an in-memory sqlite,
 *     verifying the classify node's source-tag + confidence behaviour.
 *     This is the floor for the Slice-7 eval suite.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 6 smoke (cron + resolve + eval)\n";
echo "=======================================================\n\n";

// ── migration 094 ──────────────────────────────────────────────────
echo "core/migrations/094_ai_classify_idempotency.sql\n";
$m = $read("{$ROOT}/core/migrations/094_ai_classify_idempotency.sql");
$a('file exists',                                  $m !== '');
$a('alters accounting_bank_statement_lines',       str_contains($m, 'ALTER TABLE accounting_bank_statement_lines'));
$a('  adds ai_classified_at timestamp',            str_contains($m, 'ADD COLUMN IF NOT EXISTS ai_classified_at   TIMESTAMP NULL'));
$a('  adds ai_workflow_run_id back-link',          str_contains($m, 'ADD COLUMN IF NOT EXISTS ai_workflow_run_id CHAR(36) NULL'));
$a('  pending index for cron lookup',              str_contains($m, 'ADD KEY IF NOT EXISTS ix_abst_ai_pending'));
$a('alters mercury_transactions',                  str_contains($m, 'ALTER TABLE mercury_transactions'));
$a('  pending index for mercury cron lookup',      str_contains($m, 'ADD KEY IF NOT EXISTS ix_mtx_ai_pending'));

// ── cron worker ────────────────────────────────────────────────────
echo "\ncron/ai_classify_bank_transactions.php\n";
$c = $read("{$ROOT}/cron/ai_classify_bank_transactions.php");
$a('file exists',                                  $c !== '');
$a('requires workflow engine + classification graph',
    str_contains($c, "require_once __DIR__ . '/../core/ai/workflows/engine.php';")
 && str_contains($c, "require_once __DIR__ . '/../core/ai/workflows/graphs/transaction_classification.php';"));
$a('tenant discovery UNIONs both sources',         str_contains($c, 'UNION')
                                                && str_contains($c, 'FROM accounting_bank_statement_lines WHERE ai_classified_at IS NULL')
                                                && str_contains($c, 'FROM mercury_transactions             WHERE ai_classified_at IS NULL'));
$a('per-source batch cap configurable',            str_contains($c, '$BATCH_PER_SOURCE = 50;'));
$a('total batch cap to prevent runaways',          str_contains($c, '$TOTAL_LIMIT      = 500;'));
$a('per-tenant try/catch isolates failures',       str_contains($c, "// Plaid lane.")
                                                && str_contains($c, "} catch (\\Throwable \$e) {\n        error_log(\"[ai_classify_cron] tenant {\$tenantId} aborted: \""));
$a('per-row try/catch isolates failures',          str_contains($c, "[ai_classify_cron] plaid row #{\$rowId} failed:")
                                                && str_contains($c, "[ai_classify_cron] mercury row #{\$rowId} failed:"));
$a('plaid: workflowStart + stamp ai_classified_at',str_contains($c, "workflowStart(\$tenantId, /* user */ null, 'transaction_classification',")
                                                && str_contains($c, 'UPDATE accounting_bank_statement_lines'));
$a('mercury: workflowStart + stamp ai_classified_at',
    str_contains($c, 'UPDATE mercury_transactions')
 && str_contains($c, "'source'       => 'mercury'"));
$a('use_llm defaults to false (Slice 6 floor)',    str_contains($c, "'use_llm' => false,"));
$a('exit code reflects failure count',             str_contains($c, "exit(\$totals['failed'] > 0 ? 1 : 0);"));

// ── api/ai/exceptions.php ──────────────────────────────────────────
echo "\napi/ai/exceptions.php\n";
$ex = $read("{$ROOT}/api/ai/exceptions.php");
$a('file exists',                                  $ex !== '');
$a('GET filter + ranking',                         str_contains($ex, "in_array(\$status, ['open','assigned','resolved','dismissed','all'], true)")
                                                && str_contains($ex, "FIELD(severity, 'critical','high','medium','low')"));
$a('GET open to ai.audit.view OR accounting.review',
    str_contains($ex, "rbac_legacy_can(\$user, 'ai.audit.view') || rbac_legacy_can(\$user, 'accounting.review')"));
$a('POST resolve persists resolution_note',        str_contains($ex, 'JSON_SET(COALESCE(detail_json, JSON_OBJECT()), "$.resolution_note", :note)'));
$a('POST resolve gated by canView',                str_contains($ex, "if (\$method === 'POST' && in_array(\$action, ['resolve','dismiss'], true)) {")
                                                && str_contains($ex, 'if (!$canView) api_error(\'Forbidden\', 403);'));
$a('POST resolve writes ai_exception_resolved audit',
    str_contains($ex, "aiGatewayAuditEvent(\$tid, \$uid, \"ai_exception_{\$newStatus}\""));
$a('POST assign requires accounting.approve',      str_contains($ex, "if (!rbac_legacy_can(\$user, 'accounting.approve')) {"));
$a('POST assign writes ai_exception_assigned audit',str_contains($ex, "'ai_exception_assigned'"));
$a('rejects mutation on already-terminal status',  str_contains($ex, "cannot {\$action} exception in status '{\$row['status']}'"));

// ── AiReviewerDashboard — Slice 6 buttons ──────────────────────────
echo "\ndashboard/src/pages/AiReviewerDashboard.jsx — Slice 6 buttons\n";
$j = $read("{$ROOT}/dashboard/src/pages/AiReviewerDashboard.jsx");
$a('resolveException helper declared',             str_contains($j, 'const resolveException = useCallback(async (id, action) =>'));
$a('  prompts for resolution_note',                str_contains($j, "prompt(`\${verb} exception #\${id}. Optional note:`)"));
$a('  POSTs /api/ai/exceptions.php?action=resolve|dismiss',
    str_contains($j, '`/api/ai/exceptions.php?action=${action}`'));
$a('per-exception Resolve button testid',          str_contains($j, 'data-testid={`reviewer-exception-resolve-${ex.id}`}'));
$a('per-exception Dismiss button testid',          str_contains($j, 'data-testid={`reviewer-exception-dismiss-${ex.id}`}'));
$a('exceptions header has Action column',          str_contains($j, '<th style={th}>Reference</th><th style={th}>Created</th><th style={th}>Action</th>'));

// ── Eval harness — replay golden transactions through classify node ─
echo "\nEval harness — replay golden transactions through classify node\n";

$dbDir = sys_get_temp_dir() . '/ai_gw_slice6_' . getmypid();
@mkdir($dbDir, 0777, true);
$dbPath = $dbDir . '/test.sqlite';
@unlink($dbPath);
$sqlitePdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sqlitePdo->sqliteCreateFunction('NOW',   fn () => date('Y-m-d H:i:s'), 0);
$sqlitePdo->sqliteCreateFunction('LOWER', fn ($s) => mb_strtolower((string) $s), 1);

$sqlitePdo->exec("
CREATE TABLE workflow_runs (
    id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL, sub_tenant_id INTEGER,
    user_id INTEGER, ai_run_id TEXT, graph_name TEXT NOT NULL,
    graph_version TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'queued',
    current_node TEXT, input_json TEXT, state_json TEXT, output_json TEXT,
    error_code TEXT, error_message TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME, completed_at DATETIME
);
CREATE TABLE workflow_checkpoints (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_run_id TEXT NOT NULL,
    tenant_id INTEGER NOT NULL, node_name TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'entered',
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
CREATE TABLE vendors (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, name TEXT NOT NULL);
CREATE TABLE ai_prior_classifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    vendor_id INTEGER NOT NULL, account_code TEXT, memo TEXT, confidence REAL
);
");
$sqlitePdo->exec("INSERT INTO vendors (tenant_id, name) VALUES (7, 'ACME Stationery')");
$acmeId = (int) $sqlitePdo->lastInsertId();
$sqlitePdo->exec("INSERT INTO ai_prior_classifications (tenant_id, vendor_id, account_code, memo, confidence)
                  VALUES (7, {$acmeId}, '6300-OFFICE', 'office supplies', 0.92)");

require_once "{$ROOT}/core/db.php";
require_once "{$ROOT}/core/ai/workflows/engine.php";
require_once "{$ROOT}/core/ai/workflows/graphs/transaction_classification.php";
$GLOBALS['pdo'] = $sqlitePdo;

// Golden set: 3 transactions exercise the three deterministic branches.
$golden = [
    ['name' => 'known-vendor-with-prior',
     'txn'  => ['source' => 'plaid', 'id' => 1, 'amount_cents' => 12500, 'description' => 'ACME Stationery'],
     'expect_route'      => 'auto_suggest',
     'expect_source'     => 'deterministic',
     'expect_confidence' => fn ($c) => $c >= 0.85,
     'expect_account'    => '6300-OFFICE'],
    ['name' => 'unknown-vendor',
     'txn'  => ['source' => 'mercury', 'id' => 2, 'amount_cents' => 9900, 'description' => 'Mystery Inc'],
     'expect_route'      => 'review_required',
     'expect_source'     => 'deterministic',
     'expect_confidence' => fn ($c) => $c < 0.85,
     'expect_account'    => '6000-MISC'],
    ['name' => 'empty-description',
     'txn'  => ['source' => 'plaid', 'id' => 3, 'amount_cents' => 5000, 'description' => ''],
     'expect_route'      => 'review_required',
     'expect_source'     => 'deterministic',
     'expect_confidence' => fn ($c) => $c < 0.85,
     'expect_account'    => '6000-MISC'],
];

$evalResults = [];
foreach ($golden as $g) {
    $res = workflowStart(7, /* user */ null, 'transaction_classification',
        ['use_llm' => false, 'transaction' => $g['txn']]);
    $cls = $res['state']['classification'] ?? [];
    $route = $res['state']['route'] ?? null;
    $evalResults[$g['name']] = ['route' => $route, 'classification' => $cls];
    $a("eval '{$g['name']}': route = {$g['expect_route']}",
        $route === $g['expect_route']);
    $a("eval '{$g['name']}': classification.source = {$g['expect_source']}",
        ($cls['source'] ?? null) === $g['expect_source']);
    $a("eval '{$g['name']}': account_code = {$g['expect_account']}",
        ($cls['account_code'] ?? null) === $g['expect_account']);
    $a("eval '{$g['name']}': confidence band",
        is_callable($g['expect_confidence']) && $g['expect_confidence']((float) ($cls['confidence'] ?? -1)));
}

// One more probe — assert all three landed deterministically (no LLM
// reach-out happened in offline CI).
$allDeterministic = array_reduce($evalResults, fn ($acc, $r) => $acc && (($r['classification']['source'] ?? null) === 'deterministic'), true);
$a('eval set: every classification stayed deterministic offline', $allDeterministic);

// Cron-ready dryrun: assert that the cron's SELECT shape is valid.
$sqlitePdo->exec("
CREATE TABLE accounting_bank_statement_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    bank_account_id INTEGER, transaction_date TEXT,
    amount_cents INTEGER, description TEXT, merchant_name TEXT,
    currency TEXT DEFAULT 'USD', posted_at DATETIME,
    ai_classified_at DATETIME, ai_workflow_run_id TEXT
);
CREATE TABLE mercury_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    account_pk INTEGER, mercury_txn_id TEXT, amount_cents INTEGER,
    currency TEXT DEFAULT 'USD', counterparty_name TEXT, bank_description TEXT,
    posted_at DATETIME, received_at DATETIME,
    ai_classified_at DATETIME, ai_workflow_run_id TEXT
);
INSERT INTO accounting_bank_statement_lines (tenant_id, description, amount_cents) VALUES (7, 'pending-plaid-1', 100);
INSERT INTO mercury_transactions (tenant_id, counterparty_name, amount_cents) VALUES (7, 'pending-mercury-1', 200);
INSERT INTO accounting_bank_statement_lines (tenant_id, description, amount_cents, ai_classified_at) VALUES (7, 'already-done', 50, datetime('now'));
");
$tenants = $sqlitePdo->query("
    SELECT tenant_id FROM (
        SELECT DISTINCT tenant_id FROM accounting_bank_statement_lines WHERE ai_classified_at IS NULL
        UNION
        SELECT DISTINCT tenant_id FROM mercury_transactions             WHERE ai_classified_at IS NULL
    ) t
")->fetchAll(PDO::FETCH_COLUMN);
$a('cron tenant discovery returns tenant 7',       in_array(7, array_map('intval', $tenants), true));
$a('cron tenant discovery deduplicates',           count($tenants) === 1);

// Cleanup.
unset($sqlitePdo); @unlink($dbPath); @rmdir($dbDir);

// ── Syntax ─────────────────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'cron/ai_classify_bank_transactions.php',
    'api/ai/exceptions.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 6: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
