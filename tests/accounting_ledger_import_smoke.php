<?php
/** Read-only CSV review, exact replay, and period lifecycle boundaries. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../modules/accounting/lib/ledger_import.php';
require_once __DIR__ . '/../core/rbac/legacy_map.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE accounting_entities (id INTEGER, tenant_id INTEGER, base_currency TEXT, active INTEGER)');
$pdo->exec('CREATE TABLE accounting_periods (id INTEGER, tenant_id INTEGER, entity_id INTEGER,
    period_number INTEGER, start_date TEXT, end_date TEXT, status TEXT)');
$pdo->exec('CREATE TABLE accounting_accounts (id INTEGER, tenant_id INTEGER, code TEXT,
    active INTEGER, is_postable INTEGER, currency TEXT)');
$pdo->exec('CREATE TABLE accounting_bank_accounts (id INTEGER, tenant_id INTEGER, gl_account_code TEXT)');
$pdo->exec('CREATE TABLE accounting_dimensions (id INTEGER, tenant_id INTEGER, dim_key TEXT, label TEXT,
    data_type TEXT, reference_table TEXT, required_default INTEGER, sort_order INTEGER, active INTEGER)');
$pdo->exec('CREATE TABLE accounting_posting_idempotency (tenant_id INTEGER, idempotency_key TEXT, je_id INTEGER)');
$pdo->exec('CREATE TABLE accounting_journal_entries (id INTEGER, tenant_id INTEGER, entity_id INTEGER,
    posting_date TEXT, currency TEXT, memo TEXT, source_module TEXT, status TEXT)');
$pdo->exec('CREATE TABLE accounting_journal_entry_lines (tenant_id INTEGER, je_id INTEGER,
    line_no INTEGER, account_id INTEGER, debit TEXT, credit TEXT, memo TEXT, dim_json TEXT)');
$pdo->exec("INSERT INTO accounting_entities VALUES (10,1,'USD',1),(20,1,'EUR',1),(30,2,'USD',1)");
$pdo->exec("INSERT INTO accounting_periods VALUES
    (1,1,10,1,'2026-01-01','2026-01-31','open'),
    (2,1,10,2,'2026-02-01','2026-02-28','closed'),
    (3,1,20,1,'2026-01-01','2026-01-31','open')");
$pdo->exec("INSERT INTO accounting_accounts VALUES
    (1,1,'6100',1,1,NULL),(2,1,'3000',1,1,NULL),
    (3,1,'EUR-ONLY',1,1,'EUR'),(4,2,'OTHER',1,1,NULL),
    (5,1,'1100',1,1,NULL),(6,1,'1000',1,1,NULL)");
$pdo->exec("INSERT INTO accounting_bank_accounts VALUES (1,1,'1000')");

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$journal = [
    'entity_id' => 10, 'posting_date' => '2026-01-15', 'memo' => 'Opening test',
    'lines' => [
        ['account_code' => '6100', 'debit' => '12.34', 'credit' => '', 'memo' => 'Cost', 'dims' => []],
        ['account_code' => '3000', 'debit' => '', 'credit' => '12.34', 'memo' => 'Equity', 'dims' => []],
    ],
];
$review = accountingImportReviewJe(1, 'BATCH-1', $journal);
$assert($review['errors'] === [], 'balanced, active-entity journal reviews successfully');
$assert($review['journal']['currency'] === 'USD'
    && $review['journal']['lines'][0]['dims']['legal_entity'] === 10,
    'review derives entity currency and stamps line dimensions');
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_periods')->fetchColumn() === 3,
    'preview never creates accounting periods');

$changed = $journal;
$changed['posting_date'] = '2026-02-15';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'closed'),
    'closed accounting period is refused');
$changed['posting_date'] = '2026-03-15';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'create the accounting period'),
    'missing period is reported without auto-creation');
$changed['posting_date'] = '2026-02-30';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'real YYYY-MM-DD'),
    'impossible date is refused');
$changed['posting_date'] = '02/15/2026';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'real YYYY-MM-DD'),
    'non-ISO date is refused rather than silently normalized');
$changed = $journal;
$changed['entity_id'] = '10.5';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'whole number'),
    'fractional entity id is refused');
$changed = $journal;
$changed['lines'][1]['credit'] = '12.33';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'balance to the cent'),
    'unbalanced file is caught in preview');
$changed['lines'][1]['credit'] = '12.345';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'two decimal places'),
    'excess precision is refused instead of rounded silently');
$changed = $journal;
$changed['lines'][0]['account_code'] = 'EUR-ONLY';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'currency differs'),
    'account currency must match entity');
$changed['lines'][0]['account_code'] = 'OTHER';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'active, postable account'),
    'other-tenant account is unavailable');
$changed['lines'][0]['account_code'] = '1100';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'invoice, bill'),
    'subledger control account is unavailable to CSV journals');
$changed['lines'][0]['account_code'] = '1000';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'bank workflow'),
    'bank-linked cash account is unavailable to CSV journals');
$changed = $journal;
$changed['entity_id'] = 30;
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-2', $changed)['errors']), 'active legal entity'),
    'other-tenant entity is unavailable');

$intent = $review['journal'];
$pdo->prepare('INSERT INTO accounting_posting_idempotency VALUES (1,?,1)')
    ->execute([$intent['idempotency_key']]);
$pdo->exec("INSERT INTO accounting_journal_entries VALUES
    (1,1,10,'2026-01-15','USD','Opening test','manual','posted')");
$pdo->exec("INSERT INTO accounting_journal_entry_lines VALUES
    (1,1,1,1,'12.34','0.00','Cost','{\"legal_entity\":10}'),
    (1,1,2,2,'0.00','12.34','Equity','{\"legal_entity\":10}')");
$assert(accountingImportReviewJe(1, 'BATCH-1', $journal)['errors'] === [],
    'exact batch replay remains valid');
$pdo->exec("UPDATE accounting_periods SET status = 'closed' WHERE id = 1");
$assert(accountingImportReviewJe(1, 'BATCH-1', $journal)['errors'] === [],
    'exact posted replay remains available after period close');
$changed = $journal;
$changed['memo'] = 'Changed purpose';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-1', $changed)['errors']), 'already used'),
    'changed header under same batch is refused');
$changed = $journal;
$changed['lines'][0]['debit'] = '12.35';
$changed['lines'][1]['credit'] = '12.35';
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-1', $changed)['errors']), 'different journal lines'),
    'balanced but changed line under same batch is refused');
$pdo->exec("UPDATE accounting_journal_entries SET status = 'reversed' WHERE id = 1");
$assert(str_contains(implode(' ', accountingImportReviewJe(1, 'BATCH-1', $journal)['errors']), 'no-longer-posted'),
    'reversed batch is not reported as a successful replay');
$pdo->exec("UPDATE accounting_periods SET status = 'open' WHERE id = 1");

$period = ['entity_id' => 10, 'period_number' => 3,
    'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'];
$assert(accountingImportPeriodError(1, $period) === null,
    'new open period passes read-only review');
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_periods')->fetchColumn() === 3,
    'period preview creates no rows');
$changed = $period;
$changed['status'] = 'closed';
$assert(str_contains((string) accountingImportPeriodError(1, $changed), 'Periods'),
    'period CSV cannot close a period');
$changed = $period;
$changed['entity_id'] = 30;
$assert(str_contains((string) accountingImportPeriodError(1, $changed), 'active legal entity'),
    'period CSV cannot use another tenant entity');
$changed = $period;
$changed['start_date'] = '2026-01-15';
$assert(str_contains((string) accountingImportPeriodError(1, $changed), 'overlaps'),
    'overlap with stored period is refused');
$changed = $period;
$changed['start_date'] = '2026-02-30';
$assert(str_contains((string) accountingImportPeriodError(1, $changed), 'real YYYY-MM-DD'),
    'invalid period date is refused');
$changed = $period;
$changed['entity_id'] = '10.5';
$assert(str_contains((string) accountingImportPeriodError(1, $changed), 'whole number'),
    'fractional period entity id is refused');
$changed = $period;
$changed['period_number'] = '3.5';
$assert(str_contains((string) accountingImportPeriodError(1, $changed), '1 to 53'),
    'fractional period number is refused');
$assert(accountingImportPeriodError(1, [
    'entity_id' => 10, 'period_number' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open',
]) === null, 'exact existing period is idempotent');
$overlappingRows = [2 => $period, 3 => $period + []];
$assert(isset(accountingImportPeriodErrors(1, $overlappingRows, [])[3]),
    'overlap inside the same file is refused');
$assert(RbacLegacyMap::resolve('accounting.ledger.import') === ['accounting', 'write']
    && RbacLegacyMap::resolve('accounting.period.close') === ['accounting', 'admin'],
    'import and period capabilities are explicitly mapped');
$route = file_get_contents(__DIR__ . '/../modules/accounting/api/import.php');
$assert(str_contains($route, "'je' => 'accounting.je.post'")
    && str_contains($route, "'periods' => 'accounting.period.close'")
    && str_contains($route, 'Journal and period imports are all-or-nothing'),
    'route requires domain permissions and refuses partial financial imports');
$assert(!str_contains($route, "'posting_date' => ['label' => 'Posting date', 'required' => true, 'type' => 'date']")
    && !str_contains($route, "'start_date'    => ['label' => 'Start date',    'required' => true, 'type' => 'date']"),
    'accounting CSV retains raw date text for strict calendar validation');
$periodRoute = file_get_contents(__DIR__ . '/../modules/accounting/api/periods.php');
$periodUi = file_get_contents(__DIR__ . '/../modules/accounting/ui/Periods.jsx');
$assert(str_contains($periodRoute, "['open', 'future']")
    && !str_contains($periodUi, '<option value="closed">')
    && str_contains($periodUi, '<option value="future">'),
    'new periods can only start open or future through the screen and API');
$assert(str_contains($periodRoute, "DateTimeImmutable::createFromFormat('!Y-m-d'")
    && str_contains($periodRoute, 'active = 1 FOR UPDATE')
    && !str_contains($periodRoute, 'status, created_at'),
    'period API validates real dates, tenant entity, and serialized insertion');
$ledger = file_get_contents(__DIR__ . '/../modules/accounting/lib/accounting.php');
$assert(substr_count($ledger, "!in_array(\$period['status'], ['open', 'reopened'], true)") === 3,
    'posting, transaction recheck and draft validation allow only open or reopened periods');

echo "Accounting ledger import: {$checks} checks passed.\n";
