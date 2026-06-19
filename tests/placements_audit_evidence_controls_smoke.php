<?php
/**
 * Placements audit evidence controls smoke.
 *
 * Locks the rule that placement activation and rate approval evidence writes
 * through the platform audit writer with source-row snapshots.
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
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$lib = (string) file_get_contents("{$ROOT}/modules/placements/lib/placements.php");
$workflow = (string) file_get_contents("{$ROOT}/modules/placements/lib/workflow.php");
$sync = (string) file_get_contents("{$ROOT}/modules/placements/lib/workflow_sync.php");
$rateApprove = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$placementsApi = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$ratesApi = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'modules/placements/lib/placements.php',
    'modules/placements/lib/workflow.php',
    'modules/placements/lib/workflow_sync.php',
    'modules/placements/lib/rate_approve.php',
    'modules/placements/api/placements.php',
    'modules/placements/api/rates.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nPlacement audit writer\n";
$a('placementsAudit requires shared platform audit writer',
    str_contains($lib, "require_once __DIR__ . '/../../../core/audit.php'")
    && str_contains($lib, 'platformAuditLogWrite('));
$a('placementsAudit accepts platform audit options',
    str_contains($lib, 'function placementsAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])'));
$a('placementsAudit stamps placement source/object metadata',
    $containsAll($lib, ["'object_type' => placementsAuditObjectType(\$event)", "'source' => \$meta['source'] ?? 'placements'"]));
$a('placementsAudit maps high-risk placement object types',
    $containsAll($lib, ['placement_rate', 'placement_chain', 'placement_commission', 'placement_referral']));
$a('placementsAudit no longer inserts audit_log directly',
    !preg_match('/function placementsAudit[\s\S]*INSERT INTO audit_log/', $lib));
$a('placement row audit helper exists',
    str_contains($lib, 'function placementAuditRow(int $id): ?array')
    && str_contains($lib, "placementsSafeFields('p')"));

echo "\nRate workflow evidence\n";
$a('workflow audit helper uses canonical platform writer',
    str_contains($workflow, "/../../../core/audit.php")
    && str_contains($workflow, 'platformAuditLogWrite')
    && str_contains($workflow, "'object_type' => 'placement_rate'"));
$a('workflow audit helper no longer inserts audit_log directly',
    !preg_match('/function placementsWorkflowAudit[\s\S]*INSERT INTO audit_log/', $workflow));
$a('workflow start snapshots rate before and after routing',
    $containsAll($workflow, [
        '$latest = placementsRateWorkflowRow($tenantId, $rateId) ?? $rate',
        "'before' => \$rate",
        "'after' => \$latest",
    ]));
$a('workflow approval and blocked decisions snapshot rate rows',
    $containsAll($workflow, [
        "placement.rate.workflow_approved",
        "'after' => \$updated",
        "placement.rate.approval_blocked",
        "'after' => \$rate",
    ]));
$a('workflow sync snapshots rejection and snapshot lock',
    $containsAll($sync, [
        "placement.rate.approval_rejected",
        "'before' => \$rate",
        "'after' => \$rate",
        "placement.rate.workflow_snapshot_locked",
        "'after' => \$updated",
    ]));

echo "\nRate snapshot lock evidence\n";
$a('tenant rate audit helper uses canonical platform writer',
    str_contains($rateApprove, 'function placementsRateAuditForTenant(')
    && str_contains($rateApprove, 'platformAuditLogWrite')
    && !preg_match('/function placementsRateAuditForTenant[\s\S]*INSERT INTO audit_log/', $rateApprove));
$a('rate approval snapshots draft and approved rows',
    $containsAll($rateApprove, [
        '$approvedRate = placementsRateAuditRowForTenant($tenantId, $rateId) ?? $rate',
        "placement.rate.approved",
        "'before' => \$rate",
        "'after' => \$approvedRate",
    ]));
$a('rate supersede snapshots prior rows before and after closure',
    $containsAll($rateApprove, [
        '$supersededBefore = placementsRateAuditRowsForTenant(',
        '$supersededAfter = placementsRateAuditRowsByIdsForTenant(',
        "placement.rate.superseded",
        "'before' => \$supersededBefore",
        "'after' => \$supersededAfter",
    ]));
$a('rate draft audit captures created row snapshot',
    $containsAll($ratesApi, [
        "placementsAudit('placement.rate.drafted'",
        "'after' => placementsRateAuditRowForTenant(currentTenantId(), \$id)",
    ]));

echo "\nActivation and status evidence\n";
$a('activation readiness snapshots placement row',
    $containsAll($placementsApi, [
        '$placement = placementAuditRow($placementId)',
        "placement.activation_rate_verified",
        "placement.activation_blocked_missing_rate",
        "'before' => \$placement",
        "'after' => \$placement",
    ]));
$a('activate action snapshots status transition',
    $containsAll($placementsApi, [
        '$before = placementAuditRow($id) ?? $placement',
        "placement.status_changed",
        "'via' => 'activate_action'",
        "'after' => placementAuditRow(\$id)",
    ]));
$a('bulk status and PATCH snapshot placement transitions',
    $containsAll($placementsApi, [
        '$before = placementAuditRow($pid) ?? $prior',
        "'after' => placementAuditRow(\$pid)",
        '$before = placementAuditRow($id) ?? $existing',
        "'after' => placementAuditRow(\$id)",
    ]));
$a('create/end/override audits carry source snapshots',
    $containsAll($placementsApi, [
        "placement.override_cleared",
        "placement.ended",
        "placement.created",
        "'after' => placementAuditRow(\$id)",
    ]));

echo "\nDocs\n";
$a('audit governance names placement controls',
    str_contains($auditDoc, 'Placement activation and rate approvals'));
$a('architecture alignment records placement audit evidence status',
    $containsAll($alignment, [
        'Placement Activation And Rates',
        '`placementsAudit`, `placementsWorkflowAudit`, and placement rate tenant-audit',
        'Rate drafting, WorkflowGraph start, approval/rejection sync, snapshot locking',
    ]));

echo "\nPlacements audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
