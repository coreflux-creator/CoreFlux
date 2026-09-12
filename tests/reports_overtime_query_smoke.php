<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../modules/reports/api/overtime_watch.php');
$checks = [
    'employee ranking does not reuse aggregate aliases inside an expression' => !str_contains($source, 'ORDER BY (ot_hours / total_hours)'),
    'employee ranking guards division by zero' => str_contains($source, '/ NULLIF(SUM(v.hours), 0)'),
    'client filter uses the aggregate expression' => str_contains($source, 'HAVING SUM(CASE WHEN v.is_overtime = 1 THEN v.hours ELSE 0 END) > 0'),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'OK  ' : 'BAD ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
