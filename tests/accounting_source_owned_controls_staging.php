<?php
/** Rollback-only control-account acceptance on the isolated simulation tenant. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--execute') {
    fwrite(STDERR, "Run only on isolated CoreFlux staging with --execute.\n");
    exit(2);
}

define('QA_LIFECYCLE_LIBRARY_MODE', true);
require_once __DIR__ . '/accounting_staging_lifecycle.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';
require_once __DIR__ . '/../modules/accounting/lib/ledger_import.php';
require_once __DIR__ . '/../core/accounting/coreone_v1.php';
require_once __DIR__ . '/../core/accounting/account_mutation.php';
require_once __DIR__ . '/../modules/treasury/lib/bank_posting.php';

if (!defined('COREFLUX_STAGING') || !COREFLUX_STAGING) {
    throw new RuntimeException('Refusing a non-staging environment.');
}

function controlRejected(callable $action): bool
{
    try {
        $action();
        return false;
    } catch (InvalidArgumentException|RuntimeException $error) {
        return str_contains(strtolower($error->getMessage()), 'source workflow')
            || str_contains(strtolower($error->getMessage()), 'control account');
    }
}

$entities = $pdo->query('SELECT id, code FROM accounting_entities WHERE tenant_id = '
    . QA_TENANT . ' AND code IN ("SIM-LIFECYCLE-QA", "SIM-CUTOVER-QA")')->fetchAll(PDO::FETCH_ASSOC);
$byCode = array_column($entities, 'id', 'code');
$entityId = (int) ($byCode['SIM-LIFECYCLE-QA'] ?? 0);
$otherEntityId = (int) ($byCode['SIM-CUTOVER-QA'] ?? 0);
if ($entityId <= 0 || $otherEntityId <= 0) {
    throw new RuntimeException('Synthetic staging entities are missing.');
}

$controlCode = 'QA-CTL-' . strtoupper(bin2hex(random_bytes(4)));
$ordinaryCode = 'QA-REV-' . strtoupper(bin2hex(random_bytes(4)));
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO accounting_accounts
        (tenant_id, code, name, account_type, normal_side, is_postable, active)
        VALUES (:t, :code, :name, :type, :side, 1, 1)');
    $insert->execute(['t' => QA_TENANT, 'code' => $controlCode,
        'name' => 'Temporary control fixture', 'type' => 'asset', 'side' => 'debit']);
    $controlId = (int) $pdo->lastInsertId();
    $insert->execute(['t' => QA_TENANT, 'code' => $ordinaryCode,
        'name' => 'Temporary ordinary fixture', 'type' => 'revenue', 'side' => 'credit']);

    $journal = [
        'entity_id' => $entityId, 'posting_date' => date('Y-m-d'),
        'currency' => 'USD', 'memo' => 'Rollback-only control fixture',
        'lines' => [
            ['account_id' => $controlId, 'debit' => 1, 'credit' => 0],
            ['account_code' => $ordinaryCode, 'debit' => 0, 'credit' => 1],
        ],
    ];
    $draft = accountingPostJe(QA_TENANT, $journal, null, false);
    qaExpect(($draft['status'] ?? '') === 'draft', 'ordinary account creates a draft before mapping');

    $pdo->prepare('INSERT INTO accounting_intercompany_mappings
        (tenant_id, from_entity_id, to_entity_id, due_from_account_code, due_to_account_code, active)
        VALUES (:t, :from_id, :to_id, :control_code, "2500", 1)
        ON DUPLICATE KEY UPDATE due_from_account_code = VALUES(due_from_account_code),
            due_to_account_code = VALUES(due_to_account_code), active = 1')
        ->execute(['t' => QA_TENANT, 'from_id' => $entityId,
            'to_id' => $otherEntityId, 'control_code' => $controlCode]);

    $protected = accountingSourceOwnedControlCodes(QA_TENANT, $pdo);
    qaExpect(in_array($controlCode, $protected, true)
        && !in_array($ordinaryCode, $protected, true),
        'live tenant mapping reserves only its configured control code');
    qaExpect(!in_array($controlCode, accountingSourceOwnedControlCodes(1002, $pdo), true),
        'another simulation tenant does not inherit the mapping');
    $account = ['code' => $controlCode, 'account_type' => 'asset',
        'is_postable' => 1, 'currency' => 'USD'];
    qaExpect(accountingDirectCategoryIssue($account, $protected) !== null,
        'bank counterpart picker rejects configured control');
    qaExpect(controlRejected(static fn() => treasuryAssertCategoryCounterpart($account, $protected)),
        'Treasury direct categorization rejects configured control');
    qaExpect(controlRejected(static fn() => accountingPostJe(QA_TENANT, $journal, null, false)),
        'manual journal rejects configured control by account ID');
    qaExpect(controlRejected(static fn() => accountingPostDraftJe(QA_TENANT, (int) $draft['je_id'])),
        'draft created earlier cannot post after the mapping changes');
    qaExpect(controlRejected(static fn() => accountingUpdateDraftJe(
        QA_TENANT, (int) $draft['je_id'], $journal
    )), 'draft edit rejects configured control');

    $coreone = [
        'schema_version' => 1, 'source_record_id' => 'control-fixture',
        'event_date' => date('Y-m-d'), 'memo' => 'Rollback-only control fixture',
        'currency' => 'USD', 'lines' => [
            ['account_code' => $controlCode, 'debit' => '1.00', 'credit' => '0'],
            ['account_code' => $ordinaryCode, 'debit' => '0', 'credit' => '1.00'],
        ],
    ];
    qaExpect(controlRejected(static fn() => coreoneV1NormalizeJournal([
        'tenant_id' => QA_TENANT, 'entity_id' => $entityId, 'base_currency' => 'USD',
    ], $coreone)), 'CoreOne journal contract rejects configured control');

    $import = accountingImportReviewJe(QA_TENANT, 'control-fixture-' . $controlCode, [
        'entity_id' => (string) $entityId, 'posting_date' => date('Y-m-d'),
        'memo' => 'Rollback-only control fixture', 'lines' => [
            ['account_code' => $controlCode, 'debit' => '1.00', 'credit' => '0'],
            ['account_code' => $ordinaryCode, 'debit' => '0', 'credit' => '1.00'],
        ],
    ]);
    qaExpect((bool) array_filter($import['errors'], static fn(string $error): bool =>
        str_contains($error, 'belongs to an invoice')),
        'journal CSV preview rejects configured control');
    $current = qaOne($pdo, 'SELECT * FROM accounting_accounts WHERE tenant_id = :t AND id = :id',
        ['t' => QA_TENANT, 'id' => $controlId]);
    qaExpect(controlRejected(static fn() => accountingReviewAccountChange(
        QA_TENANT, $current, ['active' => 0]
    )), 'chart edit cannot disable a configured control');

    $pdo->rollBack();
    qaExpect(!qaOne($pdo, 'SELECT id FROM accounting_accounts WHERE tenant_id = :t AND code = :code',
        ['t' => QA_TENANT, 'code' => $controlCode]), 'all fixture records were rolled back');
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $error;
}
