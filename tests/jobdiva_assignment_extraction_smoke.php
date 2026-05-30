<?php
/**
 * jobdiva_assignment_extraction_smoke.php
 *
 * Verifies that JobDiva BI placement payloads expose assignment-level
 * fields (payRate, billRate, etc.) as mappable source paths under
 * `entity_type=assignment`, EVEN when the optional `_jd_start`
 * enrichment endpoint never responded.
 *
 * Regression guard for the operator-reported issue:
 *   "I see a few fields called pay, however not the one from
 *    assignment record."
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/jobdiva/sync.php';

function _fail(string $msg): void { fwrite(STDERR, "❌ $msg\n"); exit(1); }
function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// -----------------------------------------------------------------------------
// CASE 1 — Flat snake_case BI payload (no nested _jd_start). Assignment fields
// should land under `assignment` with the prefix stripped.
// -----------------------------------------------------------------------------
$payload = [
    'id'                  => 12345,
    'placement_id'        => 12345,
    'candidate_id'        => 999,
    'candidate_first_name'=> 'Jane',
    'job_id'              => 4242,
    'job_title'           => 'Senior Engineer',
    'customer_id'         => 7,
    'customer_name'       => 'Acme Corp',
    // assignment-level fields prefixed `start_` in BI feed
    'start_pay_rate'      => 75.00,
    'start_bill_rate'     => 150.00,
    'start_markup'        => 2.0,
    'start_overtime_rate' => 112.50,
    'start_status'        => 'active',
    'start_date'          => '2026-01-01',
];

$subs = jobdivaExtractJoinedSubPayloads($payload);
$asn  = $subs['assignment'] ?? [];

assert(($asn['pay_rate'] ?? null) === 75.00,        'snake_case start_pay_rate → assignment.pay_rate');
assert(($asn['bill_rate'] ?? null) === 150.00,      'snake_case start_bill_rate → assignment.bill_rate');
assert(($asn['markup'] ?? null) === 2.0,            'snake_case start_markup → assignment.markup');
assert(($asn['overtime_rate'] ?? null) === 112.50,  'snake_case start_overtime_rate → assignment.overtime_rate');
assert(($asn['status'] ?? null) === 'active',       'snake_case start_status → assignment.status');
assert(($asn['date'] ?? null) === '2026-01-01',     'snake_case start_date → assignment.date');
_ok('CASE 1 — snake_case start_* fields land in assignment bucket');

// -----------------------------------------------------------------------------
// CASE 2 — Flat camelCase BI payload (alt JobDiva BI export shape).
// -----------------------------------------------------------------------------
$payload = [
    'id'              => 999,
    'candidateId'     => 1,
    'jobId'           => 2,
    'startPayRate'    => 60,
    'startBillRate'   => 130,
    'startMarkup'     => 2.1666,
    'startStatus'     => 'pending',
    'assignmentNotes' => 'Extra notes from BI feed',
];

$subs = jobdivaExtractJoinedSubPayloads($payload);
$asn  = $subs['assignment'] ?? [];

assert(($asn['payRate'] ?? null) === 60,            'camelCase startPayRate → assignment.payRate');
assert(($asn['billRate'] ?? null) === 130,          'camelCase startBillRate → assignment.billRate');
assert(($asn['markup'] ?? null) === 2.1666,         'camelCase startMarkup → assignment.markup');
assert(($asn['status'] ?? null) === 'pending',      'camelCase startStatus → assignment.status');
assert(($asn['notes'] ?? null) === 'Extra notes from BI feed', 'camelCase assignmentNotes → assignment.notes');
_ok('CASE 2 — camelCase start*/assignment* fields land in assignment bucket');

// -----------------------------------------------------------------------------
// CASE 3 — _jd_start nested takes precedence over flat (when both present).
// -----------------------------------------------------------------------------
$payload = [
    'id'             => 1,
    'start_pay_rate' => 50,           // flat
    '_jd_start'      => ['pay_rate' => 80, 'pay_freq' => 'hourly'],  // nested — should win
];
$subs = jobdivaExtractJoinedSubPayloads($payload);
$asn  = $subs['assignment'] ?? [];
assert(($asn['pay_rate'] ?? null) === 80,           '_jd_start nested wins over flat start_*');
assert(($asn['pay_freq'] ?? null) === 'hourly',     '_jd_start nested-only fields still surface');
_ok('CASE 3 — _jd_start nested takes precedence over flat start_*');

// -----------------------------------------------------------------------------
// CASE 4 — placement-level fields (no joined prefix) must NOT leak into
// the assignment bucket. They stay at the top-level placement record.
// -----------------------------------------------------------------------------
$payload = [
    'id'             => 5,
    'placement_id'   => 5,
    'pay_rate'       => 999,    // top-level placement pay_rate — must NOT go to assignment
    'bill_rate'      => 1999,
    'status'         => 'active',
    'start_pay_rate' => 88,     // this is the assignment-level one
];
$subs = jobdivaExtractJoinedSubPayloads($payload);
$asn  = $subs['assignment'] ?? [];
assert(($asn['pay_rate'] ?? null) === 88,           'top-level pay_rate stays out of assignment; start_pay_rate=88 wins');
// `pay_rate` (placement-level) does NOT match any prefix and therefore
// is NOT extracted into any sub-bucket — that's correct.
assert(!array_key_exists('rate', $asn),             'top-level pay_rate not re-stripped into assignment');
_ok('CASE 4 — placement-level pay_rate does not leak into assignment bucket');

// -----------------------------------------------------------------------------
// CASE 5 — every documented "surface everything" assignment field shows up.
// -----------------------------------------------------------------------------
$payload = [
    'id' => 7,
    'start_pay_rate'           => 1,
    'start_bill_rate'          => 2,
    'start_markup'             => 3,
    'start_overtime_rate'      => 4,
    'start_doubletime_rate'    => 5,
    'start_salary'             => 6,
    'start_salary_frequency'   => 'annual',
    'start_status'             => 'active',
    'start_date'               => '2026-01-01',
    'start_end_date'           => '2026-12-31',
    'start_original_start_date'=> '2025-06-01',
    'start_pay_cycle'          => 'weekly',
    'start_vms_fee'            => 0.05,
    'start_division'           => 'East',
    'start_pay_basis'          => 'hourly',
];
$subs = jobdivaExtractJoinedSubPayloads($payload);
$asn  = $subs['assignment'] ?? [];

$mustHave = [
    'pay_rate', 'bill_rate', 'markup', 'overtime_rate', 'doubletime_rate',
    'salary', 'salary_frequency', 'status', 'date', 'end_date',
    'original_start_date', 'pay_cycle', 'vms_fee', 'division', 'pay_basis',
];
foreach ($mustHave as $f) {
    assert(array_key_exists($f, $asn), "CASE 5 — assignment.$f must be present");
}
_ok('CASE 5 — every known assignment-level field surfaces (' . count($mustHave) . ' fields)');

// -----------------------------------------------------------------------------
// CASE 6 — JobDiva V2 BI uses SPACE-separated keys. The operator-reported
// payload showed `candidate id`, `job id`, `candidate name`, `candidate email`
// etc. with literal spaces. We must extract them into the matching entity
// bucket — otherwise the operator only sees `placement.candidate_id` (the
// indexer-normalised flat path) and NOT the joined-entity fields.
// -----------------------------------------------------------------------------
$payload = [
    'id'                  => 52147399,
    'extRejectedBy'       => 'Mike',
    // Space-separated joined-entity flat keys (the actual JobDiva BI shape)
    'job id'              => 4242,
    'job title'           => 'Senior Engineer',
    'candidate id'        => 999,
    'candidate first name'=> 'Jane',
    'candidate last name' => 'Doe',
    'candidate email'     => 'jane@example.com',
    'customer id'         => 7,
    'customer name'       => 'Acme Corp',
    'customer address1'   => '123 Main',
    // Assignment-level fields prefixed `start ` with literal space (the
    // actual JobDiva V2 BI shape for pay-rate-class fields):
    'start pay rate'      => 75.00,
    'start bill rate'     => 150.00,
    'start markup'        => 2.0,
    'start overtime rate' => 112.50,
    'start status'        => 'active',
    'start date'          => '2026-01-01',
];

$subs = jobdivaExtractJoinedSubPayloads($payload);

// person bucket
$person = $subs['person'] ?? [];
assert(($person['id'] ?? null) === 999,                    'space-keyed `candidate id` → person.id');
assert(($person['first_name'] ?? null) === 'Jane',         'space-keyed `candidate first name` → person.first_name');
assert(($person['last_name'] ?? null) === 'Doe',           'space-keyed `candidate last name` → person.last_name');
assert(($person['email'] ?? null) === 'jane@example.com',  'space-keyed `candidate email` → person.email');

// job bucket
$job = $subs['job'] ?? [];
assert(($job['id'] ?? null) === 4242,                      'space-keyed `job id` → job.id');
assert(($job['title'] ?? null) === 'Senior Engineer',      'space-keyed `job title` → job.title');

// customer bucket
$cust = $subs['jobdiva_customer'] ?? [];
assert(($cust['id'] ?? null) === 7,                        'space-keyed `customer id` → jobdiva_customer.id');
assert(($cust['name'] ?? null) === 'Acme Corp',            'space-keyed `customer name` → jobdiva_customer.name');
assert(($cust['address1'] ?? null) === '123 Main',         'space-keyed `customer address1` → jobdiva_customer.address1');

// assignment bucket (THE operator's primary pain point)
$asn = $subs['assignment'] ?? [];
assert(($asn['pay_rate'] ?? null) === 75.00,               'space-keyed `start pay rate` → assignment.pay_rate');
assert(($asn['bill_rate'] ?? null) === 150.00,             'space-keyed `start bill rate` → assignment.bill_rate');
assert(($asn['markup'] ?? null) === 2.0,                   'space-keyed `start markup` → assignment.markup');
assert(($asn['overtime_rate'] ?? null) === 112.50,         'space-keyed `start overtime rate` → assignment.overtime_rate');
assert(($asn['status'] ?? null) === 'active',              'space-keyed `start status` → assignment.status');
_ok('CASE 6 — JobDiva V2 BI space-keyed flat fields route to joined-entity buckets');

echo "\n🎯 jobdiva_assignment_extraction_smoke — ALL PASS\n";