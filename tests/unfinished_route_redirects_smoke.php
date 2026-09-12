<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$time = (string) file_get_contents($root . '/modules/time/ui/TimeModule.jsx');
$reports = (string) file_get_contents($root . '/modules/reports/ui/ReportsModule.jsx');
$reportsSidebar = (string) file_get_contents($root . '/modules/reports/ui/ReportsSidebar.jsx');

$checks = [
    'retired missing-timesheets placeholder redirects to intake' =>
        str_contains($time, 'path="missing"')
        && str_contains($time, 'to="/modules/time/intake"'),
    'unfinished custom report route redirects to working reports' =>
        str_contains($reports, 'path="custom"')
        && !str_contains($reports, 'Custom Report Builder'),
    'unfinished report directory redirects to working reports' =>
        str_contains($reports, 'path="other"')
        && !str_contains($reports, 'Other Reports'),
    'unused reports sidebar exposes no unfinished destinations' =>
        !str_contains($reportsSidebar, '/modules/reports/custom')
        && !str_contains($reportsSidebar, '/modules/reports/other'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}
