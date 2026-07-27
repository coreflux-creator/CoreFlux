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

$storedActive = jobdivaAssignmentValidate($assignment, '56830791');
$decision = jobdivaAssignmentSourceDecision(
    ['status' => 'not_found', 'seen_ids' => []],
    $storedActive,
    '56830791',
    true
);
$assert(
    'inconclusive remote lookup trusts an existing source-backed Start',
    ($decision['action'] ?? '') === 'trusted_stored'
);

$decision = jobdivaAssignmentSourceDecision(
    ['status' => 'error', 'error' => 'HTTP 500'],
    $storedActive,
    '56830791',
    true
);
$assert(
    'remote API failure cannot delete a valid stored Start',
    ($decision['action'] ?? '') === 'trusted_stored'
);

$invalidStored = jobdivaAssignmentValidate($candidate, '56830791');
$decision = jobdivaAssignmentSourceDecision(
    ['status' => 'not_found', 'seen_ids' => []],
    $invalidStored,
    '56830791'
);
$assert(
    'not-found without assignment evidence requires review instead of deletion',
    ($decision['action'] ?? '') === 'review'
);

$decision = jobdivaAssignmentSourceDecision(
    ['status' => 'not_found', 'seen_ids' => []],
    $storedActive,
    '56830791',
    false
);
$assert(
    'stale stored assignment evidence cannot resurrect an archived placement',
    ($decision['action'] ?? '') === 'review'
);

$assert(
    'stored assignment evidence is current only when observed in the latest completed sync',
    jobdivaAssignmentObservedInLatestSync(
        $storedActive,
        '2026-07-26 10:00:00',
        '2026-07-26 10:02:00'
    )
        && !jobdivaAssignmentObservedInLatestSync(
            $storedActive,
            '2026-07-20 10:00:00',
            '2026-07-26 10:02:00'
        )
);

$cancelledValidation = jobdivaAssignmentValidate($cancelled, '56830791');
$decision = jobdivaAssignmentSourceDecision(
    [
        'status' => 'not_assignment',
        'seen_ids' => ['56830791'],
        'identity' => $cancelledValidation,
    ],
    $invalidStored,
    '56830791'
);
$assert(
    'exact echoed terminal Start can be archived',
    ($decision['action'] ?? '') === 'terminal'
);

$decision = jobdivaAssignmentSourceDecision(
    [
        'status' => 'not_assignment',
        'seen_ids' => ['999'],
        'identity' => $cancelledValidation,
    ],
    $invalidStored,
    '56830791'
);
$assert(
    'terminal state on a different Start ID cannot archive the placement',
    ($decision['action'] ?? '') === 'review'
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

$multiAssignment = $assignment;
$multiAssignment['assignment'] = [
    [
        'id' => '57219188',
        'candidate id' => '99999',
        'job id' => '77777',
        'start date' => '07/01/2026',
        'EMPLOYMENT_CATEGORY' => 'C2C',
    ],
    [
        'id' => '56830791',
        'candidate id' => (string) $assignment['candidate id'],
        'job id' => (string) $assignment['job id'],
        'start date' => (string) $assignment['start date'],
        'EMPLOYMENT_CATEGORY' => 'W2',
    ],
];
$sanitised = jobdivaAssignmentSanitisePayload($multiAssignment, '56830791');
$assert(
    'multi-row assignment facet keeps only the exact Start',
    isset($sanitised['assignment'])
        && count($sanitised['assignment']) === 1
        && (string) ($sanitised['assignment'][0]['id'] ?? '') === '56830791'
        && (string) ($sanitised['assignment'][0]['EMPLOYMENT_CATEGORY'] ?? '') === 'W2'
);

$conflictingAssignment = $assignment;
$conflictingAssignment['assignment'] = [
    'id' => '56830791',
    'startId' => '57219188',
    'candidate id' => (string) $assignment['candidate id'],
    'job id' => (string) $assignment['job id'],
    'start date' => (string) $assignment['start date'],
    'EMPLOYMENT_CATEGORY' => 'C2C',
];
$sanitised = jobdivaAssignmentSanitisePayload($conflictingAssignment, '56830791');
$assert(
    'one assignment object with conflicting identity aliases is removed',
    !isset($sanitised['assignment'])
);

$cached = $assignment;
$cached['_jd_job'] = ['id' => '77777', 'title' => 'Stale role'];
$cached['job'] = ['id' => '77777', 'title' => 'Stale role'];
$cached['_jd_candidate'] = ['id' => '99999', 'name' => 'Wrong person'];
$cached['assignment'] = [['id' => '57219188']];
$cached['__cf_resolved_job_title'] = 'Stale role';
$stripped = jobdivaAssignmentStripDerivedFacets($cached);
$assert(
    'verified-source merge strips every cached derived facet',
    !isset($stripped['_jd_job'])
        && !isset($stripped['job'])
        && !isset($stripped['_jd_candidate'])
        && !isset($stripped['assignment'])
        && !isset($stripped['__cf_resolved_job_title'])
        && (string) ($stripped['id'] ?? '') === '56830791'
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
    'projector sanitises assignment identity before canonicalization',
    strpos($projector, 'jobdivaAssignmentSanitisePayload($payload, $externalId)')
        < strpos($projector, 'jobdivaCanonicalPlacementPayload($payload, jobdivaExtractJoinedSubPayloads($payload))')
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
    'repair reports source reconciliation paths separately from API failures',
    str_contains($alignment, "'non_assignments' => 0")
        && str_contains($settingsUi, 'trusted source snapshots')
        && str_contains($settingsUi, 'review required')
);
$assert(
    'repair restores source-backed archived assignments and never treats not-found as deletion',
    str_contains($alignment, "'placements_restored' => 0")
        && str_contains($alignment, 'SET deleted_at = NULL')
        && str_contains($alignment, "if (\$action !== 'terminal')")
);
$assert(
    'source repair replaces cached snapshots with the verified Start',
    str_contains($alignment, 'jobdivaAssignmentStripDerivedFacets($stored)')
        && str_contains($alignment, '$payload = $exact[\'row\'];')
        && !str_contains($alignment, '$payload = array_replace($stored, $exact[\'row\']);')
);
$assert(
    'mirror reconstruction is rooted in the mapping Start ID',
    str_contains($sync, '?string $expectedStartId = null')
        && str_contains($sync, '$startId = $expectedStartId;')
        && str_contains($projector, 'jobdivaPlacementPayloadWithMirrors($tenantId, $payload, $joinStats, $externalId)')
);
$assert(
    'contact enrichment cannot mistake a customer id for a contact id',
    !preg_match(
        '/\\$contactId\\s*=\\s*jobdivaPluckField\\(\\$payload,\\s*\\[[^\\]]*customer id/is',
        $sync
    )
);
$assert(
    'force projection cannot replace a real title with a placeholder',
    str_contains($sync, '[jobdiva placement sync] existing title protection failed:')
        && str_contains($sync, '$title = $existingTitle;')
        && str_contains($sync, 'staffingJobFindBySource($tid, \'jobdiva\', $titleJobId)')
);
$assert(
    'webhook path also requires assignment identity',
    str_contains($webhook, 'jobdivaAssignmentValidate($record, $recordId)')
        && str_contains($webhook, 'jobdivaFetchExactAssignmentById($tid, $startId)')
);

exit($failures === 0 ? 0 : 1);
