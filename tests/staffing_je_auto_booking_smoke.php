<?php
/**
 * Smoke: staffing.worker_hours.approved → JE auto-booking via posting rules.
 *
 * Pins:
 *   • Event payload includes engagement_type breakdown (w2 / 1099/c2c / internal / referral).
 *   • Posting-rule seeder installs 4 templates + 4 rules with the right
 *     account selectors, debit/credit formulas, and condition routing.
 *   • System-account list includes the staffing-specific accounts.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$read = fn (string $p) => (string) file_get_contents($p);

echo "Event emitter — per-assignment breakdown\n";
$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$a('GROUP BY keeps placements separate',          str_contains($lib, 'te.placement_id, engagement_type'));
$a('LEFT JOIN placements for engagement_type',    str_contains($lib, 'LEFT JOIN placements pl'));
$a('one event per timesheet and placement',       str_contains($lib, 'foreach ($groups as $g)') && str_contains($lib, "':placement:' . \$placementId"));
$a('payload includes engagement_type',            str_contains($lib, "'engagement_type' => \$engagementType"));
$a('payload includes is_w2 flag',                 str_contains($lib, "'is_w2'") && str_contains($lib, "in_array(\$engagementType, ['w2','temp_to_perm'], true)"));
$a('payload includes is_1099_or_c2c flag',        str_contains($lib, "'is_1099_or_c2c'") && str_contains($lib, "in_array(\$engagementType, ['1099','c2c'], true)"));
$a('payload includes is_internal flag',           str_contains($lib, "'is_internal'") && str_contains($lib, "=== 'internal' ? 1 : 0"));
$a('payload includes is_referral flag',           str_contains($lib, "'is_referral'") && str_contains($lib, "=== 'referral' ? 1 : 0"));
$a('source identity includes placement and type', str_contains($lib, "':placement:' . \$placementId . ':' . \$engagementType"));
$a('event carries assignment dimensions',         str_contains($lib, 'staffingAssignmentDimensionContext') && str_contains($lib, "'dimensions'      => \$dimensionContext['dimensions']"));
$a('revenue and cost respect entry flags',        str_contains($lib, 'te.billable = 1') && str_contains($lib, 'te.payable <> 1'));
$a('loaded labor costs are included',             str_contains($lib, 'pr.workers_comp_pct') && str_contains($lib, 'pr.c2c_overhead_pct'));
$a('W2 load is emitted as distinct cost components',
    str_contains($lib, 'AS wage_cost')
    && str_contains($lib, 'AS employer_load_cost')
    && str_contains($lib, 'AS workers_comp_cost')
    && str_contains($lib, 'AS benefits_cost')
    && str_contains($lib, 'AS other_direct_cost'));

echo "\nSystem accounts — staffing-specific\n";
$sa = $read(__DIR__ . '/../core/accounting/system_accounts.php');
$a('adds Service Revenue (4000)',                 preg_match("/'4000'.*?Service Revenue.*?revenue/s", $sa) === 1);
$a('adds Direct Labor Expense (5000) cogs',       preg_match("/'5000'.*?Direct Labor Expense.*?cogs/s", $sa) === 1);
$a('adds Subcontractor Expense (5010) cogs',      preg_match("/'5010'.*?Subcontractor Expense.*?cogs/s", $sa) === 1);
$a('adds separated W2 burden accounts',           preg_match("/'5020'.*?Employer Payroll Tax Expense.*?'5050'.*?Workers Compensation Expense.*?'5060'.*?Worker Benefits Expense.*?'5080'.*?Other Direct Placement Cost/s", $sa) === 1);
$a('adds Accrued Payroll (2150) liability',       preg_match("/'2150'.*?Accrued Payroll.*?liability/s", $sa) === 1);
$a('adds Accrued AP (2050) liability',            preg_match("/'2050'.*?Accrued AP.*?liability/s", $sa) === 1);
$a('adds Unbilled Receivable (1150) asset',       preg_match("/'1150'.*?Unbilled Receivable.*?asset/s", $sa) === 1);

echo "\nPosting-rules seeder\n";
$seed = $read(__DIR__ . '/../modules/staffing/lib/posting_rules_seed.php');
$internalStart = strpos($seed, "'staffing.internal_hours_approved' => [");
$referralStart = strpos($seed, "'staffing.referral_hours_approved' => [");
$internalBlock = ($internalStart !== false && $referralStart !== false && $referralStart > $internalStart)
    ? substr($seed, $internalStart, $referralStart - $internalStart)
    : '';
$a('function staffingSeedPostingRules defined',   str_contains($seed, 'function staffingSeedPostingRules'));
$a('seeds system accounts first',                 str_contains($seed, 'accountingSeedSystemAccounts'));
$a('resolves customized system-account names by canonical identity',
    str_contains($seed, 'accountingSystemAccountId($tenantId, $name)'));
$a('W2 template separates wages and burden before accrued payroll',
    str_contains($seed, "['Direct Labor Expense', 'payload.wage_cost'")
    && str_contains($seed, "['Employer Payroll Tax Expense', 'payload.employer_load_cost'")
    && str_contains($seed, "['Workers Compensation Expense', 'payload.workers_comp_cost'")
    && str_contains($seed, "['Worker Benefits Expense', 'payload.benefits_cost'")
    && str_contains($seed, "['Other Direct Placement Cost', 'payload.other_direct_cost'")
    && str_contains($seed, "['Accrued Payroll',      '0',               'payload.cost'"));
$a('W2 template: DR Unbilled AR + CR Service Revenue',  str_contains($seed, "['Unbilled Receivable',  'payload.revenue'") && str_contains($seed, "['Service Revenue',      '0',               'payload.revenue'"));
$a('contractor template uses Subcontractor + Accrued AP', str_contains($seed, "['Subcontractor Expense','payload.cost'") && str_contains($seed, "['Accrued AP',           '0',               'payload.cost'"));
$a('internal template has NO revenue leg',
    str_contains($internalBlock, "['Accrued Payroll'")
    && !str_contains($internalBlock, 'Service Revenue'));
$a('referral template books payout and client revenue', preg_match("/staffing\.referral_hours_approved.*?\['Subcontractor Expense'.*?payload\.cost.*?\['Accrued AP'.*?payload\.cost.*?\['Unbilled Receivable'.*?payload\.revenue.*?\['Service Revenue'.*?payload\.revenue/s", $seed) === 1);
$a('4 posting rules include referral routing',    preg_match('/payload\.is_w2.*?payload\.is_1099_or_c2c.*?payload\.is_internal.*?payload\.is_referral/s', $seed) === 1);
$a('rules tied to staffing.worker_hours.approved event', str_contains($seed, "'staffing.worker_hours.approved'"));
$a('registers assignment dimensions',             str_contains($seed, "['placement',") && str_contains($seed, "['legal_entity',") && str_contains($seed, "['vendor',"));
$a('AP-side lines receive vendor dimension',      substr_count($seed, "['vendor' => 'payload.vendor_dimension']") >= 4);
$a('uses canonical posting-rule columns',         str_contains($seed, 'priority, conditions, journal_template_id, status'));
$a('idempotent and preserves customized rows',
    str_contains($seed, 'SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n LIMIT 1')
    && str_contains($seed, '$isUntouchedLegacy')
    && str_contains($seed, "!in_array(\$dimensionJson, ['', '{}', '[]', 'null'], true)"));
$a('production seed pass installs staffing rules',str_contains($read(__DIR__ . '/../core/seeds/posting_rules_seed_all.php'), 'staffingSeedPostingRules($tenantId)'));

echo "\nAdmin seed endpoint\n";
$ep = $read(__DIR__ . '/../modules/staffing/api/seed_posting_rules.php');
$a('endpoint requires master_admin role',         str_contains($ep, "api_require_role(['master_admin'])"));
$a('endpoint dispatches to seeder',               str_contains($ep, 'staffingSeedPostingRules(') && str_contains($ep, '$tenantId'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
