<?php
/**
 * /api/admin/integrations/placement_schema.php
 *
 * Auto-build payload for a CoreFlux placement-detail page that mirrors
 * the JobDiva Assignment edit screen. Returns the live indexed
 * schema for a given placement (or the global indexed schema if no
 * specific id is given), grouped into logical sections:
 *   - Placement / Assignment       (root + _jd_start)
 *   - Job                          (_jd_job + flat job_* prefix)
 *   - Person / Candidate           (_jd_candidate + candidate_* prefix)
 *   - End-client / Customer        (_jd_customer + customer_* prefix)
 *   - Hiring contact               (_jd_contact)
 *   - Rates / Overheads / VMS      (sub-keys of _jd_start)
 *
 * Operator can then render the schema as a read-only detail screen
 * with sections + field labels + sample values — no manual wiring
 * needed. As soon as JobDiva's enrichment endpoints are reachable
 * and the backfill has run, the screen "just exists" with the right
 * fields populated for every placement in the tenant.
 *
 * Read-only by design — operators who want to TRANSFORM the data
 * into CoreFlux columns use the existing Field Mapping Studio.
 *
 * RBAC: tenant_admin.integrations.
 *
 *   GET /api/admin/integrations/placement_schema.php?integration=jobdiva
 *     → { ok, integration, sections: [
 *           { key, label, icon, entity_type, field_count,
 *             fields: [{path, value_type, sample_value, occurrence_count}, ...] },
 *           ...
 *         ] }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/integrations/payload_field_index.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'tenant_admin.integrations');

$integration = trim((string) ($_GET['integration'] ?? 'jobdiva'));
if ($integration === '' || !preg_match('/^[a-z0-9_]{1,40}$/', $integration)) {
    api_error('integration must be lowercase alphanumeric/underscore', 400);
}

/**
 * Section definitions per integration. Each section maps to one
 * indexed (integration, entity_type) source. Order matters — the
 * detail screen renders them top→bottom in this order.
 */
$SECTIONS = [
    'jobdiva' => [
        ['key' => 'assignment',       'label' => 'Assignment / Placement', 'icon' => '📋', 'entity_type' => 'assignment'],
        ['key' => 'placement_summary','label' => 'Placement summary',      'icon' => '📄', 'entity_type' => 'placement'],
        ['key' => 'job',              'label' => 'Job',                    'icon' => '💼', 'entity_type' => 'job'],
        ['key' => 'person',           'label' => 'Person (candidate)',     'icon' => '👤', 'entity_type' => 'person'],
        ['key' => 'jobdiva_customer', 'label' => 'End-client',             'icon' => '🏢', 'entity_type' => 'jobdiva_customer'],
        ['key' => 'contact',          'label' => 'Hiring contact',         'icon' => '☎️', 'entity_type' => 'contact'],
    ],
];

$sections = $SECTIONS[$integration] ?? [];
if (empty($sections)) {
    // Unknown integration — still return a flat schema so the UI
    // can render whatever's indexed.
    $sections = [['key' => $integration, 'label' => ucfirst($integration), 'icon' => '🧩', 'entity_type' => 'record']];
}

$out = [];
foreach ($sections as $sect) {
    $rows = integrationPayloadFieldIndexList($tid, $integration, $sect['entity_type'], 500);
    if (!is_array($rows)) $rows = [];
    $fields = [];
    foreach ($rows as $r) {
        // Skip intermediate object/array bones — only show leaf paths
        // that an operator would actually display on a detail screen.
        $type = (string) ($r['value_type'] ?? '');
        if ($type === 'object' || $type === 'array') continue;
        $fields[] = [
            'path'             => (string) ($r['source_path'] ?? ''),
            'value_type'       => $type,
            'sample_value'     => isset($r['sample_value']) ? (string) $r['sample_value'] : null,
            'occurrence_count' => (int) ($r['occurrence_count'] ?? 0),
        ];
    }
    $out[] = [
        'key'         => (string) $sect['key'],
        'label'       => (string) $sect['label'],
        'icon'        => (string) $sect['icon'],
        'entity_type' => (string) $sect['entity_type'],
        'field_count' => count($fields),
        'fields'      => $fields,
    ];
}

api_ok([
    'ok'          => true,
    'integration' => $integration,
    'sections'    => $out,
]);
