<?php
/**
 * Domain module -> People Graph contract smoke.
 *
 * Locks the P2 alignment that domain modules consume the shared People Graph
 * authority layer instead of creating parallel owner/approver/reviewer models.
 */

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
require_once $root . '/core/domain_people_graph.php';

$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};

ModuleRegistry::reset($root . '/modules');

echo "Helper contract\n";
$helper = (string) file_get_contents($root . '/core/domain_people_graph.php');
$out = [];
$rc = 0;
exec('php -l ' . escapeshellarg($root . '/core/domain_people_graph.php') . ' 2>&1', $out, $rc);
$a('domain_people_graph.php parses', $rc === 0);
$a('helper requires people_graph.php', str_contains($helper, "require_once __DIR__ . '/people_graph.php';"));
$a('helper assigns through People Graph', str_contains($helper, 'peopleGraphAssignResponsibility('));
$a('helper resolves through People Graph', str_contains($helper, 'peopleGraphResolve('));
$a('helper resolves approvers through People Graph', str_contains($helper, 'peopleGraphResolveApprovers('));
$a('helper checks permission through People Graph', str_contains($helper, 'peopleGraphCheckPermission('));

echo "\nRegistry contract\n";
$registry = ModuleRegistry::getInstance();
$contracts = domainPeopleGraphContracts();
foreach (['placements', 'time', 'ap', 'billing', 'payroll', 'accounting', 'treasury', 'staffing'] as $moduleId) {
    $a("{$moduleId} declares People Graph contract", isset($contracts[$moduleId]));
    $a("{$moduleId} consumes People Graph", domainPeopleGraphConsumes($moduleId));
}
$a('registry exposes People Graph contracts', method_exists($registry, 'getPeopleGraphContracts'));
$a('ModuleRegistry default includes people_graph field', is_array(($registry->getModule('people')['people_graph'] ?? null)));

echo "\nDomain object declarations\n";
$apBill = domainPeopleGraphResponsibilitiesFor('ap', 'bill');
$a('AP bill has preparer', in_array('preparer', $apBill, true));
$a('AP bill has approver', in_array('approver', $apBill, true));
$a('AP bill has AI supervisor', in_array('ai_supervisor', $apBill, true));

$payrollRun = domainPeopleGraphResponsibilitiesFor('payroll', 'run');
$a('payroll run has preparer', in_array('preparer', $payrollRun, true));
$a('payroll run has approver', in_array('approver', $payrollRun, true));
$a('payroll run has operator', in_array('operator', $payrollRun, true));

$timeTimesheet = domainPeopleGraphResponsibilitiesFor('time', 'timesheet');
$a('time timesheet has requester', in_array('requester', $timeTimesheet, true));
$a('time timesheet has approver', in_array('approver', $timeTimesheet, true));
$a('time timesheet has recipient', in_array('recipient', $timeTimesheet, true));

$staffing = domainPeopleGraphContract('staffing');
$a('staffing is consumer_orchestrator', ($staffing['mode'] ?? null) === 'consumer_orchestrator');
$a('staffing consumes time/payroll/billing/accounting/reports',
    count(array_intersect(['time', 'payroll', 'billing', 'accounting', 'reports'], $staffing['consumes_from'] ?? [])) === 5);
$a('staffing declares source-of-truth note', str_contains((string) ($staffing['source_of_truth_note'] ?? ''), 'owning modules'));

echo "\nBridge behavior without DB\n";
$ref = domainPeopleGraphObjectRef('payroll', 'run', 123);
$a('object ref maps module/type/id', $ref === [
    'object_module' => 'payroll',
    'object_type' => 'run',
    'object_id' => '123',
]);
$workflowResolution = domainPeopleGraphWorkflowApproverResolution('ap', 'bill', 'bill_9', ['amount' => 12500]);
$a('workflow payload uses approval_policy', ($workflowResolution['strategy'] ?? null) === 'approval_policy');
$a('workflow payload uses domain resource module', ($workflowResolution['resource_module'] ?? null) === 'ap');
$a('workflow payload carries context', ($workflowResolution['context']['amount'] ?? null) === 12500);

$allowedOk = true;
try {
    domainPeopleGraphAssertResponsibilityAllowed('billing', 'invoice', 'approver');
} catch (\Throwable $e) {
    $allowedOk = false;
}
$a('declared responsibility is allowed', $allowedOk);

$blocked = false;
try {
    domainPeopleGraphAssertResponsibilityAllowed('billing', 'dunning_case', 'approver');
} catch (\InvalidArgumentException $e) {
    $blocked = true;
}
$a('undeclared responsibility is blocked', $blocked);

$unknown = false;
try {
    domainPeopleGraphObjectRef('billing', 'unknown_object', 1);
} catch (\InvalidArgumentException $e) {
    $unknown = true;
}
$a('undeclared object type is blocked', $unknown);

echo "\nDomain People Graph smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
