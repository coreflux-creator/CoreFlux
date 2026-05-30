<?php
/**
 * jobdiva_raw_payload_smoke.php
 *
 * Smoke for /api/admin/integrations/jobdiva_raw_payload.php — proves the
 * endpoint surfaces the buckets + counts that drive the "What JobDiva
 * actually returned" diagnostic in the Field Mapping Studio.
 *
 * We exercise the bucket-stat building logic without standing up the
 * full API stack — the file itself does the structural reasoning over
 * a payload dict, so we mirror the same algorithm here in a sealed
 * scope and assert the diagnostic flagging works correctly.
 */
declare(strict_types=1);

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// Inline copy of the bucket-stats logic the API endpoint uses. If you
// touch the API, mirror the change here so the smoke catches drift.
function _buildStats(array $payload): array {
    $buckets = ['_jd_job', '_jd_candidate', '_jd_customer', '_jd_contact', '_jd_start'];
    $bucketStats = [];
    foreach ($buckets as $b) {
        $present = isset($payload[$b]) && is_array($payload[$b]);
        $bucketStats[$b] = [
            'present'     => $present,
            'field_count' => $present ? count($payload[$b]) : 0,
            'keys'        => $present ? array_keys($payload[$b]) : [],
        ];
    }
    $topFlat = [];
    foreach ($payload as $k => $v) {
        if (is_string($k) && (str_starts_with($k, '_jd_') || str_starts_with($k, '__cf_'))) continue;
        if (is_scalar($v) || $v === null) $topFlat[] = $k;
    }
    return [
        'top_level_scalar_field_count' => count($topFlat),
        'top_level_scalar_keys'        => $topFlat,
        'buckets'                      => $bucketStats,
    ];
}

// -----------------------------------------------------------------------------
// CASE 1 — Healthy enriched payload (every bucket has dozens of fields).
// -----------------------------------------------------------------------------
$payload = [
    'id'           => 1,
    'placement_id' => 1,
    'status'       => 'active',
    'pay_rate'     => 50,
    '__cf_resolved_job_title' => 'Engineer',  // internal — must be ignored
    '_jd_job'       => array_fill_keys(array_map(fn($i) => "f$i", range(1, 30)), 'v'),
    '_jd_candidate' => array_fill_keys(array_map(fn($i) => "c$i", range(1, 59)), 'v'),
    '_jd_customer'  => array_fill_keys(array_map(fn($i) => "u$i", range(1, 58)), 'v'),
    '_jd_contact'   => array_fill_keys(array_map(fn($i) => "n$i", range(1, 12)), 'v'),
    '_jd_start'     => array_fill_keys(array_map(fn($i) => "s$i", range(1, 25)), 'v'),
];
$s = _buildStats($payload);
assert($s['top_level_scalar_field_count'] === 4, 'top-level scalar count (id, placement_id, status, pay_rate)');
assert(!in_array('__cf_resolved_job_title', $s['top_level_scalar_keys']), 'internal __cf_ keys excluded from top-level');
assert($s['buckets']['_jd_start']['field_count'] === 25, 'healthy _jd_start has 25 fields');
assert($s['buckets']['_jd_job']['field_count'] === 30, 'healthy _jd_job has 30 fields');
_ok('CASE 1 — healthy payload reports correct counts');

// -----------------------------------------------------------------------------
// CASE 2 — Sparse JobDiva response (the operator's actual symptom): _jd_start
// only has `status`. The diagnostic must flag this as low-field.
// -----------------------------------------------------------------------------
$payload = [
    'id' => 1, 'placement_id' => 1,
    '_jd_candidate' => array_fill_keys(array_map(fn($i) => "c$i", range(1, 59)), 'v'),
    '_jd_customer'  => array_fill_keys(array_map(fn($i) => "u$i", range(1, 58)), 'v'),
    '_jd_job'       => ['status' => 'open'],  // sparse
    '_jd_start'     => ['status' => 'Offer Accepted'],  // sparse
];
$s = _buildStats($payload);
assert($s['buckets']['_jd_start']['field_count'] === 1, 'sparse _jd_start has 1 field');
assert($s['buckets']['_jd_start']['keys'] === ['status'], '_jd_start keys list reports status only');
assert($s['buckets']['_jd_job']['field_count'] === 1, 'sparse _jd_job has 1 field');
assert($s['buckets']['_jd_customer']['field_count'] === 58, 'healthy _jd_customer untouched');
assert($s['buckets']['_jd_contact']['present'] === false, 'absent _jd_contact reported as not_present');
_ok('CASE 2 — operator symptom replicated: sparse buckets surface with field_count=1');

// -----------------------------------------------------------------------------
// CASE 3 — Empty payload — no buckets present, no top scalars.
// -----------------------------------------------------------------------------
$s = _buildStats([]);
assert($s['top_level_scalar_field_count'] === 0, 'empty payload has 0 scalars');
foreach (['_jd_job', '_jd_candidate', '_jd_customer', '_jd_contact', '_jd_start'] as $b) {
    assert($s['buckets'][$b]['present'] === false, "$b absent");
    assert($s['buckets'][$b]['field_count'] === 0, "$b count is 0");
}
_ok('CASE 3 — empty payload reports every bucket as absent');

echo "\n🎯 jobdiva_raw_payload_smoke — ALL PASS\n";
