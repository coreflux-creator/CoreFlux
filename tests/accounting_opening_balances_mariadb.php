<?php
/** Rollback-only first-run cutover check on a disposable local MariaDB tenant. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/opening_balances.php';
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$assert(accountingOpeningSignedCents('$1,234.50') === 123450, 'currency and grouping parse exactly');
$assert(accountingOpeningSignedCents('($12.30)') === -1230, 'parentheses represent negative balance');
$assert(accountingOpeningEquitySummary(-8050) === ['amount' => '80.50', 'negative' => true],
    'debit-side opening equity displays with exact cents');
$postingSource = (string) file_get_contents(__DIR__ . '/../modules/accounting/lib/accounting.php');
$assert((bool) preg_match('/function accountingPostJe\(.*?\$entityLock.*?FOR UPDATE/s', $postingSource),
    'ordinary journal posts serialize on the same legal-entity lock as cutover');
try {
    accountingOpeningSignedCents('1.234');
    throw new RuntimeException('Sub-cent amount was accepted');
} catch (InvalidArgumentException $error) {
    $assert(str_contains($error->getMessage(), 'two decimals'), 'sub-cent amounts rejected');
}
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities WHERE tenant_id = 1 AND code = "MAIN"')->fetchColumn();
$assert($entityId > 0, 'fresh tenant has an explicit legal entity');
$baselineJournals = (int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn();
$baselinePeriods = (int) $pdo->query('SELECT COUNT(*) FROM accounting_periods WHERE tenant_id = 1')->fetchColumn();
$assert($baselineJournals === 0, 'fixture has no preexisting journals');
$primary = $pdo;
$pdo->beginTransaction();
try {
    $entityLock = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = 1 AND id = :e FOR UPDATE');
    $entityLock->execute(['e' => $entityId]);
    $probe = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $probe->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $GLOBALS['pdo'] = $probe;
    try {
        accountingPostJe(1, [
            'entity_id' => $entityId, 'posting_date' => '2025-01-01', 'currency' => 'USD',
            'source_module' => 'manual', 'lines' => [
                ['account_code' => '1000', 'debit' => '1.00', 'credit' => '0.00'],
                ['account_code' => '3900', 'debit' => '0.00', 'credit' => '1.00'],
            ],
        ], null, true);
        throw new RuntimeException('An ordinary journal bypassed the entity lock');
    } catch (PDOException $error) {
        $assert((int) ($error->errorInfo[1] ?? 0) === 1205,
            'ordinary journal waits for the cutover entity lock');
    } finally {
        $GLOBALS['pdo'] = $primary;
        if ($probe->inTransaction()) $probe->rollBack();
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
    === $baselineJournals, 'lock-contention probe leaves no journal');
$csv = "Account code,Balance\n1000,1000.00\n3900,200.00\n";
$pdo->beginTransaction();
try {
    $preview = accountingOpeningReview($pdo, 1, $entityId, $csv);
    $assert($preview['error_count'] === 0 && $preview['posting_date'] === '2024-12-31',
        'preview uses the day before the first fiscal year');
    $assert($preview['balancing_equity']['credit'] === '800.00'
        && $preview['opening_equity'] === ['amount' => '800.00', 'negative' => false]
        && $preview['total_debit'] === '1000.00'
        && $preview['total_credit'] === '1000.00', 'preview shows the balancing equity and exact totals');
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn() === 0
        && (int) $pdo->query('SELECT COUNT(*) FROM accounting_periods WHERE tenant_id = 1')->fetchColumn() === $baselinePeriods,
        'preview writes neither a journal nor a period');
    $invalid = accountingOpeningReview($pdo, 1, $entityId,
        "Account code,Balance\n1100,25.00\n4000,20.00\n1000,10.00\n1000,2.00\n");
    $assert($invalid['error_count'] >= 3 && $invalid['preview_token'] === null,
        'AR control, revenue, and duplicate accounts are blocked');
    $pdo->exec('UPDATE accounting_accounts SET currency = "EUR" WHERE tenant_id = 1 AND code = "3000"');
    $wrongCurrency = accountingOpeningReview($pdo, 1, $entityId, $csv);
    $assert($wrongCurrency['error_count'] > 0 && $wrongCurrency['preview_token'] === null,
        'calculated opening equity must have the entity currency');
    $pdo->exec('UPDATE accounting_accounts SET currency = "USD" WHERE tenant_id = 1 AND code = "3000"');
    try {
        accountingOpeningCommit($pdo, 1, $entityId, $csv, str_repeat('0', 64), null);
        throw new RuntimeException('Stale preview token was accepted');
    } catch (AccountingOpeningConflict $error) {
        $assert(str_contains($error->getMessage(), 'changed since preview'), 'stale preview is refused');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn() === 0,
        'failed commit left no journal');
    $posted = accountingOpeningCommit($pdo, 1, $entityId, $csv, $preview['preview_token'], null);
    $assert($posted['journal_entry_id'] > 0 && !$posted['idempotent_replay'],
        'commit posts one canonical journal');
    $openingPeriod = $pdo->prepare(
        'SELECT period_number, start_date, end_date, status FROM accounting_periods
          WHERE tenant_id = 1 AND entity_id = :e AND start_date = "2024-12-31"'
    );
    $openingPeriod->execute(['e' => $entityId]);
    $period = $openingPeriod->fetch(PDO::FETCH_ASSOC);
    $assert($period && (int) $period['period_number'] === 0
        && $period['start_date'] === '2024-12-31' && $period['end_date'] === '2024-12-31'
        && $period['status'] === 'open', 'one-day cutover period is explicit');
    $same = accountingOpeningCommit($pdo, 1, $entityId, $csv, $preview['preview_token'], null);
    $assert($same['idempotent_replay'] && $same['journal_entry_id'] === $posted['journal_entry_id'],
        'identical retry returns the original journal');
    $changed = accountingOpeningReview($pdo, 1, $entityId,
        "Account code,Balance\n1000,1001.00\n3900,200.00\n");
    $assert($changed['error_count'] > 0 && $changed['preview_token'] === null,
        'changed balances cannot silently replace a posted opening');
    $balance = reportBalanceSheet(1, '2024-12-31', $entityId);
    $assert($balance['balanced'] && (float) $balance['total_assets'] === 1000.0
        && (float) $balance['total_equity'] === 1000.0,
        'cutover balance sheet is balanced on the canonical ledger');
    $flow = reportCashFlowIndirect(1, '2025-01-01', '2025-01-31', $entityId);
    $assert($flow['balanced'] && (float) $flow['cash_beginning'] === 1000.0
        && (float) $flow['cash_ending'] === 1000.0
        && (float) $flow['net_change_in_cash'] === 0.0,
        'new-year cash flow starts with the opening cash, not a false inflow');
    try {
        accountingOpeningReview($pdo, 1, $entityId + 99999, $csv);
        throw new RuntimeException('Other entity was accepted');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'active legal entity'), 'entity ownership is enforced');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_journal_entries WHERE tenant_id = 1')->fetchColumn()
    === $baselineJournals, 'rollback removed the opening journal');
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_periods WHERE tenant_id = 1')->fetchColumn()
    === $baselinePeriods, 'rollback removed the one-day cutover period');
echo "Accounting opening balances MariaDB: {$checks} checks passed.\n";
