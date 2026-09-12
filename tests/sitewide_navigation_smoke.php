<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $name, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        echo "  PASS {$name}\n";
        $pass++;
        return;
    }
    echo "  FAIL {$name}\n";
    $fail++;
};
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

echo "Public navigation semantics\n";
$publicPages = [
    'people.html', 'finance.html', 'accounting.html', 'tax.html',
    'wealth.html', 'reporting.html', 'crm.html', 'pricing.html', 'login.html',
];
foreach ($publicPages as $page) {
    $html = $read($page);
    $assert("{$page} has a page heading", preg_match('/<h1(?:\s|>)/i', $html) === 1);
    $assert("{$page} has no dead Modules link", !str_contains($html, '<a href="#">Modules</a>'));
    $assert("{$page} exposes an accessible Modules trigger", str_contains($html, 'class="dropdown-trigger" aria-haspopup="true"'));
}
$css = $read('assets/css/styles.css');
$assert('dropdown opens for keyboard focus', str_contains($css, '.dropdown:focus-within .dropdown-content'));
$assert('public navigation has a visible keyboard focus style', str_contains($css, ':focus-visible'));

echo "\nCanonical SPA links\n";
$activeSources = [
    'api/ap/approve_by_email.php',
    'api/staffing/approve_timesheet_by_email.php',
    'api/gusto_oauth_callback.php',
    'scripts/ap_weekly_queue_sunday.php',
    'scripts/billing_recurring_generate.php',
    'modules/staffing/api/timesheet_email_approver.php',
    'modules/ap/api/weekly_queue.php',
    'modules/ap/api/vendor_portal.php',
    'modules/ap/api/bill_approvals.php',
    'modules/time/api/intake.php',
    'modules/payroll/ui/PayPeriods.jsx',
    'modules/ap/ui/WeeklyQueue.jsx',
    'modules/accounting/ui/StandardReports.jsx',
];
foreach ($activeSources as $source) {
    $contents = $read($source);
    $assert("{$source} avoids obsolete hash routes", !preg_match('#(?:/spa\.php)?/\#/modules|\#/(?:modules|vendor)/#', $contents));
}

echo "\nModule navigation consistency\n";
$timeModule = $read('modules/time/ui/TimeModule.jsx');
$coreModules = $read('core/modules.php');
$fallbackModules = $read('dashboard/src/App.jsx');
$treasury = $read('modules/treasury/ui/TreasuryOverview.jsx');
$mailSettings = $read('dashboard/src/pages/MailSettingsPage.jsx');
$assert('legacy Time inbox deep link reaches the working intake queue', str_contains($timeModule, 'to="/modules/time/intake"'));
$assert('primary Time navigation names Intake Queue', str_contains($coreModules, "['name' => 'Intake Queue'"));
$assert('primary Time navigation does not expose unfinished Missing Time', !str_contains($coreModules, "['name' => 'Missing Time'"));
$assert('fallback Time navigation names Intake Queue', str_contains($fallbackModules, "{ name: 'Intake Queue'"));
$assert('Treasury overview links to the shipped forecast', str_contains($treasury, 'to="../forecast"'));
$assert('Treasury overview links to cash scenarios', str_contains($treasury, 'to="../scenario"'));
$assert('Treasury overview no longer calls its forecast coming soon', !str_contains($treasury, '13-week forecast coming soon'));
$assert('unknown authenticated paths return to the workspace', str_contains($fallbackModules, '<Route path="*" element={<Navigate to="/" replace />} />'));
$assert('unknown module paths do not expose unfinished placeholder screens', str_contains($fallbackModules, '<Route path="/modules/:moduleId/*" element={<Navigate to="/" replace />} />'));
$assert('Mail Settings does not advertise an unavailable sending-domain control', !str_contains($mailSettings, 'coming soon'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
