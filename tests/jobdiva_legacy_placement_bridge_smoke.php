<?php
/**
 * Smoke: legacy spreadsheet placements bridge to exact JobDiva Starts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/jobdiva/mapping_alignment.php';

$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $condition) use (&$pass, &$fail): void {
    if ($condition) {
        $pass++;
        echo "  ok - {$label}\n";
        return;
    }
    $fail++;
    echo "  FAIL - {$label}\n";
};

$row = static fn(
    int $id,
    int $personId,
    string $externalId,
    string $startDate,
    string $firstName,
    string $lastName,
    string $client = 'TCS'
): array => [
    'id' => $id,
    'person_id' => $personId,
    'external_id' => $externalId,
    'status' => 'active',
    'start_date' => $startDate,
    'end_client_name' => $client,
    'end_client_company_id' => $client === 'TCS' ? 83 : 0,
    'person_first_name' => $firstName,
    'person_last_name' => $lastName,
    'person_email' => '',
];

echo "JobDiva legacy placement bridge smoke\n";
echo "=====================================\n";

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(771, 293, 'jd:57857901', '2026-09-02', 'Nikhil Reddy', 'Chityala'),
    $row(847, 293, 'placements-xlsx-TR-69', '2026-09-02', 'Nikhil Reddy', 'Chityala', 'Tata Consultancy Services'),
], [771 => '57857901']);
$assert('same person and exact start bridge despite a client-label mismatch',
    count($pairs) === 1 && (int) $pairs[0]['legacy_id'] === 847);

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(865, 359, 'jd:57874920', '2026-08-24', 'Johny', 'Shaik'),
    $row(844, 345, 'placements-xlsx-TR-65', '2026-08-24', 'Johny', 'Shaik'),
], [865 => '57874920']);
$assert('exact name, client, and start bridge duplicate person rows',
    count($pairs) === 1 && (int) $pairs[0]['canonical_id'] === 865);

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(866, 360, 'jd:57884918', '2026-09-01', 'Indralekha', 'Almala'),
    $row(850, 346, 'placements-xlsx-TR-72', '2026-09-14', 'Indralekha', 'Almala'),
], [866 => '57884918']);
$assert('unique exact-name/client match tolerates a short source date drift', count($pairs) === 1);

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(900, 400, 'jd:60000001', '2026-09-01', 'Alex', 'Smith'),
    $row(901, 400, 'placements-xlsx-A', '2026-09-01', 'Alex', 'Smith'),
    $row(902, 400, 'placements-xlsx-B', '2026-09-01', 'Alex', 'Smith'),
], [900 => '60000001']);
$assert('ambiguous legacy candidates are never auto-bridged', count($pairs) === 0);

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(910, 410, 'jd:60000002', '2026-09-01', 'Taylor', 'Jones', 'Client A'),
    $row(911, 410, 'placements-xlsx-C', '2026-09-20', 'Taylor', 'Jones', 'Client B'),
], [910 => '60000002']);
$assert('date drift without a matching client is not bridged', count($pairs) === 0);

$pairs = _jobdivaMappingLegacyPlacementBridgePairs([
    $row(920, 420, 'jd:60000003', '2026-09-01', 'Jordan', 'Lee', 'Client A'),
    $row(921, 421, 'placements-xlsx-D', '2026-09-01', 'Jordan', 'Lee', 'Client B'),
], [920 => '60000003']);
$assert('parallel people with the same name require compatible client evidence', count($pairs) === 0);

$keeper = _jobdivaMappingChooseDuplicatePlacementKeeper([
    'canonical_external_id' => 'jd:60000004',
    'duplicate_basis' => 'jobdiva_start_legacy_import',
    'rows' => [
        $row(930, 430, 'jd:60000004', '2026-09-01', 'Morgan', 'Patel'),
        $row(931, 430, 'placements-xlsx-E', '2026-09-01', 'Morgan', 'Patel'),
    ],
]);
$assert('legacy spreadsheet row wins when neither duplicate owns downstream activity', $keeper === 931);

$sync = (string) file_get_contents(dirname(__DIR__) . '/core/jobdiva/sync.php');
$peopleSync = (string) file_get_contents(dirname(__DIR__) . '/core/jobdiva/sync_placements.php');
$assert('future projections adopt a legacy placement before inserting',
    str_contains($sync, 'jobdivaFindLegacyPlacementAdoptionCandidate(')
    && str_contains($sync, '$legacyPlacementId = jobdivaFindLegacyPlacementAdoptionCandidate('));
$assert('future projections adopt a legacy person before auto-create',
    str_contains($peopleSync, 'jobdivaLegacyImportPersonCandidate(')
    && str_contains($peopleSync, '$legacyPersonId = jobdivaLegacyImportPersonCandidate('));

echo "\n{$pass} passed / {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
