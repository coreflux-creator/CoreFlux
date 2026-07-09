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
$placements  = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$jobdivaSync = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");

echo "\n1. Missing-rate repair helper\n";
$a('rate_approve.php defines placementsEnsureDraftRateFromSourcePayload',
    str_contains($rateApprove, 'function placementsEnsureDraftRateFromSourcePayload(int $placementId, array $user): bool'));
$a('repair reads JobDiva source bindings by internal placement id',
    str_contains($rateApprove, 'FROM external_entity_mappings')
    && str_contains($rateApprove, "source_system = 'jobdiva'")
    && str_contains($rateApprove, "internal_entity_type = 'placement'")
    && str_contains($rateApprove, 'internal_entity_id = :pid'));
$a('repair prefers payload snapshots but can rebuild from placement binding ids',
    str_contains($rateApprove, 'CASE WHEN payload_snapshot IS NOT NULL AND payload_snapshot <>')
    && str_contains($rateApprove, 'placementsJobDivaSeedPayloadFromBinding($placement, $mapping)')
    && str_contains($rateApprove, '$payload[\'startId\'] = $externalId')
    && str_contains($rateApprove, '$payload[\'jobID\'] = $jobId'));
$a('repair enriches the placement payload with local JobDiva mirrors before resolving rates',
    str_contains($rateApprove, 'jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $mirrorStats)'));
$a('repair delegates rate creation to the canonical JobDiva placement_rates writer',
    str_contains($rateApprove, 'jobdivaSyncUpsertPlacementRates($tenantId, $placementId, $startDate, $payload)'));
$a('repair does not skip unsafe auto-drafted bill=pay rows',
    str_contains($rateApprove, "SELECT id, bill_rate, pay_rate, created_by_user_id")
    && str_contains($rateApprove, '$unsafeAutoDraft = empty($draft[\'created_by_user_id\'])')
    && str_contains($rateApprove, 'if (!$unsafeAutoDraft) return false;'));
$a('repair audits success and unavailable/failure reasons',
    str_contains($rateApprove, "placement.rate.auto_drafted_from_source")
    && str_contains($rateApprove, "placement.rate.auto_draft_from_source_unavailable")
    && str_contains($rateApprove, "placement.rate.auto_draft_from_source_failed"));

echo "\n2. Auto-approve runs repair before looking for draft rows\n";
$repairPos = strpos($rateApprove, 'placementsEnsureDraftRateFromSourcePayload($placementId, $user);');
$selectPos = strpos($rateApprove, 'SELECT id FROM placement_rates', $repairPos ?: 0);
$a('placementsAutoApproveDraftRates invokes source-payload repair first',
    $repairPos !== false && $selectPos !== false && $repairPos < $selectPos);
$a('auto-approve remains financial-approval gated',
    str_contains($rateApprove, "rbac_legacy_can(\$user, 'placements.financials.approve')")
    && strpos($rateApprove, "if (!\$canApprove)") < $repairPos);

echo "\n3. Every activation path gets the same repair/approve pass\n";
$activateAuto = strpos($placements, '$autoApproved = placementsAutoApproveDraftRates($id, $user);');
$activateGate = strpos($placements, "_placementsRequireActiveReady(\$id, (string) (\$placement['start_date'] ?? date('Y-m-d')), 'activate_action')");
$a('POST action=activate auto-approves/repairs before readiness gate',
    $activateAuto !== false && $activateGate !== false && $activateAuto < $activateGate);
$a('PATCH active from non-draft also calls auto-approve/repair',
    str_contains($placements, "if (!\$promotingFromDraft) {\n            \$autoApproved = placementsAutoApproveDraftRates(\$id, \$user);\n        }"));
$a('bulk active transitions call auto-approve/repair even if prior status is not draft',
    str_contains($placements, '$shouldAutoApproveRates = $prior')
    && str_contains($placements, "|| \$newStatus === 'active'"));

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
