<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$session = $read('session.php');
$app = $read('dashboard/src/App.jsx');
$sidebar = $read('dashboard/src/layout/Sidebar.jsx');
$header = $read('dashboard/src/layout/Header.jsx');
$layout = $read('dashboard/src/layout/AppLayout.jsx');
$paths = $read('dashboard/src/lib/coreAccountingPaths.js');
$accounting = $read('modules/accounting/ui/AccountingModule.jsx');
$bookkeeping = $read('dashboard/src/pages/BookkeepingOverview.jsx');
$billingModule = $read('modules/billing/ui/BillingModule.jsx');
$apModule = $read('modules/ap/ui/APModule.jsx');
$apPayments = $read('modules/ap/ui/PaymentsList.jsx');
$treasuryModule = $read('modules/treasury/ui/TreasuryModule.jsx');
$treasuryOverview = $read('modules/treasury/ui/TreasuryOverview.jsx');
$settings = $read('dashboard/src/pages/SettingsPage.jsx');
$admin = $read('dashboard/src/pages/AdminModule.jsx');
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
    && str_contains($app, 'if (!coreAccountingPathAllowed(location.pathname)) return <Navigate to={accountingPath} replace />;')
    && str_contains($paths, "'/settings/mail'")
    && !str_contains($paths, "'/settings/staffing-economics'"));
$check('standalone sidebar leads with accounting tasks',
    str_contains($sidebar, 'ACCOUNTING_WORKSPACE_ITEMS')
    && str_contains($sidebar, "label: 'Invoices'")
    && str_contains($sidebar, "label: 'Bills'")
    && str_contains($sidebar, "label: 'Reports'"));
$check('standalone header links to finance pages and uses curated search',
    str_contains($layout, 'productMode={session.product_mode}')
    && str_contains($header, 'standaloneAccounting ? CORE_ACCOUNTING_SEARCH_DESTINATIONS')
    && str_contains($header, 'to="/modules/accounting/reports"')
    && str_contains($header, 'const homePath = standaloneAccounting ? CORE_ACCOUNTING_HOME')
    && str_contains($paths, "to: '/modules/billing/invoices'"));
$check('standalone Tools omits the unavailable AI workspace',
    str_contains($accounting, "item.to !== 'ai-agents'")
    && str_contains($accounting, 'visibleMoreNav.map(group => ('));
$check('standalone bank connection shortcuts avoid disabled admin routes',
    str_contains($sidebar, "label: 'Bank connections', to: '/modules/treasury/overview'")
    && str_contains($apPayments, "session?.product_mode === 'coreaccounting' ? '/modules/treasury/overview'")
    && str_contains($accounting, '<BookkeepingOverview standaloneAccounting=')
    && str_contains($bookkeeping, '{!standaloneAccounting && (')
    && str_contains($treasuryModule, 'items={visibleNavItems}')
    && str_contains($treasuryModule, "standaloneAccounting ? '/modules/treasury/overview' : '/admin/integrations'"));
$check('standalone payment rails and Treasury payment workflows are not presented',
    str_contains($paths, "pathname === '/modules/ap/settings'")
    && str_contains($paths, "'/modules/treasury/reconciliation'")
    && !str_contains($paths, "'/modules/treasury/payments'")
    && str_contains($treasuryOverview, '!standaloneAccounting && <section')
    && str_contains($apPayments, 'ap-manual-payment-notice')
    && str_contains($apPayments, '!standaloneAccounting && <button')
    && str_contains($apModule, "'/modules/ap/settings'].includes(n.to)"));
$check('generic Billing and AP overview links reach their worklists',
    str_contains($billingModule, '<Route path="overview" element={<Navigate to="../invoices" replace />} />')
    && str_contains($apModule, '<Route path="overview" element={<Navigate to="../bills" replace />} />'));
$check('standalone invoice and bill worklists omit staffing time entry points',
    substr_count($invoices, "session?.product_mode !== 'coreaccounting'") >= 2
    && substr_count($bills, "session?.product_mode !== 'coreaccounting'") >= 3);
$check('standalone settings omit ERP links and inactive controls',
    str_contains($settings, "session?.product_mode === 'coreaccounting'")
    && str_contains($settings, 'data-testid="coreaccounting-settings"')
    && str_contains($settings, "to: '/settings/mail'"));
$check('standalone administration restricts its route tree',
    str_contains($admin, 'const standalone = session?.product_mode === \'coreaccounting\'')
    && str_contains($admin, '<Route path="*" element={<Navigate to="/admin" replace />} />')
    && str_contains($admin, 'STANDALONE_ADMIN_LINKS'));

$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
