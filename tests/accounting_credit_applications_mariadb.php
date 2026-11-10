<?php
/** Synthetic, rollback-only application, cash interaction, dated reversal and isolation. */
declare(strict_types=1);
$database = (string) getenv('DB_NAME');
$local = preg_match('/^coreaccounting_blank_test\d+$/D', $database);
$ci = $database === 'coreaccounting_ci' && getenv('CI') === 'true';
$hosted = $database === 'muzqvdvqbx' && in_array('--execute', $argv, true);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging' || (!$local && !$ci && !$hosted)) exit(2);
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/opening_cutover.php';
require_once __DIR__ . '/../modules/accounting/lib/credit_applications.php';
require_once __DIR__ . '/../modules/billing/lib/statement.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
$pdo = getDB();
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Database identity mismatch.');
$t = $hosted ? 999 : 1;
if ($hosted && (int) $pdo->query('SELECT is_simulation FROM tenants WHERE id=999')->fetchColumn() !== 1) exit(2);
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks) {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$reject = static function (callable $call, string $label) use ($assert) {
    try { $call(); } catch (InvalidArgumentException | RuntimeException $e) { $assert(true, $label); return; }
    $assert(false, $label);
};
$read = static function (string $table, int $id) use ($pdo, $t) {
    $q = $pdo->prepare("SELECT * FROM $table WHERE tenant_id = :t AND id = :id");
    $q->execute(['t' => $t, 'id' => $id]); return $q->fetch(PDO::FETCH_ASSOC);
};
$baseline = (int) $pdo->query('SELECT COUNT(*) FROM accounting_credit_applications')->fetchColumn();
$pdo->beginTransaction();
try {
    $entity = accountingCreateEntityWithCalendar($pdo, $t, [
        'code' => 'CREDITTEST', 'legal_name' => 'Synthetic Credit Test LLC', 'country' => 'US',
        'base_currency' => 'USD', 'entity_type' => 'llc', 'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
    ], 2026);
    $e = (int) $entity['entity_id'];
    $balance = "Account code,Balance\n1000,1000.00\n";
    $ar = "Invoice number,Client name,Issue date,Due date,Open amount,Source type,Source reference\n"
        . "CRTEST-AR,Synthetic Credit Client,2026-09-01,2026-10-15,150.00,Invoice,fixture-invoice\n"
        . "CRTEST-CR,Synthetic Credit Client,2026-09-02,2026-09-02,-25.00,Payment,fixture-credit\n";
    $ap = "Bill number,Vendor name,Bill date,Due date,Open amount,Source type,Source reference\n"
        . "CRTEST-AP,Synthetic Credit Vendor,2026-09-01,2026-10-15,80.00,Bill,fixture-bill\n"
        . "CRTEST-VC,Synthetic Credit Vendor,2026-09-02,2026-09-02,-15.00,Journal Entry,fixture-vendor-credit\n";
    $p = accountingOpeningCutoverReview($pdo, $t, $e, $balance, $ar, $ap, '2026-10-01', true);
    $assert($p['error_count'] === 0, 'fixture previews cleanly');
    accountingOpeningCutoverCommit($pdo, $t, $e, $balance, $ar, $ap, $p['preview_token'], null, '2026-10-01', true);
    $ledgerBefore = accountingTrialBalance($t, '2026-10-20', $e);
    foreach (['ar' => ['billing_invoices', '25.00'], 'ap' => ['ap_bills', '15.00']] as $side => [$table, $amount]) {
        $rows = accountingCreditList($pdo, $t, $e, $side)['documents'];
        $credit = array_values(array_filter($rows, fn($r) => (float) $r['total'] < 0))[0];
        $doc = array_values(array_filter($rows, fn($r) => (float) $r['total'] > 0))[0];
        $request = ['entity_id' => $e, 'side' => $side, 'credit_id' => $credit['id'], 'document_id' => $doc['id'],
            'application_date' => '2026-10-02', 'amount' => $amount];
        $reject(fn() => accountingCreditReview($pdo, $t, array_replace($request, ['amount' => '999.00'])), 'excess amount rejected');
        $reject(fn() => accountingCreditReview($pdo, $t, array_replace($request, ['amount' => '0.001'])), 'fractional cent rejected');
        $reject(fn() => accountingCreditReview($pdo, $t, array_replace($request, ['application_date' => '2026-09-30'])), 'opening snapshot cannot be altered');
        $reject(fn() => accountingCreditReview($pdo, $t + 100000, $request), 'other tenant cannot access sources');
        $reject(fn() => accountingCreditReview($pdo, $t, array_replace($request, ['entity_id' => $e + 100000])), 'other entity rejected');
        $partyField = $side === 'ar' ? 'client_company_id' : 'vendor_company_id';
        $pdo->exec('SAVEPOINT mismatch');
        $pdo->exec("UPDATE $table SET $partyField = NULL WHERE id = " . (int) $doc['id']);
        $reject(fn() => accountingCreditReview($pdo, $t, $request), 'unidentified party cannot receive a credit');
        $pdo->exec('ROLLBACK TO SAVEPOINT mismatch');
        if ($side === 'ap') {
            $pdo->exec("INSERT INTO ap_payments (tenant_id, vendor_name, pay_date, amount, status)
                VALUES ($t, 'Synthetic Credit Vendor', '2026-10-02', 70, 'queued')");
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->exec("INSERT INTO ap_payment_allocations (payment_id, bill_id, amount_applied)
                VALUES ($paymentId, " . (int) $doc['id'] . ", 70)");
            $reject(fn() => accountingCreditReview($pdo, $t, $request), 'reserved vendor payment reduces available balance');
            $pdo->exec('ROLLBACK TO SAVEPOINT mismatch');
        }
        $pdo->exec("UPDATE $table SET currency = 'EUR' WHERE id = " . (int) $doc['id']);
        $reject(fn() => accountingCreditReview($pdo, $t, $request), 'currency mismatch rejected');
        $pdo->exec('ROLLBACK TO SAVEPOINT mismatch');
        $beforeCount = (int) $pdo->query('SELECT COUNT(*) FROM accounting_credit_applications')->fetchColumn();
        $preview = accountingCreditReview($pdo, $t, $request);
        $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_credit_applications')->fetchColumn() === $beforeCount, 'review is read-only');
        $request['preview_token'] = $preview['preview_token'];
        $reject(fn() => accountingCreditCommit($pdo, $t, array_replace($request, ['amount' => '1.00']), null), 'changed preview amount rejected');
        $result = accountingCreditCommit($pdo, $t, $request, null);
        $assert(!$result['idempotent_replay'], 'first application recorded');
        $assert(accountingCreditCommit($pdo, $t, $request, null)['idempotent_replay'], 'exact retry replays');
        $settledCredit = $read($table, (int) $credit['id']);
        $settledDoc = $read($table, (int) $doc['id']);
        $assert($settledCredit['amount_due'] === '0.00' && $settledCredit['status'] === 'paid', 'credit fully applied');
        $assert($settledDoc['amount_paid'] === '0.00' && $settledDoc['amount_credited'] === $amount, 'credit is distinct from cash');
        $assert((float) $settledDoc['amount_due'] === (float) $doc['total'] - (float) $amount, 'target balance reduced');
        $reject(fn() => accountingReverseJe($t, (int) $settledDoc['journal_entry_id'], 'fixture'), 'active credit protects source journal');
        $reject(fn() => accountingDeleteJe($t, (int) $settledDoc['journal_entry_id'], 'fixture'), 'source cannot be deleted');
        if ($side === 'ar') {
            $old = billingStatementOpenInvoices($t, 'Synthetic Credit Client', '2026-10-01', $e);
            $new = billingStatementOpenInvoices($t, 'Synthetic Credit Client', '2026-10-02', $e);
            $assert(count($old) === 2 && count($new) === 1, 'as-of statement retains then consumes credit');
            $assert((float) $new[0]['amount_due'] === 125.0, 'statement reflects application');
            $pdo->exec('SAVEPOINT cash_test');
            $pay = $pdo->prepare("INSERT INTO billing_payments (tenant_id, client_name, received_at, method, currency, amount, unallocated_amount)
                VALUES (:t, 'Synthetic Credit Client', '2026-10-03', 'check', 'USD', 20, 20)");
            $pay->execute(['t' => $t]);
            billingAllocatePayment((int) $pdo->lastInsertId(), ['allocations' => [['invoice_id' => $doc['id'], 'amount' => 20]], 'defer_pwp' => true]);
            $afterCash = $read($table, (int) $doc['id']);
            $assert($afterCash['amount_due'] === '105.00' && $afterCash['amount_paid'] === '20.00' && $afterCash['amount_credited'] === '25.00', 'cash allocation preserves credit');
            $pdo->exec('ROLLBACK TO SAVEPOINT cash_test');
        } else {
            apRefreshReleasedPaymentBills($pdo, $t, [(int) $doc['id'], (int) $credit['id']]);
            $assert($read($table, (int) $doc['id'])['amount_due'] === '65.00', 'AP refresh preserves credit');
            $aging = apComputeAging($t, '2026-10-02', $e);
            $assert((float) $aging[0]['total_due'] === 65.0, 'AP aging ties after application');
        }
        $reject(fn() => accountingCreditReverse($pdo, $t, $result['id'], '2026-10-01', 'fixture', null), 'reversal cannot precede application');
        $pdo->exec('SAVEPOINT reversal_failure');
        $pdo->exec("UPDATE $table SET status = 'void' WHERE id = " . (int) $doc['id']);
        $reject(fn() => accountingCreditReverse($pdo, $t, $result['id'], '2026-10-05', 'Late failure', null), 'invalid target rejects reversal');
        $assert($read($table, (int) $credit['id'])['amount_due'] === '0.00', 'late reversal failure rolls back first balance update');
        $pdo->exec('ROLLBACK TO SAVEPOINT reversal_failure');
        accountingCreditReverse($pdo, $t, $result['id'], '2026-10-05', 'Fixture correction', null);
        $assert(accountingCreditReverse($pdo, $t, $result['id'], '2026-10-05', 'Fixture correction', null)['idempotent_replay'], 'reversal retry replays');
        $assert($read($table, (int) $credit['id'])['amount_due'] === '-' . $amount, 'reversal restores credit');
        $assert($read($table, (int) $doc['id'])['amount_due'] === $doc['amount_due'], 'reversal restores target');
        if ($side === 'ar') {
            $assert(count(billingStatementOpenInvoices($t, 'Synthetic Credit Client', '2026-10-04', $e)) === 1, 'reversal leaves prior statement intact');
            $assert(count(billingStatementOpenInvoices($t, 'Synthetic Credit Client', '2026-10-05', $e)) === 2, 'reversal appears on effective date');
        }
        $reject(fn() => accountingCreditReview($pdo, $t, $request), 'reapplication cannot predate restored credit');
        $request['application_date'] = '2026-10-05';
        $again = accountingCreditReview($pdo, $t, $request);
        $assert($again['preview_token'] !== $preview['preview_token'], 'new review after reversal has new identity');
        $pdo->exec("UPDATE accounting_periods SET status = 'closed' WHERE tenant_id = $t AND entity_id = $e AND start_date = '2026-10-01'");
        $reject(fn() => accountingCreditReview($pdo, $t, $request), 'closed period rejected');
        $pdo->exec("UPDATE accounting_periods SET status = 'open' WHERE tenant_id = $t AND entity_id = $e AND start_date = '2026-10-01'");
    }
    $assert(accountingTrialBalance($t, '2026-10-20', $e) === $ledgerBefore, 'applications and corrections never alter the ledger');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_credit_applications')->fetchColumn() === $baseline, 'fixture rolled back');
echo json_encode(['status' => 'passed', 'checks' => $checks, 'rollback' => true]) . PHP_EOL;
