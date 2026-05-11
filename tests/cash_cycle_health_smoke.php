<?php
/**
 * Smoke: Cash Cycle Health home-page tile.
 *
 * Static contract: API endpoint exists + emits the right envelope shape,
 * React tile is wired into DashboardOverview, and it tolerates missing
 * data without crashing the home page.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};

echo "Backend: /api/billing/cash_cycle_health.php\n";
$apiPath = __DIR__ . '/../modules/billing/api/cash_cycle_health.php';
$apiSrc  = (string) file_get_contents($apiPath);
$a('api file exists',                            is_file($apiPath));
$a('api parses',                                 (int) shell_exec('php -l ' . escapeshellarg($apiPath) . ' >/dev/null 2>&1; echo $?') === 0);
$a('requires api_bootstrap',                     str_contains($apiSrc, "require_once __DIR__ . '/../../../core/api_bootstrap.php'"));
$a('requires weekly_queue lib',                  str_contains($apiSrc, "require_once __DIR__ . '/../../ap/lib/weekly_queue.php'"));
$a("permission: billing.view",                   str_contains($apiSrc, "RBAC::requirePermission(\$user, 'billing.view')"));

echo "\nResponse envelope shape\n";
$a('returns dso_days',                           str_contains($apiSrc, "'dso_days'"));
$a('returns ar_outstanding_total',               str_contains($apiSrc, "'ar_outstanding_total'"));
$a('returns pwp_awaiting_ar {count, total}',     str_contains($apiSrc, "'pwp_awaiting_ar'") && str_contains($apiSrc, "'count'") && str_contains($apiSrc, "'total_amount'"));
$a('returns pwp_released_last_week',             str_contains($apiSrc, "'pwp_released_last_week'") && str_contains($apiSrc, "'ar_invoice_count'"));
$a('returns weekly_queue_blocked_count',         str_contains($apiSrc, "'weekly_queue_blocked_count'"));

echo "\nDSO + tolerance\n";
$a('DSO query joins payments to invoices',       str_contains($apiSrc, 'JOIN billing_payment_allocations a ON a.invoice_id = i.id')
                                                  && str_contains($apiSrc, 'JOIN billing_payments p             ON p.id = a.payment_id'));
$a('DSO window = last 90 days',                  str_contains($apiSrc, 'INTERVAL 90 DAY'));
$a('DSO scoped to status="paid"',                str_contains($apiSrc, "i.status = 'paid'"));
$a('each metric wrapped in try/catch fallback',  substr_count($apiSrc, "catch (\\Throwable") >= 3);

echo "\nPWP rollups\n";
$a('awaiting query filters pwp_status=awaiting_ar',str_contains($apiSrc, "pwp_status = 'awaiting_ar'"));
$a('awaiting query excludes paid/void',          str_contains($apiSrc, "status NOT IN ('paid','void')"));
$a('released query reads audit_log 7d window',   str_contains($apiSrc, "event = 'ap.bill.pwp.released'") && str_contains($apiSrc, 'INTERVAL 7 DAY'));
$a('released query joins to ap_bills for $',     str_contains($apiSrc, 'SELECT COALESCE(SUM(total), 0) FROM ap_bills'));

echo "\nBlocked count uses Weekly Queue lib\n";
$a('reuses apWeeklyQueueList()',                 str_contains($apiSrc, 'apWeeklyQueueList($tid, 7)'));
$a("counts blocker != none && != approver_pending", str_contains($apiSrc, "\$b !== 'none' && \$b !== 'approver_pending'"));

echo "\nReact tile + DashboardOverview wiring\n";
$tilePath = __DIR__ . '/../dashboard/src/pages/CashCycleHealthTile.jsx';
$tileSrc  = (string) file_get_contents($tilePath);
$a('tile file exists',                           is_file($tilePath));
$a('tile uses useApi() hook',                    str_contains($tileSrc, "useApi('/modules/billing/api/cash_cycle_health.php')"));
$a('tile hides while loading/error/no data',     str_contains($tileSrc, 'if (loading || error || !data) return null'));
$a('tile uses fmtMoney for currency',            str_contains($tileSrc, 'fmtMoney(arOut)') && str_contains($tileSrc, 'fmtMoney(released.total_amount)'));
$a('drill-in link → /modules/ap/weekly-queue',   str_contains($tileSrc, 'to="/modules/ap/weekly-queue"'));
$a('DSO tone good ≤30, neutral ≤45, warn ≤60',   str_contains($tileSrc, 'dso <= 30')
                                                  && str_contains($tileSrc, 'dso <= 45')
                                                  && str_contains($tileSrc, 'dso <= 60'));
$a('blocked banner when blocked > 0',            str_contains($tileSrc, 'blocked > 0') && str_contains($tileSrc, 'cash-cycle-blocked-banner'));
foreach (['cash-cycle-health-tile','cash-cycle-dso','cash-cycle-ar-outstanding','cash-cycle-pwp-awaiting','cash-cycle-pwp-released','cash-cycle-health-drill-in'] as $tid) {
    $a("testid: {$tid}",                         str_contains($tileSrc, "data-testid=\"{$tid}\"") || str_contains($tileSrc, "data-testid=\"{$tid}\"") || str_contains($tileSrc, 'testid="' . $tid . '"') || str_contains($tileSrc, "'{$tid}'") || str_contains($tileSrc, "\"{$tid}\""));
}

$ovSrc = (string) file_get_contents(__DIR__ . '/../dashboard/src/pages/DashboardOverview.jsx');
$a('DashboardOverview imports tile',             str_contains($ovSrc, "import CashCycleHealthTile from './CashCycleHealthTile'"));
$a('Manager-only render guard',                  str_contains($ovSrc, '{isManager && <CashCycleHealthTile />}'));
$a('Tile renders below the KpiSnapshotStrip',    strpos($ovSrc, '<KpiSnapshotStrip />') < strpos($ovSrc, '<CashCycleHealthTile />'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
