<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$passed = 0;
$failed = 0;
$source = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);
$check = static function (string $label, bool $ok) use (&$passed, &$failed): void {
    if ($ok) { echo "PASS {$label}\n"; $passed++; }
    else { echo "FAIL {$label}\n"; $failed++; }
};

$overview = $source('dashboard/src/pages/BookkeepingOverview.jsx');
$queue = $source('api/transactions_to_review.php');
$bills = $source('modules/ap/api/bills.php');
$rawExport = $source('modules/ap/api/bills_csv_export.php');
$templateExport = $source('core/export_datasets.php');
$payments = $source('api/treasury_payments.php');
$transfers = $source('api/treasury_transfers.php');
$periods = $source('modules/accounting/api/periods.php');
$entityScope = $source('dashboard/src/lib/useAccountingEntityScope.js');
$apAging = $source('modules/ap/api/aging.php');
$apAgingUi = $source('modules/ap/ui/AgingTable.jsx');
$reportLibrary = $source('dashboard/src/components/FinancialReportLibrary.jsx');
$accountingModule = $source('modules/accounting/ui/AccountingModule.jsx');
$journalList = $source('modules/accounting/ui/JournalEntries.jsx');
$journalCreate = $source('modules/accounting/ui/JournalEntryCreate.jsx');
$journalDetail = $source('modules/accounting/ui/JournalEntryDetail.jsx');

$check('Entity validation waits for the available-entity response',
    str_contains($entityScope, 'loaded && requested !== null && !allEntities'));
$check('Accounting navigation and entry actions retain legal entity scope',
    str_contains($accountingModule, 'scopedAccountingPath(to, search)')
    && str_contains($accountingModule, "scopedAccountingPath('/modules/accounting/journal-entries/new', location.search)")
    && str_contains($journalList, 'AccountingEntitySelector scope={scope} allowAll={false}')
    && str_contains($journalList, 'scope.withScope(')
    && str_contains($journalList, "if (scope.entityId) qs.set('entity_id'")
    && str_contains($journalCreate, "searchParams.get('entity_id')")
    && str_contains($journalCreate, 'navigate(scopedPath(')
    && str_contains($journalDetail, 'const entryEntityId =')
    && str_contains($journalDetail, "navigate(scopedPath('/modules/accounting/journal-entries')"));
$check('Changing accounting entity resets prior page without losing journal filters',
    str_contains($entityScope, "next.delete('page')")
    && str_contains($journalList, 'setSearchParams(next)')
    && str_contains($journalList, "next.set('entity_id', String(scope.entityId))"));
$check('Direct journal links resolve their source legal entity before navigation',
    str_contains($journalDetail, "String(data?.entry?.id) === String(id)")
    && str_contains($journalDetail, "next.set('entity_id', String(entryEntityId))")
    && str_contains($journalCreate, "next.set('entity_id', String(sourceEntry.entity_id))")
    && str_contains($journalCreate, 'scopedSourceId.current === String(sourceId)')
    && str_contains($journalCreate, 'const sourcePath = path => addEntityScope(path, sourceEntry?.entity_id)')
    && str_contains($journalCreate, 'to={sourcePath(`/modules/accounting/journal-entries/${sourceEntry.id}`)}'));
$check('AP aging and its report link retain validated legal-entity scope',
    str_contains($apAging, 'booksHealthResolveEntity')
    && str_contains($apAging, 'apComputeAging($tid, $asOf, $entityId)')
    && str_contains($apAgingUi, 'useAccountingEntityScope')
    && str_contains($apAgingUi, 'data?.entity_id')
    && str_contains($reportLibrary, "moduleId !== 'ap'"));

$check('Overview carries entity into exact task queues',
    str_contains($overview, '/modules/ap/bills?status=needs_action')
    && str_contains($overview, '/modules/treasury/payments?queue=pending')
    && str_contains($overview, '/modules/treasury/transfers?queue=pending')
    && str_contains($overview, '/modules/accounting/periods?status=ready_to_close')
    && str_contains($overview, 'scope.withScope('));
$check('Bank review validates entity and filters both account choices and lines',
    str_contains($queue, 'booksHealthResolveEntity')
    && str_contains($queue, 'AND entity_id = :e')
    && str_contains($queue, 'ba.entity_id = :e')
    && str_contains($queue, "'entity_id'     => \$entityId"));
$check('AP list and both export modes share entity and needs-action filters',
    str_contains($bills, "\$statusFilter === 'needs_action'")
    && str_contains($bills, 'AS action_count')
    && str_contains($rawExport, "'entity_id'   => \$entityId")
    && str_contains($rawExport, "\$datasetOptions['status'] === 'needs_action'")
    && str_contains($templateExport, "\$opts['status'] ?? '') === 'needs_action'")
    && str_contains($templateExport, 'entity_id = :entity_id'));
$check('Treasury pending queues have matching entity filters and total counts',
    str_contains($payments, "\$statusFilter === 'pending'")
    && str_contains($transfers, "\$statusFilter === 'pending'")
    && str_contains($transfers, 'source_entity_id = :source_entity_id OR destination_entity_id = :destination_entity_id')
    && str_contains($payments, 'SELECT COUNT(*) FROM treasury_payments')
    && str_contains($transfers, 'SELECT COUNT(*) FROM treasury_transfers'));
$check('Periods expose the ready-to-close subset',
    str_contains($periods, "\$statusFilter === 'ready_to_close'")
    && str_contains($periods, "status = 'open' AND end_date < CURRENT_DATE"));
foreach (['IncomeStatement', 'BalanceSheet', 'CashFlowStatement', 'TrialBalance'] as $report) {
    $ui = $source("modules/accounting/ui/{$report}.jsx");
    $check("{$report} scopes comparisons, drill and snapshot",
        str_contains($ui, 'scope.entityId')
        && str_contains($ui, 'entityId={scope.entityId}')
        && str_contains($ui, 'entity_id: scope.entityId')
        && str_contains($ui, 'AccountingEntitySelector'));
}

echo "{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
