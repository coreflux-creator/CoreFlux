<?php
/** Read-only month-end comparison of aged subledgers with entity GL controls. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'staging') {
    fwrite(STDERR, "This audit is restricted to the staging CLI.\n");
    exit(2);
}
require_once __DIR__ . '/../modules/accounting/lib/standard_reports.php';
require_once __DIR__ . '/../modules/billing/lib/billing.php';
require_once __DIR__ . '/../modules/ap/lib/ap.php';

$tenantId = 0;
$entityId = 0;
$dates = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--tenant=([1-9][0-9]*)$/', $arg, $match)) $tenantId = (int) $match[1];
    elseif (preg_match('/^--entity=([1-9][0-9]*)$/', $arg, $match)) $entityId = (int) $match[1];
    elseif (preg_match('/^--as-of=(\d{4}-\d{2}-\d{2})$/', $arg, $match)) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1]);
        if (!$parsed || $parsed->format('Y-m-d') !== $match[1]) {
            fwrite(STDERR, "Invalid --as-of date.\n");
            exit(2);
        }
        $dates[] = $match[1];
    }
}
if ($tenantId <= 0 || $entityId <= 0 || !$dates) {
    fwrite(STDERR, "Use --tenant=ID --entity=ID --as-of=YYYY-MM-DD (repeat dates).\n");
    exit(2);
}
$GLOBALS['__cf_request_tenant_id'] = $tenantId;
$pdo = getDB();
$scope = $pdo->prepare(
    'SELECT t.name FROM tenants t JOIN accounting_entities e ON e.tenant_id = t.id
      WHERE t.id = :t AND e.id = :e AND e.active = 1'
);
$scope->execute(['t' => $tenantId, 'e' => $entityId]);
if (!in_array($scope->fetchColumn(), ['CoreFlux CI Simulation', "CoreFlux CI Simulation {$tenantId}"], true)) {
    fwrite(STDERR, "This audit only reads an active entity in a CI Simulation tenant.\n");
    exit(2);
}

$totals = [];
foreach ($dates as $asOf) {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    try {
        $trial = accountingTrialBalance($tenantId, $asOf, $entityId);
        $accounts = [];
        foreach ($trial as $row) $accounts[(string) $row['code']] = (float) $row['balance_signed'];
        $ar = array_sum(array_map(static fn(array $row): float => (float) $row['total_due'],
            billingComputeAging($tenantId, $asOf, $entityId)));
        $ap = array_sum(array_map(static fn(array $row): float => (float) $row['total_due'],
            apComputeAging($tenantId, $asOf, $entityId)));
        $pdo->commit();
        $totals[] = ['as_of' => $asOf, 'ar_aging' => round($ar, 2),
            'ar_gl_1100' => round($accounts['1100'] ?? 0, 2),
            'ar_difference' => round($ar - ($accounts['1100'] ?? 0), 2),
            'ap_aging' => round($ap, 2), 'ap_gl_2000' => round($accounts['2000'] ?? 0, 2),
            'ap_difference' => round($ap - ($accounts['2000'] ?? 0), 2)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
echo json_encode(['tenant_id' => $tenantId, 'entity_id' => $entityId, 'snapshots' => $totals],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
