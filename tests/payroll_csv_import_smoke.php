<?php
/**
 * payroll_csv_import_smoke.php
 *
 * End-to-end smoke for the Payroll register CSV importer. Runs the
 * importer in-process against an SQLite in-memory database mirroring
 * the production schema (payroll_pay_periods, payroll_runs,
 * payroll_line_items, people).
 *
 * Verifies:
 *   1. Pure-helper coverage (findColumn, parseDollarsToCents).
 *   2. Employee lookup by id / email / "First Last" name.
 *   3. Pay-period gating (wrong period_id is rejected).
 *   4. Transactional integrity (one run + N line items in one tx).
 *   5. Aggregate totals roll-up onto payroll_runs.
 *   6. API endpoint structural checks (RBAC, body validation,
 *      upload cap).
 *
 * Run:  php -d zend.assertions=1 tests/payroll_csv_import_smoke.php
 */
declare(strict_types=1);
require_once '/app/core/tx_helpers.php';

require_once dirname(__DIR__) . '/modules/payroll/lib/csv_import.php';

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "Payroll CSV importer smoke\n";
echo "==========================\n";

// 1) Helpers ------------------------------------------------------------
echo "\n1. helpers\n";
$h = ['Employee Email', 'Gross Pay', 'Employee Taxes', 'Net Pay', 'Work State'];
$a('findColumn snake/space tolerant',
    payrollCsvFindColumn($h, ['employee_email', 'email']) === 0
    && payrollCsvFindColumn($h, ['gross_pay', 'gross']) === 1
    && payrollCsvFindColumn($h, ['net', 'net_pay']) === 3
    && payrollCsvFindColumn($h, ['work_state', 'state']) === 4);
$a('parseDollarsToCents plain',
    payrollCsvParseDollarsToCents('1234.56') === 123456);
$a('parseDollarsToCents currency + commas',
    payrollCsvParseDollarsToCents('$1,234.56') === 123456);
$a('parseDollarsToCents parentheses-negative',
    payrollCsvParseDollarsToCents('(50.00)') === -5000);
$a('parseDollarsToCents rejects garbage',
    payrollCsvParseDollarsToCents('') === null
    && payrollCsvParseDollarsToCents('xx') === null);
$a('parseDollarsToCents rounds half-up',
    payrollCsvParseDollarsToCents('99.999') === 10000);

// 2) End-to-end SQLite harness -----------------------------------------
echo "\n2. end-to-end import\n";
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "  SKIP pdo_sqlite is not installed in this PHP runtime\n";
    goto endpoint_checks;
}
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(
    'CREATE TABLE people (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        first_name TEXT, last_name TEXT,
        email_primary TEXT, email_secondary TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE payroll_pay_periods (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        period_start TEXT, period_end TEXT, pay_date TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE payroll_runs (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER NOT NULL,
        pay_period_id INTEGER NOT NULL,
        run_type TEXT NOT NULL,
        status TEXT NOT NULL,
        employee_count INTEGER DEFAULT 0,
        gross_total_cents INTEGER DEFAULT 0,
        taxes_total_cents INTEGER DEFAULT 0,
        deductions_total_cents INTEGER DEFAULT 0,
        net_total_cents INTEGER DEFAULT 0,
        employer_taxes_cents INTEGER DEFAULT 0,
        computed_at TEXT, created_at TEXT
    )'
);
$pdo->exec(
    'CREATE TABLE payroll_line_items (
        id INTEGER PRIMARY KEY,
        tenant_id INTEGER, run_id INTEGER, employee_id INTEGER,
        work_state TEXT, pay_type TEXT,
        pay_rate_cents INTEGER, pay_frequency TEXT,
        hours_regular NUMERIC, hours_overtime NUMERIC,
        gross_cents INTEGER, pretax_cents INTEGER, taxable_cents INTEGER,
        employee_taxes_cents INTEGER, posttax_cents INTEGER, net_cents INTEGER,
        employer_taxes_cents INTEGER, payment_method TEXT, status TEXT,
        created_at TEXT
    )'
);
$pdo->exec("INSERT INTO people VALUES
    (101, 7, 'Alice',   'Wong',  'alice@example.com',  NULL),
    (102, 7, 'Bob',     'Smith', NULL,                 'bob@personal.com'),
    (103, 7, 'Carla',   'Patel', NULL,                 NULL),
    (999, 99, 'Other',  'Tenant', NULL,                NULL)
");
$pdo->exec("INSERT INTO payroll_pay_periods VALUES
    (501, 7, '2026-02-01', '2026-02-15', '2026-02-20')
");

$tmp = tempnam(sys_get_temp_dir(), 'cf_pay_csv_');
file_put_contents($tmp,
    "\xEF\xBB\xBFemployee_email,employee_name,work_state,pay_type,pay_rate,gross_pay,employee_taxes,pretax_deductions,net_pay,employer_taxes\n" .
    "alice@example.com,Alice Wong,CA,salary,75.00,3000.00,450.00,100.00,2450.00,229.50\n" .
    ",Bob Smith,NY,hourly,42.50,1700.00,265.00,50.00,1385.00,130.00\n" .         // matched by name (Bob's email is secondary, not in this CSV — falls back to name match)
    ",Carla Patel,TX,salary,65.00,2600.00,400.00,0,2200.00,198.90\n" .
    ",,XX,,,,,,,\n" .                                                                // empty-ish row → skip
    ",Unknown Person,FL,salary,50.00,2000.00,300.00,0,1700.00,153.00\n"               // not in people → skip
);

$res = payrollImportRunCsv($pdo, 7, 501, $tmp, 'regular');
$a('rows_inserted matches the 3 resolvable employees', $res['rows_inserted'] === 3);
$a('rows_skipped accounts for empty + unknown rows', $res['rows_skipped'] >= 2);
$a('run_id populated', is_int($res['run_id']) && $res['run_id'] > 0);

// 3) Aggregate roll-up ---------------------------------------------------
echo "\n3. payroll_runs totals roll-up\n";
$st = $pdo->prepare('SELECT * FROM payroll_runs WHERE id = ?');
$st->execute([$res['run_id']]);
$run = $st->fetch(PDO::FETCH_ASSOC);
$a('employee_count rolled up', (int) $run['employee_count'] === 3);
$a('gross_total_cents rolled up',
    (int) $run['gross_total_cents'] === 3000*100 + 1700*100 + 2600*100);
$a('taxes_total_cents rolled up',
    (int) $run['taxes_total_cents'] === 450*100 + 265*100 + 400*100);
$a('deductions_total_cents rolled up (pre + post)',
    (int) $run['deductions_total_cents'] === 100*100 + 50*100 + 0);
$a('net_total_cents rolled up',
    (int) $run['net_total_cents'] === 2450*100 + 1385*100 + 2200*100);
$a('employer_taxes_cents rolled up',
    (int) $run['employer_taxes_cents'] === 22950 + 13000 + 19890);
$a('status set to "computed"', $run['status'] === 'computed');
$a('run_type honoured', $run['run_type'] === 'regular');

// 4) Per-employee line-item integrity ----------------------------------
echo "\n4. payroll_line_items shape\n";
$st = $pdo->query("SELECT * FROM payroll_line_items WHERE employee_id = 101");
$alice = $st->fetch(PDO::FETCH_ASSOC);
$a('Alice line item written', is_array($alice));
$a('Alice gross_cents = 300000', (int) $alice['gross_cents'] === 300000);
$a('Alice net_cents = 245000', (int) $alice['net_cents'] === 245000);
$a('Alice work_state stored as 2-char upper', $alice['work_state'] === 'CA');
$a('Alice pay_type lowercased', $alice['pay_type'] === 'salary');
$a('Alice taxable_cents = gross - pretax', (int) $alice['taxable_cents'] === 300000 - 10000);

// 5) Employee resolver --------------------------------------------------
echo "\n5. employee resolver\n";
$a('resolves by id when valid',
    payrollResolveEmployeeId($pdo, 7, '101', null, null) === 101);
$a('rejects id from a different tenant',
    payrollResolveEmployeeId($pdo, 7, '999', null, null) === null);
$a('resolves by email_primary',
    payrollResolveEmployeeId($pdo, 7, null, 'alice@example.com', null) === 101);
$a('resolves by email_secondary',
    payrollResolveEmployeeId($pdo, 7, null, 'bob@personal.com', null) === 102);
$a('resolves by "First Last" name when no id/email',
    payrollResolveEmployeeId($pdo, 7, null, null, 'Carla Patel') === 103);
$a('returns null on no match',
    payrollResolveEmployeeId($pdo, 7, null, 'nobody@nowhere.com', null) === null);

// 6) Pay-period gating ---------------------------------------------------
echo "\n6. pay-period gating\n";
$res2 = payrollImportRunCsv($pdo, 7, 999, $tmp);
$a('unknown pay_period_id returns errors[] and no run',
    $res2['run_id'] === null && !empty($res2['errors']));
$res3 = payrollImportRunCsv($pdo, 99, 501, $tmp);
$a('wrong tenant_id rejects (period not visible to tenant)',
    $res3['run_id'] === null && !empty($res3['errors']));

unlink($tmp);

endpoint_checks:
// 7) API endpoint structural checks ------------------------------------
echo "\n7. /api/payroll/import_csv.php endpoint\n";
$ep = dirname(__DIR__) . '/modules/payroll/api/import_csv.php';
$a('endpoint exists', file_exists($ep));
$src = (string) file_get_contents($ep);
$a('endpoint RBAC-gated by payroll.run.compute',
    str_contains($src, "rbac_legacy_require(\$ctx['user'], 'payroll.run.compute')"));
$a('endpoint enforces POST', str_contains($src, "if (api_method() !== 'POST')"));
$a('endpoint requires pay_period_id > 0',
    str_contains($src, "if (\$payPeriodId <= 0)"));
$a('endpoint whitelists run_type',
    str_contains($src, "['regular', 'off_cycle', 'correction', 'final']"));
$a('endpoint enforces 25MB upload cap',
    str_contains($src, '25 * 1024 * 1024'));
$a('endpoint invokes payrollImportRunCsv',
    str_contains($src, 'payrollImportRunCsv($pdo, $tid, $payPeriodId, $tmp, $runType)'));

echo "\n==========================\n";
echo "Payroll CSV importer smoke: $pass ✓ / $fail ✗\n";
echo "==========================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
