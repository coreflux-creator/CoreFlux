<?php
/** Finance CSV source evidence and claim contracts. */
declare(strict_types=1);

require_once __DIR__ . '/../core/accounting/csv_import_gate.php';

$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n"; }
};
$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

$assert('exact source bytes match', coreAccountingCsvContentMatches("Name\nTest\n", "Name\nTest\n"));
$assert('UTF-8 BOM does not change import text', coreAccountingCsvContentMatches("\xEF\xBB\xBFName\nTest\n", "Name\nTest\n"));
$assert('different value is refused', !coreAccountingCsvContentMatches("Name\nTest\n", "Name\nOther\n"));
$assert('different line ending is refused', !coreAccountingCsvContentMatches("Name\r\nTest\r\n", "Name\nTest\n"));

$gate = $read('core/accounting/csv_import_gate.php');
foreach (['import_run_id', 'created_by_user_id = :user_id', 'tenant_id = :tenant_id',
          "\$run['entity'] !== \$entity", "document_type = 'csv_source'",
          'attached_by_user_id = :user_id', 'deleted_at IS NULL',
          'StorageService::getInstance()->read', '_import_outcome_unconfirmed',
          '_source_commit_started', 'FOR UPDATE'] as $needle) {
    $assert("gate checks {$needle}", str_contains($gate, $needle));
}
$assert('standalone-only claim', str_contains($gate, "getenv('COREFLUX_ENV') !== 'coreaccounting'"));

$simple = coreAccountingCsvOutcomeSummary([
    'imported_count' => 2, 'skipped_count' => 0, 'errors' => [],
]);
$assert('simple import summary uses source counts', $simple['imported'] === 2
    && $simple['skipped'] === 0 && $simple['total'] === 2 && $simple['status'] === 'success');
$grouped = coreAccountingCsvOutcomeSummary([
    'imported_count' => 1, 'skipped_count' => 1, 'row_count' => 5,
    'imported_row_count' => 3, 'skipped_row_count' => 2,
    'errors' => ['4' => ['invalid line']],
]);
$assert('grouped import summary counts lines, not documents', $grouped['imported'] === 3
    && $grouped['skipped'] === 2 && $grouped['total'] === 5
    && $grouped['status'] === 'partial' && count($grouped['errors']) === 1);
$failed = coreAccountingCsvOutcomeSummary([
    'imported_count' => 0, 'skipped_count' => 4, 'errors' => ['2' => ['invalid']],
]);
$assert('validation-aborted import records failure', $failed['status'] === 'failed'
    && $failed['imported'] === 0 && $failed['total'] === 4);
$assert('server outcome is scoped to claimed actor and tenant', str_contains($gate, 'function coreAccountingRecordCsvImportOutcome(')
    && str_contains($gate, 'created_by_user_id = :user_id')
    && str_contains($gate, "isset(\$markers['_source_commit_started'])")
    && str_contains($gate, "'_server_outcome_recorded'"));

$entities = [
    'modules/ap/api/csv_import.php' => 'ap_vendors',
    'modules/accounting/api/accounts_csv_import.php' => 'accounting_accounts',
    'modules/billing/api/items_csv_import.php' => 'billing_items',
    'modules/ap/api/bills_csv_import.php' => 'ap_bills',
    'modules/billing/api/csv_import.php' => 'billing_invoices',
    'modules/ap/api/payments_csv_import.php' => 'ap_payments',
    'modules/billing/api/payments_csv_import.php' => 'billing_payments',
];
foreach ($entities as $path => $entity) {
    $source = $read($path);
    $claim = strpos($source, "coreAccountingClaimCsvImport('{$entity}'");
    $write = strrpos($source, 'scopedInsert(');
    if ($write === false) $write = strrpos($source, 'CsvImportService::commit(');
    if ($write === false) $write = strrpos($source, 'api_ok($result)');
    $assert("{$entity} claims source before writing", $claim !== false && $write !== false && $claim < $write);
    $commitSource = $claim === false ? '' : substr($source, $claim);
    $assert("{$entity} records every commit outcome on the server",
        substr_count($commitSource, 'coreAccountingRecordCsvImportOutcome(') > 0
        && substr_count($commitSource, 'api_ok(') === substr_count($commitSource, 'coreAccountingRecordCsvImportOutcome('));
}
$single = $read('dashboard/src/components/CsvImportPage.jsx');
$bulk = $read('dashboard/src/pages/CsvBulkImport.jsx');
$assert('single importer sends run identity', str_contains($single, 'body.import_run_id = runId'));
$assert('bulk importer sends run identity', str_contains($bulk, 'body.import_run_id = runId'));
$assert('single importer can recover a lost response without another source commit',
    str_contains($single, 'recoverCsvImportAudit({ runId })')
    && str_contains($single, 'checkServerOutcome')
    && str_contains($single, 'Check server outcome'));
$assert('bulk importer can recover a lost response without another source commit',
    str_contains($bulk, 'recoverCsvImportAudit({ runId })')
    && str_contains($bulk, "f.audit?.state === 'unconfirmed' && (f.committed || financeOnly)")
    && str_contains($bulk, 'Check server outcome'));
$history = $read('api/admin/csv_import_history.php');
$assert('unconfirmed standalone run cannot be client-finalized', str_contains($history,
    "if (getenv('COREFLUX_ENV') === 'coreaccounting')")
    && str_contains($history, 'The server has not recorded this import outcome'));
$assert('standalone cannot forge a direct history result', str_contains($history,
    "getenv('COREFLUX_ENV') === 'coreaccounting' && \$action !== 'reserve'"));
$assert('history exposes server-recorded receipt', str_contains($history, "'_server_outcome_recorded'")
    && str_contains($history, "'rows_imported' => (int) \$run['rows_imported']"));
$audit = $read('dashboard/src/lib/csvImportAudit.js');
$assert('standalone browser checks server receipt counts', str_contains($audit, '!finalized.server_outcome_recorded')
    && str_contains($audit, 'Number(finalized.rows_imported) !== Number(imported)'));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
