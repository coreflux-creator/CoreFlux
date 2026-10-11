<?php
/** Exercise Plaid currency-to-entity bank mapping on disposable CI books. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false || !is_file($root . '/core/plaid_bank_currency.php')) {
    throw new RuntimeException('Installed CoreAccounting runtime is required.');
}
require_once $root . '/core/db.php';
require_once $root . '/core/plaid_bank_currency.php';
require_once $root . '/modules/accounting/lib/bank_account_ledger.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$tenantId = (int) $pdo->query("SELECT tenant_id FROM users
    WHERE email = 'ci-admin@coreaccounting.invalid' AND is_active = 1 LIMIT 1")
    ->fetchColumn();
if ($tenantId <= 0) throw new RuntimeException('Disposable administrator missing.');
$entityStmt = $pdo->prepare('SELECT id, base_currency FROM accounting_entities
    WHERE tenant_id = :tenant AND active = 1 AND base_currency = "USD" ORDER BY id LIMIT 1');
$entityStmt->execute(['tenant' => $tenantId]);
$usdEntity = $entityStmt->fetch(PDO::FETCH_ASSOC);
if (!$usdEntity) throw new RuntimeException('Disposable USD entity missing.');

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM accounting_bank_accounts WHERE tenant_id = :tenant');
$countStmt->execute(['tenant' => $tenantId]);
$before = (int) $countStmt->fetchColumn();
$mappingStmt = $pdo->prepare('SELECT bank.entity_id, bank.currency AS bank_currency,
        entity.id AS mapped_entity_id, entity.active AS entity_active,
        entity.base_currency, account.id AS gl_account_id, account.code,
        account.account_type, account.normal_side, account.is_postable,
        account.active, account.cash_flow_tag, account.is_system_account,
        account.currency AS ledger_currency
    FROM accounting_bank_accounts bank
    LEFT JOIN accounting_entities entity
      ON entity.tenant_id = bank.tenant_id AND entity.id = bank.entity_id
    LEFT JOIN accounting_accounts account
      ON account.tenant_id = bank.tenant_id AND account.code = bank.gl_account_code
    WHERE bank.tenant_id = :tenant AND bank.id = :bank');

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO accounting_entities
        (tenant_id, code, legal_name, base_currency)
        VALUES (:tenant, "CI-PLAID-EUR", "CI Plaid EUR Entity", "EUR")')
        ->execute(['tenant' => $tenantId]);
    $eurEntity = ['id' => (int) $pdo->lastInsertId(), 'base_currency' => 'EUR'];

    $addLedger = $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, currency, cash_flow_tag)
        VALUES (:tenant, :code, :name, "asset", "debit", :currency, "cash_and_equivalents")');
    $addBank = $pdo->prepare('INSERT INTO accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency, status, feed_provider)
        VALUES (:tenant, :entity, :name, :code, :currency, "active", "plaid_transactions")');
    foreach ([
        [$usdEntity, ['balances' => ['iso_currency_code' => 'USD']], 'CI-PLAID-USD'],
        [$eurEntity, ['iso_currency_code' => 'EUR'], 'CI-PLAID-EUR-CASH'],
    ] as [$entity, $account, $code]) {
        $currency = plaidBankMirrorCurrency($account, $entity);
        $addLedger->execute(['tenant' => $tenantId, 'code' => $code,
            'name' => $code . ' synthetic cash', 'currency' => $currency]);
        $addBank->execute(['tenant' => $tenantId, 'entity' => (int) $entity['id'],
            'name' => $code . ' synthetic bank', 'code' => $code, 'currency' => $currency]);
        $bankId = (int) $pdo->lastInsertId();
        $mappingStmt->execute(['tenant' => $tenantId, 'bank' => $bankId]);
        $mapping = $mappingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$mapping || !accountingBankCashPositionMappingValid($mapping)
            || $mapping['bank_currency'] !== $currency) {
            throw new RuntimeException("Synthetic {$currency} Plaid bank failed cash mapping");
        }
        echo "PASS synthetic {$currency} Plaid bank maps to its legal entity and cash ledger\n";
    }

    foreach ([
        [['balances' => ['iso_currency_code' => 'CAD']], $usdEntity],
        [['iso_currency_code' => null], $usdEntity],
    ] as [$account, $entity]) {
        try {
            plaidBankMirrorCurrency($account, $entity);
            throw new RuntimeException('Plaid bank with mismatched or missing currency was accepted');
        } catch (RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'Connected bank')) throw $e;
        }
    }
    $countStmt->execute(['tenant' => $tenantId]);
    if ((int) $countStmt->fetchColumn() !== $before + 2) {
        throw new RuntimeException('Rejected Plaid accounts changed the bank count');
    }
    echo "PASS mismatched and unknown-currency Plaid accounts create no bank\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
}
$countStmt->execute(['tenant' => $tenantId]);
if ((int) $countStmt->fetchColumn() !== $before) {
    throw new RuntimeException('Synthetic Plaid bank fixture did not roll back');
}
echo "PASS synthetic Plaid bank items roll back\n";
