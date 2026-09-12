<?php
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/api/books_health.php');

$checks = [
    'books health derives fiscal year from the period schema' =>
        str_contains($source, 'YEAR(end_date) AS fiscal_year'),
    'books health does not require a nonexistent fiscal_year column' =>
        !str_contains($source, 'period_number, fiscal_year, start_date'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
