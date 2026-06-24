<?php
/**
 * Time module Phase A smoke test — static contract verification.
 */
declare(strict_types=1);
require_once __DIR__ . '/../core/ModuleRegistry.php';
require_once __DIR__ . '/../modules/time/lib/time.php';

$pass = 0; $fail = 0;
$assert = function ($n, $c) use (&$pass, &$fail) { if ($c) { echo "  ✓ {$n}\n"; $pass++; } else { echo "  ✗ {$n}\n"; $fail++; } };

echo "Manifest\n";
$reg = ModuleRegistry::reset(__DIR__ . '/../modules');
$t = $reg->getModule('time');
$assert('registered',         $t !== null);
$assert('depends_on people + placements',
    in_array('people', $t['depends_on'] ?? [], true) && in_array('placements', $t['depends_on'] ?? [], true));

foreach (['time.view','time.entry.self','time.entry.manage','time.review','time.approve',
          'time.reject','time.bulk_upload','time.period.close','time.feed.consume',
          'time.dashboard.missing','time.categories.manage','time.audit.view',
          'time.tokenized_email.issue','time.tokenized_email.revoke'] as $p) {
    $assert("perm: {$p}", in_array($p, array_keys($t['permissions'] ?? []), true));
}

foreach (['time.entry.created','time.entry.approved','time.entry.superseded','time.bulk.uploaded',
          'time.period.opened','time.period.closed','time.feed.bundle_built','time.feed.consumed',
          'time.feed.superseded','time.category.created'] as $ev) {
    $assert("event: {$ev}", in_array($ev, $t['audit_events'] ?? [], true));
}

echo "\nMigration SQL\n";
$sql = (string) file_get_contents(__DIR__ . '/../modules/time/migrations/001_init.sql');
$assert('file exists',                           strlen($sql) > 0);
$assert('utf8mb4_unicode_ci used',               strpos($sql, 'utf8mb4_unicode_ci') !== false);
$assert('NOT utf8mb4_0900_ai_ci',                strpos($sql, 'utf8mb4_0900_ai_ci') === false);
foreach (['time_periods','tenant_time_categories','time_entries','time_downstream_feed'] as $tbl) {
    $assert("CREATE TABLE {$tbl}",               strpos($sql, "CREATE TABLE IF NOT EXISTS {$tbl}") !== false);
}
$assert('9 categories + custom enum',
    strpos($sql, "ENUM('regular_billable','regular_nonbillable','OT_billable','OT_nonbillable','holiday','vacation','sick','bereavement','unpaid_leave','custom')") !== false);
$assert('entry status 5 values',
    strpos($sql, "ENUM('draft','pending_review','approved','rejected','superseded')") !== false);
$assert('approved_via includes external email channel',
    strpos($sql, "ENUM('manual','tokenized_client_email','bulk_pre_approved','external_email')") !== false);
$assert('bundle_type 4 values',
    strpos($sql, "ENUM('ar','ap','payroll','revrec')") !== false);

echo "\nAPI files exist + parse\n";
foreach (['entries.php','periods.php','categories.php','reports.php','feed.php','csv_import.php'] as $f) {
    $p = __DIR__ . "/../modules/time/api/{$f}";
    $assert("api/{$f} exists", is_file($p));
    if (is_file($p)) { $o = []; $rc = 0; @exec('php -l ' . escapeshellarg($p) . ' 2>&1', $o, $rc); $assert("api/{$f} parses", $rc === 0); }
}

echo "\nUI components\n";
foreach (['TimeModule.jsx','MyTime.jsx','ReviewQueue.jsx','Periods.jsx','Reports.jsx','Categories.jsx','CsvImport.jsx','PeriodCloseWizard.jsx'] as $f) {
    $assert("ui/{$f}",  is_file(__DIR__ . "/../modules/time/ui/{$f}"));
}

echo "\nPeriodCloseWizard wiring\n";
$periodsJsx = (string) file_get_contents(__DIR__ . '/../modules/time/ui/Periods.jsx');
$assert('Periods.jsx imports PeriodCloseWizard',     strpos($periodsJsx, "import PeriodCloseWizard from './PeriodCloseWizard'") !== false);
$assert('Periods.jsx renders <PeriodCloseWizard',     strpos($periodsJsx, '<PeriodCloseWizard') !== false);
$assert('Periods.jsx Close button opens wizard',      strpos($periodsJsx, 'setWizardPeriod(p)') !== false);
$wizJsx = (string) file_get_contents(__DIR__ . '/../modules/time/ui/PeriodCloseWizard.jsx');
$assert('Wizard calls preview_close',                 strpos($wizJsx, 'action=preview_close') !== false);
$assert('Wizard calls action=close on confirm',       strpos($wizJsx, 'action=close') !== false);
$assert('Wizard has confirm test id',                 strpos($wizJsx, 'time-period-close-wizard-confirm') !== false);
$assert('Wizard has bundle table for ar/ap/payroll/revrec',
    strpos($wizJsx, "['ar', 'ap', 'payroll', 'revrec']") !== false);

echo "\nperiods.php preview_close action\n";
$periodsPhp = (string) file_get_contents(__DIR__ . '/../modules/time/api/periods.php');
$assert("periods.php has preview_close branch",       strpos($periodsPhp, "action === 'preview_close'") !== false);
$assert("periods.php calls timePreviewBundlesForPeriod", strpos($periodsPhp, 'timePreviewBundlesForPeriod(') !== false);

echo "\nLib contract\n";
foreach (['timeEntryGet','timeEntriesList','timeResolveRateSnapshot','timeBucket',
          'timeBuildBundlesForPeriod','timePreviewBundlesForPeriod','timeAudit'] as $fn) {
    $assert("fn: {$fn}", function_exists($fn));
}

echo "\nTimeBucket rollups (SPEC §2, §3.3)\n";
$assert('regular_billable → billable',       timeBucket('regular_billable')    === 'billable');
$assert('OT_billable → billable',            timeBucket('OT_billable')         === 'billable');
$assert('regular_nonbillable → nonbillable', timeBucket('regular_nonbillable') === 'nonbillable');
$assert('OT_nonbillable → nonbillable',      timeBucket('OT_nonbillable')      === 'nonbillable');
$assert('holiday → pto',                     timeBucket('holiday')              === 'pto');
$assert('vacation → pto',                    timeBucket('vacation')             === 'pto');
$assert('sick → pto',                        timeBucket('sick')                 === 'pto');
$assert('bereavement → pto',                 timeBucket('bereavement')          === 'pto');
$assert('unpaid_leave → unpaid',             timeBucket('unpaid_leave')         === 'unpaid');
$assert('custom → custom',                   timeBucket('custom')               === 'custom');

echo "\nStandard categories count = 9 (+ custom)\n";
$assert('TIME_CATEGORIES has exactly 10 entries', count(TIME_CATEGORIES) === 10);

echo "\nLegacy preserved\n";
$leg = glob(__DIR__ . '/../legacy/time_pre_spec_*');
$assert('legacy copy exists', is_array($leg) && count($leg) >= 1);

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
