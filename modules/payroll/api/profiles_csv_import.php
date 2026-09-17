<?php
/** Payroll profile CSV import with employee, schedule, and cycle resolution. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/CsvImportService.php';
require_once __DIR__ . '/../lib/payroll.php';
require_once __DIR__ . '/../lib/profiles.php';

use Core\CsvImportService;

CsvImportService::registerSchema('payroll_profiles', [
    'fields' => [
        'employee_id' => ['label' => 'Employee ID', 'type' => 'integer'],
        'employee_number' => ['label' => 'Employee number'],
        'work_email' => ['label' => 'Work email', 'type' => 'email'],
        'cycle_id' => ['label' => 'Pay cycle ID', 'type' => 'integer'],
        'cycle_name' => ['label' => 'Pay cycle name'],
        'schedule_id' => ['label' => 'Pay schedule ID', 'type' => 'integer'],
        'schedule_name' => ['label' => 'Pay schedule name'],
        'work_state' => ['label' => 'Work state'],
        'payment_method' => [
            'label' => 'Payment method',
            'enum' => ['direct_deposit', 'check'],
        ],
        'default_hours_per_period' => ['label' => 'Default hours per period', 'type' => 'number'],
        'retirement_percent' => ['label' => 'Retirement percent', 'type' => 'number'],
        'health_premium' => ['label' => 'Health premium per period', 'type' => 'number'],
        'hsa_pretax' => ['label' => 'HSA contribution per period', 'type' => 'number'],
        'extra_post_tax' => ['label' => 'Other post-tax per period', 'type' => 'number'],
        'enabled' => ['label' => 'Enabled', 'type' => 'boolean'],
        'notes' => ['label' => 'Notes'],
    ],
    'unique_within_batch' => ['employee_id', 'employee_number', 'work_email'],
]);

$ctx = api_require_auth();
$user = (array) $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) ($_GET['action'] ?? '');
rbac_legacy_require($user, 'payroll.profiles.manage');

if ($method === 'GET' && in_array($action, ['template', 'sample'], true)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_profiles_' . $action . '.csv"');
    header('Cache-Control: no-store');
    if ($action === 'template') {
        echo CsvImportService::buildTemplate('payroll_profiles');
    } else {
        echo CsvImportService::buildSample('payroll_profiles', payrollProfileSampleRows($tenantId));
    }
    exit;
}

if ($method === 'POST' && $action === 'inspect') {
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    api_ok(CsvImportService::inspect('payroll_profiles', $csv));
}

if ($method === 'POST' && $action === 'ai_suggest_map') {
    require_once __DIR__ . '/../../../core/ai_csv_mapper.php';
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $headers = fgetcsv($stream) ?: [];
    $samples = [];
    for ($i = 0; $i < 3; $i++) {
        $sample = fgetcsv($stream);
        if ($sample === false) break;
        $samples[] = $sample;
    }
    fclose($stream);
    $body = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $inspection = CsvImportService::inspect('payroll_profiles', $csv);
    try {
        api_ok(aiSuggestColumnMap([
            'feature_key' => 'csv.mapping.payroll_profiles',
            'entity_label' => 'Payroll employee profiles',
            'schema_fields' => $inspection['fields'],
            'headers' => $headers,
            'sample_rows' => $samples,
            'already_mapped' => is_array($body['already_mapped'] ?? null) ? $body['already_mapped'] : [],
        ]));
    } catch (AIDisabledException $e) {
        api_error('AI is not enabled for this tenant: ' . $e->getMessage(), 503);
    } catch (Throwable $e) {
        api_error('AI suggestion failed: ' . $e->getMessage(), 502);
    }
}

$context = payrollProfileCsvContext($tenantId);
$resolve = static fn(array $row): array => payrollResolveProfileCsvRow($row, $context);
$validateReferences = static function (array &$result) use ($resolve): void {
    foreach ($result['rows'] as $rowNumber => $row) {
        try {
            $resolve($row);
        } catch (Throwable $e) {
            $result['errors'][$rowNumber][] = $e->getMessage();
        }
    }
    $result['error_count'] = count($result['errors']);
};

if ($method === 'POST' && $action === 'dry_run') {
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $result = CsvImportService::dryRun('payroll_profiles', $csv, CsvImportService::readRequestColumnMap());
    $validateReferences($result);
    api_ok($result);
}

if ($method === 'POST' && $action === 'commit') {
    $csv = CsvImportService::readRequestCsv();
    if (!$csv) api_error('No CSV body received', 400);
    $columnMap = CsvImportService::readRequestColumnMap();
    $skipInvalid = !empty($_GET['skip_invalid']);
    $updateExisting = !empty($_GET['update_existing']);

    $preview = CsvImportService::dryRun('payroll_profiles', $csv, $columnMap);
    $validateReferences($preview);
    if (!$skipInvalid && $preview['error_count'] > 0) {
        api_ok([
            'imported_count' => 0,
            'skipped_count' => $preview['row_count'],
            'errors' => $preview['errors'],
            'ids' => [],
            'message' => 'Validation errors present; import was not committed.',
        ]);
    }

    $pdo = getDB();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $result = CsvImportService::commit('payroll_profiles', $csv, function (array $row) use ($resolve, $updateExisting): int {
            $resolved = $resolve($row);
            $existing = $resolved['existing'];
            if ($existing && !$updateExisting) {
                throw new RuntimeException(
                    "Employee {$resolved['employee']['employee_number']} already has a payroll profile; enable Update existing rows"
                );
            }
            if ($existing) {
                scopedUpdate('payroll_profiles', (int) $existing['id'], $resolved['payload']);
                return (int) $existing['id'];
            }
            return scopedInsert('payroll_profiles', $resolved['payload'] + [
                'employee_id' => (int) $resolved['employee']['id'],
            ]);
        }, ['skip_invalid' => $skipInvalid, 'column_map' => $columnMap]);

        if (!$skipInvalid && $result['errors']) {
            if ($ownsTransaction) $pdo->rollBack();
            $result['skipped_count'] = $preview['row_count'];
            $result['imported_count'] = 0;
            $result['ids'] = [];
            $result['message'] = 'No changes were saved because at least one row could not be applied.';
            api_ok($result);
        }
        if ($skipInvalid && $preview['errors']) {
            $result['errors'] = array_replace($preview['errors'], $result['errors']);
            $result['skipped_count'] = count($result['errors']);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        api_error('Payroll profile import failed: ' . $e->getMessage(), 500);
    }

    payrollAudit('payroll.profile.csv_imported', [
        'imported' => $result['imported_count'],
        'skipped' => $result['skipped_count'],
        'errors' => count($result['errors']),
        'update_existing' => $updateExisting,
    ]);
    api_ok($result);
}

api_error('Unknown action. Use ?action=template|sample|inspect|ai_suggest_map|dry_run|commit', 400);

function payrollProfileCsvContext(int $tenantId): array
{
    $pdo = getDB();
    $employees = $pdo->prepare(
        "SELECT * FROM people_employees WHERE tenant_id = :tenant_id AND status IN ('active','on_leave')"
    );
    $employees->execute(['tenant_id' => $tenantId]);
    $context = [
        'employees_by_id' => [], 'employees_by_number' => [], 'employees_by_email' => [],
        'schedules_by_id' => [], 'schedules_by_name' => [],
        'cycles_by_id' => [], 'cycles_by_name' => [], 'cycles_by_schedule' => [],
        'profiles_by_employee' => [],
    ];
    foreach ($employees->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['employees_by_id'][(int) $row['id']] = $row;
        $context['employees_by_number'][strtolower(trim((string) $row['employee_number']))] = $row;
        if (!empty($row['work_email'])) $context['employees_by_email'][strtolower(trim((string) $row['work_email']))] = $row;
    }
    $refs = payrollProfileReferenceData($tenantId);
    foreach ($refs['schedules'] as $id => $row) {
        $context['schedules_by_id'][(int) $id] = $row;
        $context['schedules_by_name'][strtolower(trim((string) $row['name']))] = $row;
    }
    foreach ($refs['cycles'] as $id => $row) {
        $context['cycles_by_id'][(int) $id] = $row;
        $context['cycles_by_name'][strtolower(trim((string) $row['name']))] = $row;
        $context['cycles_by_schedule'][(int) $row['schedule_id']][] = $row;
    }
    $profiles = $pdo->prepare('SELECT * FROM payroll_profiles WHERE tenant_id = :tenant_id');
    $profiles->execute(['tenant_id' => $tenantId]);
    foreach ($profiles->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $context['profiles_by_employee'][(int) $row['employee_id']] = $row;
    }
    return $context;
}

function payrollResolveProfileCsvRow(array $row, array $context): array
{
    $matches = [];
    if (!empty($row['employee_id'])) {
        $matches[] = $context['employees_by_id'][(int) $row['employee_id']] ?? null;
        if (!$matches[array_key_last($matches)]) throw new RuntimeException("employee_id: employee {$row['employee_id']} was not found");
    }
    if (trim((string) ($row['employee_number'] ?? '')) !== '') {
        $key = strtolower(trim((string) $row['employee_number']));
        $matches[] = $context['employees_by_number'][$key] ?? null;
        if (!$matches[array_key_last($matches)]) throw new RuntimeException("employee_number: {$row['employee_number']} was not found");
    }
    if (trim((string) ($row['work_email'] ?? '')) !== '') {
        $key = strtolower(trim((string) $row['work_email']));
        $matches[] = $context['employees_by_email'][$key] ?? null;
        if (!$matches[array_key_last($matches)]) throw new RuntimeException("work_email: {$row['work_email']} was not found");
    }
    if (!$matches) throw new RuntimeException('employee: provide Employee ID, employee number, or work email');
    $employee = $matches[0];
    foreach ($matches as $match) {
        if ((int) $match['id'] !== (int) $employee['id']) {
            throw new RuntimeException('employee: the supplied ID, number, and email refer to different employees');
        }
    }
    $existing = $context['profiles_by_employee'][(int) $employee['id']] ?? null;

    $schedule = null;
    if (!empty($row['schedule_id'])) {
        $schedule = $context['schedules_by_id'][(int) $row['schedule_id']] ?? null;
        if (!$schedule) throw new RuntimeException("schedule_id: schedule {$row['schedule_id']} was not found");
    }
    if (trim((string) ($row['schedule_name'] ?? '')) !== '') {
        $byName = $context['schedules_by_name'][strtolower(trim((string) $row['schedule_name']))] ?? null;
        if (!$byName) throw new RuntimeException("schedule_name: {$row['schedule_name']} was not found");
        if ($schedule && (int) $schedule['id'] !== (int) $byName['id']) {
            throw new RuntimeException('schedule: Pay schedule ID and name do not match');
        }
        $schedule = $byName;
    }

    $cycle = null;
    if (!empty($row['cycle_id'])) {
        $cycle = $context['cycles_by_id'][(int) $row['cycle_id']] ?? null;
        if (!$cycle) throw new RuntimeException("cycle_id: pay cycle {$row['cycle_id']} was not found");
    }
    if (trim((string) ($row['cycle_name'] ?? '')) !== '') {
        $byName = $context['cycles_by_name'][strtolower(trim((string) $row['cycle_name']))] ?? null;
        if (!$byName) throw new RuntimeException("cycle_name: {$row['cycle_name']} was not found");
        if ($cycle && (int) $cycle['id'] !== (int) $byName['id']) {
            throw new RuntimeException('cycle: Pay cycle ID and name do not match');
        }
        $cycle = $byName;
    }
    if ($cycle) {
        if ($schedule && (int) $schedule['id'] !== (int) $cycle['schedule_id']) {
            throw new RuntimeException('cycle: selected pay cycle does not belong to the selected schedule');
        }
        $schedule = $context['schedules_by_id'][(int) $cycle['schedule_id']] ?? null;
    }
    if (!$schedule && $existing && !empty($existing['schedule_id'])) {
        $schedule = $context['schedules_by_id'][(int) $existing['schedule_id']] ?? null;
    }
    if (!$cycle && $existing && !empty($existing['cycle_id'])) {
        $cycle = $context['cycles_by_id'][(int) $existing['cycle_id']] ?? null;
    }
    if ($schedule && !$cycle) {
        $activeCycles = array_values(array_filter(
            $context['cycles_by_schedule'][(int) $schedule['id']] ?? [],
            static fn(array $candidate): bool => (int) $candidate['active'] === 1
        ));
        if (count($activeCycles) === 1) $cycle = $activeCycles[0];
        elseif (count($activeCycles) > 1) throw new RuntimeException('cycle: this schedule has multiple active cycles; choose one');
    }

    $enabled = array_key_exists('enabled', $row) && $row['enabled'] !== ''
        ? (int) $row['enabled']
        : (int) ($existing['enabled'] ?? 1);
    if ($enabled && !$schedule) throw new RuntimeException('schedule: an enabled profile needs a pay schedule');
    if ($enabled && $schedule && !(int) $schedule['active']) throw new RuntimeException('schedule: selected schedule is inactive');
    if ($enabled && $cycle && !(int) $cycle['active']) throw new RuntimeException('cycle: selected pay cycle is inactive');

    $workState = strtoupper(trim((string) ($row['work_state'] ?? ($existing['work_state'] ?? 'CA'))));
    if (!preg_match('/^[A-Z]{2}$/', $workState)) throw new RuntimeException('work_state: expected a 2-letter state code');
    $method = trim((string) ($row['payment_method'] ?? ($existing['payment_method'] ?? 'direct_deposit')));
    if (!in_array($method, ['direct_deposit', 'check'], true)) throw new RuntimeException('payment_method: use direct_deposit or check');

    $hours = trim((string) ($row['default_hours_per_period'] ?? ''));
    $hours = $hours === '' ? ($existing['default_hours_per_period'] ?? null) : (float) $hours;
    if ($hours !== null && ($hours < 0 || $hours > 744)) throw new RuntimeException('default_hours_per_period: expected 0 to 744');
    $retirement = payrollProfileCsvNumber($row, 'retirement_percent', ($existing['retirement_pretax_bps'] ?? 0) / 100);
    if ($retirement < 0 || $retirement > 100) throw new RuntimeException('retirement_percent: expected 0 to 100');
    $health = payrollProfileCsvNumber($row, 'health_premium', ($existing['health_premium_cents'] ?? 0) / 100);
    $hsa = payrollProfileCsvNumber($row, 'hsa_pretax', ($existing['hsa_pretax_cents'] ?? 0) / 100);
    $postTax = payrollProfileCsvNumber($row, 'extra_post_tax', ($existing['extra_post_tax_cents'] ?? 0) / 100);
    foreach (['health_premium' => $health, 'hsa_pretax' => $hsa, 'extra_post_tax' => $postTax] as $field => $amount) {
        if ($amount < 0) throw new RuntimeException("{$field}: amount cannot be negative");
    }

    return [
        'employee' => $employee,
        'existing' => $existing,
        'payload' => [
            'schedule_id' => $schedule ? (int) $schedule['id'] : null,
            'cycle_id' => $cycle ? (int) $cycle['id'] : null,
            'work_state' => $workState,
            'payment_method' => $method,
            'default_hours_per_period' => $hours,
            'retirement_pretax_bps' => (int) round($retirement * 100),
            'health_premium_cents' => (int) round($health * 100),
            'hsa_pretax_cents' => (int) round($hsa * 100),
            'extra_post_tax_cents' => (int) round($postTax * 100),
            'enabled' => $enabled,
            'notes' => array_key_exists('notes', $row)
                ? (trim((string) $row['notes']) ?: null)
                : ($existing['notes'] ?? null),
        ],
    ];
}

function payrollProfileCsvNumber(array $row, string $field, float $fallback): float
{
    if (!array_key_exists($field, $row) || trim((string) $row[$field]) === '') return $fallback;
    return (float) $row[$field];
}

function payrollProfileSampleRows(int $tenantId): array
{
    $context = payrollProfileCsvContext($tenantId);
    $rows = [];
    foreach (array_slice(array_values($context['employees_by_id']), 0, 3) as $employee) {
        $profile = $context['profiles_by_employee'][(int) $employee['id']] ?? null;
        $schedule = $profile ? ($context['schedules_by_id'][(int) ($profile['schedule_id'] ?? 0)] ?? null) : null;
        $cycle = $profile ? ($context['cycles_by_id'][(int) ($profile['cycle_id'] ?? 0)] ?? null) : null;
        if (!$schedule) $schedule = array_values(array_filter($context['schedules_by_id'], static fn(array $s): bool => (int) $s['active'] === 1))[0] ?? null;
        if (!$cycle && $schedule) {
            $cycle = array_values(array_filter(
                $context['cycles_by_schedule'][(int) $schedule['id']] ?? [],
                static fn(array $c): bool => (int) $c['active'] === 1
            ))[0] ?? null;
        }
        $rows[] = [
            'employee_id' => $employee['id'],
            'employee_number' => $employee['employee_number'],
            'work_email' => $employee['work_email'],
            'cycle_id' => $cycle['id'] ?? '',
            'cycle_name' => $cycle['name'] ?? '',
            'schedule_id' => $schedule['id'] ?? '',
            'schedule_name' => $schedule['name'] ?? '',
            'work_state' => $profile['work_state'] ?? 'CA',
            'payment_method' => $profile['payment_method'] ?? 'direct_deposit',
            'default_hours_per_period' => $profile['default_hours_per_period'] ?? 80,
            'retirement_percent' => isset($profile['retirement_pretax_bps']) ? ((int) $profile['retirement_pretax_bps'] / 100) : 0,
            'health_premium' => isset($profile['health_premium_cents']) ? ((int) $profile['health_premium_cents'] / 100) : 0,
            'hsa_pretax' => isset($profile['hsa_pretax_cents']) ? ((int) $profile['hsa_pretax_cents'] / 100) : 0,
            'extra_post_tax' => isset($profile['extra_post_tax_cents']) ? ((int) $profile['extra_post_tax_cents'] / 100) : 0,
            'enabled' => isset($profile['enabled']) ? (int) $profile['enabled'] : 1,
            'notes' => $profile['notes'] ?? '',
        ];
    }
    return $rows;
}
