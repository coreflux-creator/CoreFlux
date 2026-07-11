<?php
/**
 * Smoke: placement detail, rate approval, and activation auto-approve flow.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  OK {$msg}\n"; $pass++; }
    else     { echo "  FAIL {$msg}" . ($detail !== '' ? " - {$detail}" : '') . "\n"; $fail++; }
};

$ROOT       = realpath(__DIR__ . '/..');
$lib        = (string) file_get_contents("{$ROOT}/modules/placements/lib/placements.php");
$rates      = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$placements = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$rateAppr   = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$detail     = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementDetail.jsx");

echo "\n1. placementGet() joins people + companies\n";
$a('placementAuditRow helper exists for before/after audit snapshots',
   str_contains($lib, 'function placementAuditRow(int $placementId): ?array')
   && str_contains($lib, 'WHERE p.tenant_id = :tenant_id')
   && str_contains($lib, 'AND p.deleted_at IS NULL'));
$a('joins people for person name/email fields',
   str_contains($lib, 'pe.first_name        AS person_first_name')
   && str_contains($lib, 'pe.last_name         AS person_last_name')
   && str_contains($lib, 'pe.email_primary     AS person_email_primary'));
$a('joins people for phone/work auth without competing person classification',
   str_contains($lib, 'pe.phone_primary     AS person_phone_primary')
   && !str_contains($lib, 'pe.classification    AS person_classification')
   && str_contains($lib, "\$k === 'classification'")
   && str_contains($lib, 'pe.work_auth_status  AS person_work_auth_status')
   && str_contains($lib, 'pe.work_auth_expiry  AS person_work_auth_expiry'));
$a('joins companies for end-client company name + website',
   str_contains($lib, 'ec.name              AS end_client_company_name')
   && str_contains($lib, 'ec.website           AS end_client_company_website'));
$a('LEFT JOIN companies on end_client_company_id',
   str_contains($lib, 'LEFT JOIN companies ec ON ec.id = p.end_client_company_id'));

echo "\n2. Detail page Overview surfaces relevant placement context\n";
$a('person section renders name/email/phone; worker class lives on placement',
   str_contains($detail, 'data-testid="tab-overview-section-person"')
   && str_contains($detail, 'overview-person-name')
   && str_contains($detail, 'overview-person-email')
   && str_contains($detail, 'overview-person-phone')
   && !str_contains($detail, 'overview-person-classification')
   && str_contains($detail, 'Worker classification')
   && str_contains($detail, 'overview-etype'));
$a('person section renders work auth + expiry',
   str_contains($detail, 'overview-person-work-auth')
   && str_contains($detail, 'overview-person-work-auth-expiry'));
$a('person name links to /modules/people/{id}',
   str_contains($detail, 'href={personLink}')
   && str_contains($detail, 'data-testid="overview-person-name-link"'));
$a('client section renders approver, token email, and bulk pre-approval',
   str_contains($detail, 'overview-approver-name')
   && str_contains($detail, 'overview-approver-email')
   && str_contains($detail, 'overview-token-email')
   && str_contains($detail, 'overview-bulk-preapprove'));
$a('JobDiva section is gated to JobDiva-sourced placement context',
   str_contains($detail, 'data-testid="tab-overview-section-jobdiva"')
   && str_contains($detail, '(fromJD || placement.jobdiva_job_id || placement.recruiter_name || placement.account_manager_name)'));
$a('JobDiva section renders job id + recruiter + account manager',
   str_contains($detail, 'overview-jd-job-id')
   && str_contains($detail, 'overview-recruiter-name')
   && str_contains($detail, 'overview-am-name'));
$a('header surfaces person name link + email mailto',
   str_contains($detail, 'data-testid="placement-detail-person-link"')
   && str_contains($detail, 'data-testid="placement-detail-person-email"'));

echo "\n3. Shared rate approval helper\n";
$a('rate_approve.php defines placementsRateApproveOne',
   str_contains($rateAppr, 'function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array'));
$a('rate_approve.php defines placementsAutoApproveDraftRates',
   str_contains($rateAppr, 'function placementsAutoApproveDraftRates(int $placementId, array $user): int'));
$a('rate_approve.php defines source-payload rate repair helper',
   str_contains($rateAppr, 'function placementsEnsureDraftRateFromSourcePayload(int $placementId, array $user): bool'));
$a('auto-approve helper is RBAC-gated via rbac_legacy_can',
   str_contains($rateAppr, "rbac_legacy_can(\$user, 'placements.financials.approve')")
   && str_contains($rateAppr, 'if (!$canApprove) {'));
$repairPos = strpos($rateAppr, 'placementsEnsureDraftRateFromSourcePayload($placementId, $user);');
$scanPos   = strpos($rateAppr, 'SELECT id FROM placement_rates');
$a('auto-approve repairs missing source-backed draft before scanning drafts',
   $repairPos !== false && $scanPos !== false && $repairPos < $scanPos);
$a('auto-approve iterates each draft rate row',
   str_contains($rateAppr, 'placement_id = :pid AND approved_at IS NULL')
   && str_contains($rateAppr, 'ORDER BY id ASC'));
$a('auto-approve catches per-rate errors with audit',
   str_contains($rateAppr, "placementsAudit('placement.rate.auto_approve_failed'"));
$a('rates.php and placements.php require the shared helper',
   str_contains($rates, "require_once __DIR__ . '/../lib/rate_approve.php';")
   && str_contains($placements, "require_once __DIR__ . '/../lib/rate_approve.php';"));
$a('rates.php old in-file copy of helper was removed',
   !preg_match('/^function placementsRateApproveOne\(/m', $rates));

echo "\n4. Single-rate approve auto-detects correction\n";
$a('server probes for prior approved row',
   str_contains($rates, 'AND id != :rid AND approved_at IS NOT NULL')
   && str_contains($rates, '$autoCorrection = (bool) $prior;'));
$a('is_correction OR-ed with autoCorrection',
   str_contains($rates, "\$isCorrection     = !empty(\$body['is_correction']) || \$autoCorrection;"));
$a('server auto-generates reason when missing on auto-detected supersede',
   str_contains($rates, "'Rate update (auto-detected supersede of prior approved row)'"));
$a('correction_reason no longer hard-required at API layer',
   !preg_match('/if \(\$isCorrection && empty\(\$correctionReason\)\) \{\s*api_error/', $rates));
$a('approve response returns auto_correction flag',
   str_contains($rates, "'auto_correction' => \$autoCorrection"));
$a('UI approve handler no longer prompts or confirms correction',
   !preg_match('/confirm\\(.Is this a correction/', $detail)
   && !preg_match('/prompt\\(.Correction reason/', $detail));
$a('UI approve handler POSTs an empty body',
   (bool) preg_match('/api\\.post\\(`\\/modules\\/placements\\/api\\/rates\\.php\\?action=approve&id=\\$\\{rateId\\}`, \\{\\}\\)/', $detail));

echo "\n5. Placement activation auto-approves/repairs rates\n";
$a('PATCH calls placementsAutoApproveDraftRates when leaving draft',
   str_contains($placements, '$autoApproved = placementsAutoApproveDraftRates($id, $user);'));
$a('PATCH preserves draft -> non-terminal promotion detection',
   (bool) preg_match("/\\(string\\) \\\$existing\\['status'\\] === 'draft'\\s*\\&\\&\\s*!in_array\\(\\(string\\) \\\$body\\['status'\\], \\['draft', 'cancelled'\\], true\\)/", $placements));
$a('PATCH active from non-draft calls auto-approve/repair before readiness gate',
   str_contains($placements, "if (!\$promotingFromDraft) {\n            \$autoApproved = placementsAutoApproveDraftRates(\$id, \$user);\n        }"));
$a('POST activate action calls auto-approve/repair before readiness gate',
   strpos($placements, '$autoApproved = placementsAutoApproveDraftRates($id, $user);') !== false
   && strpos($placements, "_placementsRequireActiveReady(\$id, (string) (\$placement['start_date'] ?? date('Y-m-d')), 'activate_action')") !== false
   && strpos($placements, '$autoApproved = placementsAutoApproveDraftRates($id, $user);') < strpos($placements, "_placementsRequireActiveReady(\$id, (string) (\$placement['start_date'] ?? date('Y-m-d')), 'activate_action')"));
$a('promotion audit and response include rates_auto_approved',
   str_contains($placements, "placementsAudit('placement.rates.auto_approved_on_promotion'")
   && str_contains($placements, "'rates_auto_approved' => \$autoApproved"));

echo "\n6. bulk_status also fires the auto-approve side effect\n";
$a('bulk_status captures pre-update status per row',
   str_contains($placements, "'SELECT status FROM placements WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL'"));
$a('bulk_status calls auto-approve for draft promotions and any active promotion',
   str_contains($placements, '$shouldAutoApproveRates = $prior')
   && str_contains($placements, "|| \$newStatus === 'active'"));
$a('bulk_status emits per-row auto_approved audit with via=bulk_status',
   str_contains($placements, "'via'             => 'bulk_status'"));
$a('bulk_status response includes per-row rates_auto_approved + total',
   str_contains($placements, "'rates_auto_approved' => \$autoApproved")
   && str_contains($placements, "'rates_auto_approved'  => \$totalAutoApproved"));

echo "\n7. PHP syntax\n";
foreach ([
    "{$ROOT}/modules/placements/lib/placements.php",
    "{$ROOT}/modules/placements/lib/rate_approve.php",
    "{$ROOT}/modules/placements/api/rates.php",
    "{$ROOT}/modules/placements/api/placements.php",
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placement detail + auto-approve smoke: {$pass} OK / {$fail} FAIL\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
