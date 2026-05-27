<?php
/**
 * Smoke — Integration Payload Field Index (Phase 1 of the
 * generalised field-mapping rebuild).
 *
 * Validates:
 *   1. Migration 076 adds the integration_payload_field_index table
 *      with the right shape (PK, unique key, supporting index).
 *   2. integrationPayloadFlatten() emits a complete + correct set of
 *      dotted paths for a realistic enriched JobDiva placement
 *      payload, including `_jd_candidate.*`, `_jd_job.*`,
 *      `_jd_customer.*`, `_jd_contact.*` paths.
 *   3. Array elements collapse to `[]` suffix (no path explosion).
 *   4. Scalar leaves carry the right value_type + truncated sample.
 *   5. mappingUpsert() in entity_mappings.php now calls
 *      integrationPayloadFieldIndexRecord() after persisting the
 *      payload snapshot.
 *   6. The discovery API endpoint exposes sources + paths with the
 *      right RBAC gate.
 *   7. PHP syntax of all new files.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/integrations/payload_field_index.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Migration 076 — integration_payload_field_index table shape\n";
$migration = (string) file_get_contents('/app/core/migrations/076_integration_payload_field_index.sql');
$a('table created',
    str_contains($migration, 'CREATE TABLE IF NOT EXISTS integration_payload_field_index'));
$a('unique key on (tenant, integration, entity_type, source_path)',
    str_contains($migration, 'UNIQUE KEY uq_tenant_integration_entity_path (tenant_id, integration, entity_type, source_path)'));
$a('supporting index for picker queries (last_seen_at)',
    str_contains($migration, 'KEY ix_tenant_integration_entity (tenant_id, integration, entity_type, last_seen_at)'));
$a('source_path width 255 (enough for deep enriched paths)',
    str_contains($migration, 'source_path          VARCHAR(255) NOT NULL'));
$a('sample_value capped at 200 chars',
    str_contains($migration, 'sample_value         VARCHAR(200) DEFAULT NULL'));
$a('value_type column for picker UX',
    str_contains($migration, 'value_type           VARCHAR(16)'));

echo "\n2. integrationPayloadFlatten() — paths emitted for realistic JobDiva fixture\n";
$fixture = [
    'placementId' => 27857851,
    'startDate'   => '2026-05-22',
    'endDate'     => null,
    'dailyBillRate' => 600,
    '_jd_candidate' => [
        'id'        => 1010101,
        'firstName' => 'Andrew',
        'lastName'  => 'Lee',
        'email'     => 'andrew@example.com',
        'phone'     => '555-1234',
        'skills'    => [
            ['name' => 'PHP'], ['name' => 'React'], ['name' => 'MySQL'],
        ],
    ],
    '_jd_job' => [
        'id'         => 2020,
        'title'      => 'Service Desk Analyst',
        'department' => 'IT',
        'description' => 'Long description goes here ...',
    ],
    '_jd_customer' => [
        'id'      => 9090,
        'name'    => 'Public Storage',
        'address' => ['city' => 'Glendale', 'state' => 'CA'],
    ],
    '_jd_contact' => [
        'id'       => 7070,
        'fullName' => 'Jane Manager',
        'email'    => 'jane@client.example.com',
    ],
    '_jd_start' => [
        'finalBillRate' => '85.50',
        'payRate'       => '45.00',
    ],
];

$paths = integrationPayloadFlatten($fixture);
$pathSet = array_column($paths, 'path');

$expect = [
    'placementId', 'startDate', 'endDate', 'dailyBillRate',
    '_jd_candidate', '_jd_candidate.id', '_jd_candidate.firstName',
    '_jd_candidate.lastName', '_jd_candidate.email', '_jd_candidate.phone',
    '_jd_candidate.skills', '_jd_candidate.skills[]', '_jd_candidate.skills[].name',
    '_jd_job', '_jd_job.id', '_jd_job.title', '_jd_job.department', '_jd_job.description',
    '_jd_customer', '_jd_customer.id', '_jd_customer.name',
    '_jd_customer.address', '_jd_customer.address.city', '_jd_customer.address.state',
    '_jd_contact', '_jd_contact.id', '_jd_contact.fullName', '_jd_contact.email',
    '_jd_start', '_jd_start.finalBillRate', '_jd_start.payRate',
];
foreach ($expect as $p) {
    $a("path emitted: {$p}", in_array($p, $pathSet, true),
        'paths: ' . implode(',', array_slice($pathSet, 0, 10)) . '...');
}

echo "\n3. Type + sample correctness on key leaves\n";
$byPath = [];
foreach ($paths as $r) $byPath[$r['path']] = $r;
$a('_jd_candidate.firstName carries sample value',
    ($byPath['_jd_candidate.firstName']['value'] ?? null) === 'Andrew');
$a('_jd_candidate.firstName typed as string',
    ($byPath['_jd_candidate.firstName']['type'] ?? '') === 'string');
$a('dailyBillRate typed as number',
    ($byPath['dailyBillRate']['type'] ?? '') === 'number');
$a('endDate (null) typed as null',
    ($byPath['endDate']['type'] ?? '') === 'null');
$a('_jd_candidate (object bone) typed as object',
    ($byPath['_jd_candidate']['type'] ?? '') === 'object');
$a('_jd_candidate.skills (array bone) typed as array',
    ($byPath['_jd_candidate.skills']['type'] ?? '') === 'array');
$a('_jd_candidate.skills[].name carries sample from first element',
    ($byPath['_jd_candidate.skills[].name']['value'] ?? null) === 'PHP');

echo "\n4. Long-value truncation (>200 chars)\n";
$big = ['note' => str_repeat('x', 500)];
$bigPaths = integrationPayloadFlatten($big);
$a('sample_value truncated to 200 chars',
    strlen($bigPaths[0]['value'] ?? '') === 200);

echo "\n5. mappingUpsert() wires the indexer\n";
$mapSrc = (string) file_get_contents('/app/core/integrations/entity_mappings.php');
$a('requires payload_field_index.php after the direction check',
    str_contains($mapSrc, "require_once __DIR__ . '/payload_field_index.php';"));
$a('calls integrationPayloadFieldIndexRecord with (tid, source, entity_type, payload)',
    str_contains($mapSrc, 'integrationPayloadFieldIndexRecord($tenantId, $source, $entityType, $payload);'));
$a('indexer call is wrapped in try/catch (best-effort)',
    (bool) preg_match('/try\s*\{\s*require_once[^}]+integrationPayloadFieldIndexRecord/s', $mapSrc));
$a('indexer fires ONLY when payload is non-null',
    (bool) preg_match('/if \(\$payload !== null\) \{\s*try\s*\{\s*require_once[^}]+payload_field_index/s', $mapSrc));

echo "\n6. /api/admin/integrations/payload_fields.php — discovery endpoint\n";
$api = (string) file_get_contents('/app/api/admin/integrations/payload_fields.php');
$a('GET only',
    str_contains($api, "if (api_method() !== 'GET') api_error('Method not allowed', 405);"));
$a('RBAC gate tenant_admin.integrations',
    str_contains($api, "rbac_legacy_require(\$user, 'tenant_admin.integrations')"));
$a('source-discovery mode (no integration arg) returns sources[]',
    str_contains($api, "api_ok([\n        'sources' => integrationPayloadFieldIndexSources(\$tid)"));
$a('path-listing mode returns paths[]',
    str_contains($api, "'paths'       => integrationPayloadFieldIndexList(\$tid, \$integration, \$entityType, \$limit)"));

echo "\n7. PHP syntax\n";
foreach ([
    '/app/core/integrations/payload_field_index.php',
    '/app/core/integrations/entity_mappings.php',
    '/app/api/admin/integrations/payload_fields.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Payload field index (Phase 1) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
