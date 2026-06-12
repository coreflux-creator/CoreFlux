<?php
/**
 * Sprint 6e — Treasury entity-scope + AP WorkflowEngine bridge + Period
 * Close Readiness AI narrative — static smoke.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6e_close_readiness_ai_smoke.php
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $name, bool $cond, ?string $hint = null) use (&$pass, &$fail): void {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}" . ($hint ? "  ({$hint})" : '') . "\n"; $fail++; }
};
$lint = function (string $p): bool {
    $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc);
    return $rc === 0;
};
$ROOT = realpath(__DIR__ . '/..');

echo "Treasury — entity-scope API + UI\n";
$tApi = (string) file_get_contents("{$ROOT}/modules/treasury/api/deposit_accounts.php");
$assert('deposit_accounts.php parses',                  $lint("{$ROOT}/modules/treasury/api/deposit_accounts.php"));
$assert('deposit_accounts.php accepts ?entity_id',      stripos($tApi, "\$_GET['entity_id']") !== false);
$assert('deposit_accounts.php filters by ba.entity_id', stripos($tApi, 'ba.entity_id = :eid') !== false);
$assert('deposit_accounts.php selects ba.entity_id',    stripos($tApi, 'ba.entity_id,') !== false);

$tUI = (string) file_get_contents("{$ROOT}/modules/treasury/ui/DepositAccounts.jsx");
$assert('DepositList imports useActiveEntity',          stripos($tUI, 'useActiveEntity') !== false);
$assert('DepositList appends entityQuery to URL',       stripos($tUI, "entityQuery('?')") !== false);
$assert('DepositList shows scope notice testid',        stripos($tUI, 'data-testid="treasury-deposits-entity-scope"') !== false);

echo "\nAP legacy approval API delegates to WorkflowEngine\n";
$ba = (string) file_get_contents("{$ROOT}/modules/ap/api/bill_approvals.php");
$bridge = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_bridge.php");
$assert('bill_approvals.php parses',                    $lint("{$ROOT}/modules/ap/api/bill_approvals.php"));
$assert('bill_approvals loads workflow_bridge',         stripos($ba, "../lib/workflow_bridge.php") !== false);
$assert('approve/reject path calls bridge action gate', stripos($ba, 'apWorkflowActBillApproval($tenantId, $bill, $userId, $action, $note, true)') !== false);
$assert('blocked decisions use shared status mapping',  stripos($ba, 'apWorkflowDecisionHttpStatus($e)') !== false);
$assert('old reverse mirror helper removed',            stripos($ba, 'function apMirrorToWorkflow') === false);
$assert('bridge finds latest pending instance for bill',preg_match('#FROM workflow_instances\s+WHERE\s+tenant_id\s*=\s*:t\s+AND\s+subject_type\s*=\s*[\'\"]ap_bill[\'\"]\s+AND\s+subject_id\s*=\s*:s#i', $bridge) === 1);
$assert('bridge calls workflowAct',                     stripos($bridge, 'workflowAct(') !== false);
$assert('bridge only acts on pending instances',        stripos($bridge, "status = 'pending'") !== false);
echo "\nPeriod Close Readiness AI endpoint\n";
$ai = (string) file_get_contents("{$ROOT}/modules/accounting/api/close_ai.php");
$assert('close_ai.php parses',                          $lint("{$ROOT}/modules/accounting/api/close_ai.php"));
$assert('requires api_bootstrap',                       stripos($ai, "require_once __DIR__ . '/../../../core/api_bootstrap.php'") !== false);
$assert('requires ai_service',                          stripos($ai, "require_once __DIR__ . '/../../../core/ai_service.php'") !== false);
$assert('only POST ?action=readiness',                  stripos($ai, "\$method !== 'POST'") !== false && stripos($ai, "\$action !== 'readiness'") !== false);
$assert('requires period_id',                           stripos($ai, "period_id required") !== false);
$assert('reads accounting_close_tasks stats',           stripos($ai, 'FROM accounting_close_tasks') !== false);
$assert('reads draft journal entries in period',        stripos($ai, "status = 'draft'") !== false && stripos($ai, 'BETWEEN :sd AND :ed') !== false);
$assert('reads pending_review timesheets best-effort',  stripos($ai, "status = 'pending_review'") !== false && stripos($ai, "FROM time_entries") !== false);
$assert('time_entries query in try/catch (optional module)', preg_match('#try\\s*\\{[^}]*time_entries[^}]*\\}\\s*catch#s', $ai) === 1);
$assert('feature_key is accounting.period_close.readiness',
    stripos($ai, 'accounting.period_close.readiness') !== false);
$assert('narrative feature_class',                      stripos($ai, "'narrative'") !== false);
$assert('catches throwable → empty summary',            preg_match("#catch\\s*\\(\\s*\\\\Throwable\\s+\\\$_\\s*\\)\\s*\\{\\s*\\\$summary\\s*=\\s*''#", $ai) === 1);
$assert('returns signals block alongside summary',      stripos($ai, "'signals'") !== false && stripos($ai, "'open_tasks'") !== false);
$assert('404 on unknown period',                        preg_match("#api_error\\('Period not found',\\s*404\\)#", $ai) === 1);

echo "\nPeriodCloseWorkflow UI — readiness affordance\n";
$pcw = (string) file_get_contents("{$ROOT}/modules/accounting/ui/PeriodCloseWorkflow.jsx");
$assert('hits /modules/accounting/api/close_ai.php',    stripos($pcw, '/modules/accounting/api/close_ai.php?action=readiness&period_id=') !== false);
$assert('readiness ask button testid',                  stripos($pcw, 'data-testid="close-readiness-ask"') !== false);
$assert('readiness card testid',                        stripos($pcw, 'data-testid="close-readiness-card"') !== false);
$assert('readiness summary testid',                     stripos($pcw, 'data-testid="close-readiness-summary"') !== false);
$assert('readiness refresh button testid',              stripos($pcw, 'data-testid="close-readiness-refresh"') !== false);
$assert('readiness signal slot testid',                 stripos($pcw, 'data-testid="close-readiness-signal-open"') !== false);
$assert('labels block as advisory only',                stripos($pcw, 'advisory only') !== false);

echo "\nSchema contract — entity_id columns referenced\n";
$mig10 = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/010_bank_accounts_entity.sql");
$assert('accounting_bank_accounts.entity_id added in migration 010',
    stripos($mig10, 'ALTER TABLE accounting_bank_accounts ADD COLUMN entity_id') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
