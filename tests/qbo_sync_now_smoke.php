<?php
/** Static contract smoke for QBO Sync now + 15-minute scheduling. */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . "\n";
    $ok ? $pass++ : $fail++;
};

$service = (string) file_get_contents($root . '/core/qbo/sync_now.php');
$api = (string) file_get_contents($root . '/api/qbo.php');
$ui = (string) file_get_contents($root . '/dashboard/src/pages/QboSettings.jsx');
$syncDashboard = (string) file_get_contents($root . '/dashboard/src/pages/AccountingSyncDashboard.jsx');
$deploy = (string) file_get_contents($root . '/.github/workflows/deploy-cloudways.yml');
$masterCron = (string) file_get_contents($root . '/cron/qbo_sync_inbound.php');
$txnCron = (string) file_get_contents($root . '/cron/qbo_two_way_sync.php');
$masterPull = (string) file_get_contents($root . '/core/qbo/sync_in.php');

$check('canonical direction-aware runner exists', str_contains($service, 'function qboSyncNowEntity'));
foreach (['journal_entries','customers','vendors','invoices','bills','payments','chart_of_accounts'] as $entity) {
    $check("runner declares {$entity}", str_contains($service, "'{$entity}'"));
}
$check('two-way invoices run inbound and outbound workers',
    str_contains($service, 'qboPullInvoices') && str_contains($service, 'qboSyncInvoices'));
$check('payments Sync now covers AR, AP, and deposits',
    str_contains($service, 'qboPullPayments') && str_contains($service, 'qboPullBillPayments')
    && str_contains($service, 'qboPullDeposits') && str_contains($service, 'qboSyncBillPayments'));
$check('API exposes POST sync_now with manage permission',
    str_contains($api, "case 'sync_now'") && str_contains($api, 'qboSyncNowEntity')
    && str_contains($api, "integrations.qbo.manage"));
$check('sync_now shim exists', file_exists($root . '/api/qbo/sync_now.php'));
$check('every QBO settings workflow row renders Sync now',
    str_contains($ui, 'data-testid={`qbo-sync-now-${entity}`}')
    && str_contains($ui, "'Sync now'"));
$check('unsaved/off/unsupported rows cannot run',
    str_contains($ui, "busy || dirty || dir === 'off' || !supported"));
$check('unified accounting dashboard calls the canonical runner',
    str_contains((string) file_get_contents($root . '/api/admin/accounting_sync_reconcile.php'), 'qboSyncNowEntity'));
$check('unified accounting UI labels actions Sync now',
    str_contains($syncDashboard, "'Sync now'") && str_contains($syncDashboard, 'Sync all now'));
$check('master-data worker is incremental and retains nightly full mode',
    str_contains($masterCron, 'qboSyncScheduleSince') && str_contains($masterCron, "in_array('--full'"));
$check('master-data Query API accepts modified_since',
    str_contains($masterPull, 'MetaData.LastUpdatedTime') && str_contains($masterPull, 'modified_since'));
$check('transaction worker honors saved directions',
    str_contains($txnCron, "['pull', 'two_way']") && str_contains($txnCron, 'qboSyncConfigRead'));
foreach (['qbo_sync_outbound.php','qbo_sync_inbound.php','qbo_two_way_sync.php','qbo_payments_poll.php','qbo_health_alerts.php'] as $worker) {
    $check("deployment installs {$worker} every 15 minutes", preg_match('/(?:\\*\/15|(?:2,17,32,47|5,20,35,50|8,23,38,53|11,26,41,56|14,29,44,59)) \\* \\* \\* \\*[^\\n]*' . preg_quote($worker, '/') . '/', $deploy) === 1);
}

echo "QBO Sync now smoke: {$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
