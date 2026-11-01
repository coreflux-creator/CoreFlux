<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/accounting/lib/bank_rec.php';

$opts = getopt('', ['tenant:']);
$tenantId = (int) ($opts['tenant'] ?? 0);
if ($tenantId <= 0) {
    fwrite(STDERR, "Usage: php sim/check_bank_csv_import.php --tenant=ID\n");
    exit(2);
}
$pdo = getDB();
$tenant = $pdo->prepare('SELECT is_simulation FROM tenants WHERE id = :id');
$tenant->execute(['id' => $tenantId]);
if ((int) $tenant->fetchColumn() !== 1) {
    fwrite(STDERR, "Refusing to mutate a non-simulation tenant.\n");
    exit(3);
}
setRequestTenantId($tenantId);

$entity = $pdo->prepare('SELECT id FROM accounting_entities WHERE tenant_id = :tenant_id AND code = "SIM"');
$entity->execute(['tenant_id' => $tenantId]);
$entityId = (int) $entity->fetchColumn();
if ($entityId <= 0) throw new RuntimeException('Simulation entity is missing');
$account = $pdo->prepare('SELECT id FROM accounting_bank_accounts WHERE tenant_id = :tenant_id AND gl_account_code = "1000"');
$account->execute(['tenant_id' => $tenantId]);
$accountId = (int) $account->fetchColumn();
if ($accountId <= 0) {
    $addAccount = $pdo->prepare(
        'INSERT INTO accounting_bank_accounts
            (tenant_id, entity_id, name, gl_account_code, currency, feed_provider, status)
         VALUES (:tenant_id, :entity_id, "CSV import check cash", "1000", "USD", "manual_csv", "active")'
    );
    $addAccount->execute(['tenant_id' => $tenantId, 'entity_id' => $entityId]);
    $accountId = (int) $pdo->lastInsertId();
}

$lineCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_bank_statement_lines WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id');
$importCount = $pdo->prepare('SELECT COUNT(*) FROM accounting_bank_statement_imports WHERE tenant_id = :tenant_id AND bank_account_id = :bank_account_id');
$counts = static function () use ($lineCount, $importCount, $tenantId, $accountId): array {
    $params = ['tenant_id' => $tenantId, 'bank_account_id' => $accountId];
    $lineCount->execute($params);
    $importCount->execute($params);
    return [(int) $lineCount->fetchColumn(), (int) $importCount->fetchColumn()];
};

[$beforeLines, $beforeImports] = $counts();
$csv = "Date,Description,Amount,Transaction ID\n"
    . "9/19/2026,Client receipt,125.00,CSV-CHECK-1\n"
    . "9/20/2026,Monthly fee,($10.00),\n"
    . "9/20/2026,Monthly fee,($10.00),\n";
$first = bankRecImportCsv($tenantId, $accountId, $csv, null, null);
$second = bankRecImportCsv($tenantId, $accountId, $csv, null, null);
[$afterRepeatLines, $afterRepeatImports] = $counts();

$malformed = false;
try {
    bankRecImportCsv($tenantId, $accountId, "date,description,amount,fitid\n2026-09-21,Valid,4,CSV-CHECK-2\n2026-09-30,Invalid,abc,CSV-CHECK-3\n", null, null);
} catch (RuntimeException $e) {
    $malformed = str_contains($e->getMessage(), 'record 3: invalid amount');
}
[$afterInvalidLines, $afterInvalidImports] = $counts();

$conflict = false;
try {
    bankRecImportCsv($tenantId, $accountId, "date,description,amount,fitid\n2026-09-21,New line,4,CSV-CHECK-2\n2026-09-19,Client receipt,126,CSV-CHECK-1\n", null, null);
} catch (RuntimeException $e) {
    $conflict = str_contains($e->getMessage(), 'reuses a transaction ID');
}
[$afterConflictLines, $afterConflictImports] = $counts();

$checks = [
    'first_import_3_lines' => $first['inserted'] === 3 && $first['duplicates'] === 0,
    'repeat_import_0_lines_3_duplicates' => $second['inserted'] === 0 && $second['duplicates'] === 3,
    'all_duplicate_looking_lines_preserved' => $afterRepeatLines === $beforeLines + 3,
    'malformed_rejected' => $malformed,
    'malformed_saved_nothing' => $afterInvalidLines === $afterRepeatLines && $afterInvalidImports === $afterRepeatImports,
    'conflict_rejected' => $conflict,
    'conflict_rolled_back' => $afterConflictLines === $afterRepeatLines && $afterConflictImports === $afterRepeatImports,
    'expected_import_headers' => $afterRepeatImports === $beforeImports + 2,
];
echo json_encode([
    'tenant_id' => $tenantId,
    'bank_account_id' => $accountId,
    'checks' => $checks,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
