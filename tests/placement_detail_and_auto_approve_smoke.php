<?php
/**
 * Smoke — placement approval UX overhaul:
 *
 *   1. placementGet() now LEFT JOINs `people` and `companies` so the
 *      detail page can show the person's name + email + classification
 *      + work-auth, and the end-client FK resolves to a clickable
 *      company. Operator complaint: "It doesn't even have the NAME?!"
 *
 *   2. PlacementDetail Overview tab renders 5 sections (Person,
 *      Engagement, Client+approver, JobDiva metadata, Notes) covering
 *      every safe-to-display column from the schema.
 *
 *   3. Initial placement approval (draft → non-terminal) auto-approves
 *      the placement's draft rate rows. Operator request: "initial
 *      placement approval should include approved rates."
 *      - Wired in PATCH and bulk_status.
 *      - Soft-gated by RBAC inside placementsAutoApproveDraftRates()
 *        so a recruiter without financials.approve can't escalate.
 *
 *   4. Single-rate approve auto-detects "is this a correction?" from
 *      DB state — no more confirm() / prompt() popup in the UI.
 *      Operator request: "updates are already updates to previously
 *      approved items. shouldn't need the popup to ask."
 *      - Server: prior-approved-row probe sets is_correction=true and
 *        auto-generates a reason if none provided.
 *      - UI: approve() handler POSTs an empty body, logs an info line
 *        when server returns auto_correction=true.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$lib        = (string) file_get_contents("{$ROOT}/modules/placements/lib/placements.php");
$rates      = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$placements = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$rateAppr   = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$detail     = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementDetail.jsx");

echo "\n1. placementGet() joins people + companies\n";
$a('joins people for person_first_name / person_last_name / person_email_primary',
   str_contains($lib, 'pe.first_name        AS person_first_name')
   && str_contains($lib, 'pe.last_name         AS person_last_name')
   && str_contains($lib, 'pe.email_primary     AS person_email_primary'));
$a('joins people for phone + classification + work auth',
   str_contains($lib, 'pe.phone_primary     AS person_phone_primary')
   && str_contains($lib, 'pe.classification    AS person_classification')
   && str_contains($lib, 'pe.work_auth_status  AS person_work_auth_status')
   && str_contains($lib, 'pe.work_auth_expiry  AS person_work_auth_expiry'));
$a('joins companies for end_client_company_name + website',
   str_contains($lib, 'ec.name              AS end_client_company_name')
   && str_contains($lib, 'ec.website           AS end_client_company_website'));
$a('LEFT JOIN companies on end_client_company_id',
   str_contains($lib, 'LEFT JOIN companies ec ON ec.id = p.end_client_company_id'));
$a('still scoped by tenant_id + deleted_at',
   str_contains($lib, 'WHERE p.tenant_id = :tenant_id AND p.id = :id AND p.deleted_at IS NULL'));

echo "\n2. Detail page Overview surfaces every relevant field\n";
$a('person section renders Name field',
   str_contains($detail, 'data-testid="tab-overview-section-person"')
   && str_contains($detail, 't="overview-person-name"'));
$a('person section renders email as mailto + phone + classification',
   str_contains($detail, 'overview-person-email')
   && str_contains($detail, 'overview-person-phone')
   && str_contains($detail, 'overview-person-classification'));
$a('person section renders work auth + expiry',
   str_contains($detail, 'overview-person-work-auth')
   && str_contains($detail, 'overview-person-work-auth-expiry'));
$a('person name links to /modules/people/{id}',
   str_contains($detail, 'href={personLink}')
   && str_contains($detail, 'data-testid="overview-person-name-link"'));
$a('client section renders approver name + email',
   str_contains($detail, 'overview-approver-name')
   && str_contains($detail, 'overview-approver-email'));
$a('client section shows tokenised email + bulk pre-approval flags',
   str_contains($detail, 'overview-token-email')
   && str_contains($detail, 'overview-bulk-preapprove'));
$a('client section links end_client_company_id when present',
   str_contains($detail, 'data-testid="overview-end-client-link"'));
$a('JobDiva section gated to JobDiva-sourced placements',
   str_contains($detail, 'data-testid="tab-overview-section-jobdiva"')
   && str_contains($detail, '(fromJD || placement.jobdiva_job_id || placement.recruiter_name || placement.account_manager_name)'));
$a('JobDiva section renders job id + recruiter + AM',
   str_contains($detail, 'overview-jd-job-id')
   && str_contains($detail, 'overview-recruiter-name')
   && str_contains($detail, 'overview-am-name'));
$a('notes section renders multi-line (whitespace: pre-wrap)',
   str_contains($detail, 'data-testid="tab-overview-section-notes"')
   && str_contains($detail, "whiteSpace: 'pre-wrap'"));
$a('header surfaces person name link + email mailto',
   str_contains($detail, 'data-testid="placement-detail-person-link"')
   && str_contains($detail, 'data-testid="placement-detail-person-email"'));

echo "\n3. Rate approve helper extracted to lib/rate_approve.php\n";
$a('rate_approve.php defines placementsRateApproveOne',
   str_contains($rateAppr, 'function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array'));
$a('rate_approve.php defines placementsAutoApproveDraftRates',
   str_contains($rateAppr, 'function placementsAutoApproveDraftRates(int $placementId, array $user): int'));
$a('auto-approve helper is RBAC-gated via rbac_legacy_can (soft skip, no 403)',
   str_contains($rateAppr, "rbac_legacy_can(\$user, 'placements.financials.approve')")
   && str_contains($rateAppr, 'if (!$canApprove) {'));
$a('auto-approve helper iterates each draft rate row',
   str_contains($rateAppr, 'placement_id = :pid AND approved_at IS NULL')
   && str_contains($rateAppr, "ORDER BY id ASC"));
$a('auto-approve helper acts through WorkflowGraph',
   str_contains($rateAppr, 'placementsRateWorkflowAct(currentTenantId(), (int) $r[\'id\']')
   && str_contains($rateAppr, 'placement.rate.auto_approve_pending_workflow'));
$a('auto-approve catches per-rate errors with auto_approve_failed audit',
   str_contains($rateAppr, "placementsAudit('placement.rate.auto_approve_failed'"));
$a('rates.php requires the new lib',
   str_contains($rates, "require_once __DIR__ . '/../lib/rate_approve.php';"));
$a('rates.php old in-file copy of helper was removed',
   !preg_match('/^function placementsRateApproveOne\(/m', $rates));
$a('placements.php requires the new lib',
   str_contains($placements, "require_once __DIR__ . '/../lib/rate_approve.php';"));

echo "\n4. Single-rate approve auto-detects correction (no popup)\n";
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
$a('UI approve handler no longer calls confirm()',
   !preg_match('/confirm\\(.Is this a correction/', $detail));
$a('UI approve handler no longer calls prompt()',
   !preg_match('/prompt\\(.Correction reason/', $detail));
$a('UI approve handler POSTs an empty body',
   (bool) preg_match('/api\\.post\\(`\\/modules\\/placements\\/api\\/rates\\.php\\?action=approve&id=\\$\\{rateId\\}`, \\{\\}\\)/', $detail));
$a('UI logs auto_correction quietly via console.info',
   str_contains($detail, 'console.info(`Rate ${rateId} approved as a correction'));

echo "\n5. Initial placement approval auto-approves rates (PATCH path)\n";
$a('PATCH calls placementsAutoApproveDraftRates when leaving draft',
   str_contains($placements, '$autoApproved = placementsAutoApproveDraftRates($id, $user);'));
$a('PATCH only triggers on draft → non-terminal',
   (bool) preg_match("/\\(string\\) \\\$existing\\['status'\\] === 'draft'\\s*\\&\\&\\s*!in_array\\(\\(string\\) \\\$body\\['status'\\], \\['draft', 'cancelled'\\], true\\)/", $placements));
$a('PATCH emits placement.rates.auto_approved_on_promotion audit',
   str_contains($placements, "placementsAudit('placement.rates.auto_approved_on_promotion'"));
$a('PATCH returns rates_auto_approved in response',
   str_contains($placements, "'rates_auto_approved' => \$autoApproved + \$activatedAutoApproved"));

echo "\n6. bulk_status also fires the auto-approve side effect\n";
$a('bulk_status captures pre-update status per row',
   str_contains($placements, 'SELECT id, status, start_date FROM placements WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL'));
$a('bulk_status calls auto-approve when prior=draft and target ∉ {draft,cancelled}',
   str_contains($placements, "(string) \$prior['status'] === 'draft'")
   && str_contains($placements, "!in_array(\$newStatus, ['draft', 'cancelled'], true)"));
$a('bulk_status emits per-row auto_approved audit with via=bulk_status',
   str_contains($placements, "'via'             => 'bulk_status'"));
$a('bulk_status response includes per-row rates_auto_approved + total',
   str_contains($placements, "'rates_auto_approved' => \$autoApproved")
   && str_contains($placements, "'rates_auto_approved'  => \$totalAutoApproved"));

echo "\n7. PHP syntax\n";
foreach ([
    'modules/placements/lib/placements.php',
    'modules/placements/lib/rate_approve.php',
    'modules/placements/api/rates.php',
    'modules/placements/api/placements.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placement detail + auto-approve overhaul smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
