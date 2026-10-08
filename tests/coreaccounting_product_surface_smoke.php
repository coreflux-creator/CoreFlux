<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$session = $read('session.php');
$app = $read('dashboard/src/App.jsx');
$sidebar = $read('dashboard/src/layout/Sidebar.jsx');
$bills = $read('modules/ap/ui/BillsList.jsx');
$invoices = $read('modules/billing/ui/InvoicesList.jsx');
$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[] = $passed;
    echo ($passed ? 'OK  ' : 'FAIL  ') . $label . PHP_EOL;
};

$check('standalone session returns only finance modules',
    str_contains($session, "getenv('COREFLUX_ENV') === 'coreaccounting'")
    && str_contains($session, "['accounting', 'billing', 'ap', 'treasury']")
    && str_contains($session, "'product_mode' => \$productMode"));
$check('standalone SPA does not restore staffing from demo modules',
    str_contains($app, "data.product_mode === 'coreaccounting'")
    && str_contains($app, '? []')
    && str_contains($app, "session.product_mode !== 'coreaccounting' && moduleId"));
$check('standalone deep links return to accounting overview',
    str_contains($app, "const accountingPath = '/modules/accounting/overview'")
    && str_contains($app, 'if (!allowed) return <Navigate to={accountingPath} replace />;'));
$check('standalone sidebar leads with accounting tasks',
    str_contains($sidebar, 'ACCOUNTING_WORKSPACE_ITEMS')
    && str_contains($sidebar, "label: 'Invoices'")
    && str_contains($sidebar, "label: 'Bills'")
    && str_contains($sidebar, "label: 'Reports'"));
$check('standalone invoice and bill worklists omit staffing time entry points',
    substr_count($invoices, "session?.product_mode !== 'coreaccounting'") >= 2
    && substr_count($bills, "session?.product_mode !== 'coreaccounting'") >= 3);

$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
