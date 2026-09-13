<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/api/books_health.php');

$checks = [
    'books health derives fiscal year from the period schema' =>
        str_contains($source, 'YEAR(end_date) AS fiscal_year'),
    'books health does not require a nonexistent fiscal_year column' =>
        !str_contains($source, 'period_number, fiscal_year, start_date'),
    'current-period query receives its exact date parameters' =>
        str_contains($source, "\$currentPeriodParams = ['t' => \$tid, 'd_lo' => \$asOf, 'd_hi' => \$asOf]")
        && str_contains($source, '$periodStmt->execute($currentPeriodParams)'),
    'ready-to-close query receives the required d parameter' =>
        str_contains($source, "\$readyParams = ['t' => \$tid, 'd' => \$asOf]")
        && str_contains($source, '$readyStmt->execute($readyParams)'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
