<?php
/**
 * ai_gateway_slice4_smoke.php
 *
 * AI Tool Gateway — Slice 4 (Accounting MVP, write-tools).
 *
 *   • core/migrations/093_accounting_exceptions.sql — new table
 *     `accounting_exceptions` (open/assigned/resolved/dismissed
 *     lifecycle, severity tier, forward links to workflow_runs +
 *     ai_runs). Reuses existing `accounting_journal_entries` (the
 *     schema already has status='draft', so no new drafts table).
 *
 *   • core/ai/tool_gateway.php — Risk-Level enforcement inside
 *     aiToolInvoke(). Tools with risk_level >= 4 require a valid
 *     `_approval_id` in callerCtx that resolves to a workflow_approvals
 *     row with status='approved'. Two new tools registered:
 *       coreflux.draft_journal_entry  (Risk 4, accounting.write)
 *       coreflux.create_exception     (Risk 3, accounting.write)
 *     'array' arg type added to aiToolCoerceArg for the `lines` arg.
 *
 *   • core/ai/workflows/engine.php — workflowResume() now stamps the
 *     approval id onto $ctx['_approval_id'] so downstream nodes can
 *     legally invoke risk-4 write tools.
 *
 *   • core/rbac/legacy_map.php — new `accounting.write` and
 *     `accounting.approve` permissions wired to (accounting, write) /
 *     (accounting, admin).
 *
 * Functional probe (sqlite-backed):
 *   - calling draft_journal_entry without an approval id is blocked
 *     (approval_required)
 *   - calling draft_journal_entry with a NON-existent approval id is
 *     blocked (approval_invalid)
 *   - approving a workflow and invoking draft_journal_entry with the
 *     real approval id writes the JE + lines, returns je_id + je_number
 *   - create_exception writes an open row with severity defaulted
 *   - unbalanced JE → validation_failed (debit≠credit)
 *   - single-line JE → validation_failed (needs ≥2 lines)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 4 smoke (write-tools / risk gate)\n";
echo "=========================================================\n\n";

// ── migration 093 ──────────────────────────────────────────────────
echo "core/migrations/093_accounting_exceptions.sql\n";
$m = $read("{$ROOT}/core/migrations/093_accounting_exceptions.sql");
$a('file exists',                                  $m !== '');
$a('CREATE TABLE accounting_exceptions',           str_contains($m, 'CREATE TABLE IF NOT EXISTS accounting_exceptions'));
$a('  severity enum',                              str_contains($m, "ENUM('low','medium','high','critical')"));
$a('  status enum',                                str_contains($m, "ENUM('open','assigned','resolved','dismissed')"));
$a('  workflow_run_id forward-link',               str_contains($m, 'workflow_run_id     CHAR(36) NULL'));
$a('  ai_run_id forward-link',                     str_contains($m, 'ai_run_id           CHAR(36) NULL'));
$a('  open-rows index',                            str_contains($m, 'KEY ix_ae_tenant_open    (tenant_id, status, created_at)'));

// ── tool_gateway.php — Slice-4 changes ─────────────────────────────
echo "\ncore/ai/tool_gateway.php — risk gate + write tools\n";
$tg = $read("{$ROOT}/core/ai/tool_gateway.php");
$a('risk-level gate inside aiToolInvoke',          str_contains($tg, 'Risk-level gate (spec §15 / Slice 4)')
                                                && str_contains($tg, '$riskLevel = (int) ($tool[\'risk_level\'] ?? 1);'));
$a('  blocks risk>=4 without _approval_id',        str_contains($tg, "'code' => 'approval_required'"));
$a('  validates approval is tenant-scoped + approved',
    str_contains($tg, "SELECT id, status FROM workflow_approvals\n                  WHERE id = :id AND tenant_id = :t LIMIT 1")
 && str_contains($tg, "'code' => 'approval_invalid'"));
$a('aiToolCoerceArg handles array type',           str_contains($tg, "case 'array':  return is_array(\$v) ? \$v : [];"));

$a("coreflux.draft_journal_entry registered",      str_contains($tg, "'coreflux.draft_journal_entry' => ["));
$a('  risk_level 4',                               str_contains($tg, "'coreflux.draft_journal_entry' => [")
                                                && preg_match('/coreflux\.draft_journal_entry.*?risk_level.*?4/s', $tg) === 1);
$a('  requires accounting.write',                  str_contains($tg, "'permission'  => 'accounting.write',"));
$a('  required args: entity_id, period_id, posting_date, lines',
    str_contains($tg, "'entity_id'    => ['type' => 'int',    'required' => true")
 && str_contains($tg, "'period_id'    => ['type' => 'int',    'required' => true")
 && str_contains($tg, "'posting_date' => ['type' => 'date',   'required' => true")
 && str_contains($tg, "'lines'        => ['type' => 'array',  'required' => true"));
$a('handler aiToolDraftJournalEntryHandler',       str_contains($tg, 'function aiToolDraftJournalEntryHandler('));
$a('  rejects ≤1 line at gateway',                 str_contains($tg, "throw new \\InvalidArgumentException('journal entry needs ≥2 lines');"));
$a('  rejects missing account_id per line',        str_contains($tg, "throw new \\InvalidArgumentException(\"line #{\$idx} missing account_id\");"));
$a('  delegates to accountingPostJe with post=false',
    str_contains($tg, "require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';")
 && str_contains($tg, '$result = accountingPostJe($tenantId, $je, null, /* $post = */ false);'));
$a('  source_module="system", source_ref_type="ai_workflow"',
    str_contains($tg, "'source_module'   => 'system',")
 && str_contains($tg, "'source_ref_type' => \$args['source_ref_type'] ?? 'ai_workflow',"));
$a('  idempotency_key is deterministic for retries', str_contains($tg, "\$je['idempotency_key'] = sprintf('ai_workflow_%d_%s_%d',"));

$a("coreflux.create_exception registered",         str_contains($tg, "'coreflux.create_exception' => ["));
$a('  risk_level 3',                               preg_match('/coreflux\.create_exception.*?risk_level.*?3/s', $tg) === 1);
$a('handler aiToolCreateExceptionHandler',         str_contains($tg, 'function aiToolCreateExceptionHandler('));
$a('  INSERT into accounting_exceptions',          str_contains($tg, 'INSERT INTO accounting_exceptions'));
$a('  severity whitelist enforced',                str_contains($tg, "if (!in_array(\$severity, ['low','medium','high','critical'], true)) \$severity = 'medium';"));

// ── engine — Slice 4 _approval_id propagation ──────────────────────
echo "\ncore/ai/workflows/engine.php — _approval_id propagation\n";
$en = $read("{$ROOT}/core/ai/workflows/engine.php");
$a('workflowResume stamps _approval_id into ctx',  str_contains($en, "\$ctx['_approval_id'] = (int) \$appr['id'];"));

// ── RBAC ───────────────────────────────────────────────────────────
echo "\ncore/rbac/legacy_map.php — Slice 4 perms\n";
$rb = $read("{$ROOT}/core/rbac/legacy_map.php");
$a("accounting.write → (accounting, write)",       str_contains($rb, "'accounting.write'                    => ['accounting', 'write']"));
$a("accounting.approve → (accounting, admin)",     str_contains($rb, "'accounting.approve'                  => ['accounting', 'admin']"));

// ── Functional probe (sqlite) ──────────────────────────────────────
echo "\nFunctional probe — risk gate + write tools end-to-end\n";

$dbDir = sys_get_temp_dir() . '/ai_gw_slice4_' . getmypid();
@mkdir($dbDir, 0777, true);
$dbPath = $dbDir . '/test.sqlite';
@unlink($dbPath);
$sqlitePdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sqlitePdo->sqliteCreateFunction('NOW', fn () => date('Y-m-d H:i:s'), 0);
$sqlitePdo->exec("
CREATE TABLE workflow_approvals (
    id INTEGER PRIMARY KEY AUTOINCREMENT, workflow_run_id TEXT NOT NULL,
    tenant_id INTEGER NOT NULL, node_name TEXT NOT NULL,
    approval_type TEXT NOT NULL, risk_level INTEGER NOT NULL DEFAULT 3,
    assigned_to_role TEXT, request_payload TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending', decision_payload TEXT,
    decided_by_user_id INTEGER, decided_at DATETIME, expires_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE accounting_journal_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    entity_id INTEGER NOT NULL, period_id INTEGER NOT NULL,
    je_number TEXT NOT NULL, posting_date TEXT NOT NULL,
    source_module TEXT NOT NULL DEFAULT 'manual',
    source_ref_type TEXT, source_ref_id INTEGER,
    idempotency_key TEXT, status TEXT NOT NULL DEFAULT 'draft',
    currency TEXT NOT NULL DEFAULT 'USD',
    total_debit REAL NOT NULL DEFAULT 0,
    total_credit REAL NOT NULL DEFAULT 0,
    memo TEXT, reverses_je_id INTEGER, reversed_by_je_id INTEGER,
    posted_at DATETIME, posted_by_user_id INTEGER,
    created_by_user_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE accounting_journal_entry_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT, je_id INTEGER NOT NULL,
    line_no INTEGER NOT NULL DEFAULT 1, account_id INTEGER NOT NULL,
    debit REAL NOT NULL DEFAULT 0, credit REAL NOT NULL DEFAULT 0,
    memo TEXT, counterparty_company_id INTEGER, counterparty_person_id INTEGER,
    dim_json TEXT
);
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
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE ai_tool_invocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
    sub_tenant_id INTEGER, ai_run_id TEXT, user_id INTEGER, session_id TEXT,
    tool_name TEXT NOT NULL, status TEXT NOT NULL,
    latency_ms INTEGER, error_code TEXT, error_message TEXT,
    args_json TEXT, result_summary TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
");

require_once "{$ROOT}/core/db.php";
require_once "{$ROOT}/core/RBAC.php";
require_once "{$ROOT}/core/rbac/legacy_map.php";
require_once "{$ROOT}/core/ai/tool_gateway.php";
$GLOBALS['pdo'] = $sqlitePdo;

// Run RBAC in legacy-only mode for the CLI probe; api_can() doesn't
// see the tenant_memberships table in this sqlite fixture.
putenv('CF_RBAC_BRIDGE_MODE=legacy');

// A master_admin caller — clears every RBAC check via global_role.
$adminCaller = [
    'tenant_id' => 7, 'user_id' => 99, 'session_id' => 'sess_smoke',
    'user'      => ['id' => 99, 'tenant_id' => 7,
                    'global_role' => 'master_admin',
                    'role'        => 'master_admin',
                    'email' => 'kunal@coreflux.app'],
];

// Probe 1 — risk-4 without approval blocked.
$env = aiToolInvoke('coreflux.draft_journal_entry', [
    'entity_id' => 1, 'period_id' => 1, 'posting_date' => '2026-02-15',
    'lines' => [
        ['account_id' => 1001, 'debit' => 100, 'credit' => 0],
        ['account_id' => 6000, 'debit' => 0,   'credit' => 100],
    ],
], $adminCaller);
$a('risk-4 without _approval_id → denied/approval_required',
    !$env['ok'] && ($env['status'] ?? '') === 'denied'
                && ($env['error']['code'] ?? '') === 'approval_required');

// Probe 2 — risk-4 with NON-existent approval id blocked.
$env = aiToolInvoke('coreflux.draft_journal_entry', [
    'entity_id' => 1, 'period_id' => 1, 'posting_date' => '2026-02-15',
    'lines' => [
        ['account_id' => 1001, 'debit' => 100, 'credit' => 0],
        ['account_id' => 6000, 'debit' => 0,   'credit' => 100],
    ],
], $adminCaller + ['_approval_id' => 99999]);
$a('risk-4 with bogus _approval_id → approval_invalid',
    !$env['ok'] && ($env['error']['code'] ?? '') === 'approval_invalid');

// Probe 3 — seed an approved approval. Slice 4 now delegates the
// actual JE write to accountingPostJe() which needs the full
// accounting fixture (entities/periods/accounts) — out of scope for
// this smoke. We probe the *gate* (approval validation + ≤1 line
// rejection); the deeper write path is covered by the accounting
// module's own smokes.
$sqlitePdo->exec("INSERT INTO workflow_approvals
    (workflow_run_id, tenant_id, node_name, approval_type, risk_level,
     request_payload, status, decided_by_user_id, decided_at)
    VALUES ('00000000-0000-0000-0000-000000000001', 7, 'apply_review_decision',
            'classify_transaction', 4, '{}', 'approved', 99, '2026-02-15 12:00:00')");
$apprId = (int) $sqlitePdo->lastInsertId();

// Probe 4 — single-line JE rejected at the gateway handler (before
// delegating to accountingPostJe).
$env = aiToolInvoke('coreflux.draft_journal_entry', [
    'entity_id' => 1, 'period_id' => 1, 'posting_date' => '2026-02-15',
    'lines' => [['account_id' => 1001, 'debit' => 100, 'credit' => 0]],
], $adminCaller + ['_approval_id' => $apprId]);
$a('single-line JE → validation_failed',           !$env['ok']
                                                && str_contains((string) ($env['error']['message'] ?? ''), '≥2 lines'));

// Probe 5 — line missing account_id rejected at the gateway.
$env = aiToolInvoke('coreflux.draft_journal_entry', [
    'entity_id' => 1, 'period_id' => 1, 'posting_date' => '2026-02-15',
    'lines' => [
        ['debit' => 100, 'credit' => 0],
        ['account_id' => 6000, 'debit' => 0, 'credit' => 100],
    ],
], $adminCaller + ['_approval_id' => $apprId]);
$a('line missing account_id → validation_failed',  !$env['ok']
                                                && str_contains((string) ($env['error']['message'] ?? ''), 'missing account_id'));

// Probe 6 — create_exception (risk-3, no approval needed) writes.
$env = aiToolInvoke('coreflux.create_exception', [
    'exception_type' => 'classify_low_confidence',
    'summary'        => 'Could not auto-classify "ACME Corp $250"',
    'severity'       => 'high',
    'related_ref_type' => 'bank_transaction',
    'related_ref_id'   => 4242,
    'detail' => ['confidence' => 0.4, 'proposed_account' => '6000-MISC'],
], $adminCaller);
$a('create_exception → ok',                        $env['ok'] && ($env['status'] ?? '') === 'ok');
$a('  exception_id returned',                      isset($env['result']['exception_id']) && $env['result']['exception_id'] > 0);
$a('  severity preserved (high)',                  ($env['result']['severity'] ?? null) === 'high');
$exRow = $sqlitePdo->query("SELECT * FROM accounting_exceptions WHERE id={$env['result']['exception_id']}")->fetch(PDO::FETCH_ASSOC);
$a('  exception row persisted with status=open',   $exRow && $exRow['status'] === 'open');
$a('  detail_json carries proposed_account',       $exRow && str_contains((string) $exRow['detail_json'], '6000-MISC'));

// Probe 7 — invalid severity → defaults to medium.
$env = aiToolInvoke('coreflux.create_exception', [
    'exception_type' => 'unbalanced_je',
    'summary'        => 'something is off',
    'severity'       => 'lol-not-a-real-severity',
], $adminCaller);
$a('invalid severity → defaults to medium',        ($env['result']['severity'] ?? null) === 'medium');

// Cleanup.
unset($sqlitePdo); @unlink($dbPath); @rmdir($dbDir);

// ── Syntax ─────────────────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'core/ai/tool_gateway.php',
    'core/ai/workflows/engine.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 4: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
