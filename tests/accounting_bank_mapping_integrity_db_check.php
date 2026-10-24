<?php
/** Verify bank-account ownership on the disposable installed CoreAccounting database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/core/business_integrity.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/business_integrity.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query("SELECT tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('Disposable administrator missing.');

$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS {$message}\n";
};
$mappingCheck = static function () use ($tenantId): array {
    foreach (businessIntegrityBankChecks($tenantId) as $check) {
        if ($check['key'] === 'bank_account_mapping_integrity') return $check;
    }
    throw new RuntimeException('Bank mapping audit check is missing.');
};

$baseline = $mappingCheck();
$assert($baseline['status'] === 'ok' && $baseline['issue_count'] === 0,
    'clean install starts without bank mapping exceptions');

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO accounting_entities
        (tenant_id, code, legal_name, base_currency)
        VALUES (:tenant, :code, :name, "USD")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-BANK-MAP-ENTITY',
            'name' => 'CI Synthetic Second Entity']);
    $entityId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, currency, cash_flow_tag)
        VALUES (:tenant, :code, :name, "asset", "debit", "USD", "cash_and_equivalents")')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-BANK-MAP-CASH',
            'name' => 'CI Synthetic Second-Entity Cash']);
    $pdo->prepare('INSERT INTO accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency, status)
        VALUES (:tenant, :entity, :name, :code, "USD", "active")')
        ->execute(['tenant' => $tenantId, 'entity' => $entityId,
            'name' => 'CI Synthetic Second-Entity Bank', 'code' => 'CI-BANK-MAP-CASH']);
    $bankId = (int) $pdo->lastInsertId();

    $assert($mappingCheck()['issue_count'] === 0,
        'new second-entity bank with its own cash ledger passes mapping audit');
    $setBank = $pdo->prepare('UPDATE accounting_bank_accounts
        SET entity_id = :entity, currency = :currency, status = :status
        WHERE tenant_id = :tenant AND id = :bank');
    $setBank->execute(['entity' => null, 'currency' => 'USD', 'status' => 'active',
        'tenant' => $tenantId, 'bank' => $bankId]);
    $missingOwner = $mappingCheck();
    $assert($missingOwner['issue_count'] === 1
        && (int) ($missingOwner['sample'][0]['bank_account_id'] ?? 0) === $bankId,
        'unowned bank appears in the audit with its exact account ID');

    $setBank->execute(['entity' => $entityId, 'currency' => 'EUR', 'status' => 'active',
        'tenant' => $tenantId, 'bank' => $bankId]);
    $assert($mappingCheck()['issue_count'] === 1,
        'bank currency differing from its legal entity is flagged');

    $setBank->execute(['entity' => $entityId, 'currency' => 'USD', 'status' => 'active',
        'tenant' => $tenantId, 'bank' => $bankId]);
    $pdo->prepare('UPDATE accounting_bank_accounts SET gl_account_code = :code
        WHERE tenant_id = :tenant AND id = :bank')
        ->execute(['code' => 'CI-BANK-MISSING-CASH', 'tenant' => $tenantId, 'bank' => $bankId]);
    $assert($mappingCheck()['issue_count'] === 1,
        'bank with no chart-of-accounts cash ledger is flagged');
    $pdo->prepare('UPDATE accounting_bank_accounts SET gl_account_code = :code
        WHERE tenant_id = :tenant AND id = :bank')
        ->execute(['code' => 'CI-BANK-MAP-CASH', 'tenant' => $tenantId, 'bank' => $bankId]);

    $pdo->prepare('UPDATE accounting_accounts SET cash_flow_tag = NULL
        WHERE tenant_id = :tenant AND code = :code')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-BANK-MAP-CASH']);
    $assert($mappingCheck()['issue_count'] === 1,
        'active bank without an eligible cash ledger tag is flagged');

    $pdo->prepare('UPDATE accounting_accounts
        SET cash_flow_tag = "cash_and_equivalents", active = 0
        WHERE tenant_id = :tenant AND code = :code')
        ->execute(['tenant' => $tenantId, 'code' => 'CI-BANK-MAP-CASH']);
    $assert($mappingCheck()['issue_count'] === 1,
        'active bank with inactive cash ledger is flagged');

    $setBank->execute(['entity' => $entityId, 'currency' => 'USD', 'status' => 'closed',
        'tenant' => $tenantId, 'bank' => $bankId]);
    $assert($mappingCheck()['issue_count'] === 0,
        'closed historical bank may retain an inactive but owned cash ledger');

    $setBank->execute(['entity' => null, 'currency' => 'USD', 'status' => 'closed',
        'tenant' => $tenantId, 'bank' => $bankId]);
    $assert($mappingCheck()['issue_count'] === 1,
        'closed historical bank still needs a reviewed legal entity');
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}

$assert($mappingCheck()['issue_count'] === 0,
    'rolling back the synthetic second-entity fixture restores a clean audit');
