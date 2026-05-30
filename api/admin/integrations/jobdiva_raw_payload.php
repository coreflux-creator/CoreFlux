<?php
/**
 * /api/admin/integrations/jobdiva_raw_payload.php
 *
 * Read-only diagnostic: returns the FULL enriched payload_snapshot for
 * one placement so the operator can see exactly what JobDiva actually
 * returned — including the `_jd_job` / `_jd_candidate` / `_jd_customer`
 * / `_jd_contact` / `_jd_start` nested objects with every field set by
 * JobDiva.
 *
 * Critical for diagnosing the "assignment bucket has only 1 mappable
 * field" symptom: if `_jd_start` truly contains only `{ status: ... }`,
 * the bottleneck is the JobDiva account's `/searchStart` permission set
 * — not our indexer. The operator can take that evidence to a JobDiva
 * support ticket OR to their JobDiva admin to widen the field
 * permissions.
 *
 * GET /api/admin/integrations/jobdiva_raw_payload.php
 *      [?external_id=12345]   // optional — defaults to the most recent
 *      [?internal_entity_type=placement]
 *
 * Returns:
 *   {
 *     ok: true,
 *     external_id: "27857851",
 *     internal_entity_type: "placement",
 *     payload: { ... full enriched JSON ... },
 *     stats: {
 *       top_level_field_count: N,
 *       _jd_job:        { present: bool, field_count: N, keys: [...] },
 *       _jd_candidate:  { ... },
 *       _jd_customer:   { ... },
 *       _jd_contact:    { ... },
 *       _jd_start:      { ... }
 *     }
 *   }
 *
 * RBAC: tenant_admin.integrations.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/db.php';
require_once __DIR__ . '/../../../core/jobdiva/sync.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
if (api_method() !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'tenant_admin.integrations');

$externalId = trim((string) ($_GET['external_id'] ?? ''));
$entityType = trim((string) ($_GET['internal_entity_type'] ?? 'placement'));

try {
    $pdo = getDB();
    if (!$pdo) api_error('No database connection', 500);

    if ($externalId !== '') {
        $st = $pdo->prepare(
            "SELECT external_id, internal_entity_type, payload_snapshot, updated_at
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = :et
                AND external_id = :eid"
        );
        $st->execute(['t' => $tid, 'et' => $entityType, 'eid' => $externalId]);
    } else {
        // Default: most-recent record for this entity_type.
        $st = $pdo->prepare(
            "SELECT external_id, internal_entity_type, payload_snapshot, updated_at
               FROM external_entity_mappings
              WHERE tenant_id = :t
                AND source_system = 'jobdiva'
                AND internal_entity_type = :et
                AND payload_snapshot IS NOT NULL
              ORDER BY updated_at DESC
              LIMIT 1"
        );
        $st->execute(['t' => $tid, 'et' => $entityType]);
    }
    $row = $st->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    api_error('Query failed: ' . $e->getMessage(), 500);
}

if (!$row) {
    api_ok([
        'ok'                   => false,
        'reason'               => 'no_record',
        'message'              => "No JobDiva $entityType found"
            . ($externalId !== '' ? " with external_id=$externalId" : '')
            . '. Run a JobDiva sync first.',
    ]);
}

$payload = json_decode((string) $row['payload_snapshot'], true);
if (!is_array($payload)) {
    api_error('Stored payload is not valid JSON', 500);
}

// Build the per-bucket stats so the UI can render a one-glance summary
// without forcing the operator to read the full JSON.
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

// Flat top-level fields that AREN'T nested (anything not under _*).
$topFlat = [];
foreach ($payload as $k => $v) {
    if (is_string($k) && (str_starts_with($k, '_jd_') || str_starts_with($k, '__cf_'))) continue;
    if (is_scalar($v) || $v === null) $topFlat[] = $k;
}

// Run the extractor against the live payload and report what it would
// route into each CoreFlux entity bucket. CRITICAL diagnostic when
// `_jd_*` enrichment endpoints are absent: shows the operator that
// flat-extraction (with the new space-separated key handling) is the
// real source of mappable fields, not the missing enrichment buckets.
$extracted = jobdivaExtractJoinedSubPayloads($payload);
$extractedStats = [];
foreach (['person', 'job', 'jobdiva_customer', 'contact', 'assignment'] as $b) {
    $sub = $extracted[$b] ?? [];
    $extractedStats[$b] = [
        'field_count' => count($sub),
        'keys'        => array_keys($sub),
        'sample'      => array_slice($sub, 0, 6, true),
    ];
}

api_ok([
    'ok'                   => true,
    'external_id'          => (string) $row['external_id'],
    'internal_entity_type' => (string) $row['internal_entity_type'],
    'updated_at'           => (string) $row['updated_at'],
    'payload'              => $payload,
    'stats'                => [
        'top_level_scalar_field_count' => count($topFlat),
        'top_level_scalar_keys'        => $topFlat,
        'buckets'                      => $bucketStats,
        'extracted_into_buckets'       => $extractedStats,
    ],
]);
