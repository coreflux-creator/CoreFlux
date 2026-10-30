<?php
/** Entity-scoped liquidity must not include another entity's cash or obligations. */
declare(strict_types=1);

putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__ . '/../core/treasury/liquidity_projection.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$pdo = getDB();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE accounting_bank_accounts (
    id INTEGER, tenant_id INTEGER, entity_id INTEGER, gl_account_code TEXT, status TEXT
)');
$pdo->exec('CREATE TABLE accounting_accounts (id INTEGER, tenant_id INTEGER, code TEXT)');
$pdo->exec('CREATE TABLE accounting_journal_entry_lines (
    je_id INTEGER, account_id INTEGER, tenant_id INTEGER, debit REAL, credit REAL
)');
$pdo->exec('CREATE TABLE accounting_journal_entries (
    id INTEGER, tenant_id INTEGER, entity_id INTEGER, status TEXT, posting_date TEXT
)');
$pdo->exec('CREATE TABLE billing_invoices (
    tenant_id INTEGER, entity_id INTEGER, status TEXT, due_date TEXT,
    amount_due REAL, total REAL, amount_paid REAL
)');
$pdo->exec('CREATE TABLE treasury_payments (
    tenant_id INTEGER, entity_id INTEGER, status TEXT, payment_date TEXT,
    amount REAL, payee_name TEXT
)');
$pdo->exec('CREATE TABLE ap_bills (
    id INTEGER, tenant_id INTEGER, entity_id INTEGER, status TEXT, due_date TEXT,
    amount_due REAL, vendor_name TEXT
)');

$pdo->exec("INSERT INTO accounting_bank_accounts VALUES
    (1,1,10,'1000','active'), (2,1,20,'1010','active')");
$pdo->exec("INSERT INTO accounting_accounts VALUES (1,1,'1000'), (2,1,'1010')");
$pdo->exec("INSERT INTO accounting_journal_entries VALUES
    (1,1,10,'posted','2026-10-01'), (2,1,20,'posted','2026-10-01'),
    (3,1,20,'posted','2026-10-01'), (4,1,10,'posted','2026-10-01')");
$pdo->exec('INSERT INTO accounting_journal_entry_lines VALUES
    (1,1,1,100,0), (2,1,1,900,0), (3,2,1,200,0), (4,2,1,700,0)');
$pdo->exec("INSERT INTO billing_invoices VALUES
    (1,10,'sent','2026-10-20',10,10,0),
    (1,20,'sent','2026-10-20',20,20,0)");
$pdo->exec("INSERT INTO treasury_payments VALUES
    (1,10,'scheduled','2026-10-21',5,'Payment A'),
    (1,20,'scheduled','2026-10-21',6,'Payment B')");
$pdo->exec("INSERT INTO ap_bills VALUES
    (1,1,10,'approved','2026-10-22',3,'Vendor A'),
    (2,1,20,'approved','2026-10-22',4,'Vendor B')");

$all = liquidityBaselineDatasets(1, '2026-10-07', '2026-10-31');
$one = liquidityBaselineDatasets(1, '2026-10-07', '2026-10-31', 10);
$two = liquidityBaselineDatasets(1, '2026-10-07', '2026-10-31', 20);
$withoutBill = liquidityBaselineDatasets(1, '2026-10-07', '2026-10-31', 10, 1);

$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$assert($all['starting_cash'] === 300.0, 'Tenant cash must exclude cross-entity journals on a linked bank GL');
$assert($one['starting_cash'] === 100.0 && $two['starting_cash'] === 200.0,
    'Each entity sees only its own linked bank cash journals');
$assert($all['bank_count'] === 2 && $one['bank_count'] === 1 && $two['bank_count'] === 1,
    'Bank-account readiness is scoped to the selected entity');
$assert(count($one['ar']) === 1 && (float) $one['ar'][0]['due'] === 10.0
    && count($two['ar']) === 1 && (float) $two['ar'][0]['due'] === 20.0,
    'Open receivables do not cross entity boundaries');
$assert(count($one['tp']) === 1 && (float) $one['tp'][0]['amount'] === 5.0
    && count($two['tp']) === 1 && (float) $two['tp'][0]['amount'] === 6.0,
    'Scheduled treasury payments do not cross entity boundaries');
$assert(count($one['ap']) === 1 && (int) $one['ap'][0]['id'] === 1
    && count($two['ap']) === 1 && (int) $two['ap'][0]['id'] === 2,
    'Open vendor bills do not cross entity boundaries');
$assert($withoutBill['ap'] === [] && count($withoutBill['ar']) === 1,
    'Per-bill overlay excludes only its own bill');

echo "Liquidity entity scope: {$checks} checks passed.\n";
