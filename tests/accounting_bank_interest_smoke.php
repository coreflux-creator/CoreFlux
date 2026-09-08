<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = 0;
$assert = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('modules/accounting/migrations/027_general_account_terms.sql');
$library = $read('modules/accounting/lib/account_interest.php');
$termsApi = $read('modules/accounting/api/account_terms.php');
$accountsApi = $read('modules/accounting/api/accounts.php');
$bankApi = $read('modules/accounting/api/bank_accounts.php');
$reconApi = $read('modules/accounting/api/reconciliations.php');
$packet = $read('modules/accounting/lib/reconciliation_packet.php');
$bankUi = $read('modules/accounting/ui/BankReconciliation.jsx');
$accountUi = $read('modules/accounting/ui/AccountDetail.jsx');
$accountingModule = $read('modules/accounting/ui/AccountingModule.jsx');
$accountLink = $read('dashboard/src/components/AccountLink.jsx');
$worker = $read('cron/accounting_outbox_worker.php');
$treasuryOverview = $read('modules/treasury/ui/TreasuryOverview.jsx');
$treasuryTransactions = $read('modules/treasury/ui/AccountTransactions.jsx');
$savedRules = $read('modules/treasury/ui/SavedRules.jsx');
$missingDimensions = $read('dashboard/src/pages/MissingDimensions.jsx');
$draftReview = $read('dashboard/src/pages/JeDraftsReview.jsx');

$assert('general terms are scoped to account and legal entity',
    str_contains($migration, 'accounting_account_terms')
    && str_contains($migration, 'uq_aat_tenant_account_entity'));
$assert('interest runs are unique per ledger account, entity, and period',
    str_contains($migration, 'uq_aair_account_period'));
$assert('migration preserves old bank terms without inventing a posting date',
    str_contains($migration, 'FROM accounting_bank_account_terms')
    && str_contains($migration, 'NULL, 1, aa.id'));
$assert('account workspace exposes general terms and scheduling',
    str_contains($accountUi, 'accounting-account-terms-form')
    && str_contains($accountUi, 'Counterparty')
    && str_contains($accountUi, 'Next period end'));
$assert('account routes support id and code navigation',
    str_contains($accountingModule, 'path="accounts/:id"')
    && str_contains($accountingModule, 'path="accounts/detail"')
    && str_contains($accountLink, 'accountCode'));
$assert('treasury accounts link to the shared account workspace',
    str_contains($treasuryOverview, 'AccountLink')
    && str_contains($treasuryOverview, 'accountId={r.gl_account_id}')
    && str_contains($treasuryOverview, 'accountId={r.id}')
    && str_contains($treasuryTransactions, 'accountId={category.account_id}')
    && str_contains($savedRules, 'accountId={r.account_id}'));
$assert('accounting exception queues link to the shared account workspace',
    str_contains($missingDimensions, 'accountId={a.account_id}')
    && str_contains($missingDimensions, 'accountId={r.account_id}')
    && str_contains($draftReview, 'accountId={ln.account_id}'));
$assert('account API can resolve account by id or code',
    str_contains($accountsApi, "!empty(\$_GET['id']) || !empty(\$_GET['code'])"));
$assert('terms API validates entity-specific asset and liability interest',
    str_contains($termsApi, "['asset','liability']")
    && str_contains($termsApi, 'next_post_date'));
$assert('posting uses the central JE service with stable account/entity/period idempotency',
    str_contains($library, 'accountingPostJe(')
    && str_contains($library, "'account_interest:' . (int) \$terms['account_id'] . ':' . (int) \$terms['entity_id'] . ':' . \$periodEnd")
    && str_contains($library, '?string $excludeIdempotencyKey = null'));
$assert('shared accounting worker runs due schedules',
    str_contains($worker, 'accountInterestRunDueAllTenants(')
    && str_contains($library, 'next_post_date < :d'));
$assert('bank reconciliation no longer owns or triggers interest',
    !str_contains($reconApi, 'accountInterestProcessReconciliation(')
    && !str_contains($bankApi, "\$action === 'terms'")
    && !str_contains($packet, "'interest_run'")
    && !str_contains($bankUi, 'AccountInterestTerms'));

require_once $root . '/modules/accounting/lib/account_interest.php';
$assert('inclusive day count covers a 31-day period', accountInterestDayCount('2026-01-01', '2026-01-31') === 31);
$assert('actual/365 interest rounds to cents', accountInterestCalculate(1000, 12, '2026-01-01', '2026-01-31', 'actual_365') === 10.19);
$assert('actual/360 interest rounds to cents', accountInterestCalculate(1000, 12, '2026-01-01', '2026-01-30', 'actual_360') === 10.0);
$average = accountInterestAverageDailyBalance(1000, '2026-01-01', '2026-01-03', [
    ['posted_date' => '2026-01-02', 'amount' => -300],
    ['posted_date' => '2026-01-03', 'amount' => 100],
]);
$assert('average daily balance uses each end-of-day ledger balance', abs($average - 833.333333) < 0.000001);
$assert('monthly cadence preserves month end', accountInterestShiftDate('2026-01-31', 'monthly') === '2026-02-28');
$assert('quarterly cadence preserves month end', accountInterestShiftDate('2026-11-30', 'quarterly') === '2027-02-28');
$assert('annual cadence preserves leap-day safely', accountInterestShiftDate('2028-02-29', 'annual') === '2029-02-28');
$assert('weekly cadence advances seven days', accountInterestShiftDate('2026-09-08', 'weekly') === '2026-09-15');

if ($failures > 0) exit(1);
echo "General account terms and interest smoke passed.\n";
