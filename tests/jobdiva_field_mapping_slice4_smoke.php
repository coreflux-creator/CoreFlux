<?php
/**
 * Slice 4 — Tenant Integration Field Map wired into the syncer.
 *
 * Verifies:
 *   1. New resolver helpers exported (resolveAll, applyTransform,
 *      pluckPath, pluckInternal, flushCache).
 *   2. resolveAll() caches per-(tenant, integration, entity_type) so
 *      sync loops don't hit the DB per record. flushCache() clears it.
 *   3. applyTransform() handles every documented transform.
 *   4. pluckPath() walks dotted paths and is case/separator-insensitive
 *      at every segment.
 *   5. pluckInternal() prefers the registry over the default function,
 *      but falls back gracefully when the configured external_field
 *      isn't present in the payload.
 *   6. jobdivaSyncUpsertPlacement consults the registry for title,
 *      start_date, end_date, end_client_name, status.
 *   7. jobdivaPlacementsAutoCreatePerson consults the registry for
 *      first_name, last_name, email_primary, phone_primary.
 *   8. Admin UI banner flipped from yellow scaffolding → green live.
 *
 * Behavioural tests (resolver+pluck) run inline against an in-memory
 * payload — no DB connection required. Smoke tests for the upsert
 * paths verify source-level wiring (the upsert helpers themselves
 * require a live DB).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$assert = function (string $msg, bool $ok) use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}\n"; $fail++; }
};
$ROOT = realpath(__DIR__ . '/..');

require_once "{$ROOT}/core/integrations/field_map.php";
require_once "{$ROOT}/core/jobdiva/sync.php";   // for jobdivaNormaliseDate used by 'date_normalise' transform

echo "Resolver — exports\n";
$assert('exports tenantIntegrationFieldMapResolveAll',
    function_exists('tenantIntegrationFieldMapResolveAll'));
$assert('exports tenantIntegrationFieldMapApplyTransform',
    function_exists('tenantIntegrationFieldMapApplyTransform'));
$assert('exports tenantIntegrationFieldMapPluckPath',
    function_exists('tenantIntegrationFieldMapPluckPath'));
$assert('exports tenantIntegrationFieldMapPluckInternal',
    function_exists('tenantIntegrationFieldMapPluckInternal'));
$assert('exports tenantIntegrationFieldMapFlushCache',
    function_exists('tenantIntegrationFieldMapFlushCache'));

echo "\nTransform — applyTransform()\n";
$assert("'none' is a no-op",                     tenantIntegrationFieldMapApplyTransform('Hello', 'none') === 'Hello');
$assert("'lowercase' lowercases",                tenantIntegrationFieldMapApplyTransform('Hello', 'lowercase') === 'hello');
$assert("'uppercase' uppercases",                tenantIntegrationFieldMapApplyTransform('Hello', 'uppercase') === 'HELLO');
$assert("'trim' trims whitespace",               tenantIntegrationFieldMapApplyTransform('  hi  ', 'trim') === 'hi');
$assert("'cents_to_dollars' divides by 100",     tenantIntegrationFieldMapApplyTransform('12345', 'cents_to_dollars') === 123.45);
$assert("'dollars_to_cents' multiplies by 100",  tenantIntegrationFieldMapApplyTransform('12.34', 'dollars_to_cents') === 1234);
$assert("'date_normalise' delegates to jobdivaNormaliseDate (epoch ms)",
    tenantIntegrationFieldMapApplyTransform('1779231290000', 'date_normalise') === gmdate('Y-m-d', 1779231290));
$assert('null passes through transforms unchanged',
    tenantIntegrationFieldMapApplyTransform(null, 'lowercase') === null);
$assert('empty string passes through unchanged',
    tenantIntegrationFieldMapApplyTransform('', 'uppercase') === '');
$assert('unknown transform is a no-op (no fatal)',
    tenantIntegrationFieldMapApplyTransform('foo', 'this_does_not_exist') === 'foo');
$assert("'cents_to_dollars' is a no-op for non-numeric",
    tenantIntegrationFieldMapApplyTransform('hello', 'cents_to_dollars') === 'hello');

echo "\nPath pluck — pluckPath()\n";
$payload = [
    'id' => '55843075',
    'job' => [
        'JobTitle' => 'Service Desk Analyst',   // mixed-case, nested
        'job number' => '26-03327',             // space-separated, nested
        'meta' => ['JOB_OWNER' => 'kunal'],     // double-nested + ALL CAPS
    ],
    'CandidateEmail' => 'alice@example.com',
];
$assert('flat key resolves',                     tenantIntegrationFieldMapPluckPath($payload, 'id') === '55843075');
$assert('dotted path walks one level',           tenantIntegrationFieldMapPluckPath($payload, 'job.JobTitle') === 'Service Desk Analyst');
$assert('case-insensitive at every segment',     tenantIntegrationFieldMapPluckPath($payload, 'JOB.jobtitle') === 'Service Desk Analyst');
$assert('separator-insensitive (matches "job number" via "jobnumber")',
    tenantIntegrationFieldMapPluckPath($payload, 'job.jobnumber') === '26-03327');
$assert('double-nested walks both segments',     tenantIntegrationFieldMapPluckPath($payload, 'job.meta.jobOwner') === 'kunal');
$assert('returns empty string when path misses',
    tenantIntegrationFieldMapPluckPath($payload, 'job.nonexistent') === '');
$assert('empty path returns empty string',       tenantIntegrationFieldMapPluckPath($payload, '') === '');
$assert('non-scalar terminal value returns ""',
    tenantIntegrationFieldMapPluckPath($payload, 'job.meta') === '');

echo "\nPluckInternal — registry-first with default fallback\n";
// Prime the cache directly via the global so we can assert without a DB.
$GLOBALS['CF_FIELD_MAP_CACHE'] = [
    '1|jobdiva|placement' => [
        'title' => ['external_field' => 'job.JobTitle', 'transform' => 'none'],
        'end_client_name' => ['external_field' => 'companyName', 'transform' => 'uppercase'],
        'start_date' => ['external_field' => 'startTs', 'transform' => 'date_normalise'],
    ],
];
$sample = [
    'job' => ['JobTitle' => 'Service Desk Analyst'],
    'companyName' => 'Public Storage',
    'startTs' => '1779231290000',
];
$defaultCalls = 0;
$default = static function () use (&$defaultCalls) { $defaultCalls++; return 'BUILTIN'; };
$assert('registry win — title pulled via dotted path',
    tenantIntegrationFieldMapPluckInternal(1, 'jobdiva', 'placement', 'title', $sample, $default) === 'Service Desk Analyst');
$assert('default NOT called when registry resolved',  $defaultCalls === 0);
$assert('registry transform applied — uppercase on end_client_name',
    tenantIntegrationFieldMapPluckInternal(1, 'jobdiva', 'placement', 'end_client_name', $sample, $default) === 'PUBLIC STORAGE');
$assert('registry transform applied — date_normalise epoch ms',
    tenantIntegrationFieldMapPluckInternal(1, 'jobdiva', 'placement', 'start_date', $sample, $default) === gmdate('Y-m-d', 1779231290));
$assert('unconfigured internal_field falls back to default',
    tenantIntegrationFieldMapPluckInternal(1, 'jobdiva', 'placement', 'status', $sample, $default) === 'BUILTIN');
$assert('default IS called when nothing in registry for this field',
    $defaultCalls === 1);

// Configured external_field but missing from payload → fall back rather than wipe.
$GLOBALS['CF_FIELD_MAP_CACHE'] = [
    '1|jobdiva|placement' => [
        'title' => ['external_field' => 'notInPayload', 'transform' => 'none'],
    ],
];
$assert('configured-but-missing external_field falls through to default',
    tenantIntegrationFieldMapPluckInternal(1, 'jobdiva', 'placement', 'title', $sample, $default) === 'BUILTIN');

echo "\nCache — flushCache()\n";
tenantIntegrationFieldMapFlushCache();
$assert('flush clears the global cache',         $GLOBALS['CF_FIELD_MAP_CACHE'] === []);

echo "\nSyncer wiring — jobdivaSyncUpsertPlacement\n";
$syncSrc = (string) file_get_contents("{$ROOT}/core/jobdiva/sync.php");
$assert('Placement upsert requires field_map lib',
    strpos($syncSrc, "require_once __DIR__ . '/../integrations/field_map.php';") !== false);
$assert('title pulled via tenantIntegrationFieldMapPluckInternal',
    strpos($syncSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'title'") !== false);
$assert('start_date pulled via registry helper',
    strpos($syncSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'start_date'") !== false);
$assert('end_date pulled via registry helper',
    strpos($syncSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'end_date'") !== false);
$assert('end_client_name pulled via registry helper',
    strpos($syncSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'end_client_name'") !== false);
$assert('status pulled via registry helper',
    strpos($syncSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'placement', 'status'") !== false);
$assert('default closures preserve original built-in candidate lists',
    strpos($syncSrc, "jobdivaPluckFieldDeep(\$jd, [\n                'jobTitle', 'job_title', 'job title', 'title',") !== false);
$assert('date_normalise still applied after registry resolution (idempotent)',
    strpos($syncSrc, "\$startDate = jobdivaNormaliseDate(\$startDate) ?? '';") !== false
    && strpos($syncSrc, "\$endDateNorm = jobdivaNormaliseDate(\$endDate);") !== false);

echo "\nSyncer wiring — jobdivaPlacementsAutoCreatePerson\n";
$pSrc = (string) file_get_contents("{$ROOT}/core/jobdiva/sync_placements.php");
$assert('Auto-create person requires field_map lib',
    strpos($pSrc, "require_once __DIR__ . '/../integrations/field_map.php';") !== false);
$assert('first_name resolved via registry helper',
    strpos($pSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'person', 'first_name'") !== false);
$assert('last_name resolved via registry helper',
    strpos($pSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'person', 'last_name'") !== false);
$assert('email_primary resolved via registry helper',
    strpos($pSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'person', 'email_primary'") !== false);
$assert('phone_primary resolved via registry helper',
    strpos($pSrc, "tenantIntegrationFieldMapPluckInternal(\n        \$tid, 'jobdiva', 'person', 'phone_primary'") !== false);
$assert('synthetic placeholders preserved (no real data → still gets placeholder)',
    strpos($pSrc, "if (\$firstName === '') \$firstName = 'JobDiva';") !== false
    && strpos($pSrc, "if (\$lastName  === '') \$lastName  = 'Candidate-' . \$candidateExtId;") !== false);

echo "\nAdmin UI — banner flipped to live\n";
$ui = (string) file_get_contents("{$ROOT}/dashboard/src/pages/IntegrationFieldMapAdmin.jsx");
$assert('banner test-id renamed to field-map-status-banner',
    strpos($ui, 'data-testid="field-map-status-banner"') !== false);
$assert('"Scaffolding mode" copy removed (no longer accurate)',
    strpos($ui, 'Scaffolding mode.') === false);
$assert('"Live." copy added with sync-now hint',
    strpos($ui, '<strong>Live.</strong>') !== false
    && strpos($ui, 'The next sync will use these mappings.') !== false);
$assert('banner tips operator to use raw payload viewer to find field names',
    strpos($ui, '"View raw payload"') !== false
    && strpos($ui, 'Linked external systems') !== false);

echo "\n--- {$pass} passed, {$fail} failed ---\n";
exit($fail === 0 ? 0 : 1);
