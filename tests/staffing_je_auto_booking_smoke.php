<?php
/**
 * Smoke: staffing.worker_hours.approved → JE auto-booking via posting rules.
 *
 * Pins:
 *   • Event payload includes engagement_type breakdown (w2 / 1099/c2c / internal).
 *   • Posting-rule seeder installs 3 templates + 3 rules with the right
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

echo "Event emitter — per-engagement-type breakdown\n";
$lib = $read(__DIR__ . '/../modules/staffing/lib/timesheets.php');
$a('GROUP BY engagement_type in query',           str_contains($lib, 'GROUP BY t.id, engagement_type'));
$a('LEFT JOIN placements for engagement_type',    str_contains($lib, 'LEFT JOIN placements pl'));
$a('one event per (timesheet × engagement_type)', str_contains($lib, 'foreach ($groups as $g)'));
$a('payload includes engagement_type',            str_contains($lib, "'engagement_type' => (string) \$g['engagement_type']"));
$a('payload includes is_w2 flag',                 str_contains($lib, "'is_w2'") && str_contains($lib, "=== 'w2' ? 1 : 0"));
$a('payload includes is_1099_or_c2c flag',        str_contains($lib, "'is_1099_or_c2c'") && str_contains($lib, "in_array(\$g['engagement_type'], ['1099','c2c'], true)"));
$a('payload includes is_internal flag',           str_contains($lib, "'is_internal'") && str_contains($lib, "=== 'internal' ? 1 : 0"));
$a('source_record_id includes engagement_type',   str_contains($lib, "(string) \$g['id'] . ':' . \$g['engagement_type']"));

echo "\nSystem accounts — staffing-specific\n";
$sa = $read(__DIR__ . '/../core/accounting/system_accounts.php');
$a('adds Service Revenue (4000)',                 preg_match("/'4000'.*?Service Revenue.*?revenue/s", $sa) === 1);
$a('adds Direct Labor Expense (5000) cogs',       preg_match("/'5000'.*?Direct Labor Expense.*?cogs/s", $sa) === 1);
$a('adds Subcontractor Expense (5010) cogs',      preg_match("/'5010'.*?Subcontractor Expense.*?cogs/s", $sa) === 1);
$a('adds Accrued Payroll (2150) liability',       preg_match("/'2150'.*?Accrued Payroll.*?liability/s", $sa) === 1);
$a('adds Accrued AP (2050) liability',            preg_match("/'2050'.*?Accrued AP.*?liability/s", $sa) === 1);
$a('adds Unbilled Receivable (1150) asset',       preg_match("/'1150'.*?Unbilled Receivable.*?asset/s", $sa) === 1);

echo "\nPosting-rules seeder\n";
$seed = $read(__DIR__ . '/../modules/staffing/lib/posting_rules_seed.php');
$a('function staffingSeedPostingRules defined',   str_contains($seed, 'function staffingSeedPostingRules'));
$a('seeds system accounts first',                 str_contains($seed, 'accountingSeedSystemAccounts'));
$a('W2 template: DR Direct Labor + CR Accrued Payroll', str_contains($seed, "['Direct Labor Expense', 'payload.cost'") && str_contains($seed, "['Accrued Payroll',      '0',               'payload.cost'"));
$a('W2 template: DR Unbilled AR + CR Service Revenue',  str_contains($seed, "['Unbilled Receivable',  'payload.revenue'") && str_contains($seed, "['Service Revenue',      '0',               'payload.revenue'"));
$a('contractor template uses Subcontractor + Accrued AP', str_contains($seed, "['Subcontractor Expense','payload.cost'") && str_contains($seed, "['Accrued AP',           '0',               'payload.cost'"));
$a('internal template has NO revenue leg',        preg_match("/staffing.internal_hours_approved.*?'lines' => \[\s*\['Direct Labor Expense'.*?\['Accrued Payroll'.*?\],\s*\]/s", $seed) === 1);
$a('3 posting rules: w2 / contractor / internal', preg_match('/payload\.is_w2.*?payload\.is_1099_or_c2c.*?payload\.is_internal/s', $seed) === 1);
$a('rules tied to staffing.worker_hours.approved event', str_contains($seed, "'staffing.worker_hours.approved'"));
$a('idempotent — INSERT IGNORE on (tenant, name)',str_contains($seed, 'SELECT id FROM accounting_journal_templates WHERE tenant_id = :t AND name = :n LIMIT 1'));

echo "\nAdmin seed endpoint\n";
$ep = $read(__DIR__ . '/../modules/staffing/api/seed_posting_rules.php');
$a('endpoint requires master_admin role',         str_contains($ep, "api_require_role(['master_admin'])"));
$a('endpoint dispatches to seeder',               str_contains($ep, 'staffingSeedPostingRules(') && str_contains($ep, '$tenantId'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
