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
 *     placementsRateApproveOne() helper so semantics match single
 *     approve (margin snapshot, supersede, audit)
 *   - rates.php GET  ?action=drafts           — global queue, joined
 *     to placements + people, returns 500-row cap
 *   - single ?action=approve also routes through the shared helper
 *     (no logic drift between bulk + single)
 *   - CsvImportPage component accepts a successCtas prop
 *   - placements CsvImport.jsx passes "View N drafts" CTA + draft
 *     rates CTA derived from imported_count
 *   - PlacementsModule routes /draft-rates to DraftRatesQueue
 *   - List.jsx exposes the shared bulk editor on every placement view
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$root = dirname(__DIR__);
$placements = (string) file_get_contents($root . '/modules/placements/api/placements.php');
$rates      = (string) file_get_contents($root . '/modules/placements/api/rates.php');
$rateApprLib = (string) file_get_contents($root . '/modules/placements/lib/rate_approve.php');
$csvImp     = (string) file_get_contents($root . '/modules/placements/ui/CsvImport.jsx');
$importPg   = (string) file_get_contents($root . '/dashboard/src/components/CsvImportPage.jsx');
$list       = (string) file_get_contents($root . '/modules/placements/ui/List.jsx');
$module     = (string) file_get_contents($root . '/modules/placements/ui/PlacementsModule.jsx');
$queue      = (string) file_get_contents($root . '/modules/placements/ui/DraftRatesQueue.jsx');

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
$a('helper computes the canonical economics approval snapshot',
   str_contains($rateApprLib, 'placementEconomicsApprovalSnapshot((int) currentTenantId(), $rate)'));
$a('single ?action=approve now calls placementsRateApproveOne',
   (bool) preg_match("/action === 'approve'.*?placementsRateApproveOne\(\\\$id, \\\$user, \\\$isCorrection, \\\$correctionReason\)/s", $rates));
$a('single approve still maps known errors to 404/409',
   str_contains($rates, "api_error('Rate not found', 404)")
   && str_contains($rates, "api_error('Already approved (snapshot is locked; create a correction)', 409)"));

$a('action=bulk_approve declared with POST',
   str_contains($rates, "if (\$method === 'POST' && \$action === 'bulk_approve')"));
$a('bulk_approve requires placements.financials.approve',
   (bool) preg_match("/action === 'bulk_approve'.*?rbac_legacy_require\(\\\$user, 'placements\\.financials\\.approve'\)/s", $rates));
$a('bulk_approve caps at 500 ids to match the draft queue',
   str_contains($rates, "api_error('Too many ids (max 500 per call)', 422)"));
$a('bulk_approve never sets is_correction=true (forces per-row path)',
   str_contains($rates, '$r = placementsRateApproveOne($rid, $user, false, null);'));
$a('bulk_approve returns approved + failed + results',
   str_contains($rates, "'approved' => \$approved")
   && str_contains($rates, "'failed' => \$failed")
   && str_contains($rates, "'results' => \$results"));

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

echo "\n7. List.jsx shared bulk editor\n";
$a('reads initial status from ?status= URL param',
   str_contains($list, "searchParams.get('status') ?? 'active'"));
$a('imports the shared BulkEditBar',            str_contains($list, "components/BulkEditBar"));
$a('bulk editor is available for all statuses', str_contains($list, '<BulkEditBar')
                                                && !str_contains($list, 'isDraftView &&'));
$a('select-all checkbox testid',               str_contains($list, "data-testid=\"placements-bulk-select-all\""));
$a('per-row select testid uses row id',        str_contains($list, 'data-testid={`placement-row-select-${p.id}`}'));
$a('shared editor has placement test id',       str_contains($list, 'testid="placements-bulk"'));
$a('confirms before bulk update',               str_contains($list, 'if (!confirm(`Change ${label.toLowerCase()}'));
$a('POSTs to bulk_status endpoint',            str_contains($list, "/modules/placements/api/placements.php?action=bulk_status"));
$a('POSTs other fields to bulk_update',         str_contains($list, "/modules/placements/api/placements.php?action=bulk_update"));
$a('reloads list after successful bulk',        str_contains($list, 'bustApiCachePrefix(\'placements-list:\')'));
$a('Draft rates queue button shows in header', str_contains($list, 'data-testid="placements-draft-rates-btn"'));

echo "\n8. DraftRatesQueue page contracts\n";
$a('fetches /modules/placements/api/rates.php?action=drafts',
   str_contains($queue, "'/modules/placements/api/rates.php?action=drafts'"));
$a('POSTs to bulk_approve endpoint',
   str_contains($queue, "'/modules/placements/api/rates.php?action=bulk_approve'"));
$a('confirm copy warns about snapshot lock',
   str_contains($queue, 'Each approval locks the snapshot'));
$a('renders per-rate Review link to placement Rates tab',
   str_contains($queue, 'to={`../${r.placement_id}/rates`}'));
$a('empty state when no drafts',
   str_contains($queue, 'No draft rates pending approval'));
$a('select-all + per-rate select test ids',
   str_contains($queue, 'data-testid="placements-draft-rates-select-all"')
   && str_contains($queue, 'data-testid={`draft-rate-select-${r.id}`}'));
$a('queue can approve every currently shown draft rate',
   str_contains($queue, 'const approveFiltered = async () =>')
   && str_contains($queue, 'items.map(r => Number(r.id))')
   && str_contains($queue, 'data-testid="placements-draft-rates-approve-filtered-btn"')
   && str_contains($queue, 'Approve all shown ({items.length})'));

echo "\n9. PHP syntax\n";
foreach ([
    $root . '/modules/placements/api/placements.php',
    $root . '/modules/placements/api/rates.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Placements bulk approve + drafts queue smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
