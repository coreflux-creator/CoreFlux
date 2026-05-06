<?php
/**
 * Sprint 6d — Entity scope rollout to AP/Billing + AI Workflow Inbox summary.
 *
 *   php -d zend.assertions=1 /app/tests/sprint6d_entity_scope_ai_smoke.php
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

echo "AP bills — entity-scope API + UI\n";
$bApi = (string) file_get_contents("{$ROOT}/modules/ap/api/bills.php");
$assert('bills.php accepts ?entity_id',              stripos($bApi, "\$_GET['entity_id']") !== false);
$assert('bills.php filters by entity_id in WHERE',   preg_match("#entity_id\\s*=\\s*:eid#", $bApi) === 1);

$bUI  = (string) file_get_contents("{$ROOT}/modules/ap/ui/BillsList.jsx");
$assert('BillsList imports useActiveEntity',         stripos($bUI, 'useActiveEntity') !== false);
$assert('BillsList threads entity_id into qs',       stripos($bUI, "qs.set('entity_id'") !== false);
$assert('BillsList shows scope notice testid',       stripos($bUI, 'data-testid="ap-bills-entity-scope"') !== false);

echo "\nBilling invoices — entity-scope API + UI\n";
$iApi = (string) file_get_contents("{$ROOT}/modules/billing/api/invoices.php");
$assert('invoices.php accepts ?entity_id',           stripos($iApi, "\$_GET['entity_id']") !== false);
$assert('invoices.php filters by entity_id',         preg_match("#entity_id\\s*=\\s*:eid#", $iApi) === 1);

$iUI  = (string) file_get_contents("{$ROOT}/modules/billing/ui/InvoicesList.jsx");
$assert('InvoicesList imports useActiveEntity',      stripos($iUI, 'useActiveEntity') !== false);
$assert('InvoicesList threads entity_id into qs',    stripos($iUI, "qs.set('entity_id'") !== false);
$assert('InvoicesList scope notice testid',          stripos($iUI, 'data-testid="billing-invoices-entity-scope"') !== false);

echo "\nSchema contract — entity_id columns exist in migrations\n";
$cons = (string) file_get_contents("{$ROOT}/modules/accounting/migrations/007_consolidation.sql");
$assert('ap_bills.entity_id in migration 007',       stripos($cons, "ALTER TABLE ap_bills ADD COLUMN entity_id") !== false);
$assert('billing_invoices.entity_id in migration 007', stripos($cons, "ALTER TABLE billing_invoices ADD COLUMN entity_id") !== false);

echo "\nAI Workflow Inbox summary endpoint\n";
$aiEp = (string) file_get_contents("{$ROOT}/api/workflow_ai.php");
$assert('workflow_ai.php parses',                    $lint("{$ROOT}/api/workflow_ai.php"));
$assert('requires api_bootstrap',                    stripos($aiEp, "require_once __DIR__ . '/../core/api_bootstrap.php'") !== false);
$assert('requires workflow_engine',                  stripos($aiEp, "require_once __DIR__ . '/../core/workflow_engine.php'") !== false);
$assert('requires ai_service',                       stripos($aiEp, "require_once __DIR__ . '/../core/ai_service.php'") !== false);
$assert('only POST ?action=summarize',               stripos($aiEp, "\$method !== 'POST'") !== false && stripos($aiEp, "\$action !== 'summarize'") !== false);
$assert('calls aiAsk with feature_key',              stripos($aiEp, "aiAsk(") !== false && stripos($aiEp, 'workflow.inbox.summary') !== false);
$assert('narrative feature_class',                   stripos($aiEp, "'narrative'") !== false);
$assert('swallows throwables → empty string',        preg_match("#catch\\s*\\(\\s*\\\\Throwable\\s+\\\$_\\s*\\)\\s*\\{\\s*\\\$summary\\s*=\\s*''#", $aiEp) === 1);
$assert('returns 200 with instance_id + summary',    stripos($aiEp, "api_ok(['instance_id'") !== false && stripos($aiEp, "'summary'") !== false);
$assert('404 on unknown instance',                   preg_match("#api_error\\('Instance not found',\\s*404\\)#", $aiEp) === 1);

echo "\nWorkflowInbox UI — AI affordance\n";
$wi = (string) file_get_contents("{$ROOT}/dashboard/src/pages/WorkflowInbox.jsx");
$assert('imports Sparkles icon',                     stripos($wi, 'Sparkles') !== false);
$assert('POSTs /api/workflow_ai.php?action=summarize',stripos($wi, "/api/workflow_ai.php?action=summarize&id=") !== false);
$assert('AI hint button (dynamic testid)',           stripos($wi, 'workflow-inbox-ai-summarize-') !== false);
$assert('renders AI summary block (dynamic testid)', stripos($wi, 'workflow-inbox-ai-summary-') !== false);
$assert('labels it as advisory only',                stripos($wi, 'advisory only') !== false);
$assert('graceful degrade text',                     stripos($wi, 'summary unavailable') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
