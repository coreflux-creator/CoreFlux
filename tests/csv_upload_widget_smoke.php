<?php
/**
 * csv_upload_widget_smoke.php
 *
 * Locks in the tiny CSV upload UI surfaces wired in this batch:
 *   1. `dashboard/src/components/CsvUploadWidget.jsx` — reusable
 *      file-picker → multipart POST → result panel component.
 *   2. Treasury bank-account drawer renders it for deposit accounts,
 *      wired to `/api/v1/treasury/import-csv` with the matching
 *      `bank_account_id`. Hidden for liability accounts.
 *   3. Payroll PayPeriods table shows an "Import CSV" toggle for
 *      draft/open periods that opens the widget inline, wired to
 *      `/api/payroll/import_csv.php` with the matching `pay_period_id`.
 *
 * Run:  php -d zend.assertions=1 tests/csv_upload_widget_smoke.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$pass = 0; $fail = 0; $failures = [];
$a = function (string $label, bool $cond) use (&$pass, &$fail, &$failures) {
    if ($cond) { $pass++; echo "  ✓ $label\n"; }
    else       { $fail++; $failures[] = $label; echo "  ✗ $label\n"; }
};

echo "CSV upload widget + surface smoke\n";
echo "=================================\n";

// 1) Reusable widget component.
echo "\n1. CsvUploadWidget.jsx component\n";
$wpath = "$root/dashboard/src/components/CsvUploadWidget.jsx";
$a('component file exists', file_exists($wpath));
$wsrc = (string) file_get_contents($wpath);
$a('exports a single default function CsvUploadWidget',
    str_contains($wsrc, 'export default function CsvUploadWidget('));
$a('accepts testIdPrefix / endpoint / extraFields / accept / label / hint / onSuccess props',
    str_contains($wsrc, 'testIdPrefix = ')
    && str_contains($wsrc, 'endpoint,')
    && str_contains($wsrc, 'extraFields = {}')
    && str_contains($wsrc, 'accept')
    && str_contains($wsrc, 'label')
    && str_contains($wsrc, 'hint')
    && str_contains($wsrc, 'onSuccess'));
$a('uses FormData multipart POST',
    str_contains($wsrc, 'new FormData()')
    && str_contains($wsrc, "method: 'POST'")
    && str_contains($wsrc, 'body: form'));
$a('extraFields appended into form',
    str_contains($wsrc, 'Object.entries(extraFields).forEach(([k, v]) => form.append(k, String(v)))'));
$a('exposes stable testids for widget / file / submit / error / result',
    str_contains($wsrc, '`${testIdPrefix}-widget`')
    && str_contains($wsrc, '`${testIdPrefix}-file`')
    && str_contains($wsrc, '`${testIdPrefix}-submit`')
    && str_contains($wsrc, '`${testIdPrefix}-error`')
    && str_contains($wsrc, '`${testIdPrefix}-result`'));
$a('invokes onSuccess callback when result lands',
    str_contains($wsrc, 'if (onSuccess) onSuccess(json)'));
$a('renders collapsible per-row error details panel',
    str_contains($wsrc, 'result.errors.length} row error')
    && str_contains($wsrc, '<details'));

// 2) Treasury surface — AccountTransactions wires it for deposits.
echo "\n2. Treasury AccountTransactions wiring\n";
$tsrc = (string) file_get_contents("$root/modules/treasury/ui/AccountTransactions.jsx");
$a('imports CsvUploadWidget',
    str_contains($tsrc, "import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget'"));
$a('renders widget ONLY when type === "deposit"',
    str_contains($tsrc, "{type === 'deposit' && (")
    && str_contains($tsrc, '<CsvUploadWidget'));
$a('points endpoint at /api/v1/treasury/import-csv',
    str_contains($tsrc, 'endpoint="/api/v1/treasury/import-csv"'));
$a('passes bank_account_id={accountId} as extraField',
    str_contains($tsrc, 'extraFields={{ bank_account_id: accountId }}'));
$a('hint mentions Debit + Credit alternative',
    str_contains($tsrc, 'Amount (or Debit + Credit)'));
$a('onSuccess reloads the transactions list',
    str_contains($tsrc, 'onSuccess={() => reload()}'));
$a('plaid-connected accounts get a different label',
    str_contains($tsrc, 'plaidItemExternalId'));

// 3) Payroll surface — PayPeriods table inline drawer.
echo "\n3. Payroll PayPeriods wiring\n";
$psrc = (string) file_get_contents("$root/modules/payroll/ui/PayPeriods.jsx");
$a('imports CsvUploadWidget',
    str_contains($psrc, "import CsvUploadWidget from '../../../dashboard/src/components/CsvUploadWidget'"));
$a('declares csvPeriodId state',
    str_contains($psrc, 'const [csvPeriodId, setCsvPeriodId]'));
$a('per-row Import CSV toggle button with stable testid',
    str_contains($psrc, 'data-testid={`payroll-period-csv-toggle-${p.id}`}'));
$a('toggle is gated to draft/open status',
    str_contains($psrc, "(p.status === 'draft' || p.status === 'open')"));
$a('expanded row renders the widget inline',
    str_contains($psrc, 'data-testid={`payroll-period-csv-row-${p.id}`}')
    && str_contains($psrc, '<CsvUploadWidget'));
$a('points endpoint at /api/payroll/import_csv.php',
    str_contains($psrc, 'endpoint="/api/payroll/import_csv.php"'));
$a('passes pay_period_id={p.id} + run_type=regular as extraFields',
    str_contains($psrc, 'extraFields={{ pay_period_id: p.id, run_type: \'regular\' }}'));
$a('onSuccess routes to the new run detail page',
    str_contains($psrc, '#/modules/payroll/runs/${r.run_id}'));
$a('uses React.Fragment so two <tr> rows share one key',
    str_contains($psrc, '<React.Fragment key={p.id}>'));

// 4) Underlying endpoints still wired (sanity).
echo "\n4. Endpoints reachable\n";
$a('/api/treasury/import_csv.php exists',
    file_exists("$root/modules/treasury/api/import_csv.php"));
$a('/api/payroll/import_csv.php exists',
    file_exists("$root/modules/payroll/api/import_csv.php"));

echo "\n=================================\n";
echo "CSV upload widget smoke: $pass ✓ / $fail ✗\n";
echo "=================================\n";
if ($fail > 0) {
    foreach ($failures as $msg) echo " ! $msg\n";
    exit(1);
}
exit(0);
