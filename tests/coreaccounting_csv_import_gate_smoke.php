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
}
$assert('single importer sends run identity', str_contains($read('dashboard/src/components/CsvImportPage.jsx'), 'body.import_run_id = runId'));
$assert('bulk importer sends run identity', str_contains($read('dashboard/src/pages/CsvBulkImport.jsx'), 'body.import_run_id = runId'));
$assert('unclaimed run cannot be finalized', str_contains($read('api/admin/csv_import_history.php'), "!isset(\$oldErrors['_source_commit_started'])"));

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
