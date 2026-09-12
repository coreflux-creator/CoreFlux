<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'AI exception detail is not intercepted by the list route' => [
        $root . '/api/ai/exceptions.php',
        "if (\$method === 'GET' && \$action === '')",
        "if (\$method === 'GET' && \$action === 'detail')",
    ],
    '1099 readiness and print are not intercepted by the ledger list route' => [
        $root . '/modules/ap/api/1099.php',
        "if (\$method === 'GET' && \$action === '')",
        "if (\$method === 'GET' && \$action === 'readiness')",
    ],
    'invoice PDF is not intercepted by the invoice list route' => [
        $root . '/modules/billing/api/invoices.php',
        "if (\$method === 'GET' && \$action === '')",
        "if (\$method === 'GET' && \$action === 'pdf'",
    ],
];

foreach ($checks as $label => [$path, $guard, $specific]) {
    $source = (string) file_get_contents($path);
    $passed = str_contains($source, $guard) && str_contains($source, $specific);
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
