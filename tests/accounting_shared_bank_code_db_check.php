<?php
/** Exercise entity-owned bank cash with a reused chart code on disposable CI books. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/core/treasury/liquidity_projection.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/treasury/liquidity_projection.php';
require_once $root . '/core/ai/cash_forecast.php';
require_once $root . '/core/business_integrity.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query("SELECT tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('Disposable administrator missing.');
$entityStmt = $pdo->prepare('SELECT id FROM accounting_entities
    WHERE tenant_id = :tenant AND active = 1 ORDER BY id LIMIT 1');
$entityStmt->execute(['tenant' => $tenantId]);
$firstEntityId = (int) $entityStmt->fetchColumn();
if ($firstEntityId <= 0) throw new RuntimeException('Disposable legal entity missing.');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$cashAt = static fn(?int $entityId): float => (float) liquidityBaselineDatasets(
    $tenantId, '2026-10-11', '2026-10-11', $entityId
)['starting_cash'];
$beforeAll = $cashAt(null);
$beforeFirst = $cashAt($firstEntityId);
$beforeForecast = cashForecastReadOpeningBalanceCents($tenantId, '2026-10-11');

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO accounting_entities
        (tenant_id, code, legal_name, base_currency)
        VALUES (:tenant, :code, :name, "USD")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-CASH-ENTITY-B',
            'name' => 'CI Shared Cash Second Entity']);
    $secondEntityId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, currency, cash_flow_tag)
        VALUES (:tenant, :code, :name, "asset", "debit", "USD", "cash_and_equivalents")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-CASH-SHARED',
            'name' => 'CI Shared Cash Ledger']);
    $cashAccountId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, currency)
        VALUES (:tenant, :code, :name, "equity", "credit", "USD")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-CASH-EQUITY',
            'name' => 'CI Shared Cash Offset']);
    $offsetAccountId = (int) $pdo->lastInsertId();

    $addBank = $pdo->prepare('INSERT INTO accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency, status)
        VALUES (:tenant, :entity, :name, "CI-CASH-SHARED", "USD", "active")');
    $addBank->execute(['tenant' => $tenantId, 'entity' => $firstEntityId,
        'name' => 'CI Entity A Shared Cash Bank']);
    $firstBankId = (int) $pdo->lastInsertId();
    $addBank->execute(['tenant' => $tenantId, 'entity' => $secondEntityId,
        'name' => 'CI Entity B Shared Cash Bank']);
    $secondBankId = (int) $pdo->lastInsertId();
    $assert($firstBankId !== $secondBankId,
        'two legal entities can map different physical banks to one chart code');
    try {
        $addBank->execute(['tenant' => $tenantId, 'entity' => $secondEntityId,
            'name' => 'CI Duplicate Entity B Bank']);
        throw new RuntimeException('A second bank reused the same code within one entity.');
    } catch (PDOException $e) {
        $assert((int) ($e->errorInfo[1] ?? 0) === 1062,
            'a second bank in the same entity cannot reuse its cash code');
    }

    $addPeriod = $pdo->prepare('INSERT INTO accounting_periods
        (tenant_id, entity_id, period_number, start_date, end_date, status)
        VALUES (:tenant, :entity, 999, "2026-10-01", "2026-10-31", "open")');
    $addJournal = $pdo->prepare('INSERT INTO accounting_journal_entries
        (tenant_id, entity_id, period_id, je_number, posting_date, status, currency,
         total_debit, total_credit, memo)
        VALUES (:tenant, :entity, :period, :number, "2026-10-11", "posted", "USD",
                :amount_debit, :amount_credit, "CI synthetic shared bank cash")');
    $addLine = $pdo->prepare('INSERT INTO accounting_journal_entry_lines
        (tenant_id, je_id, line_no, account_id, debit, credit)
        VALUES (:tenant, :journal, :line, :account, :debit, :credit)');
    $periodIds = [];
    foreach ([[$firstEntityId, 'A', '100.00'], [$secondEntityId, 'B', '200.00']] as [$entityId, $suffix, $amount]) {
        $addPeriod->execute(['tenant' => $tenantId, 'entity' => $entityId]);
        $periodId = (int) $pdo->lastInsertId();
        $periodIds[(int) $entityId] = $periodId;
        $addJournal->execute(['tenant' => $tenantId, 'entity' => $entityId,
            'period' => $periodId, 'number' => 'CI-SHARED-CASH-' . $suffix,
            'amount_debit' => $amount, 'amount_credit' => $amount]);
        $journalId = (int) $pdo->lastInsertId();
        $addLine->execute(['tenant' => $tenantId, 'journal' => $journalId,
            'line' => 1, 'account' => $cashAccountId, 'debit' => $amount, 'credit' => 0]);
        $addLine->execute(['tenant' => $tenantId, 'journal' => $journalId,
            'line' => 2, 'account' => $offsetAccountId, 'debit' => 0, 'credit' => $amount]);
    }

    $addJournal->execute(['tenant' => $tenantId, 'entity' => $firstEntityId,
        'period' => $periodIds[$firstEntityId], 'number' => 'CI-SHARED-REV-ORIGINAL',
        'amount_debit' => '40.00', 'amount_credit' => '40.00']);
    $originalId = (int) $pdo->lastInsertId();
    $addLine->execute(['tenant' => $tenantId, 'journal' => $originalId,
        'line' => 1, 'account' => $cashAccountId, 'debit' => '40.00', 'credit' => 0]);
    $addLine->execute(['tenant' => $tenantId, 'journal' => $originalId,
        'line' => 2, 'account' => $offsetAccountId, 'debit' => 0, 'credit' => '40.00']);
    $pdo->prepare('UPDATE accounting_journal_entries SET status = "reversed"
        WHERE tenant_id = :tenant AND id = :journal')
        ->execute(['tenant' => $tenantId, 'journal' => $originalId]);
    $addJournal->execute(['tenant' => $tenantId, 'entity' => $firstEntityId,
        'period' => $periodIds[$firstEntityId], 'number' => 'CI-SHARED-REV-REVERSAL',
        'amount_debit' => '40.00', 'amount_credit' => '40.00']);
    $reversalId = (int) $pdo->lastInsertId();
    $addLine->execute(['tenant' => $tenantId, 'journal' => $reversalId,
        'line' => 1, 'account' => $cashAccountId, 'debit' => 0, 'credit' => '40.00']);
    $addLine->execute(['tenant' => $tenantId, 'journal' => $reversalId,
        'line' => 2, 'account' => $offsetAccountId, 'debit' => '40.00', 'credit' => 0]);
    $pdo->prepare('UPDATE accounting_journal_entries
        SET reversed_by_je_id = :reversal WHERE tenant_id = :tenant AND id = :original')
        ->execute(['reversal' => $reversalId, 'tenant' => $tenantId, 'original' => $originalId]);
    $pdo->prepare('UPDATE accounting_journal_entries
        SET reverses_je_id = :original WHERE tenant_id = :tenant AND id = :reversal')
        ->execute(['original' => $originalId, 'tenant' => $tenantId, 'reversal' => $reversalId]);

    $assert(abs($cashAt($firstEntityId) - $beforeFirst - 100.0) < 0.001,
        'first entity cash increases by only its own posted journal');
    $assert(abs($cashAt($secondEntityId) - 200.0) < 0.001,
        'second entity cash excludes the first entity on the shared code');
    $assert(abs($cashAt(null) - $beforeAll - 300.0) < 0.001,
        'tenant liquidity totals both banks exactly once');
    $assert(cashForecastReadOpeningBalanceCents($tenantId, '2026-10-11') - $beforeForecast === 30000,
        'cash forecast includes reversed originals and their offsetting journals exactly once');
    $mapping = array_values(array_filter(businessIntegrityBankChecks($tenantId),
        static fn(array $check): bool => $check['key'] === 'bank_account_mapping_integrity'));
    $assert(count($mapping) === 1 && $mapping[0]['issue_count'] === 0,
        'shared chart code with valid entity ownership passes bank mapping audit');

    $addBank->execute(['tenant' => $tenantId, 'entity' => null,
        'name' => 'CI Legacy Unowned Cash Bank']);
    try {
        $addBank->execute(['tenant' => $tenantId, 'entity' => null,
            'name' => 'CI Second Legacy Unowned Bank']);
        throw new RuntimeException('Two unowned banks reused one cash code.');
    } catch (PDOException $e) {
        $assert((int) ($e->errorInfo[1] ?? 0) === 1062,
            'unowned legacy bank mappings remain unique pending review');
    }
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$assert(abs($cashAt(null) - $beforeAll) < 0.001,
    'fixture rollback restores original cash without persisted financial records');
