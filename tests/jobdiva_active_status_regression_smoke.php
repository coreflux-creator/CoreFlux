<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
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
$workflow = (string) file_get_contents($root . '/.github/workflows/deploy-jobdiva-reconciliation.yml');
require_once $root . '/core/jobdiva/assignment_contract.php';

$selectPos = strpos($repair, 'FOR UPDATE');
$countGuardPos = strpos($repair, 'count($eligibleIds) !== $expectedRestoreCount');
$updatePos = strpos($repair, "SET status = 'active'");
$activeGuardPos = strpos($repair, '$activeTotal !== $expectedActiveTotal');
$commitPos = strpos($repair, '$pdo->commit()');

$assert('repair locks the historical placement rows before deciding eligibility',
    $selectPos !== false);
$assert('repair verifies the exact eligible count before updating',
    $countGuardPos !== false && $updatePos !== false && $countGuardPos < $updatePos);
$assert('repair is limited to JobDiva pending rows that have started and not ended',
    str_contains($repair, "status === 'pending_start'")
    && str_contains($repair, "external_id LIKE 'jd:%'")
    && str_contains($repair, 'start_date <= :start_today')
    && str_contains($repair, 'COALESCE(actual_end_date, end_date)'));
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
$assert('repair admits ended rows only with authoritative active snapshot evidence',
    str_contains($repair, "status === 'ended'")
    && str_contains($repair, '$actualEndDate ===')
    && str_contains($repair, 'jobdivaAssignmentContractSnapshotIsStronglyActive($payload)')
    && str_contains($repair, "source_active_assignment_marked_ended"));
$assert('repair verifies the final active total before commit',
    $activeGuardPos !== false && $commitPos !== false && $activeGuardPos < $commitPos);
$assert('repair writes a durable JobDiva audit record',
    str_contains($repair, "'repair_active_status_regression'")
    && str_contains($repair, 'jobdiva_sync_audit'));
$assert('deployment accepts explicit restore guards',
    str_contains($workflow, 'restore_active_placement_ids:')
    && str_contains($workflow, 'expected_restore_active_count:')
    && str_contains($workflow, 'expected_active_total_after_restore:'));
$assert('deployment packages and executes the guarded repair',
    str_contains($workflow, 'scripts/repair_jobdiva_active_status_regression.php')
    && str_contains($workflow, 'php scripts/repair_jobdiva_active_status_regression.php'));
$assert('deployment verifies the current placements search control',
    str_contains($workflow, "grep -Fq 'Search person, role, client or ID'"));

$syntax = [];
$syntaxCode = 0;
exec('php -l ' . escapeshellarg($root . '/scripts/repair_jobdiva_active_status_regression.php') . ' 2>&1', $syntax, $syntaxCode);
$assert('repair script passes php syntax validation', $syntaxCode === 0);

echo "\n{$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
