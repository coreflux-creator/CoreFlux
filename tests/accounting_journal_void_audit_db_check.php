<?php
/** Exercise journal deletion on the disposable packaged CoreAccounting install. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/modules/accounting/lib/accounting.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/modules/accounting/lib/accounting.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$actor = $pdo->query("SELECT id, tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if (!$actor) throw new RuntimeException('Disposable administrator missing.');
$tenantId = (int) $actor['tenant_id'];
$actorId = (int) $actor['id'];
$entity = $pdo->prepare('SELECT id FROM accounting_entities
    WHERE tenant_id = :tenant AND active = 1 ORDER BY id LIMIT 1');
$entity->execute(['tenant' => $tenantId]);
$entityId = (int) $entity->fetchColumn();
if ($entityId <= 0) throw new RuntimeException('Disposable legal entity missing.');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$balance = static function (string $code) use ($tenantId, $entityId): float {
    foreach (accountingTrialBalance($tenantId, '2026-10-11', $entityId) as $row) {
        if ($row['code'] === $code) return (float) $row['balance_signed'];
    }
    return 0.0;
};
$find = $pdo->prepare('SELECT status, void_reason, voided_at, voided_by_user_id
    FROM accounting_journal_entries WHERE tenant_id = :tenant AND id = :id');
$row = static function (int $id) use ($find, $tenantId): array {
    $find->execute(['tenant' => $tenantId, 'id' => $id]);
    $result = $find->fetch(PDO::FETCH_ASSOC);
    if (!$result) throw new RuntimeException('Synthetic journal entry disappeared.');
    return $result;
};
$entry = [
    'entity_id' => $entityId,
    'posting_date' => '2026-10-11',
    'source_module' => 'manual',
    'currency' => 'USD',
    'memo' => 'CI synthetic journal deletion audit',
    'lines' => [
        ['account_code' => '6200', 'debit' => 0.07, 'credit' => 0],
        ['account_code' => '6990', 'debit' => 0, 'credit' => 0.07],
    ],
];
$before6200 = $balance('6200');
$before6990 = $balance('6990');

$posted = accountingPostJe($tenantId, $entry, $actorId);
$postedId = (int) $posted['je_id'];
$assert($posted['status'] === 'posted'
    && abs($balance('6200') - $before6200 - 0.07) < 0.001
    && abs($balance('6990') - $before6990 + 0.07) < 0.001,
    'new synthetic posted journal affects both account balances');

try {
    accountingDeleteJe(999, $postedId, 'Wrong tenant must fail', $actorId);
    throw new RuntimeException('Cross-tenant deletion unexpectedly succeeded.');
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'Cross-tenant deletion unexpectedly succeeded.') throw $e;
}
$assert($row($postedId)['status'] === 'posted', 'cross-tenant deletion leaves the entry posted');

try {
    accountingDeleteJe($tenantId, $postedId, str_repeat('x', 1001), $actorId);
    throw new RuntimeException('Overlong deletion reason unexpectedly succeeded.');
} catch (InvalidArgumentException $e) {
    $assert(str_contains($e->getMessage(), '1000 characters'),
        'overlong deletion reason returns a clear validation error');
}
$assert($row($postedId)['status'] === 'posted',
    'overlong deletion reason leaves the journal posted');

$reason = 'Synthetic CI correction: preserve a reason with the journal';
$deleted = accountingDeleteJe($tenantId, $postedId, $reason, $actorId);
$saved = $row($postedId);
$assert($deleted['status'] === 'void' && $saved['status'] === 'void'
    && $saved['void_reason'] === $reason && $saved['voided_at'] !== null
    && (int) $saved['voided_by_user_id'] === $actorId,
    'posted deletion persists its reason, timestamp and actor');
$assert(abs($balance('6200') - $before6200) < 0.001
    && abs($balance('6990') - $before6990) < 0.001,
    'deleted posted journal no longer affects balances');

$replay = accountingDeleteJe($tenantId, $postedId, 'Replay must not overwrite reason', $actorId);
$assert($replay['idempotent_replay'] === true && $row($postedId) === $saved,
    'repeated deletion keeps the original audit record');

$draft = accountingPostJe($tenantId, $entry, $actorId, false);
$draftId = (int) $draft['je_id'];
$assert($draft['status'] === 'draft', 'new synthetic draft journal exists');
accountingDeleteJe($tenantId, $draftId, '', $actorId);
$draftSaved = $row($draftId);
$assert($draftSaved['status'] === 'void' && $draftSaved['void_reason'] === null
    && $draftSaved['voided_at'] !== null
    && (int) $draftSaved['voided_by_user_id'] === $actorId,
    'draft deletion records actor and timestamp without inventing a reason');
$assert(abs($balance('6200') - $before6200) < 0.001
    && abs($balance('6990') - $before6990) < 0.001,
    'draft lifecycle leaves posted balances unchanged');
