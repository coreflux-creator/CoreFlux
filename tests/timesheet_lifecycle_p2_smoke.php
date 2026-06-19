<?php
/**
 * Smoke — P2.1/P2.2/P2.3
 *
 *   P2.1: BillDetail → originating time entry deep-link (inverse cascade)
 *   P2.2: AR-paid → AP-payment-run nudge on the AP Weekly Queue
 *   P2.3: Auto-suggest payment run when PWP releases (CFO Dashboard tile)
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. P2.1 — bills.php enriches time_entry lines with source_timesheet_id\n";
$billsApi = (string) file_get_contents('/app/modules/ap/api/bills.php');
$a('JOIN to time_entries present',                str_contains($billsApi, 'FROM time_entries'));
$a('reads timesheet_id + work_date',              str_contains($billsApi, "'timesheet_id, work_date'") || (str_contains($billsApi, 'timesheet_id') && str_contains($billsApi, 'work_date')));
$a('writes source_timesheet_id into lines',       str_contains($billsApi, "source_timesheet_id"));
$a('writes source_work_date into lines',          str_contains($billsApi, "source_work_date"));
$a('skips lines without source_ref_id',           str_contains($billsApi, "!empty(\$l['source_ref_id'])"));
$a('handles missing time_entries table gracefully', (bool) preg_match('/catch \\(\\\\Throwable.*\\) \\{ \\/\\* time_entries table may be unavailable/s', $billsApi));

echo "\n2. P2.1 — BillDetail.jsx renders the inverse cascade link\n";
$billDet = (string) file_get_contents('/app/modules/ap/ui/BillDetail.jsx');
$a('time entry link conditional on source_type',  str_contains($billDet, "l.source_type === 'time_entry'") && str_contains($billDet, "l.source_type === 'time'"));
$a('routes to timesheet lifecycle URL',           (bool) preg_match("/\\/modules\\/staffing\\/timesheets\\/\\\$\\{l\\.source_timesheet_id\\}\\/lifecycle\\?entry_id=\\\$\\{l\\.source_ref_id\\}/", $billDet));
$a('renders ↳ time entry # label',                str_contains($billDet, '↳ time entry #'));
$a('has data-testid for each link',               str_contains($billDet, "ap-bill-line-time-entry-"));

echo "\n3. P2.2/P2.3 — pwp_released.php API surface\n";
$pwpApi = (string) file_get_contents('/app/modules/ap/api/pwp_released.php');
$a('auth required',                              str_contains($pwpApi, 'api_require_auth()'));
$a('ap.view RBAC gate',                          str_contains($pwpApi, "rbac_legacy_require(\$user, 'ap.view')"));
$a('only GET method',                            str_contains($pwpApi, "api_method() !== 'GET'"));
$a('caps days at 60',                            str_contains($pwpApi, 'min(60'));
$a('filters by pwp_status=triggered',            str_contains($pwpApi, "pwp_status = 'triggered'"));
$a('filters by pwp_released_at within window',   str_contains($pwpApi, 'DATE_SUB(NOW(), INTERVAL :d DAY)'));
$a('filters by status approved|partially_paid',  str_contains($pwpApi, "status IN ('approved','partially_paid')"));
$a('filters by amount_due > 0',                  str_contains($pwpApi, 'amount_due > 0'));
$a('returns count + total_due + bills payload',  str_contains($pwpApi, "'count'") && str_contains($pwpApi, "'total_due'") && str_contains($pwpApi, "'bills'"));

echo "\n4. P2.2/P2.3 — PwpReleasedNudge component\n";
$nudge = (string) file_get_contents('/app/dashboard/src/components/PwpReleasedNudge.jsx');
$a('component file exists',                      $nudge !== '');
$a('supports banner variant',                    str_contains($nudge, "variant === 'tile'") && str_contains($nudge, 'pwp-released-banner'));
$a('supports tile variant',                      str_contains($nudge, 'pwp-released-tile'));
$a('hides on count=0',                           str_contains($nudge, 'if (count === 0) return null;'));
$a('fetches /api/ap/pwp_released',               str_contains($nudge, '/modules/ap/api/pwp_released.php'));
$a('one-click suggest payment run hits /api/ap/bills.php?action=suggest-payment-run',
                                                 str_contains($nudge, 'action=suggest-payment-run'));
$a('exposes pwp-released-suggest-run testid',    str_contains($nudge, 'data-testid="pwp-released-suggest-run"'));
$a('exposes pwp-released-banner-suggest testid', str_contains($nudge, 'data-testid="pwp-released-banner-suggest"'));
$a('shows bill list with deep links',            str_contains($nudge, 'pwp-released-bill-list') && str_contains($nudge, '/modules/ap/bills/'));

echo "\n5. P2.2 — WeeklyQueue.jsx mounts the banner\n";
$wq = (string) file_get_contents('/app/modules/ap/ui/WeeklyQueue.jsx');
$a('imports PwpReleasedNudge',                   str_contains($wq, "import PwpReleasedNudge"));
$a('mounts the banner variant',                  (bool) preg_match('/<PwpReleasedNudge\\s+variant="banner"/', $wq));
$a('lookahead matches the queue (7d)',           str_contains($wq, 'days={7}'));

echo "\n6. P2.3 — CFODashboard.jsx mounts the tile\n";
$cfo = (string) file_get_contents('/app/dashboard/src/pages/CFODashboard.jsx');
$a('imports PwpReleasedNudge',                   str_contains($cfo, "import PwpReleasedNudge"));
$a('mounts the tile variant',                    (bool) preg_match('/<PwpReleasedNudge\\s+variant="tile"/', $cfo));

echo "\n7. Vite bundle picked up the new component\n";
$dv = trim((string) @file_get_contents('/app/.deploy-version'));
$bundleHashJs = '';
if (preg_match('/index-([a-zA-Z0-9_-]+)\.js/', $dv, $m)) $bundleHashJs = $m[0];
$jsBundle = '';
foreach (['/app/spa-assets/', '/app/dashboard/dist/spa-assets/'] as $dir) {
    if ($bundleHashJs && is_file($dir . $bundleHashJs)) {
        $jsBundle = (string) @file_get_contents($dir . $bundleHashJs);
        break;
    }
}
$a('bundle includes pwp-released testid', $jsBundle !== '' && (str_contains($jsBundle, 'pwp-released-banner') || str_contains($jsBundle, 'pwp-released-tile')),
   $bundleHashJs ? "bundle={$bundleHashJs}" : 'no bundle hash resolved');
$a('bundle includes ap-bill-line-time-entry testid', $jsBundle !== '' && str_contains($jsBundle, 'ap-bill-line-time-entry-'),
   $bundleHashJs ? "bundle={$bundleHashJs}" : 'no bundle hash resolved');

echo "\n— pass={$pass}  fail={$fail}\n";
exit($fail === 0 ? 0 : 1);
