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

$writeStatus = static fn(
    string $raw,
    ?string $start,
    ?string $end,
    ?string $current
): array => jobdivaAssignmentPlacementStatusForWrite(
    $raw,
    $start,
    $end,
    $current,
    '2026-08-19'
);
$assert('past-start active placement cannot roll backward to pending_start',
    ($writeStatus('Offer Accepted', '2026-08-01', '2026-12-31', 'active')['status'] ?? '') === 'active'
    && ($writeStatus('Offer Accepted', '2026-08-01', '2026-12-31', 'active')['reason'] ?? '') === 'preserve_started_active');
$assert('future active placement can return to pending_start when the source says it has not started',
    ($writeStatus('Offer Accepted', '2026-09-01', '2026-12-31', 'active')['status'] ?? '') === 'pending_start');
$assert('new past-start placement still follows pending source evidence',
    ($writeStatus('Offer Accepted', '2026-08-01', '2026-12-31', null)['status'] ?? '') === 'pending_start');
$assert('terminal and hold evidence still change an established active placement',
    ($writeStatus('Completed', '2026-08-01', '2026-12-31', 'active')['status'] ?? '') === 'ended'
    && ($writeStatus('Cancelled', '2026-08-01', '2026-12-31', 'active')['status'] ?? '') === 'cancelled'
    && ($writeStatus('On Hold', '2026-08-01', '2026-12-31', 'active')['status'] ?? '') === 'on_hold');

$alignment = (string) file_get_contents($root . '/core/jobdiva/mapping_alignment.php');
$api = (string) file_get_contents($root . '/api/admin/integrations/jobdiva_mapping_alignment.php');
$sync = (string) file_get_contents($root . '/core/jobdiva/sync_placements.php');
$placementSync = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$directory = (string) file_get_contents($root . '/modules/people/ui/Directory.jsx');
$settings = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');
$review = (string) file_get_contents($root . '/core/jobdiva/people_lifecycle_review.php');
$reviewUi = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaPeopleLifecycleReview.jsx');

$assert('repair discovers People review after placement lifecycle correction',
    strpos($alignment, "\$steps['stale_active_placements']")
        < strpos($alignment, "\$steps['source_people_lifecycle']"));
$assert('People review requires exact original-workspace candidate mappings',
    str_contains($review, "source_system='jobdiva' AND internal_entity_type='person'")
        && str_contains($review, 'jobdivaPeopleLifecycleScope')
        && str_contains($review, 'competing_links'));
$assert('candidate-only People are preserved until they have placement history',
    str_contains($review, '||!$history)continue;'));
$assert('People with any live placement are preserved',
    str_contains($review, "['draft','pending_start','active','on_hold']")&&str_contains($review, 'if($live)continue;'));
$assert('People availability requires selected decisions and a required receipt',
    str_contains($review, 'APPLY_REVIEWED_PEOPLE_AVAILABILITY')
        && str_contains($review, 'jobdiva.people_availability_applied')
        && str_contains($alignment, 'jobdivaMappingRepairSourcePeopleLifecycle($tenantId, $userId, $limit, true)'));
$assert('later JobDiva Starts hold retired Core people for reviewed reactivation',
    str_contains($sync, 'Review and reactivate the linked Core person')
    && !str_contains($sync, "SET status = 'active', deleted_at = NULL, updated_at = NOW()"));
$assert('person re-observation preserves identity and refreshes the same mapping',
    !str_contains($sync, "mappingDelete(\$tid, 'jobdiva', 'person', \$candidateExtId)")
        && str_contains($sync, "jobdivaPersonExternalIdConflicts(\$mappedExternalId, \$candidateExtId)")
        && str_contains($sync, "mappingUpsert(\$tid, 'jobdiva', 'person', (string)\$mapping['external_id'], \$mappedPersonId"));
$assert('later current Starts restore source-owned archived placements while Core lifecycle remains held',
    str_contains($placementSync, "if (!array_intersect(['status','end_date','actual_end_date'],\$overrides) && in_array(\$status, ['active', 'pending_start', 'on_hold'], true))")
        && str_contains($placementSync, "\$assignments[] = 'deleted_at = NULL';")
        && str_contains($placementSync, "'person_id' => 0")
        && str_contains($placementSync, "'placements_restored' => 0"));
$assert('placement refresh protects an already-started active assignment from pending rollback',
    str_contains($placementSync, 'jobdivaAssignmentPlacementStatusForWrite(')
        && str_contains($placementSync, '$currentStatus'));
$assert('alignment repair uses the same transition-aware lifecycle rule',
    str_contains($alignment, 'jobdivaAssignmentPlacementStatusForWrite(')
        && str_contains($alignment, "(string) (\$row['start_date'] ?? '')")
        && str_contains($alignment, "(string) (\$row['status'] ?? '')"));
$assert('People directory defaults to current records but retains All statuses',
    str_contains($directory, "const [status, setStatus] = useState('active');")
        && str_contains($directory, "'': 'All statuses'")
        && str_contains($directory, '{STATUS_LABELS[s]}'));
$assert('dedicated People lifecycle API is wired',
    str_contains($api, "\$action === 'repair_stale_people'")
        && str_contains($api, 'jobdivaPeopleLifecycleApply(')&&str_contains($api,"'people.terminate'"));
$assert('operator UI opens explicit decisions instead of bulk automatic retirement',
    str_contains($settings, 'jobdiva-mapping-alignment-preview-stale-people')
        && !str_contains($settings, 'jobdiva-mapping-alignment-repair-stale-people')
        && str_contains($reviewUi, 'Keep current')&&str_contains($reviewUi,'Retry original save'));

foreach ([
    $root . '/core/jobdiva/assignment_identity.php',
    $root . '/core/jobdiva/mapping_alignment.php',
    $root . '/core/jobdiva/people_lifecycle_review.php',
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
