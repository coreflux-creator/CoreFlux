<?php
/** Pin the CoreOne report route to canonical, entity-scoped report builders. */
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../api/coreone/v1/reports.php');
$checks = [
    'bearer credential required' => str_contains($source, 'coreoneV1Authenticate(')
        && !str_contains($source, 'api_require_auth('),
    'credential fixes both scopes' => str_contains($source, "\$credential['tenant_id']")
        && str_contains($source, "\$credential['entity_id']"),
    'only GET and explicit date filters' => str_contains($source, "api_method() !== 'GET'")
        && str_contains($source, "['type', 'from', 'to']")
        && str_contains($source, "['type', 'as_of']")
        && str_contains($source, 'array_diff(array_keys($_GET), $allowedKeys)'),
    'canonical income and balance sheets' => str_contains($source, 'reportIncomeStatement($tenantId,')
        && str_contains($source, 'reportBalanceSheet($tenantId,'),
    'canonical trial balance and cash flow' => str_contains($source, 'accountingTrialBalance($tenantId,')
        && str_contains($source, 'reportCashFlowIndirect($tenantId,'),
    'one consistent snapshot and machine-readable failure' =>
        str_contains($source, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')
        && str_contains($source, "api_error('Report could not be prepared', 503)"),
    'tenant-wide aging not exposed as entity-scoped data' =>
        !str_contains($source, 'billingComputeAging(')
        && !str_contains($source, 'apComputeAging('),
];
$failed = 0;
foreach ($checks as $name => $passed) {
    echo ($passed ? 'OK  ' : 'FAIL  ') . $name . PHP_EOL;
    if (!$passed) $failed++;
}
echo $failed ? "Failed: {$failed}\n" : 'Passed: ' . count($checks) . "\n";
exit($failed ? 1 : 0);
