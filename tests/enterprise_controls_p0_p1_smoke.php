<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [];

function check(string $label, bool $ok): void {
    global $checks;
    $checks[] = [$label, $ok];
    echo ($ok ? "ok    " : "FAIL  ") . $label . PHP_EOL;
}

function contains(string $file, string $needle): bool {
    return strpos(file_get_contents($file), $needle) !== false;
}

$time = $root . '/modules/time/api/entries.php';
$payroll = $root . '/modules/payroll/api/runs.php';
$gustoSubmit = $root . '/modules/payroll/api/gusto_submit.php';
$placements = $root . '/modules/placements/api/placements.php';
$people = $root . '/modules/people/api/employees.php';
$peopleResource = $root . '/modules/people/api/people.php';
$peopleBankAccounts = $root . '/modules/people/api/bank_accounts.php';
$peopleTaxFederal = $root . '/modules/people/api/tax_federal.php';
$peopleAddresses = $root . '/modules/people/api/addresses.php';
$peopleI9 = $root . '/modules/people/api/i9.php';
$peopleCustomValues = $root . '/modules/people/api/custom_field_values.php';
$staffingTimesheets = $root . '/modules/staffing/api/timesheets.php';
$staffingReadiness = $root . '/modules/staffing/api/readiness.php';
$staffingManifest = $root . '/modules/staffing/manifest.php';
$legacyMap = $root . '/core/rbac/legacy_map.php';

echo "Time controls" . PHP_EOL;
check('create rejects non-draft status input', contains($time, 'status cannot be set during create'));
check('create always persists draft status', contains($time, "'status'             => 'draft'"));
check('patch rejects status transitions', contains($time, 'status transitions must use submit/approve/reject actions'));
check('submit requires owner/manage permission', contains($time, '_timeRequireEntryWriteAccess($user, $entry)'));

echo PHP_EOL . "Payroll controls" . PHP_EOL;
check('GET requires payroll.view', contains($payroll, "rbac_legacy_require(\$user, 'payroll.view')"));
check('create/compute requires payroll.run.build', contains($payroll, "rbac_legacy_require(\$user, 'payroll.run.build')"));
check('approve requires payroll.run.approve', contains($payroll, "rbac_legacy_require(\$user, 'payroll.run.approve')"));
check('paid/disburse requires payroll.run.disburse', contains($payroll, "rbac_legacy_require(\$user, 'payroll.run.disburse')"));
check('approve requires computed status', contains($payroll, "_payrollRequireStatus(\$run, ['computed'], 'Approve')"));
check('paid requires approved status', contains($payroll, "_payrollRequireStatus(\$run, ['approved'], 'Mark paid')"));
check('builder cannot approve own run', contains($payroll, '_payrollDenyBuildApproveSameActor($runId, $user)'));
check('approver cannot mark paid/originate', contains($payroll, '_payrollDenySameActor((int) ($run[\'approved_by\'] ?? 0), $user'));
check('approver cannot manually submit run to Gusto', contains($payroll, 'Approver cannot submit the same payroll run to Gusto'));
check('approver cannot API-submit run to Gusto', contains($gustoSubmit, '_gustoSubmitDenySameActor((int) ($run[\'approved_by\'] ?? 0), $ctx[\'user\']'));
check('payroll manifest permissions mapped', contains($legacyMap, "'payroll.run.approve'") && contains($legacyMap, "'payroll.run.build'") && contains($legacyMap, "'payroll.run.disburse'"));

echo PHP_EOL . "Placement controls" . PHP_EOL;
check('create active placement is rejected', contains($placements, 'Placements cannot be created active'));
check('bulk active status requires approved rate coverage', contains($placements, "_placementsRequireActiveReady(\$pid"));
check('patch active status requires approved rate coverage', contains($placements, "_placementsRequireActiveReady(\n            \$id"));
check('activation requires approved rate coverage', contains($placements, 'cannot become active without an approved rate'));
check('activation readiness is audited', contains($placements, 'placement.activation_rate_verified') && contains($placements, 'placement.activation_blocked_missing_rate'));
$rates = $root . '/modules/placements/api/rates.php';
$rateWorkflow = $root . '/modules/placements/lib/workflow.php';
check('rate approval acts through workflow graph', contains($rates, 'placementsRateWorkflowAct(currentTenantId(), $id, $user'));
check('rate workflow consumes People Graph approval policy', contains($rateWorkflow, "domainPeopleGraphWorkflowApproverResolution('placements', 'rate_snapshot'"));

echo PHP_EOL . "Staffing consumer controls" . PHP_EOL;
check('staffing manifest says consumer/orchestrator', contains($staffingManifest, 'consumes') && contains($staffingManifest, 'not the source-of-truth domain records'));
check('staffing timesheet reads require time view', contains($staffingTimesheets, "rbac_legacy_require(\$user, 'staffing.time.view')"));
check('staffing timesheet writes require create/submit/approve/reject', contains($staffingTimesheets, "staffing.time.create") && contains($staffingTimesheets, "staffing.time.submit") && contains($staffingTimesheets, "staffing.time.approve") && contains($staffingTimesheets, "staffing.time.reject"));
check('staffing readiness reads require payroll/billing view', contains($staffingReadiness, "staffing.payroll.view") && contains($staffingReadiness, "staffing.billing.view"));
check('staffing readiness writes require payroll/billing manage', contains($staffingReadiness, "staffing.payroll.manage") && contains($staffingReadiness, "staffing.billing.manage"));
check('staffing readiness status flips are audited', contains($staffingReadiness, 'staffingReadinessAudit(') && contains($staffingReadiness, 'staffing.readiness.payroll_marked') && contains($staffingReadiness, 'staffing.readiness.billing_marked'));
check('staffing manifest declares readiness audit events', contains($staffingManifest, 'staffing.readiness.payroll_marked') && contains($staffingManifest, 'staffing.readiness.billing_marked'));
check('staffing manifest permissions mapped', contains($legacyMap, "'staffing.time.approve'") && contains($legacyMap, "'staffing.payroll.manage'") && contains($legacyMap, "'staffing.billing.manage'"));

echo PHP_EOL . "People/PII controls" . PHP_EOL;
check('people endpoint requires RBAC', contains($people, "require_once __DIR__ . '/../../../core/RBAC.php'"));
check('GET requires people.view', contains($people, "rbac_legacy_require(\$user, 'people.view')"));
check('POST/PATCH require people.manage', substr_count(file_get_contents($people), "rbac_legacy_require(\$user, 'people.manage')") >= 2);
check('SSN writes require people.pii.manage', contains($people, "rbac_legacy_require(\$user, 'people.pii.manage')"));
check('PII mask uses people.pii.view', contains($people, "rbac_legacy_can(\$user, 'people.pii.view')"));
check('DELETE requires people.terminate', contains($people, "rbac_legacy_require(\$user, 'people.terminate')"));
check('unified gender/marital fields require PII gate', contains($peopleResource, "'dob', 'ssn_last4', 'gender', 'marital_status'"));
check('legacy bank accounts require banking permissions', contains($peopleBankAccounts, "people.banking.view") && contains($peopleBankAccounts, "people.banking.manage"));
check('legacy tax history requires tax permissions', contains($peopleTaxFederal, "people.tax.view") && contains($peopleTaxFederal, "people.tax.manage"));
check('legacy addresses require PII permissions', contains($peopleAddresses, "people.pii.view") && contains($peopleAddresses, "people.pii.manage"));
check('legacy I-9 requires PII permissions', contains($peopleI9, "people.pii.view") && contains($peopleI9, "people.pii.manage"));
check('PII custom field reads redact without PII view', contains($peopleCustomValues, 'pii_redacted') && contains($peopleCustomValues, "rbac_legacy_can(\$user, 'people.pii.view')"));

$failed = array_values(array_filter($checks, static fn($c) => !$c[1]));
echo PHP_EOL . 'Total: ' . (count($checks) - count($failed)) . ' passed, ' . count($failed) . ' failed' . PHP_EOL;
exit($failed ? 1 : 0);
