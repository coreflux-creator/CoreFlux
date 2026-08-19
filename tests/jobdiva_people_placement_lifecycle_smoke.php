<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/jobdiva/assignment_identity.php';

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    if ($ok) {
        $pass++;
        echo "  ok - {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL - {$label}\n";
};

echo "JobDiva People and placement lifecycle smoke\n";
echo "============================================\n";

$status = static fn(string $raw, ?string $end = null): string =>
    (string) jobdivaAssignmentCanonicalPlacementStatus($raw, $end, '2026-08-19')['status'];

$assert('active Start remains active', $status('Active', '2026-12-31') === 'active');
$assert('completed Start becomes ended', $status('Assignment Completed', '2026-12-31') === 'ended');
$assert('terminated Start becomes ended', $status('Terminated', null) === 'ended');
$assert('inactive Start becomes ended rather than cancelled', $status('Inactive', null) === 'ended');
$assert('cancelled Start becomes cancelled', $status('Canceled Start', null) === 'cancelled');
$assert('future pending Start becomes pending_start', $status('Offer Accepted', '2026-12-31') === 'pending_start');
$assert('paused Start becomes on_hold', $status('On Hold', '2026-12-31') === 'on_hold');
$assert('past end date overrides an otherwise active status', $status('Active', '2026-08-18') === 'ended');
$assert('unknown current source status remains active', $status('Custom Current', '2026-12-31') === 'active');

$alignment = (string) file_get_contents($root . '/core/jobdiva/mapping_alignment.php');
$api = (string) file_get_contents($root . '/api/admin/integrations/jobdiva_mapping_alignment.php');
$sync = (string) file_get_contents($root . '/core/jobdiva/sync_placements.php');
$directory = (string) file_get_contents($root . '/modules/people/ui/Directory.jsx');
$settings = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');

$assert('repair orders People retirement after placement lifecycle correction',
    strpos($alignment, "\$steps['stale_active_placements']")
        < strpos($alignment, "\$steps['source_people_lifecycle']"));
$assert('People repair is limited to source-owned records',
    str_contains($alignment, "p.source = 'jobdiva'")
        && str_contains($alignment, "p.external_id LIKE 'jd:%'")
        && !str_contains($alignment, "OR pm.id IS NOT NULL"));
$assert('candidate-only People are preserved until they have placement history',
    str_contains($alignment, 'FROM placements historical_source')
        && str_contains($alignment, 'historical_source.person_id = p.id'));
$assert('People with any live placement are preserved',
    str_contains($alignment, "live.status IN ('draft', 'pending_start', 'active', 'on_hold')")
        && str_contains($alignment, 'live.end_date >= :today'));
$assert('repair inactivates rather than deletes historical People',
    str_contains($alignment, "SET p.status = 'inactive'")
        && !str_contains($alignment, 'mapping_alignment_repair_source_people_lifecycle_delete'));
$assert('later JobDiva Starts reactivate source-retired People',
    str_contains($sync, "SET status = 'active', updated_at = NOW()")
        && str_contains($sync, "AND source = 'jobdiva'")
        && str_contains($sync, "AND status = 'inactive'"));
$assert('People directory defaults to current records but retains All statuses',
    str_contains($directory, "const [status, setStatus] = useState('active');")
        && str_contains($directory, "s === '' ? 'All statuses'"));
$assert('dedicated People lifecycle API is wired',
    str_contains($api, "\$action === 'repair_stale_people'")
        && str_contains($api, 'jobdivaMappingRepairSourcePeopleLifecycle('));
$assert('operator UI exposes preview and apply actions',
    str_contains($settings, 'jobdiva-mapping-alignment-preview-stale-people')
        && str_contains($settings, 'jobdiva-mapping-alignment-repair-stale-people'));

foreach ([
    $root . '/core/jobdiva/assignment_identity.php',
    $root . '/core/jobdiva/mapping_alignment.php',
    $root . '/core/jobdiva/sync.php',
    $root . '/core/jobdiva/sync_placements.php',
    $root . '/api/admin/integrations/jobdiva_mapping_alignment.php',
] as $file) {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
    $assert('php -l ' . basename($file), $rc === 0);
}

echo "\n{$pass} passed / {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
