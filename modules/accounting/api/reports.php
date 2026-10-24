<?php
/**
 * Accounting API — Standard reports
 *
 *   GET /api/accounting/reports?type=income_statement&from=YYYY-MM-DD&to=YYYY-MM-DD[&entity_id=]
 *   GET /api/accounting/reports?type=balance_sheet&as_of=YYYY-MM-DD[&entity_id=]
 *
 * Both reports drive off accountingTrialBalance() for the underlying numbers
 * but reshape to the canonical financial-statement structure.
 */
require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/accounting.php';
require_once __DIR__ . '/../lib/consolidation.php';
require_once __DIR__ . '/../lib/standard_reports.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
rbac_legacy_require($user, 'accounting.coa.view');

if ($method !== 'GET') api_error('Method not allowed', 405);
$type = (string) ($_GET['type'] ?? '');
$consolidate = !empty($_GET['consolidate']);
$eid = null;
if (array_key_exists('entity_id', $_GET)) {
    if ($consolidate) api_error('Choose either one legal entity or a consolidation scope.', 422);
    $rawEntityId = $_GET['entity_id'];
    if (!is_scalar($rawEntityId) || trim((string) $rawEntityId) === '') {
        api_error('Choose a legal entity from this workspace.', 422);
    }
    try {
        $eid = accountingValidateActiveEntityId($tid, $rawEntityId);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    }
}

// Consolidation-mode input: ?consolidate=1&entity_ids=1,2,3
// Or derive from an ownership root: ?consolidate=1&root_entity_id=1
$entityIds   = [];
if ($consolidate) {
    if (!in_array($type, ['income_statement', 'balance_sheet', 'trial_balance'], true)) {
        api_error('Consolidation is available for income statement, balance sheet, and trial balance.', 422);
    }
    if (array_key_exists('entity_ids', $_GET) && array_key_exists('root_entity_id', $_GET)) {
        api_error('Choose either entity_ids or root_entity_id for consolidation.', 422);
    }
    if (array_key_exists('entity_ids', $_GET)) {
        if (!is_string($_GET['entity_ids'])) api_error('Choose valid consolidation entities.', 422);
        foreach (preg_split('/\s*,\s*/', trim($_GET['entity_ids'])) as $value) {
            if ($value === '') api_error('Choose valid consolidation entities.', 422);
            try {
                $entityIds[] = accountingValidateActiveEntityId($tid, $value);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
        }
    } elseif (array_key_exists('root_entity_id', $_GET)) {
        if (!is_scalar($_GET['root_entity_id']) || trim((string) $_GET['root_entity_id']) === '') {
            api_error('Choose a valid consolidation root entity.', 422);
        }
        try {
            $rootEntityId = accountingValidateActiveEntityId($tid, $_GET['root_entity_id']);
        } catch (\InvalidArgumentException $e) {
            api_error($e->getMessage(), 422);
        }
        if ($rootEntityId === null) api_error('Choose a valid consolidation root entity.', 422);
        $asOfForTree = $_GET['as_of'] ?? $_GET['to'] ?? date('Y-m-d');
        $tree = entityRelationshipResolveDescendants($tid, $rootEntityId, $asOfForTree);
        foreach (array_keys($tree) as $value) {
            try {
                $entityIds[] = accountingValidateActiveEntityId($tid, $value);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
        }
    }
    if (!$entityIds) api_error('consolidate=1 requires entity_ids=... or root_entity_id=...', 422);
    $entityIds = array_values(array_unique($entityIds));
}

/**
 * Wrap a report builder so any SQL / library error becomes a 200 with a
 * `data_warning` string instead of a raw 500. The front-end shows an
 * amber "data not ready yet" banner per Sprint 6f UX-cleanup pattern.
 */
function _safeReport(callable $fn): array {
    try {
        $out = $fn();
        return is_array($out) ? $out : ['rows' => $out];
    } catch (\Throwable $e) {
        error_log('accounting/reports failed: ' . $e->getMessage());
        return [
            'data_warning' => 'Report data not ready yet — ' . $e->getMessage(),
            'rows'         => [],
            'lines'        => [],
            'sections'     => [],
            'totals'       => [],
        ];
    }
}

if ($type === 'income_statement') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => $consolidate
        ? consolidateIncomeStatement($tid, $entityIds, $from, $to)
        : reportIncomeStatement($tid, $from, $to, $eid)));
}

if ($type === 'balance_sheet') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => $consolidate
        ? consolidateBalanceSheet($tid, $entityIds, $asOf)
        : reportBalanceSheet($tid, $asOf, $eid)));
}

if ($type === 'trial_balance') {
    $asOf = (string) ($_GET['as_of'] ?? date('Y-m-d'));
    api_ok(_safeReport(function () use ($consolidate, $tid, $entityIds, $asOf, $eid) {
        if ($consolidate) return consolidateTrialBalance($tid, $entityIds, $asOf);
        return ['rows' => accountingTrialBalance($tid, $asOf, $eid), 'as_of' => $asOf, 'entity_id' => $eid];
    }));
}

if ($type === 'cash_flow_indirect' || $type === 'cash_flow') {
    $from = (string) ($_GET['from'] ?? date('Y-01-01'));
    $to   = (string) ($_GET['to']   ?? date('Y-m-d'));
    api_ok(_safeReport(fn() => reportCashFlowIndirect($tid, $from, $to, $eid)));
}

api_error('Unknown report type. Use income_statement, balance_sheet, trial_balance, or cash_flow_indirect.', 422);

