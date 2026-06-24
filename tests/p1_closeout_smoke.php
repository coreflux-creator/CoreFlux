<?php
/**
 * P1 closeout smoke test — covers all six items from the 2026-02 closeout pass:
 *   1. Eager-load `config.local.php` in plaid_service.php + encryption.php
 *   2. Bulk-select export for ExpensesList (+ /modules/ap/api/expenses ?action=export_selected)
 *   3. Cycle config UI on Placement edit form (+ migration 002_cycle_config.sql)
 *   4. Plaid Transfer originate() / getStatus() implementation + link/exchange API
 *   5. Gusto Track B sync layer (+ /modules/payroll/api/gusto_sync.php)
 *   6. Engagement nudges already validated in sub_tenant_provisioning_smoke.php
 */

declare(strict_types=1);

$pass = 0;
$fail = 0;
$assert = function (string $name, $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ {$name}\n"; $pass++; }
    else       { echo "  ✗ {$name}\n"; $fail++; }
};
$lint = function (string $path): bool {
    $rc = 0; @exec('php -l ' . escapeshellarg($path) . ' 2>&1', $_, $rc);
    return $rc === 0;
};

// 1. Eager load
echo "1. config.local.php eager-load\n";
$plaid = file_get_contents(__DIR__ . '/../core/plaid_service.php');
$enc   = file_get_contents(__DIR__ . '/../core/encryption.php');
$assert('plaid_service.php eager-loads config.local.php',
        strpos($plaid, "_plaidLocalConfig = __DIR__ . '/config.local.php'") !== false);
$assert('plaid_service.php at file scope (not inside function)',
        strpos($plaid, 'function plaidConfigured') > strpos($plaid, '_plaidLocalConfig'));
$assert('encryption.php eager-loads config.local.php',
        strpos($enc, "_encLocalConfig = __DIR__ . '/config.local.php'") !== false);
$assert('encryption.php at file scope',
        strpos($enc, 'function _coreflux_data_key') > strpos($enc, '_encLocalConfig'));

// 2. Bulk export
echo "2. ExpensesList bulk-select export\n";
$ui = file_get_contents(__DIR__ . '/../modules/ap/ui/ExpensesList.jsx');
$assert('select-all checkbox',          strpos($ui, 'data-testid="ap-expenses-select-all"') !== false);
$assert('per-row checkbox',             strpos($ui, 'data-testid={`ap-expense-select-${r.id}`}') !== false);
$assert('sticky bulk action bar',       strpos($ui, 'data-testid="ap-expenses-bulk-bar"') !== false);
$assert('bulk export button',           strpos($ui, 'data-testid="ap-expenses-bulk-export"') !== false);
$assert('bulk clear button',            strpos($ui, 'data-testid="ap-expenses-bulk-clear"') !== false);
$assert('opens export endpoint',        strpos($ui, '?action=export_selected&ids=') !== false);

$api = file_get_contents(__DIR__ . '/../modules/ap/api/expenses.php');
$assert('action=export_selected handler', strpos($api, "action === 'export_selected'") !== false);
$assert('rejects empty ids',            strpos($api, "api_error('ids required'") !== false);
$assert('caps batch at 500',            strpos($api, 'too many ids') !== false);
$assert('streams CSV through shared service',
                                        strpos($api, 'Core\\CsvExportService') !== false);
$assert('uses governed expenses dataset',
                                        strpos($api, 'exportDatasetFetchExpenses') !== false);
$assert('audits ap.expense.export_selected',
                                        strpos($api, 'ap.expense.export_selected') !== false);
$assert('PHP parses cleanly',           $lint(__DIR__ . '/../modules/ap/api/expenses.php'));

// 3. Cycle config
echo "3. Placement cycle config\n";
$mig = file_get_contents(__DIR__ . '/../modules/placements/migrations/002_cycle_config.sql');
$assert('migration 002 exists',         is_string($mig) && strlen($mig) > 200);
$assert('adds billing_cycle_id',        strpos($mig, 'billing_cycle_id') !== false);
$assert('adds ap_cycle_id',             strpos($mig, 'ap_cycle_id') !== false);
$assert('adds payroll_cycle_id',        strpos($mig, 'payroll_cycle_id') !== false);
$assert('idempotent guard',             strpos($mig, 'information_schema.columns') !== false);
$assert('billing_cycle index',          strpos($mig, 'idx_pl_billing_cycle') !== false);

$pd = file_get_contents(__DIR__ . '/../modules/placements/ui/PlacementDetail.jsx');
$assert('Cycles tab declared',          strpos($pd, "slug: 'cycles'") !== false);
$assert('CyclesTab component',          strpos($pd, 'function CyclesTab(') !== false);
$assert('billing_cycle_id picker',      strpos($pd, 'data-testid={`placement-cycle-${field}`}') !== false);
$assert('cycles save button',           strpos($pd, 'data-testid="placement-cycles-save"') !== false);
$assert('PATCH placement endpoint',     strpos($pd, '/modules/placements/api/placements.php?id=') !== false);

// 4. Plaid Transfer go-live
echo "4. Plaid Transfer originate + link\n";
$drv = file_get_contents(__DIR__ . '/../core/payment_rails/plaid_transfer_driver.php');
$assert('originate calls authorization/create',
                                        strpos($drv, 'ENDPOINT_AUTHORIZATION_CREATE') !== false);
$assert('originate calls transfer/create',
                                        strpos($drv, 'ENDPOINT_TRANSFER_CREATE') !== false);
$assert('idempotency_key per transfer', strpos($drv, "':transfer'") !== false);
$assert('idempotency_key per auth',     strpos($drv, "':auth'") !== false);
$assert('reads tenant_payment_rails',   strpos($drv, 'tenant_payment_rails') !== false);
$assert('decrypts access_token',        strpos($drv, 'plaidDecryptAccessToken(') !== false);
$assert('originate throws on no link',  strpos($drv, 'has not linked a funding source') !== false);
$assert('getStatus calls /transfer/get',strpos($drv, 'ENDPOINT_TRANSFER_GET') !== false);

$tlink = file_get_contents(__DIR__ . '/../api/plaid_transfer_link.php');
$assert('plaid_transfer_link.php created',  is_string($tlink) && strlen($tlink) > 200);
$assert('action=link_token branch',     strpos($tlink, "action === 'link_token'") !== false);
$assert('action=exchange branch',       strpos($tlink, "action === 'exchange'") !== false);
$assert('persists encrypted token',     strpos($tlink, 'plaidEncryptAccessToken(') !== false);
$assert('upsert tenant_payment_rails',  strpos($tlink, 'tenant_payment_rails') !== false);
$assert('audits payment_rails.plaid.linked',
                                        strpos($tlink, 'payment_rails.plaid.linked') !== false);
$assert('PHP parses cleanly',           $lint(__DIR__ . '/../api/plaid_transfer_link.php'));

$treas = file_get_contents(__DIR__ . '/../modules/treasury/ui/TreasuryOverview.jsx');
$assert('PlaidTransferFundingCard rendered',
                                        strpos($treas, '<PlaidTransferFundingCard />') !== false);
$assert('Plaid Link CDN bootstrap',     strpos($treas, 'cdn.plaid.com/link/v2/stable/link-initialize.js') !== false);
$assert('exchange POST wired',          strpos($treas, '/api/plaid_transfer_link.php?action=exchange') !== false);
$assert('data-testid: plaid-transfer-link-btn',
                                        strpos($treas, 'data-testid="plaid-transfer-link-btn"') !== false);

// 5. Gusto Track B
echo "5. Gusto Track B\n";
$tb = file_get_contents(__DIR__ . '/../core/gusto_track_b.php');
$assert('gustoSyncEmployees() declared', preg_match('/function\s+gustoSyncEmployees\s*\(/', $tb) === 1);
$assert('gustoSyncPaySchedules() declared', preg_match('/function\s+gustoSyncPaySchedules\s*\(/', $tb) === 1);
$assert('gustoSyncCompensations() declared', preg_match('/function\s+gustoSyncCompensations\s*\(/', $tb) === 1);
$assert('gustoEnsureWebhookSubscription() declared',
                                        preg_match('/function\s+gustoEnsureWebhookSubscription\s*\(/', $tb) === 1);
$assert('writes gusto_employee_uuid back', strpos($tb, "UPDATE people_employees SET gusto_employee_uuid") !== false);
$assert('writes gusto_pay_schedule_uuid back',
                                        strpos($tb, "UPDATE payroll_pay_schedules SET gusto_pay_schedule_uuid") !== false);
$assert('frequency map covers biweekly',strpos($tb, "'Every Other Week'") !== false);
$assert('webhook idempotency probe',    strpos($tb, "if (is_array(\$sub) && (string) (\$sub['url'] ?? '') === \$url)") !== false);
$assert('PHP parses cleanly',           $lint(__DIR__ . '/../core/gusto_track_b.php'));

$ga = file_get_contents(__DIR__ . '/../modules/payroll/api/gusto_sync.php');
$assert('gusto_sync.php created',       is_string($ga) && strlen($ga) > 200);
$assert('action=employees',             strpos($ga, "case 'employees':") !== false);
$assert('action=pay_schedules',         strpos($ga, "case 'pay_schedules':") !== false);
$assert('action=compensations',         strpos($ga, "case 'compensations':") !== false);
$assert('action=webhook_subscribe',     strpos($ga, "case 'webhook_subscribe':") !== false);
$assert('action=all',                   strpos($ga, "case 'all':") !== false);
$assert('requires payroll.run.disburse',strpos($ga, "'payroll.run.disburse'") !== false);
$assert('PHP parses cleanly',           $lint(__DIR__ . '/../modules/payroll/api/gusto_sync.php'));

$gc = file_get_contents(__DIR__ . '/../modules/payroll/ui/GustoConnectCard.jsx');
$assert('Track B sync panel embedded',  strpos($gc, 'GustoTrackBSyncPanel') !== false);
$assert('Sync employees button',        strpos($gc, 'data-testid="gusto-sync-employees-btn"') !== false);
$assert('Sync pay schedules button',    strpos($gc, 'data-testid="gusto-sync-pay-schedules-btn"') !== false);
$assert('Sync compensations button',    strpos($gc, 'data-testid="gusto-sync-compensations-btn"') !== false);
$assert('Subscribe webhooks button',    strpos($gc, 'data-testid="gusto-sync-webhook-btn"') !== false);
$assert('Run full sync button',         strpos($gc, 'data-testid="gusto-sync-all-btn"') !== false);

echo "\n";
echo "Pass: {$pass}\n";
echo "Fail: {$fail}\n";
exit($fail === 0 ? 0 : 1);
