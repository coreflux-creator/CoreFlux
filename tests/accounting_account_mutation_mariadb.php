<?php
/** Rollback-only account mutation acceptance against the isolated local schema. */
declare(strict_types=1);

$database = (string) getenv('DB_NAME');
if (getenv('COREFLUX_ENV') !== 'staging'
    || !preg_match('/^coreaccounting_blank_test\d+$/D', $database)) {
    fwrite(STDERR, "This test requires an isolated local staging schema.\n");
    exit(2);
}
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/accounting/account_mutation.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$pdo = getDB();
if (!$pdo || (string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) {
    throw new RuntimeException('Connected database does not match the isolated test schema.');
}
$tenantId = 1;
$entityId = (int) $pdo->query('SELECT id FROM accounting_entities WHERE tenant_id = 1 AND active = 1 LIMIT 1')->fetchColumn();
$postingDate = (string) $pdo->query(
    'SELECT start_date FROM accounting_periods WHERE tenant_id = 1 AND status = "open" ORDER BY start_date LIMIT 1'
)->fetchColumn();
if (!$entityId || !$postingDate) throw new RuntimeException('No provisioned entity/open period in test schema.');
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException($label);
    $checks++;
};
$reject = static function (callable $action, string $contains, string $label) use ($assert): void {
    try {
        $action();
    } catch (InvalidArgumentException $error) {
        $assert(str_contains($error->getMessage(), $contains), $label);
        return;
    }
    throw new RuntimeException($label . ' was accepted.');
};
$find = static function (int $id) use ($pdo, $tenantId): array {
    $query = $pdo->prepare('SELECT * FROM accounting_accounts WHERE tenant_id = :t AND id = :id');
    $query->execute(['t' => $tenantId, 'id' => $id]);
    return $query->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('Test account missing.');
};

$system = $pdo->query('SELECT * FROM accounting_accounts WHERE tenant_id = 1 AND code = "1100" LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$assert((int) $system['is_system_account'] === 1, 'system account is seeded');
$assert(accountingReviewAccountChange($tenantId, $system, ['name' => 'Receivables'])['name'] === 'Receivables',
    'system account display name remains editable');
$reject(static fn() => accountingReviewAccountChange($tenantId, $system, ['account_type' => 'expense']),
    'System and control accounts', 'system classification is protected');
$reject(static fn() => accountingReviewAccountChange($tenantId, $system, ['active' => false]),
    'System and control accounts', 'system deactivation is protected');
$reject(static fn() => accountingReviewAccountChange($tenantId, $system, ['code' => '1101']),
    'stable ledger identifier', 'account code cannot be changed in place');
$reject(static fn() => accountingReviewAccountChange($tenantId, $system, ['parent_account_id' => 99999999]),
    'active parent account', 'parent lookup stays in tenant');
$reject(static fn() => accountingReviewAccountChange($tenantId, null, [
    'code' => '1100', 'name' => 'Impersonated receivables', 'account_type' => 'asset',
]), 'reserved for a seeded system account', 'new rows cannot take a system code');
$reject(static fn() => accountingReviewAccountChange($tenantId, $system, ['account_type' => null]),
    'Invalid account type', 'null account type cannot bypass validation');

$prefix = 'T' . substr(bin2hex(random_bytes(4)), 0, 7);
$pdo->beginTransaction();
try {
    $parent = accountingReviewAccountChange($tenantId, null, [
        'code' => $prefix . 'P', 'name' => 'Test expense group', 'account_type' => 'expense',
        'is_postable' => false,
    ]);
    $assert($parent['normal_side'] === 'debit' && $parent['is_postable'] === 0,
        'new account defaults and strict boolean are normalized');
    $pdo->prepare(
        'INSERT INTO accounting_accounts (tenant_id, code, name, account_type, normal_side, is_postable, active)
         VALUES (:t, :code, :name, :type, :side, :postable, 1)'
    )->execute(['t' => $tenantId, 'code' => $parent['code'], 'name' => $parent['name'],
        'type' => $parent['account_type'], 'side' => $parent['normal_side'],
        'postable' => $parent['is_postable']]);
    $parentId = (int) $pdo->lastInsertId();

    $child = accountingReviewAccountChange($tenantId, null, [
        'code' => $prefix . 'C', 'name' => 'Test expense', 'account_type' => 'expense',
        'parent_account_id' => $parentId,
    ]);
    $pdo->prepare(
        'INSERT INTO accounting_accounts (tenant_id, code, name, account_type, normal_side, parent_account_id, active)
         VALUES (:t, :code, :name, :type, :side, :parent, 1)'
    )->execute(['t' => $tenantId, 'code' => $child['code'], 'name' => $child['name'],
        'type' => $child['account_type'], 'side' => $child['normal_side'],
        'parent' => $parentId]);
    $childId = (int) $pdo->lastInsertId();
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($parentId), ['parent_account_id' => $childId]),
        'create a cycle', 'parent cycle is rejected');
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($parentId), ['account_type' => 'asset']),
        'Move child accounts', 'parent type cannot strand children');
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($childId), ['parent_account_id' => 99999999]),
        'active parent account', 'invalid parent is rejected');
    $assert(accountingReviewAccountChange($tenantId, $find($childId), [
        'cash_flow_tag' => 'operating_wc_other',
    ])['cash_flow_tag'] === 'operating_wc_other', 'granular cash-flow tag remains editable');
    $changes = accountingAccountCsvChanges([
        'code' => $child['code'], 'name' => 'Renamed expense', 'account_type' => 'expense',
        'normal_side' => 'debit', 'parent_account_id' => '', 'is_postable' => '',
        'currency' => '', 'cash_flow_tag' => '', 'description' => '', 'active' => '',
    ]);
    $assert(!array_key_exists('currency', $changes) && !array_key_exists('parent_account_id', $changes),
        'blank optional CSV cells do not clear existing values');
    $assert(accountingReviewAccountChange($tenantId, $find($childId), $changes)['name'] === 'Renamed expense',
        'round-trip name change is accepted');

    $journal = [
        'entity_id' => $entityId, 'posting_date' => $postingDate,
        'memo' => 'Rollback-only account classification check',
        'idempotency_key' => 'account-mutation-' . bin2hex(random_bytes(8)),
        'lines' => [
            ['account_code' => $child['code'], 'debit' => '1.00', 'credit' => '0.00'],
            ['account_code' => '3000', 'debit' => '0.00', 'credit' => '1.00'],
        ],
    ];
    $posted = accountingPostJe($tenantId, $journal, null, true);
    $assert($posted['status'] === 'posted', 'custom account receives a posted journal');
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($childId), ['normal_side' => 'credit']),
        'journal activity', 'posted account normal side cannot change');
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($childId), ['is_postable' => false]),
        'journal activity', 'posted account cannot become nonpostable');
    $reject(static fn() => accountingReviewAccountChange($tenantId, $find($childId), ['currency' => 'EUR']),
        'journal activity', 'posted account currency cannot change');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$query = $pdo->prepare('SELECT COUNT(*) FROM accounting_accounts WHERE tenant_id = :t AND code LIKE :prefix');
$query->execute(['t' => $tenantId, 'prefix' => $prefix . '%']);
$assert((int) $query->fetchColumn() === 0, 'custom accounts and journal were fully rolled back');
echo "Accounting account mutation MariaDB: {$checks} checks passed.\n";
