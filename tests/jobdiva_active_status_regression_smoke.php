<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$skip = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ok - {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL - {$label}\n";
};

echo "JobDiva active-status regression smoke\n";
echo "======================================\n";

$repair = (string) file_get_contents($root . '/scripts/repair_jobdiva_active_status_regression.php');
$rateProbe = (string) file_get_contents($root . '/scripts/jobdiva_rate_write_probe.php');
$workflowPath = $root . '/.github/workflows/deploy-jobdiva-reconciliation.yml';
$workflow = is_file($workflowPath) ? (string) file_get_contents($workflowPath) : null;
require_once $root . '/core/jobdiva/assignment_contract.php';

$service=(string)file_get_contents($root.'/core/jobdiva/active_status_review.php');
$assert('web requests cannot invoke the maintenance command',str_contains($repair,"PHP_SAPI!=='cli'"));
$assert('command exposes separate preview and reviewed apply operations',str_contains($repair,"['preview','apply']")&&str_contains($repair,'jobdivaActiveRestoreReview')&&str_contains($repair,'jobdivaActiveRestoreApply'));
$assert('review and apply share a serializable transaction boundary',str_contains($service,'JobdivaStoredReviewTransaction::run')&&str_contains($service,'jobdivaLiveContractSnapshot'));
$assert('exact source ownership, worker and approval evidence are checked',str_contains($service,'core_owned_lifecycle')&&str_contains($service,'worker_source_mismatch')&&str_contains($service,'review_approved_rate_coverage')&&str_contains($service,'jobdivaAssignmentContractBuild'));
$assert('actor permissions are checked from current locked records',str_contains($service,'jobdivaActiveRestoreActor')&&str_contains($service,'RBACResolver::resetCache')&&str_contains($service,'FOR UPDATE'));
$strongActiveSnapshot = [
    'assignment' => [
        'contract_version' => 1,
        'placement_status' => 'active',
        'actual_start' => true,
        'actual_end' => false,
        'closed' => false,
        'salary_closed' => false,
        'approved' => true,
        'salary_approved' => true,
    ],
];
$assert('stored Assignment contract requires complete active evidence before reactivation',
    jobdivaAssignmentContractSnapshotIsStronglyActive($strongActiveSnapshot)
    && !jobdivaAssignmentContractSnapshotIsStronglyActive(array_replace_recursive(
        $strongActiveSnapshot,
        ['assignment' => ['actual_end' => true]]
    ))
    && !jobdivaAssignmentContractSnapshotIsStronglyActive(array_replace_recursive(
        $strongActiveSnapshot,
        ['assignment' => ['salary_closed' => true]]
    )));
$assert('both pending and ended rows require the same active evidence',str_contains($service,"['pending_start','ended']")&&str_contains($service,'jobdivaAssignmentContractSnapshotIsStronglyActive'));
$assert('receipt is required and stale review cannot be applied',str_contains($service,"'jobdiva.active_status_restored'")&&str_contains($service,'hash_equals($token,jobdivaAssignmentRepairFingerprint($snapshot))')&&str_contains($service,"['replayed'=>true]"));
if ($workflow !== null) {
    $assert('deployment accepts explicit restore guards',
        str_contains($workflow, 'restore_active_placement_ids:')
        && str_contains($workflow, 'expected_restore_active_count:')
        && str_contains($workflow, 'expected_active_total_after_restore:')
        && str_contains($workflow, 'restore_active_actor_id:')
        && str_contains($workflow, 'restore_active_review_token:')
        && str_contains($workflow, 'restore_active_request_id:'));
    $assert('deployment packages and executes the guarded repair',
        str_contains($workflow, 'scripts/repair_jobdiva_active_status_regression.php')
        && str_contains($workflow, 'php scripts/repair_jobdiva_active_status_regression.php'));
} else {
    $skip += 2;
    echo "  skip - deployment workflow assertions (workflow is not a production runtime asset)\n";
}
$assert('post-deploy rate audit tolerates placements without a stored payload snapshot',
    str_contains($rateProbe, '$payload = is_array($decodedPayload) ? $decodedPayload : [];')
    && str_contains($rateProbe, 'jobdivaAssignmentContractFromSnapshot($payload)'));
if ($workflow !== null) {
    $assert('deployment verifies the current placements search control',
        str_contains($workflow, "grep -Fq 'Search person, role, client or ID'"));
} else {
    $skip++;
}

foreach (['2 1 1 1','preview 2junk 1 1','preview 2 0 1','apply 2 1 1','unknown 2 1 1'] as $args) {
    $out=[];$code=0;
    exec('php '.escapeshellarg($root.'/scripts/repair_jobdiva_active_status_regression.php').' '.$args.' 2>&1',$out,$code);
    $assert('invalid CLI invocation refuses before database access: '.$args,$code===2&&str_contains(implode("\n",$out),'Usage:'));
}
$syntax = [];
$syntaxCode = 0;
exec('php -l ' . escapeshellarg($root . '/scripts/repair_jobdiva_active_status_regression.php') . ' 2>&1', $syntax, $syntaxCode);
$assert('repair script passes php syntax validation', $syntaxCode === 0);

echo "\n{$pass} passed / {$fail} failed / {$skip} skipped\n";
exit($fail === 0 ? 0 : 1);
