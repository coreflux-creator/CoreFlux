<?php
/**
 * placement_universal_person_fields_smoke.php
 *
 * Regression guard for the operator-reported issue:
 *   "we're still not getting some of the details from people across to
 *    placements. why isn't it universal?"
 *
 * `placementHydratePersonFields()` must fan-out EVERY column on the
 * linked `people` row as `person_*` so adding a new column to `people`
 * (linkedin_url, secondary_email, custom fields, etc.) automatically
 * shows up on placement detail without per-field mapping.
 */
declare(strict_types=1);

require_once __DIR__ . '/../modules/placements/lib/placements.php';

function _ok(string $msg): void { fwrite(STDOUT, "✅ $msg\n"); }

// In-memory people table the loader callback queries via simple lookup.
$people = [
    42 => [
        'id'                => 42,
        'tenant_id'         => 7,
        'first_name'        => 'Jane',
        'last_name'         => 'Doe',
        'email_primary'     => 'jane@example.com',
        'phone_primary'     => '555-1212',
        'classification'    => 'W2',
        'work_auth_status'  => 'us_citizen',
        'work_auth_expiry'  => '2027-01-01',
        // Newer columns (NOT in the hand-picked SQL aliases) — these
        // must auto-surface as person_* without a code change here.
        'linkedin_url'      => 'https://linkedin.com/in/jane',
        'address_city'      => 'Austin',
        'custom_field_blob' => '{"vendor_id":99}',
        'deleted_at'        => null,
    ],
];
$loader = function (int $personId) use ($people) {
    return $people[$personId] ?? null;
};

// -----------------------------------------------------------------------------
// CASE 1 — Existing hand-picked aliases survive untouched.
// -----------------------------------------------------------------------------
$row = [
    'id' => 99, 'tenant_id' => 7,
    'person_id' => 42,
    'person_first_name'    => 'Jane',
    'person_last_name'     => 'Doe',
    'person_email_primary' => 'jane@example.com',
];
$hydrated = placementHydratePersonFields($row, $loader);
assert($hydrated['person_first_name'] === 'Jane', 'pre-aliased field preserved');
_ok('CASE 1 — hand-picked aliases survive untouched');

// -----------------------------------------------------------------------------
// CASE 2 — Universal fan-out: NEW person columns auto-surface as person_*.
// -----------------------------------------------------------------------------
assert(($hydrated['person_linkedin_url'] ?? null) === 'https://linkedin.com/in/jane',
    'new person.linkedin_url fans out as person_linkedin_url');
assert(($hydrated['person_address_city'] ?? null) === 'Austin',
    'new person.address_city fans out as person_address_city');
assert(($hydrated['person_custom_field_blob'] ?? null) === '{"vendor_id":99}',
    'arbitrary new column on people fans out');
assert(($hydrated['person_phone_primary'] ?? null) === '555-1212',
    'phone_primary still fans out via loader (covers the alias too)');
_ok('CASE 2 — new person columns auto-surface as person_*');

// -----------------------------------------------------------------------------
// CASE 3 — system columns (id, tenant_id, deleted_at) MUST NOT fan out.
// -----------------------------------------------------------------------------
assert($hydrated['person_id'] === 42, 'placement.person_id preserved (not overwritten by people.id fan-out)');
assert(!array_key_exists('person_tenant_id', $hydrated), 'people.tenant_id not fanned out');
assert(!array_key_exists('person_deleted_at', $hydrated), 'people.deleted_at not fanned out');
_ok('CASE 3 — system columns excluded from fan-out');

// -----------------------------------------------------------------------------
// CASE 4 — Draft placement (no person_id) returns unchanged.
// -----------------------------------------------------------------------------
$draftRow = ['id' => 100, 'tenant_id' => 7, 'person_id' => null];
$hydrated = placementHydratePersonFields($draftRow, $loader);
assert(count($hydrated) === 3, 'draft placement passes through untouched');
_ok('CASE 4 — draft placement (no person_id) passes through');

// -----------------------------------------------------------------------------
// CASE 5 — Loader returns null (person row deleted/missing) — return unchanged.
// -----------------------------------------------------------------------------
$row = ['id' => 200, 'tenant_id' => 7, 'person_id' => 9999];
$loaderEmpty = function (int $id) { return null; };
$hydrated = placementHydratePersonFields($row, $loaderEmpty);
assert(count($hydrated) === 3, 'missing person row leaves placement unchanged');
_ok('CASE 5 — missing person row leaves placement unchanged');

// -----------------------------------------------------------------------------
// CASE 6 — Loader throws — function swallows + returns input row.
// -----------------------------------------------------------------------------
$row = ['id' => 300, 'tenant_id' => 7, 'person_id' => 42];
$loaderThrow = function (int $id) { throw new RuntimeException('db down'); };
$hydrated = placementHydratePersonFields($row, $loaderThrow);
assert($hydrated['id'] === 300, 'throwing loader is swallowed');
_ok('CASE 6 — throwing loader is swallowed (placement detail still renders)');

echo "\n🎯 placement_universal_person_fields_smoke — ALL PASS\n";
