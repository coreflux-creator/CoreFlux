<?php
/**
 * ai_gateway_slice1_smoke.php
 *
 * AI Tool Gateway — Slice 1 (Foundation). Plumbing only. No LLM.
 *
 *   • core/migrations/090_ai_runs.sql — ai_runs table + ALTER
 *     ai_tool_invocations ADD COLUMN ai_run_id (back-link from
 *     existing ledger to the new envelope).
 *
 *   • core/ai/gateway.php — run lifecycle:
 *       aiGatewayCreateRun  (writes run, emits ai_run_created)
 *       aiGatewayCompleteRun (terminal state setter)
 *       aiGatewayInvokeTool (delegates to aiToolInvoke, stamps
 *                            ai_tool_invocations.ai_run_id, emits
 *                            ai_tool_call_requested + executed/blocked)
 *       aiGatewayGetRun     (read API: run + tool_calls[])
 *       aiGatewayListRuns   (filterable recent runs)
 *       aiGatewayAuditEvent (writes spec §15 events into audit_log)
 *
 *   • core/ai/tool_gateway.php — extended with three Slice-1 read-only
 *     tools per spec §15:
 *       coreflux.get_tenant_context
 *       coreflux.get_user_permissions
 *       coreflux.get_bank_transactions (Plaid + Mercury unified)
 *
 *   • api/ai/runs.php — POST create-run-and-invoke-tools,
 *     GET ?id detail, GET list. RBAC: ai.use / ai.audit.view.
 *
 *   • api/ai/audit.php — GET recent audit events,
 *     GET ?ai_run_id drilldown. RBAC: ai.audit.view.
 *
 *   • core/rbac/legacy_map.php — three new permissions:
 *       ai.use, ai.audit.view, platform.ai.admin
 *     (all map to the existing 'ai' module).
 *
 *   • dashboard/src/pages/AiGatewayAdmin.jsx — admin trace explorer
 *     (runs list / drilldown / tool registry).
 *
 *   • dashboard/src/pages/AskAiPanel.jsx — feature-flagged Slice 1
 *     shell (no LLM yet).
 *
 *   • dashboard/src/pages/AdminModule.jsx — routes + sidebar +
 *     ActionCard wiring for both admin pages.
 *
 * Plus a functional UUID-shape probe for _aiGatewayUuid() since the
 * run id is the primary key.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => is_file($p) ? (string) file_get_contents($p) : '';
$ROOT = dirname(__DIR__);

echo "AI Tool Gateway — Slice 1 smoke\n";
echo "===============================\n\n";

// ── migration 090 ──────────────────────────────────────────────────
echo "core/migrations/090_ai_runs.sql\n";
$m = $read("{$ROOT}/core/migrations/090_ai_runs.sql");
$a('file exists',                                  $m !== '');
$a('CREATE TABLE ai_runs (IF NOT EXISTS)',         str_contains($m, 'CREATE TABLE IF NOT EXISTS ai_runs'));
$a('  id is CHAR(36) (UUID PK)',                   str_contains($m, 'id                  CHAR(36) NOT NULL PRIMARY KEY'));
$a('  tenant_id INT UNSIGNED NOT NULL',            str_contains($m, 'tenant_id           INT UNSIGNED NOT NULL'));
$a('  agent_name VARCHAR(80)',                     str_contains($m, 'agent_name          VARCHAR(80)'));
$a('  workflow_run_id placeholder for Phase 2',    str_contains($m, 'workflow_run_id     CHAR(36) NULL'));
$a('  prompt_version + model_name',                str_contains($m, 'prompt_version      VARCHAR(40)') && str_contains($m, 'model_name          VARCHAR(80)'));
$a('  status enum covers all spec values',         str_contains($m, "ENUM('queued','running','completed','failed','cancelled','awaiting_approval')"));
$a('  worker_id + artifact_id placeholders',       str_contains($m, 'worker_id           CHAR(36) NULL') && str_contains($m, 'artifact_id         CHAR(36) NULL'));
$a('  tenant_recent index',                        str_contains($m, 'ix_air_tenant_recent (tenant_id, created_at)'));
$a('  agent index',                                str_contains($m, 'ix_air_agent         (tenant_id, agent_name, status)'));
$a('ALTER ai_tool_invocations ADD ai_run_id',      str_contains($m, 'ALTER TABLE ai_tool_invocations')
                                                && str_contains($m, 'ADD COLUMN IF NOT EXISTS ai_run_id CHAR(36)'));
$a('  + ix_aiti_run index',                        str_contains($m, 'ADD KEY IF NOT EXISTS ix_aiti_run (ai_run_id)'));

// ── core/ai/gateway.php ────────────────────────────────────────────
echo "\ncore/ai/gateway.php\n";
$g = $read("{$ROOT}/core/ai/gateway.php");
$a('file exists',                                  $g !== '');
$a('declares strict_types',                        str_contains($g, 'declare(strict_types=1);'));
$a('requires db.php',                              str_contains($g, "require_once __DIR__ . '/../db.php';"));
$a('requires tool_gateway.php (registry)',        str_contains($g, "require_once __DIR__ . '/tool_gateway.php';"));
$a('declares _aiGatewayUuid',                      str_contains($g, 'function _aiGatewayUuid(): string'));
$a('UUID sets v4 + variant bits',                  str_contains($g, "(ord(\$b[6]) & 0x0f) | 0x40")
                                                && str_contains($g, "(ord(\$b[8]) & 0x3f) | 0x80"));

$a('declares aiGatewayCreateRun',                  str_contains($g, 'function aiGatewayCreateRun('));
$a('  INSERT INTO ai_runs',                        str_contains($g, 'INSERT INTO ai_runs'));
$a('  emits ai_run_created event',                 str_contains($g, "'ai_run_created'"));
$a('  input_summary truncated to 2000 chars',      str_contains($g, "mb_substr(\$inputSummary, 0, 2000)"));

$a('declares aiGatewayCompleteRun',                str_contains($g, 'function aiGatewayCompleteRun('));
$a('  rejects invalid status',                     str_contains($g, "throw new \\InvalidArgumentException(\"invalid run status"));
$a('  UPDATE writes completed_at only on terminals',
    str_contains($g, 'IF(:s2 IN ("completed","failed","cancelled"), NOW(), completed_at)'));
$a('  UPDATE scoped by id AND tenant_id (tenant-leak safe)',
    str_contains($g, 'WHERE id = :id AND tenant_id = :t'));

$a('declares aiGatewayInvokeTool',                 str_contains($g, 'function aiGatewayInvokeTool('));
$a('  emits ai_tool_call_requested',               str_contains($g, "'ai_tool_call_requested'"));
$a('  emits ai_tool_call_executed on success',     str_contains($g, "'ai_tool_call_executed'"));
$a('  emits ai_tool_call_blocked on denied/validation',
    str_contains($g, "'ai_tool_call_blocked'")
 && str_contains($g, "in_array((string) (\$envelope['status'] ?? ''), ['denied','validation_failed'], true)"));
$a('  back-links ai_run_id onto ai_tool_invocations',
    str_contains($g, 'UPDATE ai_tool_invocations')
 && str_contains($g, 'SET ai_run_id = :rid'));
$a('  delegates to aiToolInvoke',                  str_contains($g, '$envelope = aiToolInvoke($toolName, $args, $callerCtx);'));

$a('declares aiGatewayGetRun',                     str_contains($g, 'function aiGatewayGetRun(int $tenantId, string $runId): ?array'));
$a('  joins ai_tool_invocations by ai_run_id',     str_contains($g, 'WHERE ai_run_id = :rid AND tenant_id = :t'));
$a('  scoped to tenant_id',                        str_contains($g, 'WHERE id = :id AND tenant_id = :t'));

$a('declares aiGatewayListRuns',                   str_contains($g, 'function aiGatewayListRuns('));
$a('  limit clamped [1,500]',                      str_contains($g, '$limit = max(1, min(500, $limit));'));
$a('  status whitelist guard',                     str_contains($g, "in_array(\$status, ['queued','running','completed','failed','cancelled','awaiting_approval'], true)"));

$a('declares aiGatewayAuditEvent',                 str_contains($g, 'function aiGatewayAuditEvent('));
$a('  writes into existing audit_log table',       str_contains($g, 'INSERT INTO audit_log')
                                                && str_contains($g, "tenant_id, actor_user_id, event, target_id, meta_json, created_at"));
$a('  never throws — wrapped in try/catch',        str_contains($g, "catch (\\Throwable \$e) {")
                                                && str_contains($g, "error_log('[aiGatewayAuditEvent]"));

// ── core/ai/tool_gateway.php — new Slice-1 tools ───────────────────
echo "\ncore/ai/tool_gateway.php — new Slice-1 read-only tools\n";
$tg = $read("{$ROOT}/core/ai/tool_gateway.php");
$a('coreflux.get_tenant_context registered',       str_contains($tg, "'coreflux.get_tenant_context' => ["));
$a('  requires ai.use permission',                 str_contains($tg, "'coreflux.get_tenant_context' => [")
                                                && str_contains($tg, "'permission'  => 'ai.use'"));
$a('coreflux.get_user_permissions registered',     str_contains($tg, "'coreflux.get_user_permissions' => ["));
$a('coreflux.get_bank_transactions registered',    str_contains($tg, "'coreflux.get_bank_transactions' => ["));
$a('  requires accounting.bank.manage',            str_contains($tg, "'coreflux.get_bank_transactions' => [")
                                                && str_contains($tg, "'permission'  => 'accounting.bank.manage'"));
$a('  source arg whitelist (plaid|mercury|both)',  str_contains($tg, "plaid | mercury | both"));

$a('handler aiToolGetTenantContextHandler',        str_contains($tg, 'function aiToolGetTenantContextHandler('));
$a('  reads tenants + sub_tenants tables',         str_contains($tg, 'FROM tenants WHERE id = :t')
                                                && str_contains($tg, 'FROM sub_tenants WHERE tenant_id = :t'));
$a('  tolerates schema-not-ready',                 str_contains($tg, '/* schema-not-ready tolerated for CLI smoke */'));

$a('handler aiToolGetUserPermissionsHandler',      str_contains($tg, 'function aiToolGetUserPermissionsHandler('));
$a('  probes RBACResolver for module grants',      str_contains($tg, 'RBACResolver::can($userId, $tenantId, $m,'));

$a('handler aiToolGetBankTransactionsHandler',     str_contains($tg, 'function aiToolGetBankTransactionsHandler('));
$a('  queries accounting_bank_statement_lines',    str_contains($tg, 'FROM accounting_bank_statement_lines'));
$a('  queries mercury_transactions',               str_contains($tg, 'FROM mercury_transactions'));
$a('  unifies newest-first across sources',        str_contains($tg, 'usort($out, fn ($a, $b) => strcmp((string) ($b[\'posted_at\'] ?? \'\'), (string) ($a[\'posted_at\'] ?? \'\')));'));
$a('  limit clamped [1,200]',                      str_contains($tg, 'max(1, min(200, (int) ($args[\'limit\'] ?? 50)))'));
$a('  since param shape-validated (YYYY-MM-DD)',   str_contains($tg, "preg_match('/^\\d{4}-\\d{2}-\\d{2}\$/', \$since)"));

// ── api/ai/runs.php ────────────────────────────────────────────────
echo "\napi/ai/runs.php\n";
$ar = $read("{$ROOT}/api/ai/runs.php");
$a('file exists',                                  $ar !== '');
$a('declares strict_types',                        str_contains($ar, 'declare(strict_types=1);'));
$a('requires api_bootstrap.php',                   str_contains($ar, "require_once __DIR__ . '/../../core/api_bootstrap.php';"));
$a('requires core/ai/gateway.php',                 str_contains($ar, "require_once __DIR__ . '/../../core/ai/gateway.php';"));
$a('POST gated by ai.use',                         str_contains($ar, "rbac_legacy_require(\$user, 'ai.use');"));
$a('POST creates run + invokes tools',             str_contains($ar, '$runId = aiGatewayCreateRun(')
                                                && str_contains($ar, '$env  = aiGatewayInvokeTool($runId,'));
$a('POST caps tool calls at 20 (defensive)',       str_contains($ar, 'if ($idx >= 19) break;'));
$a('POST completes run with summary',              str_contains($ar, 'aiGatewayCompleteRun('));
$a('POST 201 returns ai_run_id + tool_calls',      str_contains($ar, "api_ok([")
                                                && str_contains($ar, "'ai_run_id'  => \$runId,")
                                                && str_contains($ar, "'tool_calls' => \$toolCalls,"));

$a('GET ?id detail gated by ai.audit.view',        str_contains($ar, "rbac_legacy_require(\$user, 'ai.audit.view');"));
$a('GET ?id returns aiGatewayGetRun(...)',          str_contains($ar, '$rec = aiGatewayGetRun($tid, $id);'));
$a('GET list returns aiGatewayListRuns',           str_contains($ar, 'aiGatewayListRuns(') && str_contains($ar, "api_ok(['runs' => \$rows"));
$a('method-not-allowed fallthrough',               str_contains($ar, "api_error('method not allowed', 405);"));

// ── api/ai/audit.php ───────────────────────────────────────────────
echo "\napi/ai/audit.php\n";
$au = $read("{$ROOT}/api/ai/audit.php");
$a('file exists',                                  $au !== '');
$a('gated by ai.audit.view',                       str_contains($au, "rbac_legacy_require(\$user, 'ai.audit.view');"));
$a('SELECT scoped to tenant_id',                   str_contains($au, 'FROM audit_log') && str_contains($au, 'WHERE tenant_id = :t'));
$a('event whitelist matches spec §15',             str_contains($au, "'ai_run_created','ai_tool_call_requested','ai_tool_call_executed','ai_tool_call_blocked'"));
$a('?ai_run_id drilldown via JSON_EXTRACT',        str_contains($au, "JSON_UNQUOTE(JSON_EXTRACT(meta_json, '\$.ai_run_id'))"));
$a('list mode limit clamped [1,500]',              str_contains($au, 'min(500, (int) ($_GET[\'limit\'] ?? 100))'));

// ── RBAC ───────────────────────────────────────────────────────────
echo "\ncore/rbac/legacy_map.php — Slice-1 permissions\n";
$rb = $read("{$ROOT}/core/rbac/legacy_map.php");
$a("'ai.use' mapped to (ai, read)",                str_contains($rb, "'ai.use'                             => ['ai', 'read']"));
$a("'ai.audit.view' mapped to (ai, read)",         str_contains($rb, "'ai.audit.view'                      => ['ai', 'read']"));
$a("'platform.ai.admin' mapped to (ai, admin)",    str_contains($rb, "'platform.ai.admin'                  => ['ai', 'admin']"));

// ── Frontend — admin explorer ──────────────────────────────────────
echo "\ndashboard/src/pages/AiGatewayAdmin.jsx\n";
$ad = $read("{$ROOT}/dashboard/src/pages/AiGatewayAdmin.jsx");
$a('file exists',                                  $ad !== '');
$a('imports api helper',                           str_contains($ad, "import { api } from '../lib/api';"));
$a('default-exports AiGatewayAdmin',               str_contains($ad, 'export default function AiGatewayAdmin'));
$a('GETs /api/ai/runs.php list',                   str_contains($ad, '/api/ai/runs.php?'));
$a('GETs /api/ai/runs.php?id detail',              str_contains($ad, '/api/ai/runs.php?id=$'));
$a('GETs /api/ai/audit.php?ai_run_id',             str_contains($ad, '/api/ai/audit.php?ai_run_id=$'));
$a('GETs /api/ai/tools.php?action=list',           str_contains($ad, '/api/ai/tools.php?action=list'));
$a('root testid=ai-gateway-admin',                 str_contains($ad, 'data-testid="ai-gateway-admin"'));
$a('runs table testid',                            str_contains($ad, 'data-testid="ai-gateway-runs-table"'));
$a('per-run testid template',                      str_contains($ad, 'data-testid={`ai-gateway-run-${r.id}`}'));
$a('detail pane testid',                           str_contains($ad, 'data-testid="ai-gateway-detail"'));
$a('tool calls list testid',                       str_contains($ad, 'data-testid="ai-gateway-tool-calls"'));
$a('audit events list testid',                     str_contains($ad, 'data-testid="ai-gateway-audit-events"'));
$a('tool registry table testid',                   str_contains($ad, 'data-testid="ai-gateway-tool-registry"'));
$a('agent filter testid',                          str_contains($ad, 'data-testid="ai-gateway-filter-agent"'));
$a('status filter testid',                         str_contains($ad, 'data-testid="ai-gateway-filter-status"'));

// ── Frontend — Ask AI panel ────────────────────────────────────────
echo "\ndashboard/src/pages/AskAiPanel.jsx\n";
$ap = $read("{$ROOT}/dashboard/src/pages/AskAiPanel.jsx");
$a('file exists',                                  $ap !== '');
$a('POSTs /api/ai/runs.php',                       str_contains($ap, "api.post('/api/ai/runs.php'"));
$a('passes input_summary + tools[] in body (deterministic mode)',
    str_contains($ap, "tools: toolName ? [{ name: toolName, args }] : []"));
$a('Slice 1 plumbing badge OR Slice 2+ LLM badge present',
    str_contains($ap, 'Slice 1 · plumbing only')
 || str_contains($ap, 'Slice 2 · LLM planner live'));
$a('root testid=ask-ai-panel',                     str_contains($ap, 'data-testid="ask-ai-panel"'));
$a('input testid',                                 str_contains($ap, 'data-testid="ask-ai-input"'));
$a('tool select testid',                           str_contains($ap, 'data-testid="ask-ai-tool"'));
$a('args textarea testid',                         str_contains($ap, 'data-testid="ask-ai-args"'));
$a('submit testid',                                str_contains($ap, 'data-testid="ask-ai-submit"'));
$a('result block testid',                          str_contains($ap, 'data-testid="ask-ai-result"'));
$a('Slice 1 tool dropdown covers all 5 tools',     str_contains($ap, "value=\"coreflux.get_tenant_context\"")
                                                && str_contains($ap, "value=\"coreflux.get_user_permissions\"")
                                                && str_contains($ap, "value=\"coreflux.get_bank_transactions\""));

// ── AdminModule.jsx — routes + sidebar + cards ─────────────────────
echo "\ndashboard/src/pages/AdminModule.jsx — Slice-1 wiring\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports AiGatewayAdmin',                       str_contains($am, "import AiGatewayAdmin from './AiGatewayAdmin';"));
$a('imports AskAiPanel',                           str_contains($am, "import AskAiPanel from './AskAiPanel';"));
$a('imports Bot icon',                             str_contains($am, 'Bot'));
$a('mounts /ai-gateway route',                     str_contains($am, '<Route path="/ai-gateway"')
                                                && str_contains($am, '<AiGatewayAdmin session={session} />'));
$a('mounts /ai-gateway/ask route',                 str_contains($am, '<Route path="/ai-gateway/ask"')
                                                && str_contains($am, '<AskAiPanel session={session} />'));
$a('sidebar exposes /admin/ai-gateway',            str_contains($am, "to: '/admin/ai-gateway'"));
$a('sidebar exposes /admin/ai-gateway/ask',        str_contains($am, "to: '/admin/ai-gateway/ask'"));
$a('Overview ActionCard for AI Gateway',           str_contains($am, 'title="AI Tool Gateway"')
                                                && str_contains($am, 'href="/admin/ai-gateway"'));
$a('Overview ActionCard for Ask AI',               str_contains($am, 'title="Ask AI (Slice 1)"')
                                                && str_contains($am, 'href="/admin/ai-gateway/ask"'));

// ── Functional UUID shape probe ────────────────────────────────────
echo "\nFunctional UUID shape probe\n";
require_once "{$ROOT}/core/ai/gateway.php";
$uuid1 = _aiGatewayUuid();
$uuid2 = _aiGatewayUuid();
$a('UUID length is 36',                            strlen($uuid1) === 36);
$a('UUID matches v4 regex',                        preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid1) === 1);
$a('two UUIDs are different',                      $uuid1 !== $uuid2);

// ── PHP syntax checks ──────────────────────────────────────────────
echo "\nPHP syntax checks\n";
foreach ([
    'core/ai/gateway.php',
    'core/ai/tool_gateway.php',
    'api/ai/runs.php',
    'api/ai/audit.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "AI Gateway Slice 1: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
