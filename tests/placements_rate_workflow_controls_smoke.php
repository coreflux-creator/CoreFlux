<?php
/**
 * Placement rate workflow controls smoke.
 *
 * Locks the alignment rule that placement rates become approved snapshots only
 * after WorkflowGraph approval, with People Graph routing and SoD evidence.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$engine = (string) file_get_contents("{$ROOT}/core/workflow_engine.php");
$workflow = (string) file_get_contents("{$ROOT}/modules/placements/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/placements/lib/workflow_sync.php");
$rateApprove = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$ratesApi = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$placementsApi = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$csvImport = (string) file_get_contents("{$ROOT}/modules/placements/api/csv_import.php");
$jobdiva = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$mig1 = (string) file_get_contents("{$ROOT}/modules/placements/migrations/001_init.sql");
$mig5 = (string) file_get_contents("{$ROOT}/modules/placements/migrations/005_rate_workflow_controls.sql");
$manifest = (string) file_get_contents("{$ROOT}/modules/placements/manifest.php");
$queue = (string) file_get_contents("{$ROOT}/modules/placements/ui/DraftRatesQueue.jsx");
$bootstrap = (string) file_get_contents("{$ROOT}/core/api_bootstrap.php");

echo "Files parse\n";
foreach ([
    'core/workflow_engine.php',
    'modules/placements/lib/workflow.php',
    'modules/placements/lib/workflow_sync.php',
    'modules/placements/lib/rate_approve.php',
    'modules/placements/api/rates.php',
    'modules/placements/api/placements.php',
    'modules/placements/api/csv_import.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nWorkflowGraph routing\n";
$a('WorkflowEngine routes placement_rate subject',
    str_contains($engine, "\$subjectType === 'placement_rate'")
    && str_contains($engine, 'placementsSyncRateFromWorkflow('));
$a('workflow bridge consumes domain People Graph',
    str_contains($workflow, "domainPeopleGraphWorkflowApproverResolution('placements', 'rate_snapshot'"));
$a('workflow steps strip per-rate ids for reusable definition',
    str_contains($workflow, "unset(\$resolution['resource_id'], \$resolution['object_id']);"));
$a('workflow starts placement_rate subject',
    str_contains($workflow, "workflowStart(\$tenantId, \$defKey, 'placement_rate'"));
$a('workflow action uses WorkflowEngine approve',
    str_contains($workflow, "workflowAct(\n            \$tenantId")
    && str_contains($workflow, "'approve'"));
$a('workflow payload carries SoD blockers',
    str_contains($workflow, 'placementsRateWorkflowSodBlockedUserIds(')
    && str_contains($workflow, "'sod_blocked_user_ids' => \$blocked"));
$a('workflow payload identifies placements.rate_snapshot resource',
    str_contains($workflow, "'resource_module' => 'placements'")
    && str_contains($workflow, "'resource_type' => 'rate_snapshot'")
    && str_contains($workflow, "'approval_resource' => 'placements.rate_snapshot'"));

echo "\nAPI rate approvals\n";
$a('rates API requires workflow bridge',
    str_contains($ratesApi, "/../lib/workflow.php"));
$a('rate create starts workflow and fails closed',
    str_contains($ratesApi, 'placementsRateWorkflowStart(currentTenantId(), $id')
    && str_contains($ratesApi, 'Could not start placement rate approval workflow'));
$a('single approve acts through workflow, not direct writer',
    str_contains($ratesApi, 'placementsRateWorkflowAct(currentTenantId(), $id, $user')
    && !preg_match("/action === 'approve'[\\s\\S]{0,250}placementsRateApproveOne\\(/", $ratesApi));
$a('single approve maps SoD/approver blocks to 403',
    str_contains($ratesApi, 'Separation of duties')
    && str_contains($ratesApi, 'not an approver')
    && str_contains($ratesApi, 'api_error($msg, 403)'));
$a('bulk approve acts each row through workflow',
    str_contains($ratesApi, 'placementsRateWorkflowAct(currentTenantId(), $rid, $user'));
$a('bulk approve blocks correction drafts',
    str_contains($ratesApi, 'Correction rate requires single-row approval workflow'));
$a('bulk response surfaces pending workflows',
    str_contains($ratesApi, "'pending' => \$pending")
    && str_contains($queue, 'pending workflow'));

echo "\nWorkflow sync and snapshot lock\n";
$a('sync locks approved snapshot via tenant-explicit writer',
    str_contains($sync, 'placementsRateApproveOneForTenant($tenantId, $rateId'));
$a('sync auto-detects supersede corrections',
    str_contains($sync, '$hasPriorApproved')
    && str_contains($sync, 'Rate update (auto-detected supersede of prior approved row)'));
$a('sync emits workflow snapshot audit',
    str_contains($sync, "placement.rate.workflow_snapshot_locked"));
$a('rate writer remains transactionally locked',
    str_contains($rateApprove, 'function placementsRateApproveOneForTenant(')
    && str_contains($rateApprove, '$pdo->beginTransaction()')
    && str_contains($rateApprove, '$pdo->commit()')
    && str_contains($rateApprove, '$pdo->rollBack()'));
$a('auto approve uses workflow and does not count pending approvals',
    str_contains($rateApprove, 'placementsRateWorkflowAct(currentTenantId(), (int) $r[\'id\']')
    && str_contains($rateApprove, 'placement.rate.auto_approve_pending_workflow'));

echo "\nImport and integration paths\n";
$a('CSV-created rates start workflow',
    str_contains($csvImport, "/../lib/workflow.php")
    && str_contains($csvImport, 'placementsRateWorkflowStart(currentTenantId(), $rateId'));
$a('JobDiva reads approved_at before rate mutation',
    str_contains($jobdiva, 'SELECT id, approved_at, bill_rate'));
$a('JobDiva updates only unapproved current rate rows',
    str_contains($jobdiva, "if (\$rateId > 0 && empty(\$existingRate['approved_at']))"));
$a('JobDiva approved-row changes insert a new draft rate',
    str_contains($jobdiva, "if (\$rateId > 0 && !empty(\$existingRate['approved_at']))")
    && str_contains($jobdiva, '$newRateId = (int) $pdo->lastInsertId();'));
$a('JobDiva starts workflow for inserted or caught-up draft rate',
    substr_count($jobdiva, 'placementsRateWorkflowStart($tid') >= 2);

echo "\nActivation guard and schema\n";
$a('placement activation still requires approved current rate',
    str_contains($placementsApi, 'cannot become active without an approved rate')
    && str_contains($placementsApi, 'placementCurrentRate($placementId, $asOf)'));
$a('base migration has workflow_instance_id + index',
    str_contains($mig1, 'workflow_instance_id BIGINT UNSIGNED NULL')
    && str_contains($mig1, 'idx_prt_workflow'));
$a('upgrade migration adds workflow column + index',
    str_contains($mig5, 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at')
    && str_contains($mig5, 'ADD INDEX idx_prt_workflow'));
$a('self-heal knows placement_rates.workflow_instance_id',
    str_contains($bootstrap, "'placement_rates'")
    && str_contains($bootstrap, "'workflow_instance_id' => 'ADD COLUMN workflow_instance_id BIGINT UNSIGNED NULL AFTER approved_at'"));

echo "\nManifest events\n";
foreach ([
    'placement.rate.workflow_started',
    'placement.rate.workflow_start_failed',
    'placement.rate.workflow_approved',
    'placement.rate.workflow_snapshot_locked',
    'placement.rate.approval_blocked',
    'placement.rate.approval_rejected',
    'placement.rate.auto_approve_pending_workflow',
] as $event) {
    $a("declares {$event}", str_contains($manifest, "'{$event}'"));
}

echo "\nPlacement rate workflow controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
