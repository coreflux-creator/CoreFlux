<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
require_once $root . '/core/accounting/csv_import_scope.php';

$checks = [];
$check = static function (string $label, bool $passed) use (&$checks): void {
    $checks[] = $passed;
    echo ($passed ? 'OK  ' : 'FAIL  ') . $label . PHP_EOL;
};

$previousEnvironment = getenv('COREFLUX_ENV');
putenv('COREFLUX_ENV=coreaccounting');
$financeEntities = [
    'ap_vendors', 'accounting_accounts', 'billing_items',
    'ap_bills', 'billing_invoices', 'ap_payments', 'billing_payments',
];
$check('standalone allows only the seven finance CSV entities',
    coreAccountingCsvEntities() === $financeEntities
    && count(array_filter($financeEntities, 'coreAccountingCsvEntityAllowed')) === count($financeEntities)
    && !coreAccountingCsvEntityAllowed('people')
    && !coreAccountingCsvEntityAllowed('placements')
    && !coreAccountingCsvEntityAllowed('time'));
putenv('COREFLUX_ENV=production');
$check('ERP CSV entity behavior remains available', coreAccountingCsvEntityAllowed('people'));
if ($previousEnvironment === false) putenv('COREFLUX_ENV');
else putenv('COREFLUX_ENV=' . $previousEnvironment);

$historyApi = $read('api/admin/csv_import_history.php');
$presetApi = $read('api/admin/csv_mapping_presets.php');
$check('standalone history reads and writes enforce finance scope',
    str_contains($historyApi, 'coreAccountingCsvEntityAllowed($entity)')
    && str_contains($historyApi, 'h.entity IN (')
    && str_contains($historyApi, "coreAccountingCsvEntityAllowed((string) \$_GET['entity'])"));
$check('standalone presets enforce finance scope on all operations',
    substr_count($presetApi, 'coreAccountingCsvEntityAllowed(') >= 4
    && str_contains($presetApi, 'entity IN ('));

$shared = $read('dashboard/src/components/CsvImportPage.jsx');
$bulk = $read('dashboard/src/pages/CsvBulkImport.jsx');
$history = $read('dashboard/src/pages/CsvImportHistory.jsx');
$recovery = $read('dashboard/src/components/CsvImportRecoveryNotice.jsx');
$accounting = $read('modules/accounting/ui/AccountingModule.jsx');
$check('shared import links resolve inside standalone accounting',
    str_contains($shared, "standaloneAccounting ? '/modules/accounting/imports' : '/data/bulk-import'")
    && str_contains($shared, "standaloneAccounting ? '/modules/accounting/import-history' : '/data/import-history'"));
$check('both import screens call the served preset PHP endpoint',
    str_contains($shared, '/api/admin/csv_mapping_presets.php?entity=')
    && str_contains($shared, "api.post('/api/admin/csv_mapping_presets.php'")
    && str_contains($bulk, '/api/admin/csv_mapping_presets.php?entity=')
    && str_contains($bulk, '/api/admin/csv_mapping_presets.php?action=use'));
$check('bulk detection and selectors are finance-only in standalone',
    str_contains($bulk, 'detectEntity(headers, visibleEntityOrder)')
    && str_contains($bulk, 'visibleEntityOrder.map(k =>')
    && str_contains($bulk, "financeOnly ? FINANCE_ENTITY_ORDER : ENTITY_ORDER"));
$check('finance import and history pages are routed and searchable',
    str_contains($accounting, '<CsvBulkImport financeOnly />')
    && str_contains($accounting, '<CsvImportHistory financeOnly />')
    && str_contains($accounting, "to: 'imports', label: 'CSV imports'")
    && str_contains($read('dashboard/src/lib/coreAccountingPaths.js'), "to: '/modules/accounting/imports'"));
$check('history filters finance-only rows and links back to finance import',
    str_contains($history, 'FINANCE_ENTITIES.has(row.entity)')
    && str_contains($history, "financeOnly ? '/modules/accounting/imports' : '/data/bulk-import'"));
$check('finance screens expose exact-run recovery after a tab reload without reimporting',
    str_contains($shared, 'rememberCsvImportRun({ id: runId, entity: presetEntity })')
    && str_contains($bulk, 'rememberCsvImportRun({ id: runId, entity: f.entity })')
    && str_contains($shared, '<CsvImportRecoveryNotice entity={presetEntity} />')
    && str_contains($bulk, '<CsvImportRecoveryNotice />')
    && str_contains($history, '<CsvImportRecoveryNotice onRecovered={fetchHistory} />')
    && str_contains($recovery, 'recoverCsvImportAudit({ runId: run.id })')
    && !str_contains($recovery, '?action=commit'));
$check('standalone can close only a server-verified unstarted run',
    str_contains($historyApi, "\$action === 'abandon_unstarted'")
    && str_contains($historyApi, 'coreAccountingCsvAbandonUnstarted(')
    && str_contains($historyApi, 'SELECT id, entity, status, error_summary, rows_total, rows_imported, rows_skipped')
    && str_contains($historyApi, 'can_abandon_unstarted')
    && str_contains($recovery, 'abandonUnstartedCsvImportAudit({ runId: run.id })')
    && str_contains($history, 'r.abandoned'));
$check('finance modules pass standalone scope to each shared CSV screen',
    substr_count($read('modules/billing/ui/BillingModule.jsx'), "standaloneAccounting={session?.product_mode === 'coreaccounting'}") === 3
    && substr_count($read('modules/ap/ui/APModule.jsx'), 'standaloneAccounting={standaloneAccounting}') === 3
    && str_contains($accounting, "<AccountsCsvImport standaloneAccounting={session?.product_mode === 'coreaccounting'} />")
    && str_contains($read('modules/billing/ui/InvoicesCsvImport.jsx'), 'standaloneAccounting={standaloneAccounting}')
    && str_contains($read('modules/ap/ui/BillsCsvImport.jsx'), 'standaloneAccounting={standaloneAccounting}'));

$failed = count(array_filter($checks, static fn(bool $passed): bool => !$passed));
echo $failed ? "Failed: {$failed}" . PHP_EOL : 'Passed: ' . count($checks) . PHP_EOL;
exit($failed ? 1 : 0);
