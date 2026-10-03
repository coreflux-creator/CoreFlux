<?php
/** Entity-scoped CoreOne v1 financial reports from the canonical ERP books. */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/accounting/coreone_v1.php';
require_once __DIR__ . '/../../../modules/accounting/lib/standard_reports.php';
require_once __DIR__ . '/../../../modules/billing/lib/billing.php';
require_once __DIR__ . '/../../../modules/ap/lib/ap.php';

$credential = coreoneV1Authenticate($_SERVER['HTTP_AUTHORIZATION'] ?? null);
if (!$credential) api_error('Invalid or expired service credential', 401);
setRequestTenantId((int) $credential['tenant_id']);
setRequestModuleScope('accounting');
if (api_method() !== 'GET') api_error('Method not allowed', 405);

$type = api_query('type', '');
$periodTypes = ['income_statement', 'cash_flow_indirect'];
$snapshotTypes = ['balance_sheet', 'trial_balance', 'ar_aging', 'ap_aging'];
if (!is_string($type) || !in_array($type, array_merge($periodTypes, $snapshotTypes), true)) {
    api_error('Unknown report type', 422);
}
$period = in_array($type, $periodTypes, true);
$allowedKeys = $period ? ['type', 'from', 'to'] : ['type', 'as_of'];
if (array_diff(array_keys($_GET), $allowedKeys)) {
    api_error('Unsupported report filter', 422);
}
$dates = [];
foreach ($period ? ['from', 'to'] : ['as_of'] as $key) {
    $raw = api_query($key);
    $parsed = is_string($raw) ? DateTimeImmutable::createFromFormat('!Y-m-d', $raw) : false;
    if (!$parsed || $parsed->format('Y-m-d') !== $raw) {
        api_error("{$key} must be a valid YYYY-MM-DD date", 422);
    }
    $dates[$key] = $raw;
}
if ($period && $dates['from'] > $dates['to']) {
    api_error('from must not be later than to', 422);
}

$tenantId = (int) $credential['tenant_id'];
$entityId = (int) $credential['entity_id'];
$pdo = getDB();
try {
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->beginTransaction();
    $data = match ($type) {
        'income_statement' => reportIncomeStatement($tenantId, $dates['from'], $dates['to'], $entityId),
        'balance_sheet' => reportBalanceSheet($tenantId, $dates['as_of'], $entityId),
        'trial_balance' => ['rows' => accountingTrialBalance($tenantId, $dates['as_of'], $entityId)],
        'cash_flow_indirect' => reportCashFlowIndirect($tenantId, $dates['from'], $dates['to'], $entityId),
        'ar_aging' => ['rows' => billingComputeAging($tenantId, $dates['as_of'], $entityId)],
        'ap_aging' => ['rows' => apComputeAging($tenantId, $dates['as_of'], $entityId)],
    };
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[coreone-v1-reports] ' . $e->getMessage());
    api_error('Report could not be prepared', 503);
}
api_ok(['schema_version' => 1, 'type' => $type, 'entity_id' => $entityId,
    'period' => $dates, 'data' => $data]);
