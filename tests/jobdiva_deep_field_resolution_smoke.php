<?php
/**
 * Smoke — JobDiva deep field resolution across the joined records.
 *
 * Operator complaint:
 *   "we're still not getting the full JobDiva payload to join data
 *    for the job, the person, the assignment (placement)."
 *
 * Root cause caught in the spec re-audit:
 *   jobdivaSyncEnrichRelatedEntities() DOES fetch the joined detail
 *   records (`_jd_job`, `_jd_candidate`, `_jd_customer`, `_jd_contact`,
 *   `_jd_start`) and grafts them onto each placement row. But every
 *   downstream pluck used jobdivaPluckField(), which is SHALLOW —
 *   it only looks at top-level keys, never into the enriched nests.
 *   So the enrichment fetched data the syncer then ignored. Operators
 *   kept seeing empty person fields, blank end-client names, and
 *   "JobDiva Placement #N" titles even though the API had served the
 *   real values.
 *
 * Fix shipped:
 *   - New jobdivaPluckFieldDeep() — falls through shallow first, then
 *     walks `_jd_candidate`, `_jd_job`, `_jd_customer`, `_jd_contact`,
 *     `_jd_start`, plus legacy nest keys (`job`, `Job`, `jobInfo`, etc.).
 *   - jobdivaPlacementsAutoCreatePerson() — uses deep for
 *     first_name/last_name/email/phone so person data populates from
 *     the enriched candidate detail.
 *   - jobdivaSyncUpsertPlacement() — uses deep for title, dates,
 *     end_client_name, status, engagement_type, worksite_state/country,
 *     remote_policy, notes, approver name/email, jobdiva_job_id,
 *     recruiter name/email, account_manager name/email, due_date,
 *     actual_end_date.
 *   - End-client name lookup (customerId / customerName / name) uses deep,
 *     adding `name` to the candidate list so the _jd_customer record's
 *     bare 'name' field is found.
 *
 * This smoke EXECUTES the deep pluck against synthetic fixtures (no
 * DB, no HTTP) to prove the behaviour, and grep-asserts the wire-up.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/jobdiva/sync.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. jobdivaPluckFieldDeep — behavioural unit tests\n";

// 1a: shallow hit short-circuits — no nested walk needed
$shallowHit = ['firstName' => 'Andrew', '_jd_candidate' => ['firstName' => 'OVERRIDDEN']];
$a('shallow value wins over nested when both present (no clobbering)',
    jobdivaPluckFieldDeep($shallowHit, ['firstName']) === 'Andrew');

// 1b: shallow MISS → _jd_candidate hit
$candidateOnly = [
    'placementId' => 999,
    '_jd_candidate' => [
        'firstName' => 'Andrew',
        'lastName'  => 'Lee',
        'email'     => 'andrew@example.com',
    ],
];
$a('_jd_candidate.firstName resolved when top-level absent',
    jobdivaPluckFieldDeep($candidateOnly, ['firstName', 'first_name']) === 'Andrew');
$a('_jd_candidate.lastName resolved',
    jobdivaPluckFieldDeep($candidateOnly, ['lastName']) === 'Lee');
$a('_jd_candidate.email resolved',
    jobdivaPluckFieldDeep($candidateOnly, ['email', 'emailAddress']) === 'andrew@example.com');

// 1c: _jd_job for title
$jobOnly = [
    'placementId' => 999,
    '_jd_job' => ['title' => 'Service Desk Analyst', 'department' => 'IT'],
];
$a('_jd_job.title resolved for placement title chain',
    jobdivaPluckFieldDeep($jobOnly, ['jobTitle', 'title', 'positionTitle']) === 'Service Desk Analyst');
$a('_jd_job.department resolved',
    jobdivaPluckFieldDeep($jobOnly, ['department', 'dept']) === 'IT');

// 1d: _jd_customer for end-client name
$customerOnly = [
    'placementId' => 999,
    '_jd_customer' => ['name' => 'Public Storage'],
];
$a('_jd_customer.name resolved via candidate list incl. "name"',
    jobdivaPluckFieldDeep($customerOnly, ['customerName', 'clientName', 'name']) === 'Public Storage');

// 1e: _jd_contact for hiring/approver
$contactOnly = [
    'placementId' => 999,
    '_jd_contact' => ['fullName' => 'Jane Manager', 'email' => 'jane@client.example.com'],
];
$a('_jd_contact.fullName resolved for approver name chain',
    jobdivaPluckFieldDeep($contactOnly, ['approverName', 'clientApprover', 'fullName']) === 'Jane Manager');
$a('_jd_contact.email resolved for approver email chain',
    jobdivaPluckFieldDeep($contactOnly, ['approverEmail', 'email']) === 'jane@client.example.com');

// 1f: _jd_start for rate fields (legacy nest still works)
$startOnly = [
    'placementId' => 999,
    '_jd_start' => ['payRate' => '45.00', 'finalBillRate' => '85.50'],
];
$a('_jd_start.payRate resolved',
    jobdivaPluckFieldDeep($startOnly, ['payRate', 'pay_rate']) === '45.00');

// 1g: full joined record — Andrew Lee / Service Desk Analyst / Public Storage
$joined = [
    'placementId' => 27857851,
    'startDate'   => '2026-05-22',
    '_jd_candidate' => ['firstName' => 'Andrew', 'lastName' => 'Lee', 'email' => 'andrew@x.com'],
    '_jd_job'       => ['title' => 'Service Desk Analyst'],
    '_jd_customer'  => ['name' => 'Public Storage'],
    '_jd_contact'   => ['fullName' => 'Jane Manager', 'email' => 'jane@client.com'],
];
$a('joined record: firstName',  jobdivaPluckFieldDeep($joined, ['firstName']) === 'Andrew');
$a('joined record: title',      jobdivaPluckFieldDeep($joined, ['jobTitle', 'title']) === 'Service Desk Analyst');
$a('joined record: customer',   jobdivaPluckFieldDeep($joined, ['customerName', 'name']) === 'Public Storage');
$a('joined record: contact',    jobdivaPluckFieldDeep($joined, ['approverName', 'fullName']) === 'Jane Manager');

// 1h: ordering — shallow always preferred over enriched
$bothPresent = ['title' => 'Top Level', '_jd_job' => ['title' => 'Nested']];
$a('shallow value preferred when both shallow + enriched present',
    jobdivaPluckFieldDeep($bothPresent, ['title']) === 'Top Level');

// 1i: legacy nest keys (`job`, `Job`, `jobInfo`) — V2 searchStart variant
$legacyNest = ['placementId' => 1, 'jobInfo' => ['jobTitle' => 'Legacy Title']];
$a('legacy `jobInfo` nest still walked',
    jobdivaPluckFieldDeep($legacyNest, ['jobTitle']) === 'Legacy Title');

// 1j: empty / no match
$emptyDoc = ['placementId' => 1];
$a('returns empty string when no candidate hits anywhere',
    jobdivaPluckFieldDeep($emptyDoc, ['unrelatedField']) === '');

echo "\n2. Source-level wire-up — deep pluck is actually consumed downstream\n";

$sync  = (string) file_get_contents('/app/core/jobdiva/sync.php');
$splp  = (string) file_get_contents('/app/core/jobdiva/sync_placements.php');

$a('sync_placements.php uses deep pluck for first_name',
    str_contains($splp, "jobdivaPluckFieldDeep(\$jd, [\n            'candidateFirstName'"));
$a('sync_placements.php uses deep pluck for last_name',
    str_contains($splp, "jobdivaPluckFieldDeep(\$jd, [\n            'candidateLastName'"));
$a('sync_placements.php uses deep pluck for email_primary',
    str_contains($splp, "jobdivaPluckFieldDeep(\$jd, [\n            'candidateEmail'"));
$a('sync_placements.php uses deep pluck for phone_primary',
    str_contains($splp, "jobdivaPluckFieldDeep(\$jd, [\n            'candidatePhone'"));

$a('sync.php placement title fallback uses deep pluck',
    str_contains($sync, "return jobdivaPluckFieldDeep(\$jd, [\n                'jobTitle'"));
$a('sync.php placement start_date fallback uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, ['startDate'"));
$a('sync.php placement end_client_name fallback uses deep pluck (with `name` candidate)',
    str_contains($sync, "// _jd_customer record stores the name as 'name'")
    && str_contains($sync, "'customerName', 'customer name', 'name',"));
$a('sync.php placement status fallback uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, ['status'"));
$a('sync.php placement engagement_type uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'engagementType'"));
$a('sync.php placement worksite_state uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'worksiteState'"));
$a('sync.php placement approver_name uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'approverName'"));
$a('sync.php placement approver_email uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'approverEmail'"));
$a('sync.php placement recruiter_name uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'recruiterName'"));
$a('sync.php placement account_manager_name uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, [\n            'accountManager'"));
$a('sync.php placement jobdiva_job_id uses deep pluck',
    str_contains($sync, "jobdivaPluckFieldDeep(\$jd, ['jobId'"));
$a('sync.php customer-name resolution at placement upsert uses deep pluck',
    str_contains($sync, '$customerName  = jobdivaPluckFieldDeep($jd, ['));

echo "\n3. Enrichment scaffolding stays in place\n";
$a('jobdivaSyncEnrichRelatedEntities still injects _jd_job / _jd_candidate / _jd_customer / _jd_contact',
    str_contains($sync, "'inject'   => '_jd_job'")
    && str_contains($sync, "'inject'   => '_jd_candidate'")
    && str_contains($sync, "'inject'   => '_jd_customer'")
    && str_contains($sync, "'inject'   => '_jd_contact'"));
$a('jobdivaSyncPlacements still calls the enricher BEFORE the upsert loop',
    // Anchor on the unique enrich-with-opts call that lives only inside
    // jobdivaSyncPlacements, then on the foreach two lines below it.
    (function () use ($sync) {
        $a = strpos($sync, "jobdivaSyncEnrichRelatedEntities(\$tid, \$items, \$userId, [\n        'enrich_start'");
        if ($a === false) return false;
        $b = strpos($sync, 'foreach ($items as $jd) {', $a);
        return $b !== false;
    })());

echo "\n4. PHP syntax\n";
foreach ([
    '/app/core/jobdiva/sync.php',
    '/app/core/jobdiva/sync_placements.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "JobDiva deep-field smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
