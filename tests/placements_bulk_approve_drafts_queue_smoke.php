<?php
/**
 * Smoke — bulk status update on placements + bulk approve on rates +
 * draft rates queue listing. Pure source-level lock-in (PHP CLI cannot
 * connect to MySQL in this sandbox, so live INSERT/UPDATE assertions
 * would fail with a meaningless "No such file or directory" — same
 * pattern other API smoke tests in the suite use to stay green).
 *
 * Locks in:
 *   - placements.php POST ?action=bulk_status — validates rbac,
 *     allowed_status, max 500 ids, audits each row, returns
 *     {updated, skipped, results}
 *   - rates.php POST ?action=bulk_approve     — re-uses the shared
 *     Placement Rate WorkflowGraph bridge so semantics match single
 *     approve (routing, SoD, margin snapshot, supersede, audit)
 *   - rates.php GET  ?action=drafts           — global queue, joined
 *     to placements + people, returns 500-row cap
 *   - single ?action=approve also routes through the shared helper
 *     (no logic drift between bulk + single)
 *   - CsvImportPage component accepts a successCtas prop
 *   - placements CsvImport.jsx passes "View N drafts" CTA + draft
 *     rates CTA derived from imported_count
 *   - PlacementsModule routes /draft-rates to DraftRatesQueue
 *   - List.jsx shows bulk toolbar only on isDraftView
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ROOT = realpath(__DIR__ . '/..');
$placements = (string) file_get_contents("{$ROOT}/modules/placements/api/placements.php");
$rates      = (string) file_get_contents("{$ROOT}/modules/placements/api/rates.php");
$rateApprLib = (string) file_get_contents("{$ROOT}/modules/placements/lib/rate_approve.php");
$rateWorkflow = (string) file_get_contents("{$ROOT}/modules/placements/lib/workflow.php");
$csvImp     = (string) file_get_contents("{$ROOT}/modules/placements/ui/CsvImport.jsx");
$importPg   = (string) file_get_contents("{$ROOT}/dashboard/src/components/CsvImportPage.jsx");
$list       = (string) file_get_contents("{$ROOT}/modules/placements/ui/List.jsx");
$module     = (string) file_get_contents("{$ROOT}/modules/placements/ui/PlacementsModule.jsx");
$queue      = (string) file_get_contents("{$ROOT}/modules/placements/ui/DraftRatesQueue.jsx");

echo "\n1. Placements bulk_status endpoint\n";
$a('handles action=bulk_status',           str_contains($placements, "\$action === 'bulk_status'"));
$a('requires placements.manage',            (bool) preg_match("/action === 'bulk_status'.*?rbac_legacy_require\(\\\$user, 'placements\.manage'\)/s", $placements));
$a('validates ids[] required',              str_contains($placements, "api_error('ids[] required', 422)"));
$a('caps at 500 ids',                       str_contains($placements, "api_error('Too many ids (max 500 per call)', 422)"));
$a('validates status against ALLOWED_STATUS', str_contains($placements, "in_array(\$newStatus, ALLOWED_STATUS, true)"));
$a('coerces ids to positive ints',          str_contains($placements, 'array_map(\'intval\', $body[\'ids\'])')
                                            && str_contains($placements, 'array_filter($ids, static fn ($n) => $n > 0)'));
$a('audits each row with via=bulk_status',  str_contains($placements, "'via'    => 'bulk_status'"));
$a('returns updated + skipped + results',   (bool) preg_match("/'updated'\\s*=>\\s*\\\$updated/", $placements)
                                            && (bool) preg_match("/'skipped'\\s*=>\\s*\\\$skipped/", $placements)
                                            && (bool) preg_match("/'results'\\s*=>\\s*\\\$results/", $placements));
$a('end action remains untouched (regression guard)',
   str_contains($placements, "if (\$action === 'end') {"));

echo "\n2. Rates bulk_approve endpoint + shared helper\n";
$a('shared placementsRateApproveOne() helper defined',
   str_contains($rateApprLib, 'function placementsRateApproveOne(int $rateId, array $user, bool $isCorrection, ?string $correctionReason): array'));
$a('helper begins + commits a transaction',
   (str_contains($rateApprLib, '$pdo->beginTransaction()') || str_contains($rateApprLib, 'cf_tx_begin($pdo)'))
   && (str_contains($rateApprLib, '$pdo->commit()') || str_contains($rateApprLib, 'cf_tx_commit('))
   && (str_contains($rateApprLib, '$pdo->rollBack()') || str_contains($rateApprLib, 'cf_tx_rollback(')));
$a('helper emits placement.rate.approved audit', str_contains($rateApprLib, "placementsAudit('placement.rate.approved'"));
$a('helper supersedes prior approved rows',
   str_contains($rateApprLib, 'SET effective_to = DATE_SUB(:eff_set, INTERVAL 1 DAY)')
   && str_contains($rateApprLib, 'superseded_by = :new_id_set'));
$a('helper computes margin from chain via placementsComputeMargin',
   str_contains($rateApprLib, 'placementsComputeMargin($rate, $chain)'));
$a('single ?action=approve now calls workflow bridge',
   (bool) preg_match("/action === 'approve'.*?placementsRateWorkflowAct\(currentTenantId\(\), \\\$id, \\\$user, \\\$isCorrection, \\\$correctionReason\)/s", $rates));
$a('single approve still maps known errors to 404/409',
   str_contains($rates, "api_error('Rate not found', 404)")
   && str_contains($rates, "api_error('Already approved (snapshot is locked; create a correction)', 409)"));

$a('action=bulk_approve declared with POST',
   str_contains($rates, "if (\$method === 'POST' && \$action === 'bulk_approve')"));
$a('bulk_approve requires placements.financials.approve',
   (bool) preg_match("/action === 'bulk_approve'.*?rbac_legacy_require\(\\\$user, 'placements\\.financials\\.approve'\)/s", $rates));
$a('bulk_approve caps at 200 ids',
   str_contains($rates, "api_error('Too many ids (max 200 per call)', 422)"));
$a('bulk_approve acts through workflow and blocks corrections',
   str_contains($rates, 'placementsRateWorkflowAct(currentTenantId(), $rid, $user, false, null)')
   && str_contains($rates, 'Correction rate requires single-row approval workflow'));
$a('bulk_approve returns approved + pending + failed + results',
   str_contains($rates, "'approved' => \$approved")
   && str_contains($rates, "'pending' => \$pending")
   && str_contains($rates, "'failed' => \$failed")
   && str_contains($rates, "'results' => \$results"));
$a('workflow bridge uses People Graph policy',
   str_contains($rateWorkflow, "domainPeopleGraphWorkflowApproverResolution('placements', 'rate_snapshot'"));

echo "\n3. Rates draft queue endpoint (?action=drafts)\n";
$a('GET branch handles action=drafts',
   str_contains($rates, "if (\$action === 'drafts')"));
$a('drafts query joins placements + people',
   str_contains($rates, 'FROM placement_rates pr')
   && str_contains($rates, 'JOIN placements p ON p.id = pr.placement_id')
   && str_contains($rates, 'LEFT JOIN people pe ON pe.id = p.person_id'));
$a('drafts query filters approved_at IS NULL',
   str_contains($rates, 'pr.approved_at IS NULL'));
$a('drafts query excludes soft-deleted placements',
   str_contains($rates, '(p.deleted_at IS NULL)'));
$a('drafts query caps at 500 rows',
   str_contains($rates, 'LIMIT 500'));
$a('drafts requires placements.financials.view',
   (bool) preg_match("/method === 'GET'.*?rbac_legacy_require\(\\\$user, 'placements\\.financials\\.view'\)/s", $rates));

echo "\n4. CsvImportPage component accepts successCtas prop\n";
$a('successCtas listed in component prop destructure', str_contains($importPg, 'successCtas = null,'));
$a('successCtas is invoked with the commit result',
   str_contains($importPg, 'typeof successCtas === \'function\' ? (successCtas(committed) || [])'));
$a('rendered as Link with primary fallback',
   str_contains($importPg, "c.primary === false ? 'btn' : 'btn btn--primary'"));
$a('does not break "Done" button',
   str_contains($importPg, 'data-testid={`${testidPrefix}-result-back`}'));

echo "\n5. Placements CsvImport wires View N drafts CTA\n";
$a('passes successCtas prop',                 str_contains($csvImp, 'successCtas={(result) =>'));
$a('label includes imported count',           str_contains($csvImp, '`View ${n} draft placement${n === 1 ? \'\' : \'s\'}`'));
$a('routes to list?status=draft',             str_contains($csvImp, "to: '../list?status=draft'"));
$a('second CTA points at draft-rates queue',  str_contains($csvImp, "to: '../draft-rates'"));
$a('skips CTAs when imported_count is zero',  str_contains($csvImp, 'if (n <= 0) return [];'));

echo "\n6. PlacementsModule routes draft-rates → DraftRatesQueue\n";
$a('imports DraftRatesQueue',                  str_contains($module, "import DraftRatesQueue from './DraftRatesQueue';"));
$a('declares <Route path="draft-rates" />',    str_contains($module, '<Route path="draft-rates" element={<DraftRatesQueue />} />'));

echo "\n7. List.jsx bulk toolbar gated to draft view\n";
$a('reads initial status from ?status= URL param',
   str_contains($list, "searchParams.get('status') ?? 'active'"));
$a('isDraftView = (status === draft)',         str_contains($list, "const isDraftView = status === 'draft';"));
$a('promotion targets exclude terminal states',
   str_contains($list, "const PROMOTE_TO = ['pending_start', 'active', 'on_hold'];"));
$a('bulk toolbar testid present',              str_contains($list, "data-testid=\"placements-bulk-toolbar\""));
$a('bulk toolbar hidden when not on draft view',
   str_contains($list, '{isDraftView && rows.length > 0 && (')
   || str_contains($list, '{isDraftView && items.length > 0 && ('));
$a('select-all checkbox testid',               str_contains($list, "data-testid=\"placements-bulk-select-all\""));
$a('per-row select testid uses row id',        str_contains($list, 'data-testid={`placement-row-select-${p.id}`}'));
$a('bulk button per status target',            str_contains($list, 'data-testid={`placements-bulk-to-${s}`}'));
$a('confirms before bulk update',              str_contains($list, 'if (!confirm(`Mark ${selected.size} placement'));
$a('POSTs to bulk_status endpoint',            str_contains($list, "/modules/placements/api/placements.php?action=bulk_status"));
$a('reloads list after successful bulk',       str_contains($list, 'setSelected(new Set());'));
$a('Draft rates queue button shows in header', str_contains($list, 'data-testid="placements-draft-rates-btn"'));

echo "\n8. DraftRatesQueue page contracts\n";
$a('fetches /modules/placements/api/rates.php?action=drafts',
   str_contains($queue, "'/modules/placements/api/rates.php?action=drafts'"));
$a('POSTs to bulk_approve endpoint',
   str_contains($queue, "'/modules/placements/api/rates.php?action=bulk_approve'"));
$a('confirm copy routes through workflow approval',
   str_contains($queue, 'Route ${selected.size} draft rate')
   && str_contains($queue, 'Completed workflow approvals lock the snapshot'));
$a('result copy surfaces pending workflows',
   str_contains($queue, 'pending workflow'));
$a('renders per-rate Review link to placement Rates tab',
   str_contains($queue, 'to={`../${r.placement_id}/rates`}'));
$a('empty state when no drafts',
   str_contains($queue, 'No draft rates pending approval'));
$a('select-all + per-rate select test ids',
   str_contains($queue, 'data-testid="placements-draft-rates-select-all"')
   && str_contains($queue, 'data-testid={`draft-rate-select-${r.id}`}'));

echo "\n9. PHP syntax\n";
foreach ([
    'modules/placements/api/placements.php',
    'modules/placements/api/rates.php',
] as $rel) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    $a("php -l {$rel}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placements bulk approve + drafts queue smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
