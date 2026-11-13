<?php
/**
 * Smoke: activation cannot dead-end when an imported placement has source
 * payload data but no placement_rates row.
 *
 * The server must be able to draft the missing rate from the stored JobDiva
 * payload, then approve it through the normal margin/audit helper before
 * the active-status readiness gate runs.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  OK {$msg}\n"; $pass++; }
    else     { echo "  FAIL {$msg}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$rateApprove = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$sourceRepair=(string)file_get_contents("{$ROOT}/core/jobdiva/source_rate_repair.php");
$placements  = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$jobdivaSync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");

echo "\n1. Missing-rate repair helper\n";
$a('rate_approve.php defines placementsEnsureDraftRateFromSourcePayload',
    str_contains($rateApprove, 'function placementsEnsureDraftRateFromSourcePayload(int $placementId, array $user): bool'));
$a('repair uses original source workspace and current owner under one transaction',
    str_contains($sourceRepair,'companyRelationshipAtomic(')&&str_contains($sourceRepair,'jobdivaPlacementWriteContext($pdo,$workspace)'));
$a('repair requires the exact saved Start and candidate identities',
    str_contains($sourceRepair,'jobdivaAssignmentValidate($payload,$external)')&&str_contains($sourceRepair,'jobdivaPersonSourceWorkspaceCheck('));
$a('repair uses exact assignment mirrors without manufacturing source identities',
    str_contains($sourceRepair,'exact_assignment_evidence_required')&&!str_contains($rateApprove,'placementsJobDivaSeedPayloadFromBinding'));
$a('repair locks field maps in the original workspace and uses the canonical writer',
    str_contains($sourceRepair,'enabled=1 FOR UPDATE')&&str_contains($sourceRepair,'jobdivaSyncUpsertPlacementRates($owner,$placementId,$start,$payload,false,$workspace)'));
$a('repair preserves approved and native drafts and requires a same-transaction audit',
    str_contains($sourceRepair,'approved_rate_covers_start')&&str_contains($sourceRepair,'native_draft_requires_review')&&str_contains($sourceRepair,'companySourceAudit('));

echo "\n2. Auto-approve runs repair before looking for draft rows\n";
$repairPos = strpos($rateApprove, 'placementsEnsureDraftRateFromSourcePayload($placementId, $user);');
$selectPos = strpos($rateApprove, 'SELECT id FROM placement_rates', $repairPos ?: 0);
$a('placementsAutoApproveDraftRates invokes source-payload repair first',
    $repairPos !== false && $selectPos !== false && $repairPos < $selectPos);
$a('auto-approve remains financial-approval gated',
    str_contains($rateApprove, "rbac_legacy_can(\$user, 'placements.financials.approve')")
    && strpos($rateApprove, "if (!\$canApprove)") < $repairPos);

echo "\n3. Every activation path gets the same repair/approve pass\n";
$nativeEdit = (string) file_get_contents(__DIR__ . '/../modules/placements/lib/edit.php');
$activateAuto = strpos($nativeEdit, 'placementsAutoApproveDraftRates($id, $user)');
$activateGate = strpos($nativeEdit, 'placementsRequireActiveReady($id,');
$a('native status edits auto-approve before readiness inside the locked transaction',
    $activateAuto !== false && $activateGate !== false && $activateAuto < $activateGate
    && str_contains($nativeEdit, 'placementWithLockedRecord('));
foreach (['activate_action','patch_status','bulk_status'] as $via) {
    $a($via . ' uses the same native rate and readiness path',
        (bool) preg_match('/placementApplyNativeEdit\([^;]+\x27' . $via . '\x27\)/', $placements));
}
$a('active edits from non-draft states also run the auto-approve pass',
    str_contains($nativeEdit, "\$shouldAutoApproveRates = \$newStatus === 'active'"));

echo "\n4. JobDiva writer preserves approved snapshots\n";
$a('writer selects approved_at and prioritizes unapproved open rows',
    str_contains($jobdivaSync, 'approved_at, bill_rate, bill_rate_unit')
    && str_contains($jobdivaSync, 'ORDER BY (approved_at IS NULL) DESC'));
$a('writer only updates unapproved draft rows',
    str_contains($jobdivaSync, 'if ($rateId > 0 && empty($currentRate[\'approved_at\']))'));
$a('writer compares approved economics and returns when current approved row is already valid',
    str_contains($jobdivaSync, '$sameEconomics =')
    && str_contains($jobdivaSync, '$coversPlacementStart')
    && str_contains($jobdivaSync, 'if ($sameEconomics && $coversPlacementStart)'));
$a('writer inserts a draft correction instead of mutating approved rows',
    str_contains($jobdivaSync, 'Fall through to INSERT a draft correction')
    && str_contains($jobdivaSync, '(tenant_id, placement_id, effective_from, effective_to, bill_rate'));
$a('selected C2C profiles force old approved snapshots through a correction',
    str_contains($jobdivaSync, '$needsC2cModelUpgrade')
    && str_contains($jobdivaSync, "array_key_exists('c2c_overhead_rate', \$currentEconomicsSnapshot)"));
$a('C2C inheritance remains distinct from an explicit zero waiver',
    str_contains($jobdivaSync, '$sameNullablePercent')
    && str_contains($jobdivaSync, "'c2c_overhead_pct' => \$c2cOverheadPct")
    && str_contains($jobdivaSync, "array_key_exists('c2c', \$sourceContract['overheads'])")
    && str_contains($jobdivaSync, '$sourceC2cOverheadEnabled ? null : 0.0'));
$a('approval refuses unsafe JobDiva auto-drafts where bill equals pay',
    str_contains($rateApprove, 'function placementsRateIsUnsafeJobDivaAutoDraft')
    && str_contains($rateApprove, 'abs((float) ($rate[\'bill_rate\'] ?? 0) - (float) ($rate[\'pay_rate\'] ?? 0))')
    && str_contains($rateApprove, "source_system = 'jobdiva'")
    && str_contains($rateApprove, 'identical bill and pay values'));

echo "\n5. PHP syntax\n";
foreach ([
    "{$ROOT}/modules/placements/lib/rate_approve.php",
    "{$ROOT}/modules/placements/api/placements.php",
    "{$ROOT}/core/jobdiva/sync.php",
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=====================================\n";
echo "Placement rate source repair smoke: {$pass} OK / {$fail} FAIL\n";
echo "=====================================\n";
exit($fail === 0 ? 0 : 1);
