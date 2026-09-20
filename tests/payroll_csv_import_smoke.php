<?php
/** End-to-end smoke for safe payroll register CSV import. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/tx_helpers.php';
require_once dirname(__DIR__) . '/modules/payroll/lib/csv_import.php';

$pass = 0;
$fail = 0;
$failures = [];
$a = function (string $label, bool $condition) use (&$pass, &$fail, &$failures): void {
    if ($condition) { $pass++; echo "  PASS {$label}\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL {$label}\n"; }
};

echo "Payroll CSV importer smoke\n";
echo "==========================\n";

$headers = ['Employee Number', 'Gross Pay', 'Employee Taxes', 'Net Pay', 'Work State'];
$a('header aliases are punctuation tolerant',
    payrollCsvFindColumn($headers, ['employee_number']) === 0
    && payrollCsvFindColumn($headers, ['gross_pay']) === 1
    && payrollCsvFindColumn($headers, ['net', 'net_pay']) === 3);
$a('currency values parse to cents', payrollCsvParseDollarsToCents('$1,234.56') === 123456);
$a('parenthesized values parse as negative', payrollCsvParseDollarsToCents('(50.00)') === -5000);
$a('invalid values are rejected', payrollCsvParseDollarsToCents('not money') === null);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  SKIP pdo_sqlite is not installed\n";
    goto endpoint_checks;
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = [
    'CREATE TABLE people_employees (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, employee_number TEXT,
        legal_first_name TEXT, preferred_name TEXT, legal_last_name TEXT,
        work_email TEXT, personal_email TEXT, status TEXT
    )',
    'CREATE TABLE people_compensation (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
        pay_type TEXT, pay_rate_cents INTEGER, pay_frequency TEXT,
        effective_from TEXT, effective_to TEXT
    )',
    'CREATE TABLE payroll_pay_schedules (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, name TEXT, frequency TEXT, active INTEGER
    )',
    'CREATE TABLE payroll_pay_cycles (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, schedule_id INTEGER NOT NULL,
        name TEXT, active INTEGER
    )',
    'CREATE TABLE payroll_pay_periods (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, schedule_id INTEGER NOT NULL,
        cycle_id INTEGER, period_number INTEGER, period_start TEXT, period_end TEXT,
        pay_date TEXT, status TEXT
    )',
    'CREATE TABLE payroll_profiles (
        id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, employee_id INTEGER NOT NULL,
        schedule_id INTEGER, cycle_id INTEGER, work_state TEXT, payment_method TEXT,
        enabled INTEGER
    )',
    'CREATE TABLE payroll_runs (
        id INTEGER PRIMARY KEY, artifact_id TEXT, tenant_id INTEGER NOT NULL, pay_period_id INTEGER NOT NULL,
        run_type TEXT NOT NULL, created_by_user_id INTEGER, status TEXT NOT NULL,
        employee_count INTEGER DEFAULT 0, gross_total_cents INTEGER DEFAULT 0,
        taxes_total_cents INTEGER DEFAULT 0, deductions_total_cents INTEGER DEFAULT 0,
        net_total_cents INTEGER DEFAULT 0, employer_taxes_cents INTEGER DEFAULT 0,
        computed_at TEXT, computed_by_user_id INTEGER, created_at TEXT, updated_at TEXT
    )',
    'CREATE TABLE payroll_line_items (
        id INTEGER PRIMARY KEY, tenant_id INTEGER, run_id INTEGER, employee_id INTEGER,
        work_state TEXT, pay_type TEXT, pay_rate_cents INTEGER, pay_frequency TEXT,
        hours_regular NUMERIC, hours_overtime NUMERIC, gross_cents INTEGER,
        pretax_cents INTEGER, taxable_cents INTEGER, employee_taxes_cents INTEGER,
        posttax_cents INTEGER, net_cents INTEGER, employer_taxes_cents INTEGER,
        payment_method TEXT, status TEXT, notes TEXT, created_at TEXT
    )',
    'CREATE TABLE payroll_earnings (
        id INTEGER PRIMARY KEY, tenant_id INTEGER, line_item_id INTEGER, code TEXT,
        hours NUMERIC, rate_cents INTEGER, amount_cents INTEGER, taxable INTEGER,
        notes TEXT, created_at TEXT
    )',
    'CREATE TABLE payroll_deductions (
        id INTEGER PRIMARY KEY, tenant_id INTEGER, line_item_id INTEGER, code TEXT,
        is_pretax INTEGER, amount_cents INTEGER, notes TEXT, created_at TEXT
    )',
    'CREATE TABLE artifact_objects (
        id TEXT PRIMARY KEY, tenant_id INTEGER NOT NULL, sub_tenant_id INTEGER,
        artifact_type TEXT NOT NULL, title TEXT, status TEXT NOT NULL, version INTEGER NOT NULL,
        source_module TEXT, source_record_type TEXT, source_record_id INTEGER,
        payload_json TEXT, storage_uri TEXT, storage_bytes INTEGER, storage_mime TEXT,
        created_by_user_id INTEGER, created_by_ai_run TEXT,
        created_at TEXT, updated_at TEXT, archived_at TEXT,
        UNIQUE (tenant_id, artifact_type, source_module, source_record_type, source_record_id)
    )',
    'CREATE TABLE artifact_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL, artifact_id TEXT NOT NULL,
        event_type TEXT NOT NULL, prior_status TEXT, new_status TEXT,
        actor_user_id INTEGER, actor_ai_run TEXT, actor_worker_id TEXT,
        payload TEXT, created_at TEXT
    )',
    'CREATE TABLE artifact_relationships (
        id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id INTEGER NOT NULL,
        source_artifact_id TEXT NOT NULL, target_artifact_id TEXT,
        target_table TEXT, target_record_id INTEGER, relationship_type TEXT NOT NULL,
        metadata TEXT, created_by_user_id INTEGER, created_by_ai_run TEXT, created_at TEXT,
        UNIQUE (tenant_id, source_artifact_id, target_table, target_record_id, relationship_type)
    )',
];
foreach ($schema as $sql) $pdo->exec($sql);

$pdo->exec("INSERT INTO people_employees VALUES
    (101, 7, 'E-101', 'Alice', NULL, 'Wong', 'alice@example.com', NULL, 'active'),
    (102, 7, 'E-102', 'Bob', 'Bobby', 'Smith', NULL, 'bob@personal.com', 'active'),
    (103, 7, 'E-103', 'Carla', NULL, 'Patel', NULL, NULL, 'active'),
    (104, 7, 'E-104', 'Dana', NULL, 'Other', 'dana@example.com', NULL, 'active'),
    (999, 99, 'X-999', 'Other', NULL, 'Tenant', NULL, NULL, 'active')");
$pdo->exec("INSERT INTO payroll_pay_schedules VALUES (10, 7, 'Biweekly', 'biweekly', 1)");
$pdo->exec("INSERT INTO payroll_pay_cycles VALUES
    (20, 7, 10, 'Main cohort', 1),
    (21, 7, 10, 'Second cohort', 1)");
$pdo->exec("INSERT INTO payroll_pay_periods VALUES
    (501, 7, 10, 20, 1, '2026-02-01', '2026-02-14', '2026-02-20', 'open'),
    (502, 7, 10, 20, 2, '2026-02-15', '2026-02-28', '2026-03-06', 'open')");
$pdo->exec("INSERT INTO payroll_profiles VALUES
    (1, 7, 101, 10, 20, 'CA', 'direct_deposit', 1),
    (2, 7, 102, 10, 20, 'NY', 'check', 1),
    (3, 7, 103, 10, 20, 'TX', 'direct_deposit', 1),
    (4, 7, 104, 10, 21, 'FL', 'direct_deposit', 1)");
$pdo->exec("INSERT INTO people_compensation VALUES
    (1, 7, 101, 'salary', 9000000, 'biweekly', '2026-01-01', NULL),
    (2, 7, 102, 'hourly', 4250, 'biweekly', '2026-01-01', NULL),
    (3, 7, 103, 'salary', 7800000, 'biweekly', '2026-01-01', NULL),
    (4, 7, 104, 'salary', 8000000, 'biweekly', '2026-01-01', NULL)");
$pdo->exec("INSERT INTO payroll_runs
    (id, tenant_id, pay_period_id, run_type, created_by_user_id, status, created_at)
    VALUES (601, 7, 501, 'regular', 11, 'draft', datetime('now'))");

$directory = payrollCsvEmployeeDirectory($pdo, 7);
$a('employee number resolves canonical employee',
    (payrollCsvResolveEmployee($directory, ['employee_number' => 'E-101'])['employee']['id'] ?? null) === 101);
$a('work and personal email both resolve',
    payrollResolveEmployeeId($pdo, 7, null, 'alice@example.com', null) === 101
    && payrollResolveEmployeeId($pdo, 7, null, 'bob@personal.com', null) === 102);
$a('preferred first name can resolve', payrollResolveEmployeeId($pdo, 7, null, null, 'Bobby Smith') === 102);
$a('different tenant ID is not visible', payrollResolveEmployeeId($pdo, 7, '999', null, null) === null);

$valid = tempnam(sys_get_temp_dir(), 'cf_pay_valid_');
file_put_contents($valid,
    "\xEF\xBB\xBFemployee_number,employee_email,employee_name,hours_regular,hours_overtime,gross_pay,employee_taxes,pretax_deductions,posttax_deductions,net_pay,employer_taxes\n" .
    "E-101,alice@example.com,Alice Wong,80,0,3000.00,450.00,100.00,0,2450.00,229.50\n" .
    "E-102,bob@personal.com,Bob Smith,40,2,1700.00,265.00,50.00,0,1385.00,130.00\n" .
    "E-103,,Carla Patel,80,0,2600.00,400.00,0,0,2200.00,198.90\n"
);
$result = payrollImportRunCsv($pdo, 7, 501, $valid, 'regular', 44);
$a('all valid rows import', $result['rows_seen'] === 3 && $result['rows_inserted'] === 3 && !$result['errors']);
$a('cycle-created draft is reused', $result['run_id'] === 601 && $result['reused_draft'] === true);
$a('no duplicate run is created', (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs')->fetchColumn() === 1);

$run = $pdo->query('SELECT * FROM payroll_runs WHERE id = 601')->fetch(PDO::FETCH_ASSOC);
$a('run is computed by the importing user', $run['status'] === 'computed' && (int) $run['computed_by_user_id'] === 44);
$a('run totals roll up',
    (int) $run['employee_count'] === 3
    && (int) $run['gross_total_cents'] === 730000
    && (int) $run['taxes_total_cents'] === 111500
    && (int) $run['deductions_total_cents'] === 15000
    && (int) $run['net_total_cents'] === 603500
    && (int) $run['employer_taxes_cents'] === 55840);
$a('computed run has a first-class review artifact',
    !empty($run['artifact_id'])
    && (int) $pdo->query('SELECT COUNT(*) FROM artifact_objects WHERE tenant_id = 7 AND artifact_type = "payroll_review" AND status = "review"')->fetchColumn() === 1
    && (int) $pdo->query('SELECT COUNT(*) FROM artifact_relationships WHERE tenant_id = 7 AND target_table = "payroll_runs" AND target_record_id = 601')->fetchColumn() === 1);

$bob = $pdo->query('SELECT * FROM payroll_line_items WHERE employee_id = 102')->fetch(PDO::FETCH_ASSOC);
$a('profile supplies state and payment method', $bob['work_state'] === 'NY' && $bob['payment_method'] === 'check');
$a('compensation supplies pay settings when CSV omits them',
    $bob['pay_type'] === 'hourly' && (int) $bob['pay_rate_cents'] === 4250 && $bob['pay_frequency'] === 'biweekly');
$a('aggregate earning component is preserved', (int) $pdo->query('SELECT COUNT(*) FROM payroll_earnings')->fetchColumn() === 3);
$a('aggregate deduction components are preserved', (int) $pdo->query('SELECT COUNT(*) FROM payroll_deductions')->fetchColumn() === 2);

$duplicateAttempt = payrollImportRunCsv($pdo, 7, 501, $valid, 'regular', 44);
$a('a computed run cannot be overwritten', $duplicateAttempt['run_id'] === null && !empty($duplicateAttempt['errors']));

$invalid = tempnam(sys_get_temp_dir(), 'cf_pay_invalid_');
file_put_contents($invalid,
    "employee_number,gross_pay,employee_taxes,pretax_deductions,posttax_deductions,net_pay\n" .
    "E-101,1000.00,100.00,0,0,900.00\n" .
    "MISSING,1000.00,100.00,0,0,900.00\n"
);
$invalidResult = payrollImportRunCsv($pdo, 7, 502, $invalid, 'regular', 44);
$a('one bad row rejects the whole file', $invalidResult['run_id'] === null && $invalidResult['rows_inserted'] === 0);
$a('failed validation leaves no partial run',
    (int) $pdo->query('SELECT COUNT(*) FROM payroll_runs WHERE pay_period_id = 502')->fetchColumn() === 0);

$wrongCycle = tempnam(sys_get_temp_dir(), 'cf_pay_cycle_');
file_put_contents($wrongCycle,
    "employee_number,gross_pay,employee_taxes,net_pay\n" .
    "E-104,1000.00,100.00,900.00\n"
);
$cycleResult = payrollImportRunCsv($pdo, 7, 502, $wrongCycle, 'regular', 44);
$a('employee from a sibling cycle is rejected',
    $cycleResult['run_id'] === null && str_contains(implode(' ', $cycleResult['errors']), 'different pay cycle'));

$unbalanced = tempnam(sys_get_temp_dir(), 'cf_pay_unbalanced_');
file_put_contents($unbalanced,
    "employee_number,gross_pay,employee_taxes,net_pay\n" .
    "E-101,1000.00,100.00,950.00\n"
);
$balanceResult = payrollImportRunCsv($pdo, 7, 502, $unbalanced, 'regular', 44);
$a('out-of-balance net pay is rejected',
    $balanceResult['run_id'] === null && str_contains(implode(' ', $balanceResult['errors']), 'out of balance'));

foreach ([$valid, $invalid, $wrongCycle, $unbalanced] as $path) unlink($path);

endpoint_checks:
$endpoint = (string) file_get_contents(dirname(__DIR__) . '/modules/payroll/api/import_csv.php');
$a('endpoint is permission gated', str_contains($endpoint, "'payroll.run.compute'"));
$a('endpoint passes actor to importer', str_contains($endpoint, '$runType, $actorUserId'));
$a('validation failure returns a client error', str_contains($endpoint, 'api_error(') && str_contains($endpoint, '422'));
$a('import starts approval workflow', str_contains($endpoint, 'payrollRunWorkflowStart('));
$a('import is audited as a built run', str_contains($endpoint, "payrollAudit('payroll.run.built'"));

echo "\n==========================\n";
echo "Payroll CSV importer smoke: {$pass} passed / {$fail} failed\n";
if ($fail > 0) {
    foreach ($failures as $failure) echo " - {$failure}\n";
    exit(1);
}
