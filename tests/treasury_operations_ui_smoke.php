<?php
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $message, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { echo "  PASS {$message}\n"; $pass++; }
    else { echo "  FAIL {$message}\n"; $fail++; }
};
$root = realpath(__DIR__ . '/..');

$ui = (string) file_get_contents("{$root}/modules/treasury/ui/TreasuryOperations.jsx");
$module = (string) file_get_contents("{$root}/modules/treasury/ui/TreasuryModule.jsx");
$overview = (string) file_get_contents("{$root}/dashboard/src/pages/BookkeepingOverview.jsx");
$paymentApi = (string) file_get_contents("{$root}/api/treasury_payments.php");
$transferApi = (string) file_get_contents("{$root}/api/treasury_transfers.php");

$assert('payment and transfer APIs are both wired',
    str_contains($ui, '/api/treasury_payments.php') && str_contains($ui, '/api/treasury_transfers.php'));
$assert('operator can create payments and transfers',
    str_contains($ui, 'New payment') && str_contains($ui, 'New transfer') && str_contains($ui, 'Save draft'));
$assert('bulk submit, approve, and post actions are available',
    str_contains($ui, "onRun('submit'") && str_contains($ui, "onRun('approve'") && str_contains($ui, "onRun('execute'"));
$assert('single-row lifecycle actions are available',
    str_contains($ui, "onAction(row, 'submit')") && str_contains($ui, "onAction(row, 'approve')") && str_contains($ui, "onAction(row, 'execute'"));
$assert('deep-linked items are supported', str_contains($ui, 'const { id: routeId } = useParams()'));
$assert('modern treasury routes are mounted',
    str_contains($module, 'path="payments/:id"') && str_contains($module, 'path="transfers/:id"'));
$assert('bookkeeping shortcuts use mounted module routes',
    str_contains($overview, 'to="/modules/ap/bills"')
    && str_contains($overview, 'to="/modules/treasury/payments"')
    && str_contains($overview, 'to="/modules/treasury/transfers"')
    && str_contains($overview, 'to="/modules/accounting/periods"'));
$assert('bank and journal links use mounted module routes',
    str_contains($overview, 'to="/modules/treasury/deposits"')
    && str_contains($overview, '/modules/accounting/journal-entries/'));
$assert('failure reasons are returned to the queue',
    str_contains($paymentApi, 'failure_reason') && str_contains($transferApi, 'failure_reason'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
