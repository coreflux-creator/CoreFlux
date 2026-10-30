<?php
declare(strict_types=1);

$api = (string) file_get_contents(__DIR__ . '/../modules/ap/api/bills.php');
$ui = (string) file_get_contents(__DIR__ . '/../modules/ap/ui/BillDetail.jsx');
$checks = [
    'bill-owned time references only' => str_contains($api, "!empty(\$l['source_ref_id'])"),
    'time source tenant scoped' => str_contains($api, 'FROM time_entries')
        && str_contains($api, 'WHERE tenant_id = :tenant_id AND id IN ('),
    'timesheet source tenant scoped' => str_contains($api, 'FROM staffing_timesheets')
        && substr_count($api, 'WHERE tenant_id = :tenant_id AND id IN (') >= 2,
    'time source includes review context' => str_contains($api, 'source_hours')
        && str_contains($api, 'source_category')
        && str_contains($api, 'source_status')
        && str_contains($api, 'source_timesheet_artifact_id'),
    'standalone opens a read-only source dialog' => str_contains($ui, "session?.product_mode === 'coreaccounting'")
        && str_contains($ui, 'onClick={() => setSourceLine(l)}')
        && str_contains($ui, 'role="dialog"')
        && str_contains($ui, 'aria-modal="true"')
        && str_contains($ui, 'data-testid="ap-bill-time-source-dialog"')
        && str_contains($ui, 'Work date')
        && str_contains($ui, 'Hours')
        && str_contains($ui, 'Timesheet status'),
    'ERP keeps staffing lifecycle link' => str_contains($ui, 'standaloneAccounting ? (')
        && str_contains($ui, 'to={`/modules/staffing/timesheets/${l.source_timesheet_id}/lifecycle?entry_id=${l.source_ref_id}`}'),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
