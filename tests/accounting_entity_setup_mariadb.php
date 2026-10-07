<?php
/** Rollback-only legal-entity/calendar acceptance on disposable local MariaDB. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/entity_setup.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$tenantId = 1;
$before = (int) $pdo->query('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = 1')->fetchColumn();
$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$profile = [
    'code' => 'TEST' . strtoupper(bin2hex(random_bytes(3))),
    'legal_name' => 'Rollback-only subsidiary',
    'country' => 'US', 'base_currency' => 'USD', 'entity_type' => 'llc',
    'accounting_basis' => 'accrual', 'fiscal_year_start_month' => 1,
];

$pdo->beginTransaction();
try {
    $created = accountingCreateEntityWithCalendar($pdo, $tenantId, $profile, 2024);
    $assert($pdo->inTransaction(), 'caller transaction remains open');
    $assert($created['entity_id'] > 0 && $created['calendar_id'] > 0
        && $created['periods_created'] === 12, 'entity/calendar created together');
    $calendar = $pdo->prepare(
        'SELECT start_date, end_date, calendar_type, period_count
           FROM accounting_fiscal_calendars WHERE tenant_id = :t AND id = :id'
    );
    $calendar->execute(['t' => $tenantId, 'id' => $created['calendar_id']]);
    $row = $calendar->fetch(PDO::FETCH_ASSOC);
    $assert($row['start_date'] === '2024-01-01' && $row['end_date'] === '2024-12-31'
        && $row['calendar_type'] === 'calendar_year' && (int) $row['period_count'] === 12,
        'explicit fiscal year reaches canonical calendar');
    $periods = $pdo->prepare(
        'SELECT period_number, start_date, end_date, status
           FROM accounting_periods WHERE tenant_id = :t AND entity_id = :e ORDER BY period_number'
    );
    $periods->execute(['t' => $tenantId, 'e' => $created['entity_id']]);
    $rows = $periods->fetchAll(PDO::FETCH_ASSOC);
    $assert(count($rows) === 12, 'twelve periods are present');
    $assert($rows[0]['start_date'] === '2024-01-01' && $rows[11]['end_date'] === '2024-12-31',
        'periods cover the year without a gap');
    $assert($rows[1]['end_date'] === '2024-02-29' && $rows[1]['status'] === 'open',
        'leap-year month end and initial state are correct');
    $resolved = accountingResolvePeriod($tenantId, $created['entity_id'], '2024-02-29', false);
    $assert((int) $resolved['calendar_id'] === $created['calendar_id']
        && (int) $resolved['period_number'] === 2, 'canonical period resolver finds the new calendar');
    $posted = accountingPostJe($tenantId, [
        'entity_id' => $created['entity_id'], 'posting_date' => '2024-02-29',
        'currency' => 'USD', 'memo' => 'Rollback-only entity setup posting',
        'idempotency_key' => 'entity-setup-' . bin2hex(random_bytes(8)),
        'lines' => [
            ['account_code' => '6990', 'debit' => '1.00', 'credit' => '0.00'],
            ['account_code' => '3000', 'debit' => '0.00', 'credit' => '1.00'],
        ],
    ], null, true);
    $assert($posted['status'] === 'posted' && $posted['total_debit'] === 1.0,
        'new entity posts through the canonical ledger');
    $journal = $pdo->prepare('SELECT entity_id, period_id, currency FROM accounting_journal_entries WHERE id = :id');
    $journal->execute(['id' => $posted['je_id']]);
    $journalRow = $journal->fetch(PDO::FETCH_ASSOC);
    $assert((int) $journalRow['entity_id'] === $created['entity_id']
        && (int) $journalRow['period_id'] === (int) $resolved['id']
        && $journalRow['currency'] === 'USD', 'journal retains entity, period and currency');
    try {
        accountingCreateEntityWithCalendar($pdo, $tenantId, $profile, 2024);
        throw new RuntimeException('Duplicate entity code was accepted');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'already used'), 'duplicate code rejected');
    }
    try {
        accountingCreateEntityWithCalendar($pdo, $tenantId, array_replace($profile, [
            'code' => $profile['code'] . 'B', 'parent_entity_id' => 999999999,
        ]), 2024);
        throw new RuntimeException('Invalid parent entity was accepted');
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), 'Parent entity'), 'unknown parent rejected');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = 1')->fetchColumn() === $before + 1,
        'failed creates left no extra entity');
    $pdo->exec('UPDATE accounting_entities SET active = 0 WHERE tenant_id = 1');
    try {
        accountingDefaultEntity($tenantId);
        throw new RuntimeException('Missing default entity was silently created');
    } catch (AccountingSetupRequired $error) {
        $assert(str_contains($error->getMessage(), 'Set up an active legal entity'),
            'missing entity asks for explicit setup');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = 1')->fetchColumn() === $before + 1,
        'missing default did not insert a generic entity');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert((int) $pdo->query('SELECT COUNT(*) FROM accounting_entities WHERE tenant_id = 1')->fetchColumn() === $before,
    'rollback removed entity and its calendar');
echo "Accounting entity setup MariaDB: {$checks} checks passed.\n";
