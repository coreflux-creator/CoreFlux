<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/jobdiva/assignment_identity.php';

$failures = 0;
$assert = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    echo ($ok ? "[PASS] " : "[FAIL] ") . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
        if ($detail !== '') echo "       {$detail}" . PHP_EOL;
    }
};

$assignment = [
    'id' => '56830791',
    'candidate id' => '12345',
    'job id' => '27857851',
    'start date' => '07/01/2026',
    'end date' => '12/09/2026',
    'startStatus' => 'Active',
];
$valid = jobdivaAssignmentValidate($assignment, '56830791');
$assert('assignment-shaped searchStart row is valid', !empty($valid['valid']));
$assert('generic id resolves only with assignment tuple', ($valid['assignment_id'] ?? '') === '56830791');

$candidate = [
    'id' => '56830791',
    'firstName' => 'Anson',
    'lastName' => 'Paul',
    'email' => 'anson@example.com',
];
$assert('candidate payload cannot acquire assignment identity', jobdivaAssignmentRowId($candidate) === '');
$assert('candidate payload cannot project as placement', empty(jobdivaAssignmentValidate($candidate, '56830791')['valid']));

$job = ['id' => '56830791', 'title' => 'Robotics Engineer/Developer', 'companyName' => 'TCS'];
$assert('job payload cannot acquire assignment identity', jobdivaAssignmentRowId($job) === '');
$assert('job payload cannot project as placement', empty(jobdivaAssignmentValidate($job, '56830791')['valid']));

$dedicatedMismatch = $assignment + ['startId' => '999'];
$assert(
    'mismatched Start ID is rejected even when other fields look valid',
    empty(jobdivaAssignmentValidate($dedicatedMismatch, '56830791')['valid'])
);

$marked = jobdivaAssignmentMarkVerified(
    ['startId' => '56830791'],
    '56830791',
    'employee_assignment_records:exact'
);
$assert('authoritative assignment endpoint permits sparse detail', !empty(jobdivaAssignmentValidate($marked, '56830791')['valid']));

$nested = [
    'candidate id' => '12345',
    '_jd_start' => jobdivaAssignmentMarkVerified(
        ['startId' => '56830791'],
        '56830791',
        'employee_assignment_records:exact'
    ),
];
$assert('verified nested assignment evidence is valid', !empty(jobdivaAssignmentValidate($nested, '56830791')['valid']));

$offerOnly = $assignment;
$offerOnly['startStatus'] = 'Offer';
$assert(
    'unaccepted offer cannot become a CoreFlux placement',
    empty(jobdivaAssignmentValidate($offerOnly, '56830791')['valid'])
);

$offerWithStaleAssignment = $offerOnly;
$offerWithStaleAssignment['_jd_start'] = [
    'startId' => '56830791',
    'candidate id' => '12345',
    'job id' => '27857851',
    'start date' => '07/01/2026',
    'startStatus' => 'Active',
];
$assert(
    'nested assignment enrichment cannot promote an unaccepted root offer',
    empty(jobdivaAssignmentValidate($offerWithStaleAssignment, '56830791')['valid'])
);

$offerAccepted = $assignment;
$offerAccepted['startStatus'] = 'Offer Accepted';
$assert(
    'accepted JobDiva Start remains eligible for pending or active placement',
    !empty(jobdivaAssignmentValidate($offerAccepted, '56830791')['valid'])
);

$cancelled = $assignment;
$cancelled['startStatus'] = 'Canceled Start';
$assert(
    'canceled JobDiva Start cannot become a CoreFlux placement',
    empty(jobdivaAssignmentValidate($cancelled, '56830791')['valid'])
);

$polluted = $assignment;
$polluted['_jd_start'] = [
    'id' => '55466185',
    'candidate id' => '99999',
    'job id' => '77777',
    'start date' => '06/08/2026',
    'startStatus' => 'Active',
];
$sanitised = jobdivaAssignmentSanitisePayload($polluted, '56830791');
$assert(
    'mismatched nested assignment enrichment is removed',
    !isset($sanitised['_jd_start'])
);

$projector = (string) file_get_contents($root . '/core/jobdiva/projector.php');
$sync = (string) file_get_contents($root . '/core/jobdiva/sync.php');
$discovery = (string) file_get_contents($root . '/core/jobdiva/sync_placements.php');
$alignment = (string) file_get_contents($root . '/core/jobdiva/mapping_alignment.php');
$webhook = (string) file_get_contents($root . '/api/jobdiva.php');
$settingsUi = (string) file_get_contents($root . '/dashboard/src/pages/JobDivaSettings.jsx');

$assert(
    'projector rejects unverified source before placement upsert',
    strpos($projector, 'jobdivaAssignmentValidate($payload, $externalId)')
        < strpos($projector, 'jobdivaSyncUpsertPlacement(')
);
$assert(
    'placement writer has a defense-in-depth assignment guard',
    str_contains($sync, 'JobDiva placement write refused:')
);
$assert(
    'discovery marks only validated searchStart rows',
    str_contains($discovery, "jobdivaAssignmentMarkVerified(\$row, \$assignmentId, 'searchStart:discovery')")
);
$assert(
    'assignment mirror never invents a missing Start ID',
    !str_contains($sync, "\$row['startId'] = \$startId")
        && str_contains($sync, "assignment_identity_rejections")
);
$assert(
    'repair verifies assignment source before canonical replay',
    strpos($alignment, "\$steps['assignment_sources']")
        < strpos($alignment, "\$steps['canonical_projection']")
);
$assert(
    'alignment report finds live placements whose stored root is not an assignment',
    str_contains($alignment, 'function _jobdivaMappingInvalidPlacementSources(')
        && str_contains($alignment, "'placement_non_assignment_source'")
);
$assert(
    'repair reports rejected pipeline rows separately from API failures',
    str_contains($alignment, "'non_assignments' => 0")
        && str_contains($settingsUi, 'rejected pipeline rows')
);
$assert(
    'webhook path also requires assignment identity',
    str_contains($webhook, 'jobdivaAssignmentValidate($record, $recordId)')
        && str_contains($webhook, 'jobdivaFetchExactAssignmentById($tid, $startId)')
);

exit($failures === 0 ? 0 : 1);
