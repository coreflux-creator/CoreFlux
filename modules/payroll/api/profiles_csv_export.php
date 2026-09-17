<?php
/** Payroll profile CSV export for safe offline updates and re-import. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvExportService.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/profiles.php';

use Core\CsvExportService;

$ctx = api_require_auth();
$user = (array) $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'payroll.profiles.view');

$search = trim((string) ($_GET['q'] ?? ''));
$department = trim((string) ($_GET['department'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$refs = payrollProfileReferenceData($tenantId);
$rows = [];
foreach (peopleListActiveEmployees($search ?: null, $department ?: null) as $employee) {
    $profile = payrollGetProfile((int) $employee['id']);
    $gaps = array_values(array_unique(array_merge(
        peoplePayrollReadiness((int) $employee['id']),
        payrollProfileAssignmentGaps($profile, $refs)
    )));
    $ready = count($gaps) === 0 && $profile && (int) $profile['enabled'] === 1;
    if (($status === 'ready' && !$ready) || ($status === 'needs_setup' && $ready)) continue;
    $schedule = $profile ? ($refs['schedules'][(int) ($profile['schedule_id'] ?? 0)] ?? null) : null;
    $cycle = $profile ? ($refs['cycles'][(int) ($profile['cycle_id'] ?? 0)] ?? null) : null;
    $rows[] = [
        'employee_id' => $employee['id'],
        'employee_number' => $employee['employee_number'],
        'employee_name' => trim(($employee['preferred_name'] ?: $employee['legal_first_name']) . ' ' . $employee['legal_last_name']),
        'work_email' => $employee['work_email'],
        'department' => $employee['department'],
        'cycle_id' => $cycle['id'] ?? '',
        'cycle_name' => $cycle['name'] ?? '',
        'schedule_id' => $schedule['id'] ?? '',
        'schedule_name' => $schedule['name'] ?? '',
        'work_state' => $profile['work_state'] ?? '',
        'payment_method' => $profile['payment_method'] ?? '',
        'default_hours_per_period' => $profile['default_hours_per_period'] ?? '',
        'retirement_percent' => $profile ? round(((int) $profile['retirement_pretax_bps']) / 100, 2) : '',
        'health_premium' => $profile ? number_format(((int) $profile['health_premium_cents']) / 100, 2, '.', '') : '',
        'hsa_pretax' => $profile ? number_format(((int) $profile['hsa_pretax_cents']) / 100, 2, '.', '') : '',
        'extra_post_tax' => $profile ? number_format(((int) $profile['extra_post_tax_cents']) / 100, 2, '.', '') : '',
        'enabled' => $profile ? (int) $profile['enabled'] : 1,
        'notes' => $profile['notes'] ?? '',
        'readiness' => $ready ? 'ready' : 'needs setup',
        'setup_gaps' => implode('; ', $gaps),
    ];
}

payrollAudit('payroll.profile.csv_exported', [
    'rows' => count($rows),
    'filters' => ['q' => $search, 'department' => $department, 'status' => $status],
]);

(new CsvExportService([
    'employee_id' => 'Employee ID',
    'employee_number' => 'Employee number',
    'employee_name' => 'Employee name (reference only)',
    'work_email' => 'Work email',
    'department' => 'Department (reference only)',
    'cycle_id' => 'Pay cycle ID',
    'cycle_name' => 'Pay cycle name',
    'schedule_id' => 'Pay schedule ID',
    'schedule_name' => 'Pay schedule name',
    'work_state' => 'Work state',
    'payment_method' => 'Payment method',
    'default_hours_per_period' => 'Default hours per period',
    'retirement_percent' => 'Retirement percent',
    'health_premium' => 'Health premium per period',
    'hsa_pretax' => 'HSA contribution per period',
    'extra_post_tax' => 'Other post-tax per period',
    'enabled' => 'Enabled',
    'notes' => 'Notes',
    'readiness' => 'Readiness (reference only)',
    'setup_gaps' => 'Setup gaps (reference only)',
]))->stream($rows, 'payroll_profiles_' . date('Y-m-d') . '.csv');
