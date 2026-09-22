<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$assert = static function (string $label, bool $condition) use (&$passed, &$failed): void {
    echo ($condition ? 'OK   ' : 'FAIL ') . $label . PHP_EOL;
    $condition ? $passed++ : $failed++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$liabilityMigration = $read('modules/treasury/migrations/007_liability_account_entity.sql');
$liabilityApi = $read('modules/treasury/api/liability_accounts.php');
$liabilityUi = $read('modules/treasury/ui/LiabilityAccounts.jsx');
$transactions = $read('modules/treasury/api/account_transactions.php');
$activeTransactions = explode('// ─── split_categorize', $transactions, 2)[0];
$payments = $read('api/treasury_payments.php');
$transfers = $read('api/treasury_transfers.php');
$intercompany = $read('modules/accounting/lib/intercompany.php');
$registry = $read('core/seeds/event_registry_seed.php');
$defaults = $read('core/posting_engine/seed_defaults.php');

echo "Treasury account ownership\n";
$assert('liability accounts gain an indexed legal entity',
    str_contains($liabilityMigration, 'ADD COLUMN entity_id')
    && str_contains($liabilityMigration, 'idx_tla_tenant_entity'));
$assert('legacy liability ownership is inferred only for a single-entity tenant',
    str_contains($liabilityMigration, 'HAVING COUNT(*) = 1')
    && str_contains($liabilityMigration, 'WHERE tla.entity_id IS NULL'));
$assert('liability creation resolves and persists an active entity',
    str_contains($liabilityApi, 'activeEntityResolveForTenant')
    && str_contains($liabilityApi, "'entity_id'           => (int) \$entity['id']"));
$assert('liability UI captures and displays entity ownership',
    str_contains($liabilityUi, 'treasury-liability-entity')
    && str_contains($liabilityUi, 'r.entity_code'));

echo "\nStatement-line posting\n";
$assert('statement posting resolves account ownership before it posts',
    str_contains($activeTransactions, 'function _treasuryStatementEntityId')
    && substr_count($activeTransactions, '_treasuryStatementEntityId(') >= 3);
$assert('active statement handlers never post to entity zero',
    !str_contains($activeTransactions, "'entity_id'        => 0"));
$assert('single and split postings persist legal-entity dimensions',
    str_contains($activeTransactions, "'legal_entity' => \$postingEntityId")
    && str_contains($activeTransactions, "'counterparty_entity' => \$s['counterparty_entity_id']"));
$assert('event and direct fallback use the same resolved entity',
    substr_count($activeTransactions, "'entity_id'      => \$postingEntityId") >= 2
    && substr_count($activeTransactions, "'entity_id'        => \$postingEntityId") >= 2);

echo "\nPayments and transfers\n";
$assert('payment creation rejects an entity-bank mismatch',
    str_contains($payments, 'The selected bank account belongs to a different legal entity'));
$assert('payment execution revalidates ownership and emits its canonical contract',
    str_contains($payments, 'Payment entity no longer matches the selected bank account')
    && str_contains($payments, "'method' => (string) \$payment['payment_method']"));
$assert('payment carries vendor and legal-entity dimensions',
    str_contains($payments, "'vendor_dimension' => \$vendorDimension")
    && str_contains($payments, "'legal_entity_dimension' => \$legalEntityDimension"));
$assert('transfer creation refuses unowned accounts',
    str_contains($transfers, 'Assign an active legal entity to the source bank account')
    && str_contains($transfers, 'Assign an active legal entity to the destination bank account'));
$assert('internal transfer payload satisfies the canonical event contract',
    str_contains($transfers, "'from_bank_account_id'")
    && str_contains($transfers, "'to_bank_account_id'"));
$assert('intercompany transfer posts and records both legal-entity journals',
    str_contains($transfers, 'intercompanyPostSplit')
    && str_contains($transfers, 'source_journal_entry_id=:source_je')
    && str_contains($transfers, 'destination_journal_entry_id=:destination_je'));
$assert('paired transfer remains one first-class accounting event',
    str_contains($transfers, 'treasury.intercompany.transfer.completed')
    && str_contains($transfers, 'intercompany_group_id')
    && str_contains($transfers, "'kind' => 'destination'"));
$assert('intercompany engine dimensions both sides and counterparties',
    substr_count($intercompany, "'legal_entity' => \$sourceEntityId") >= 2
    && substr_count($intercompany, "'legal_entity' => \$targetEntityId") >= 2
    && str_contains($intercompany, "'counterparty_entity' => \$targetEntityId")
    && str_contains($intercompany, "'counterparty_entity' => \$sourceEntityId"));

echo "\nContracts and templates\n";
$assert('treasury payment is a canonical event rather than an AP alias',
    str_contains($registry, "['treasury.payment.executed', 'treasury'")
    && !str_contains($registry, "['treasury.payment.executed', 'ap.payment.executed']"));
$assert('default payment and transfer templates retain legal-entity dimensions',
    substr_count($defaults, "'legal_entity' => 'payload.legal_entity_dimension'") >= 4);
$assert('default intercompany template retains its counterparty entity',
    str_contains($defaults, "'counterparty_entity' => 'payload.destination_entity_id'"));

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed === 0 ? 0 : 1);
