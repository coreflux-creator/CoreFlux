<?php
/** Rehearse the pending Accounting DDL against populated disposable table clones. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) {
    fwrite(STDERR, "Disposable CoreAccounting CI database only.\n");
    exit(2);
}

$root = realpath((string) getenv('COREFLUX_STANDALONE_WEBROOT'));
if ($root === false) throw new RuntimeException('Installed CoreAccounting runtime is required.');
require_once $root . '/core/db.php';
require_once $root . '/core/accounting/schema_contract.php';

$pdo = getDB();
if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'coreaccounting_ci') {
    throw new RuntimeException('Connected database is not the disposable CI database.');
}
$scratch = 'coreaccounting_upgrade_fixture';
$created = false;
try {
    $pdo->exec("CREATE DATABASE {$scratch}");
    $created = true;
    foreach (['accounting_journal_entries', 'accounting_bank_accounts'] as $table) {
        $pdo->exec("CREATE TABLE {$scratch}.{$table} LIKE coreaccounting_ci.{$table}");
    }

    $pdo->exec("ALTER TABLE {$scratch}.accounting_journal_entries
        DROP COLUMN voided_by_user_id, DROP COLUMN voided_at, DROP COLUMN void_reason");
    $pdo->exec("ALTER TABLE {$scratch}.accounting_bank_accounts
        DROP INDEX uq_aba_tenant_entity_gl, DROP COLUMN entity_scope_id");
    $pdo->exec("ALTER TABLE {$scratch}.accounting_bank_accounts
        ADD UNIQUE KEY uq_aba_tenant_gl (tenant_id, gl_account_code)");

    $pdo->exec("INSERT INTO {$scratch}.accounting_journal_entries
        (tenant_id, entity_id, period_id, je_number, posting_date, status, currency,
         total_debit, total_credit, memo)
        VALUES (1, 1, 1, 'CI-UPGRADE-JE', '2026-10-11', 'posted', 'USD',
                0.19, 0.19, 'CI populated-upgrade journal')");
    $journalId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO {$scratch}.accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency, status)
        VALUES (1, 1, 'CI Existing Main Bank', 'CI-UPGRADE-CASH', 'USD', 'active'),
               (1, NULL, 'CI Existing Unowned Bank', 'CI-UPGRADE-LEGACY', 'USD', 'closed')");
    $bankIds = $pdo->query("SELECT id, name, entity_id, gl_account_code, status
        FROM {$scratch}.accounting_bank_accounts ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (count($bankIds) !== 2) throw new RuntimeException('Synthetic pre-upgrade banks missing.');

    $pdo->exec("USE {$scratch}");
    $beforeSchema = coreAccountingInspectSchema($pdo);
    if (!in_array('accounting_bank_accounts.entity_scope_id', $beforeSchema['missing'], true)
        || !in_array('accounting_bank_accounts UNIQUE (tenant_id, entity_scope_id, gl_account_code)',
            $beforeSchema['missing'], true)
        || $beforeSchema['incompatible'] === []) {
        throw new RuntimeException('Legacy bank mapping schema was not rejected before migration.');
    }
    foreach (['042_journal_void_audit.sql', '043_bank_account_entity_cash_code.sql'] as $file) {
        $sql = file_get_contents($root . '/modules/accounting/migrations/' . $file);
        if ($sql === false || substr_count(strtoupper($sql), 'ALTER TABLE') !== 1) {
            throw new RuntimeException("Missing or unexpected migration {$file}");
        }
        $pdo->exec($sql);
    }
    $afterSchema = coreAccountingInspectSchema($pdo);
    if (array_filter($afterSchema['missing'],
            static fn(string $item): bool => str_starts_with($item, 'accounting_bank_accounts'))
        || $afterSchema['incompatible']) {
        throw new RuntimeException('Upgraded bank schema failed its readiness contract.');
    }
    $pdo->exec('ALTER TABLE accounting_bank_accounts
        ADD UNIQUE KEY uq_aba_tenant_gl (tenant_id, gl_account_code)');
    if (coreAccountingInspectSchema($pdo)['incompatible'] === []) {
        throw new RuntimeException('A retained legacy bank index was incorrectly accepted.');
    }
    $pdo->exec('ALTER TABLE accounting_bank_accounts DROP INDEX uq_aba_tenant_gl');
    $pdo->exec('ALTER TABLE accounting_bank_accounts
        ADD UNIQUE KEY uq_aba_legacy_reordered (gl_account_code, tenant_id)');
    if (coreAccountingInspectSchema($pdo)['incompatible'] === []) {
        throw new RuntimeException('A reordered legacy bank index was incorrectly accepted.');
    }
    $pdo->exec('ALTER TABLE accounting_bank_accounts DROP INDEX uq_aba_legacy_reordered');
    $pdo->exec('ALTER TABLE accounting_bank_accounts
        ADD UNIQUE KEY uq_aba_legacy_prefix (tenant_id, gl_account_code(12))');
    if (coreAccountingInspectSchema($pdo)['incompatible'] === []) {
        throw new RuntimeException('A prefix legacy bank index was incorrectly accepted.');
    }
    $pdo->exec('ALTER TABLE accounting_bank_accounts DROP INDEX uq_aba_legacy_prefix');
    $pdo->exec('USE coreaccounting_ci');

    $journal = $pdo->query("SELECT id, je_number, status, total_debit, total_credit, memo,
        void_reason, voided_at, voided_by_user_id
        FROM {$scratch}.accounting_journal_entries WHERE id = {$journalId}")
        ->fetch(PDO::FETCH_ASSOC);
    if (!$journal || $journal['je_number'] !== 'CI-UPGRADE-JE'
        || $journal['status'] !== 'posted' || (float) $journal['total_debit'] !== 0.19
        || (float) $journal['total_credit'] !== 0.19
        || $journal['memo'] !== 'CI populated-upgrade journal'
        || $journal['void_reason'] !== null || $journal['voided_at'] !== null
        || $journal['voided_by_user_id'] !== null) {
        throw new RuntimeException('Journal data changed during the void-audit migration.');
    }
    $pdo->exec("UPDATE {$scratch}.accounting_journal_entries
        SET status = 'void', void_reason = 'CI audit check',
            voided_at = '2026-10-11 12:00:00', voided_by_user_id = 77
        WHERE id = {$journalId}");
    $audit = $pdo->query("SELECT void_reason, voided_by_user_id
        FROM {$scratch}.accounting_journal_entries WHERE id = {$journalId}")
        ->fetch(PDO::FETCH_ASSOC);
    if (($audit['void_reason'] ?? '') !== 'CI audit check'
        || (int) ($audit['voided_by_user_id'] ?? 0) !== 77) {
        throw new RuntimeException('Void audit fields cannot be written after migration.');
    }
    echo "PASS populated journal retains its identity, amount and memo, and accepts void audit\n";

    $after = $pdo->query("SELECT id, name, entity_id, gl_account_code, status, entity_scope_id
        FROM {$scratch}.accounting_bank_accounts ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (count($after) !== 2) throw new RuntimeException('Bank rows disappeared during migration.');
    foreach ($after as $index => $bank) {
        if ((int) $bank['id'] !== (int) $bankIds[$index]['id']
            || $bank['name'] !== $bankIds[$index]['name']
            || $bank['gl_account_code'] !== $bankIds[$index]['gl_account_code']
            || $bank['status'] !== $bankIds[$index]['status']
            || (int) $bank['entity_scope_id'] !== (int) ($bank['entity_id'] ?? 0)) {
            throw new RuntimeException('Bank ownership or identity changed during migration.');
        }
    }
    $pdo->exec("INSERT INTO {$scratch}.accounting_bank_accounts
        (tenant_id, entity_id, name, gl_account_code, currency)
        VALUES (1, 2, 'CI Second Entity Bank', 'CI-UPGRADE-CASH', 'USD')");
    foreach ([
        [1, 'CI-UPGRADE-CASH'],
        [null, 'CI-UPGRADE-LEGACY'],
    ] as [$entityId, $code]) {
        try {
            $entitySql = $entityId === null ? 'NULL' : (string) $entityId;
            $pdo->exec("INSERT INTO {$scratch}.accounting_bank_accounts
                (tenant_id, entity_id, name, gl_account_code, currency)
                VALUES (1, {$entitySql}, 'CI Duplicate Bank', '{$code}', 'USD')");
            throw new RuntimeException('Duplicate bank cash mapping was accepted.');
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
        }
    }
    echo "PASS populated bank mappings survive, use per-entity cash codes, and reject a retained legacy index\n";
} finally {
    $pdo->exec('USE coreaccounting_ci');
    if ($created) $pdo->exec("DROP DATABASE {$scratch}");
}
