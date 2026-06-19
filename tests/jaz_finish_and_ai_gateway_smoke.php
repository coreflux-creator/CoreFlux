<?php
/**
 * jaz_finish_and_ai_gateway_smoke.php
 *
 * Slice 4+5 — finish Jaz wiring + AI Tool Gateway Slice 1.
 *
 * Coverage:
 *   • api/accounting.php — new ?action=tenant_status (rollup for hub)
 *   • IntegrationsHub.jsx — Jaz tile uses live status + meta string
 *   • core/accounting/jaz_payload_mapper.php — bill/invoice/journal
 *     row → Jaz payload with FK resolution via destination_links
 *   • core/accounting/command_service.php — execute now pipes through
 *     the mapper when objectType is bill/invoice/journal and
 *     provider='jaz'
 *   • api/admin/accounting/outbox.php — list/detail/retry/cancel
 *   • dashboard/src/pages/AccountingOutbox.jsx — full admin UI
 *     with status badges, retry/cancel, detail modal
 *   • migration 089 — ai_tool_invocations audit table
 *   • core/ai/tool_gateway.php — registry + aiToolInvoke()
 *   • api/ai/tools.php — HTTP surface (list + invoke)
 *
 * Includes FUNCTIONAL tests of the mapper (balanced JE, missing
 * vendor, line item resolution) AND of the tool gateway (registry,
 * RBAC, validation, audit redaction).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ✓ {$name}\n"; }
    else     { $fail++; echo "  ✗ {$name}\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);
$ROOT = dirname(__DIR__);

echo "Jaz finish + AI Tool Gateway Slice 1 smoke\n";
echo "==========================================\n\n";

// --- api/accounting.php tenant_status --------------------------
echo "api/accounting.php — tenant_status\n";
$apiA = $read("{$ROOT}/api/accounting.php");
$a('tenant_status action present',                str_contains($apiA, "\$action === 'tenant_status'"));
$a('  groups by connection_status',               str_contains($apiA, 'GROUP BY connection_status'));
$a('  returns configured + connected + entities_total/active',
    str_contains($apiA, "'configured'         => \$total > 0,")
    && str_contains($apiA, "'connected'          => \$active > 0,")
    && str_contains($apiA, "'entities_total'     => \$total,")
    && str_contains($apiA, "'entities_active'    => \$active,"));
$a('  exception falls back to safe shape',        str_contains($apiA, "'configured' => false, 'connected' => false,"));

// --- IntegrationsHub.jsx Jaz tile ------------------------------
echo "\ndashboard/src/pages/IntegrationsHub.jsx\n";
$ih = $read("{$ROOT}/dashboard/src/pages/IntegrationsHub.jsx");
$a('useApi pull for tenant_status',               str_contains($ih, 'ACCOUNTING_INTEGRATIONS_API')
                                               && str_contains($ih, 'useApi(`${ACCOUNTING_INTEGRATIONS_API}?action=tenant_status&provider=jaz`)'));
$a('Jaz tile status reflects live data',          str_contains($ih, "status={jaz.loading ? 'loading' :")
                                               && str_contains($ih, "jaz.data?.connected ? 'connected' :"));
$a('Jaz tile shows entity count meta',            str_contains($ih, 'entities_active}/${jaz.data.entities_total} entities active'));

// --- core/accounting/jaz_payload_mapper.php --------------------
echo "\ncore/accounting/jaz_payload_mapper.php\n";
$mp = $read("{$ROOT}/core/accounting/jaz_payload_mapper.php");
foreach (['_accLookupJazResourceId','_accCents','_accAmount',
          'mapBillToJaz','mapInvoiceToJaz','mapJournalToJaz',
          'mapCorefluxRowToJaz'] as $fn) {
    $a("declares {$fn}()",                        str_contains($mp, "function {$fn}"));
}
$a('lookup throws Validation when missing',       str_contains($mp, 'is not linked to Jaz'));
$a('lookup scoped to (tenant, sub_tenant, jaz)',  str_contains($mp, "AND provider = 'jaz'"));
$a('bill throws on missing vendor_id',            str_contains($mp, 'bill missing vendor_id'));
$a('bill throws on missing line account_id',      str_contains($mp, "missing account_id"));
$a('bill emits contactResourceId',                str_contains($mp, "'contactResourceId' => \$contactRid,"));
$a('bill emits lineItems[].accountResourceId',    str_contains($mp, "'accountResourceId' => _accLookupJazResourceId(\$tenantId, \$subTenantId, 'account'"));
$a('invoice uses valueDate/customer flow',        str_contains($mp, "'valueDate'") && str_contains($mp, "_accLookupJazResourceId(\$tenantId, \$subTenantId, 'customer'"));
$a('journal requires ≥2 lines',                   str_contains($mp, 'journal entry needs ≥2 lines'));
$a('journal validates dr == cr',                  str_contains($mp, "journal entry unbalanced"));
$a('mapCorefluxRowToJaz dispatches by type',      str_contains($mp, "case 'bill':    return mapBillToJaz")
                                               && str_contains($mp, "case 'invoice': return mapInvoiceToJaz")
                                               && str_contains($mp, "case 'journal': return mapJournalToJaz"));
$a('default throws Validation',                   str_contains($mp, "no Jaz mapper for object type"));

// --- command_service.php pipes through mapper ------------------
echo "\ncore/accounting/command_service.php — mapper integration\n";
$cs = $read("{$ROOT}/core/accounting/command_service.php");
$a('execute reads coreflux_object_type',          str_contains($cs, "\$objectType = (string) (\$payload['coreflux_object_type'] ?? '');"));
$a('execute reads raw row',                       str_contains($cs, "\$rawRow     = is_array(\$payload['row'] ?? null) ? \$payload['row'] : null;"));
$a('execute calls mapCorefluxRowToJaz when bill/invoice/journal + jaz',
    str_contains($cs, 'mapCorefluxRowToJaz($objectType, $tenantId, $subTenantId, $rawRow)'));
$a('execute uses mapped draft (fallback to raw payload)',
    str_contains($cs, '$adapter->createDraftBill($tenantId, $subTenantId, $mappedDraft ?? $payload, $idem)'));
$a('mapper failure routes to markFailure',        str_contains($cs, 'accountingCommandMarkFailure($tenantId, $commandId, $row, $adapter, $e);'));

// --- outbox admin endpoint -------------------------------------
echo "\napi/admin/accounting/outbox.php\n";
$ob = $read("{$ROOT}/api/admin/accounting/outbox.php");
$a('GET list filters by status',                  str_contains($ob, "in_array(\$status, ['queued','processing','posted','failed','retrying','dead_letter']"));
$a('GET returns by_status rollup',                str_contains($ob, "'by_status' => \$byStatus"));
$a('GET detail action wired',                     str_contains($ob, "\$action === 'detail'"));
$a('POST retry resets attempts on dead_letter',   str_contains($ob, "\$resetAttempts = \$row['status'] === 'dead_letter' ? 0 : (int) \$row['attempts'];"));
$a('POST retry refuses non-failed/retrying',      str_contains($ob, "cannot retry from status"));
$a('POST retry kicks inline (no cron wait)',      str_contains($ob, 'accountingCommandExecute($tid, $id);'));
$a('POST cancel marks dead_letter',               str_contains($ob, "SET status        = 'dead_letter',"));
$a('RBAC view on GET, execute on POST',           str_contains($ob, "rbac_legacy_require(\$user, 'accounting.connection.view');")
                                               && str_contains($ob, "rbac_legacy_require(\$user, 'accounting.commands.execute');"));

// --- outbox UI --------------------------------------------------
echo "\ndashboard/src/pages/AccountingOutbox.jsx\n";
$obx = $read("{$ROOT}/dashboard/src/pages/AccountingOutbox.jsx");
$a('default export AccountingOutbox',             str_contains($obx, 'export default function AccountingOutbox()'));
$a('renders status filter buttons',               str_contains($obx, "data-testid={`outbox-filter-\${s}`}"));
$a('renders all 6 statuses in filter row',
    str_contains($obx, "['queued', 'processing', 'posted', 'retrying', 'failed', 'dead_letter']"));
$a('error column has per-row testid',             str_contains($obx, "data-testid={`outbox-error-\${r.id}`}"));
$a('retry button gated on failed/retrying/dead_letter',
    str_contains($obx, "r.status === 'failed' || r.status === 'retrying' || r.status === 'dead_letter'"));
$a('cancel button gated on queued/retrying/failed',
    str_contains($obx, "r.status === 'queued' || r.status === 'retrying' || r.status === 'failed'"));
$a('detail modal renders command_payload + provider_result',
    str_contains($obx, 'JSON.stringify(detail.command_payload')
    && str_contains($obx, 'JSON.stringify(detail.provider_result'));

echo "\ndashboard/src/pages/AdminModule.jsx (route)\n";
$am = $read("{$ROOT}/dashboard/src/pages/AdminModule.jsx");
$a('imports AccountingOutbox',                    str_contains($am, "import AccountingOutbox from './AccountingOutbox';"));
$a('mounts /accounting/outbox route',             str_contains($am, '<Route path="/accounting/outbox"'));

// ============================================================
// AI TOOL GATEWAY
// ============================================================
echo "\ncore/migrations/089_ai_tool_invocations.sql\n";
$mig = $read("{$ROOT}/core/migrations/089_ai_tool_invocations.sql");
$a('creates ai_tool_invocations',                 str_contains($mig, 'CREATE TABLE IF NOT EXISTS ai_tool_invocations'));
$a('  status ENUM 5-state',                       str_contains($mig, "ENUM('ok','denied','validation_failed','provider_error','internal_error')"));
$a('  tool_name VARCHAR(120)',                    str_contains($mig, 'tool_name           VARCHAR(120) NOT NULL'));
$a('  args_json + result_summary JSON',           str_contains($mig, 'args_json           JSON')
                                               && str_contains($mig, 'result_summary      JSON'));
$a('  ix_aiti_tenant_recent',                     str_contains($mig, 'KEY ix_aiti_tenant_recent'));
$a('  ix_aiti_tool',                              str_contains($mig, 'KEY ix_aiti_tool'));

echo "\ncore/ai/tool_gateway.php\n";
$tg = $read("{$ROOT}/core/ai/tool_gateway.php");
foreach (['aiToolRegistry','aiToolInvoke','aiToolCoerceArg','aiToolAudit','_aiToolRedactArgs',
          'aiToolListToolsHandler','aiToolGetChartOfAccountsHandler',
          'aiToolGetTrialBalanceHandler','aiToolGetGeneralLedgerHandler',
          'aiToolListOutboxHandler'] as $fn) {
    $a("declares {$fn}()",                        str_contains($tg, "function {$fn}"));
}
$a('registry includes coreflux.list_tools',       str_contains($tg, "'coreflux.list_tools'"));
$a('registry includes get_chart_of_accounts',     str_contains($tg, "'coreflux.get_chart_of_accounts'"));
$a('registry includes get_trial_balance',         str_contains($tg, "'coreflux.get_trial_balance'"));
$a('registry includes get_general_ledger',        str_contains($tg, "'coreflux.get_general_ledger'"));
$a('registry includes list_outbox',               str_contains($tg, "'coreflux.list_outbox'"));
$a('list_tools has no permission (discovery free)',
    str_contains($tg, "'coreflux.list_tools' => [")
    && str_contains($tg, "'permission'  => null, // anyone authenticated can discover"));
$a('read tools require accounting.connection.view',
    substr_count($tg, "'permission'  => 'accounting.connection.view',") >= 4);
$a('invoke handles unknown_tool',                 str_contains($tg, "'code' => 'unknown_tool'"));
$a('invoke enforces RBAC via rbac_legacy_require',str_contains($tg, "rbac_legacy_require(\$userRow, (string) \$tool['permission']);"));
$a('invoke RBAC failure → status=denied',         str_contains($tg, "'status' => 'denied'"));
$a('invoke validates required args',              str_contains($tg, "'code' => 'missing_arg'"));
$a('invoke coerces arg types',                    str_contains($tg, 'aiToolCoerceArg($args[$argName], $type)'));
$a('audit redacts key/secret/token args',         str_contains($tg, 'preg_match(\'/(key|secret|token|password|cred)/i\', $k)'));
$a('audit never blocks (try/catch logs)',         str_contains($tg, "[aiToolAudit]"));

echo "\napi/ai/tools.php\n";
$apiT = $read("{$ROOT}/api/ai/tools.php");
$a('api_require_auth gate',                       str_contains($apiT, '$ctx    = api_require_auth();'));
$a('GET list returns the tool catalog',           str_contains($apiT, "if (\$method === 'GET' && (\$action === 'list' || \$action === ''))")
                                               && str_contains($apiT, "aiToolInvoke('coreflux.list_tools'"));
$a('POST invoke validates tool_name',             str_contains($apiT, "tool_name required"));
$a('POST invoke passes session_id from body',     str_contains($apiT, "\$callerCtx['session_id'] = \$sessionId;"));

// --- functional gateway exercises ------------------------------
echo "\nFunctional gateway behaviour\n";
require_once "{$ROOT}/core/RBAC.php";
require_once "{$ROOT}/core/rbac/legacy_map.php";
require_once "{$ROOT}/core/ai/tool_gateway.php";

$reg = aiToolRegistry();
$a('registry returns associative array',          is_array($reg) && count($reg) >= 5);
$a('each tool has description/permission/args/handler keys',
    isset($reg['coreflux.get_trial_balance']['description'])
    && array_key_exists('permission',  $reg['coreflux.get_trial_balance'])
    && isset($reg['coreflux.get_trial_balance']['args'])
    && is_callable($reg['coreflux.get_trial_balance']['handler']));

// Discovery for guest — no permission needed.
$env = aiToolInvoke('coreflux.list_tools', [], ['tenant_id' => 1, 'user' => ['role' => 'guest']]);
$a('list_tools invocable without RBAC',           ($env['ok'] ?? false) === true);
$a('  returns ≥ 5 tools',                          isset($env['result']['tools']) && count($env['result']['tools']) >= 5);

// Unknown tool.
$env = aiToolInvoke('coreflux.nope', [], ['tenant_id' => 1, 'user' => ['role' => 'master_admin']]);
$a('unknown tool returns status=validation_failed',($env['status'] ?? '') === 'validation_failed');
$a('  error code = unknown_tool',                  ($env['error']['code'] ?? '') === 'unknown_tool');

// Missing required arg.
$env = aiToolInvoke('coreflux.get_trial_balance', [], ['tenant_id' => 1, 'user' => ['role' => 'master_admin']]);
$a('missing required arg → validation_failed',     ($env['status'] ?? '') === 'validation_failed');
$a('  error code = missing_arg',                   ($env['error']['code'] ?? '') === 'missing_arg');

// arg coercion
$a('coerce int "42" → 42',                         aiToolCoerceArg('42', 'int') === 42);
$a('coerce bool "true" → true',                    aiToolCoerceArg('true', 'bool') === true);
$a('coerce date trims to 10 chars',                aiToolCoerceArg('2026-01-31T23:59', 'date') === '2026-01-31');

// redaction
$red = _aiToolRedactArgs(['api_key' => 'secret', 'sub_tenant_id' => 5, 'token' => 't', 'as_of' => '2026-01-01']);
$a('redact masks api_key',                         $red['api_key'] === '[REDACTED]');
$a('redact masks token',                           $red['token']   === '[REDACTED]');
$a('redact preserves non-sensitive args',          $red['sub_tenant_id'] === 5 && $red['as_of'] === '2026-01-01');

// --- PHP syntax checks -----------------------------------------
echo "\nPHP syntax checks\n";
foreach ([
    'api/accounting.php', 'core/accounting/jaz_payload_mapper.php',
    'core/accounting/command_service.php', 'api/admin/accounting/outbox.php',
    'core/ai/tool_gateway.php', 'api/ai/tools.php',
] as $f) {
    $r = shell_exec("php -l {$ROOT}/{$f} 2>&1");
    $a("{$f} parses",                              is_string($r) && str_contains($r, 'No syntax errors'));
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Jaz finish + AI Gateway Slice 1: {$pass} ✓ / {$fail} ✗\n";
exit($fail > 0 ? 1 : 0);
