<?php
/**
 * Sprint 6c — AP → WorkflowEngine cutover + module entity-scope listeners.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6c_ap_workflow_cutover_smoke.php
 *
 * Verifies:
 *   • workflow_engine.php exposes workflowEnsureDefinition + suppress_push opt.
 *   • workflow_engine.php routes `ap_bill` subject completions through
 *     _workflowSubjectSync → apSyncFromWorkflow.
 *   • modules/ap/lib/workflow_sync.php — column names match the real
 *     ap_bill_approvals schema (decision_at, not decided_at).
 *   • modules/ap/lib/approval_router.php — `apRouteBillForApproval` now
 *     creates a workflow_instances row via workflowEnsureDefinition +
 *     workflowStart and returns `workflow_instance_id` in its result.
 *   • useActiveEntity hook exists + listens to cf:active-entity-changed.
 *   • Accounting UI components (JournalEntries, Periods, PeriodCloseWorkflow)
 *     thread the active entity into their API calls.
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

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/ap/lib/approval_router.php',
    'modules/ap/lib/workflow_sync.php',
] as $rel) {
    $assert("php -l: {$rel}", $lint("{$ROOT}/{$rel}"));
}

echo "\nworkflow_engine.php extensions\n";
$we = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$assert('exports workflowEnsureDefinition',          stripos($we, 'function workflowEnsureDefinition') !== false);
$assert('upsert-by-shape: sha256 hash compare',      stripos($we, "hash('sha256'") !== false && stripos($we, 'shapeHash') !== false);
$assert('suppress_push opt honoured',                stripos($we, "empty(\$payload['suppress_push'])") !== false);
$assert('_workflowSubjectSync exists',               stripos($we, 'function _workflowSubjectSync') !== false);
$assert('_workflowSubjectSync requires AP sync',     stripos($we, '/modules/ap/lib/workflow_sync.php') !== false);
$assert('_workflowSubjectSync guards with function_exists', stripos($we, "function_exists('apSyncFromWorkflow')") !== false);
$assert('workflowAct.reject invokes subject sync',   preg_match("#_workflowSubjectSync\\(.*WORKFLOW_STATUS_REJECTED#s", $we) === 1);
$assert('workflowAct.approve-complete invokes subject sync',
                                                     preg_match("#_workflowSubjectSync\\(.*WORKFLOW_STATUS_APPROVED#s", $we) === 1);
$assert('workflowAct.per-step invokes subject sync with PENDING',
                                                     preg_match("#_workflowSubjectSync\\(.*WORKFLOW_STATUS_PENDING#s", $we) === 1);

echo "\nworkflow_sync.php — schema-correct columns\n";
$ws = (string) file_get_contents("{$ROOT}/modules/ap/lib/workflow_sync.php");
$assert('uses decision_at (not decided_at)',         stripos($ws, 'decision_at') !== false);
$assert('does NOT reference decided_at',             stripos($ws, 'decided_at') === false);
$assert('does NOT reference decided_by_user_id',     stripos($ws, 'decided_by_user_id') === false);
$assert('updates ap_bill_approvals to approved',     preg_match("#UPDATE\\s+ap_bill_approvals.*state\\s*=\\s*'approved'#s", $ws) === 1);
$assert('updates ap_bill_approvals to rejected',     preg_match("#UPDATE\\s+ap_bill_approvals.*state\\s*=\\s*'rejected'#s", $ws) === 1);
$assert('flips ap_bills.status on reject (disputed)',stripos($ws, "status = 'disputed'") !== false);
$assert('flips ap_bills.status on approved instance',stripos($ws, "status = 'approved'") !== false);
$assert('scopes by tenant_id on every query',        substr_count($ws, ':t') >= 3);
$assert('swallows throwables (never breaks engine)', stripos($ws, 'catch (\\Throwable') !== false);

echo "\napproval_router.php — cutover wiring\n";
$ar = (string) file_get_contents("{$ROOT}/modules/ap/lib/approval_router.php");
$assert('requires workflow_engine',                  stripos($ar, '/core/workflow_engine.php') !== false);
$assert('calls workflowEnsureDefinition',            stripos($ar, 'workflowEnsureDefinition(') !== false);
$assert('calls workflowStart for ap_bill subject',   stripos($ar, "'ap_bill'") !== false && stripos($ar, 'workflowStart(') !== false);
$assert('uses per-policy def_key',                   stripos($ar, "'ap_bill_policy_'") !== false);
$assert('passes suppress_push=true in payload',      stripos($ar, "'suppress_push' => true") !== false);
$assert('catches throwable so legacy path safe',     preg_match("#catch\\s*\\(\\s*\\\\Throwable\\s+\\\$_\\s*\\)#", $ar) === 1);
$assert('return shape includes workflow_instance_id',stripos($ar, "'workflow_instance_id'") !== false);
$assert('AP push opts carry mobile_deep_link',       stripos($ar, "'mobile_deep_link'") !== false);
$assert('mobile_deep_link uses coreflux://approvals/',stripos($ar, 'coreflux://approvals/') !== false);
$assert('AP push payload carries workflow_instance_id',stripos($ar, "'workflow_instance_id'") !== false);

echo "\nuseActiveEntity hook\n";
$uae = (string) file_get_contents("{$ROOT}/dashboard/src/lib/useActiveEntity.js");
$assert('exports useActiveEntity',                   stripos($uae, 'export function useActiveEntity') !== false);
$assert('GETs /api/active_entity.php',               stripos($uae, "api.get('/api/active_entity.php')") !== false);
$assert('listens cf:active-entity-changed',          stripos($uae, "'cf:active-entity-changed'") !== false);
$assert('entityQuery helper returns query string',   stripos($uae, 'entityQuery') !== false && stripos($uae, 'entity_id=') !== false);
$assert('returns activeEntityId + entities + entityQuery',
    stripos($uae, 'activeEntityId') !== false && stripos($uae, 'entities') !== false && stripos($uae, 'entityQuery') !== false);

echo "\nModule entity-scope listeners\n";
$je  = (string) file_get_contents("{$ROOT}/modules/accounting/ui/JournalEntries.jsx");
$pr  = (string) file_get_contents("{$ROOT}/modules/accounting/ui/Periods.jsx");
$pcw = (string) file_get_contents("{$ROOT}/modules/accounting/ui/PeriodCloseWorkflow.jsx");

$assert('JournalEntries imports useActiveEntity',    stripos($je, 'useActiveEntity') !== false);
$assert('JournalEntries threads entity_id into qs',  stripos($je, "qs.set('entity_id'") !== false);
$assert('JournalEntries shows entity pill testid',   stripos($je, 'accounting-journal-filter-entity') !== false);

$assert('Periods imports useActiveEntity',           stripos($pr, 'useActiveEntity') !== false);
$assert('Periods appends entityQuery to API URL',    stripos($pr, "entityQuery('?')") !== false);
$assert('Periods shows entity scope notice testid',  stripos($pr, 'accounting-periods-entity-scope') !== false);

$assert('PeriodCloseWorkflow imports useActiveEntity',stripos($pcw, 'useActiveEntity') !== false);
$assert('PeriodCloseWorkflow uses entityQuery',       stripos($pcw, "entityQuery('?')") !== false);
$assert('PeriodCloseWorkflow shows entity scope testid', stripos($pcw, 'close-entity-scope') !== false);

echo "\nap_bill_approvals schema contract (columns the sync writes to)\n";
$sql = (string) file_get_contents("{$ROOT}/modules/ap/migrations/010_approval_workflows.sql");
$assert('ap_bill_approvals has decision_at column', stripos($sql, 'decision_at') !== false);
$assert('ap_bill_approvals has state enum',          stripos($sql, "state") !== false && stripos($sql, "'pending','approved','rejected'") !== false);
$assert('ap_bills has approved_at (from 001_init)',
    stripos((string) file_get_contents("{$ROOT}/modules/ap/migrations/001_init.sql"), 'approved_at') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
