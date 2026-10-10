<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/standalone_boundary.php';
require_once __DIR__ . '/../core/accounting/standalone_api_boundary.php';

$root = dirname(__DIR__);
$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[] = $passed;
    echo ($passed ? 'OK  ' : 'FAIL  ') . $label . PHP_EOL;
};

foreach (['payments', 'transfers'] as $endpoint) {
    $path = $root . '/modules/treasury/api/' . $endpoint . '.php';
    $check("standalone denies {$endpoint} through direct module API",
        !coreAccountingAllowsPublicApiScript($path, $root, 'coreaccounting'));
    $check("standalone denies {$endpoint} through the central router",
        !coreAccountingAllowsModuleEndpoint('treasury', $endpoint, 'coreaccounting'));
    $check("ERP retains {$endpoint}",
        coreAccountingAllowsPublicApiScript($path, $root, 'production')
        && coreAccountingAllowsModuleEndpoint('treasury', $endpoint, 'production'));
}
$check('standalone keeps Treasury bank and account APIs',
    coreAccountingAllowsModuleEndpoint('treasury', 'deposit_accounts', 'coreaccounting')
    && coreAccountingAllowsModuleEndpoint('treasury', 'liability_accounts', 'coreaccounting')
    && coreAccountingAllowsModuleEndpoint('treasury', 'bank_reconciliation', 'coreaccounting'));
foreach (['originate', 'originate_batch', 'send_via_mercury'] as $action) {
    $check("standalone denies AP {$action}",
        !coreAccountingAllowsApPaymentAction('POST', $action, 'coreaccounting'));
    $check("ERP retains AP {$action}",
        coreAccountingAllowsApPaymentAction('POST', $action, 'production'));
}
foreach (['', 'allocate', 'send', 'clear', 'void', 'correct_cleared'] as $action) {
    $check("standalone keeps manual AP {$action}",
        coreAccountingAllowsApPaymentAction('POST', $action, 'coreaccounting'));
}

$payments = (string) file_get_contents($root . '/modules/ap/api/payments.php');
$bills = (string) file_get_contents($root . '/modules/ap/api/bills.php');
$settings = (string) file_get_contents($root . '/modules/ap/api/settings.php');
$csv = (string) file_get_contents($root . '/modules/ap/api/payments_csv_import.php');
$router = (string) file_get_contents($root . '/api/index.php');
$check('AP action boundary runs before provider dispatch',
    strpos($payments, 'coreAccountingAllowsApPaymentAction(') < strpos($payments, "if (\$method === 'POST' && \$action === 'originate_batch')")
    && strpos($payments, 'coreAccountingAllowsApPaymentAction(') < strpos($payments, "if (\$method === 'POST' && \$action === 'originate')"));
$check('provider methods cannot be drafted or released in standalone',
    str_contains($payments, "['plaid', 'mercury']")
    && str_contains($payments, 'Provider-linked payments cannot be released in CoreAccounting'));
$check('payment-run creation and rail settings are blocked in standalone',
    str_contains($bills, 'Provider-backed payment runs are not available in CoreAccounting')
    && str_contains($settings, 'Payment-provider settings are not available in CoreAccounting'));
$check('CSV preview and commit use standalone manual methods',
    str_contains($csv, "getenv('COREFLUX_ENV') === 'coreaccounting'")
    && str_contains($csv, "['ach','wire','check','card','cash','other']"));
$check('central router checks endpoint before loading bootstrap',
    strpos($router, 'coreAccountingAllowsModuleEndpoint(')
        < strpos($router, "require_once __DIR__ . '/../core/api_bootstrap.php'"));

$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
