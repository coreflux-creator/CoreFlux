<?php
/**
 * People API — person merge
 *
 *   POST /api/people/merge
 *     body: {
 *       primary_person_id:  int,
 *       duplicate_person_id: int,
 *       field_resolutions: { fieldName: 'primary'|'duplicate' }   // optional, default 'primary'
 *     }
 *
 * Behavior:
 *   - Both persons must belong to the SAME tenant (no cross-tenant merge — SPEC §10).
 *   - Re-points FKs from duplicate → primary in:
 *       people_emergency_contacts, people_skills, people_documents,
 *       people_pipeline_stages, people_custom_field_values,
 *       people_pii_access_log.target_person_id
 *     (placement FKs handled by placements module separately when wired.)
 *   - For each field in field_resolutions === 'duplicate', copies that
 *     duplicate's value onto primary BEFORE soft-deleting the duplicate.
 *   - Soft-deletes the duplicate (sets deleted_at).
 *   - Audit-logs `people.merged` with full diff and per-table re-pointed counts.
 *
 * SPEC: /app/modules/people/SPEC.md §5.2, §11.5
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
if (api_method() !== 'POST') api_error('Method not allowed', 405);

rbac_legacy_require($user, 'people.merge');

$body = api_json_body();
api_require_fields($body, ['primary_person_id', 'duplicate_person_id']);
$primaryId   = (int) $body['primary_person_id'];
$duplicateId = (int) $body['duplicate_person_id'];
$resolutions = is_array($body['field_resolutions'] ?? null) ? $body['field_resolutions'] : [];

if ($primaryId <= 0 || $duplicateId <= 0 || $primaryId === $duplicateId) {
    api_error('primary_person_id and duplicate_person_id must be distinct positive ids', 422);
}

$primary   = peopleGet($primaryId);
$duplicate = peopleGet($duplicateId);
if (!$primary || !$duplicate) api_error('Both persons must exist in this tenant', 404);

$pdo = getDB();
if (!$pdo) api_error('No database connection', 500);

$pdo->beginTransaction();
$counts = [];
$diff   = [];

try {
    // Apply field resolutions onto primary
    $update = [];
    foreach ($resolutions as $field => $choice) {
        if ($choice !== 'duplicate') continue;
        if (!array_key_exists($field, $duplicate)) continue;
        if (in_array($field, ['id', 'tenant_id', 'created_at', 'created_by_user_id', 'deleted_at'], true)) continue;
        $diff[$field] = ['from' => $primary[$field] ?? null, 'to' => $duplicate[$field] ?? null];
        $update[$field] = $duplicate[$field];
    }
    if ($update) {
        $count = scopedUpdate('people', $primaryId, $update);
        $counts['primary_fields_updated'] = (int) $count;
    }

    // Re-point all dependent rows from duplicate → primary.
    $tablesToRepoint = [
        'people_emergency_contacts',
        'people_skills',
        'people_documents',
        'people_pipeline_stages',
    ];
    foreach ($tablesToRepoint as $table) {
        $stmt = $pdo->prepare(
            "UPDATE `{$table}` SET person_id = :primary
             WHERE tenant_id = :tenant_id AND person_id = :duplicate"
        );
        $stmt->execute([
            'primary'   => $primaryId,
            'tenant_id' => currentTenantId(),
            'duplicate' => $duplicateId,
        ]);
        $counts[$table] = $stmt->rowCount();
    }

    // Custom field values: handle UNIQUE(person_id, field_def_id) collisions.
    $stmt = $pdo->prepare(
        "DELETE dv FROM people_custom_field_values dv
         JOIN people_custom_field_values pv
           ON pv.tenant_id = dv.tenant_id
          AND pv.field_def_id = dv.field_def_id
          AND pv.person_id = :primary
         WHERE dv.tenant_id = :tenant_id AND dv.person_id = :duplicate"
    );
    $stmt->execute([
        'primary'   => $primaryId,
        'tenant_id' => currentTenantId(),
        'duplicate' => $duplicateId,
    ]);
    $counts['people_custom_field_values_collision_dropped'] = $stmt->rowCount();

    $stmt = $pdo->prepare(
        "UPDATE people_custom_field_values SET person_id = :primary
         WHERE tenant_id = :tenant_id AND person_id = :duplicate"
    );
    $stmt->execute([
        'primary'   => $primaryId,
        'tenant_id' => currentTenantId(),
        'duplicate' => $duplicateId,
    ]);
    $counts['people_custom_field_values'] = $stmt->rowCount();

    // PII access log target re-point (history preserved on primary)
    $stmt = $pdo->prepare(
        "UPDATE people_pii_access_log SET target_person_id = :primary
         WHERE tenant_id = :tenant_id AND target_person_id = :duplicate"
    );
    $stmt->execute([
        'primary'   => $primaryId,
        'tenant_id' => currentTenantId(),
        'duplicate' => $duplicateId,
    ]);
    $counts['people_pii_access_log'] = $stmt->rowCount();

    // Soft-delete duplicate
    $stmt = $pdo->prepare(
        'UPDATE people SET deleted_at = NOW(), email_primary = CONCAT("merged_", id, "_", email_primary)
         WHERE tenant_id = :tenant_id AND id = :id'
    );
    $stmt->execute(['tenant_id' => currentTenantId(), 'id' => $duplicateId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    api_error('Merge failed: ' . $e->getMessage(), 500);
}

peopleAudit('people.merged', [
    'primary_id'        => $primaryId,
    'duplicate_id'      => $duplicateId,
    'field_resolutions' => $resolutions,
    'diff'              => $diff,
    're_pointed_counts' => $counts,
], $primaryId);

api_ok([
    'ok'            => true,
    'primary'       => peopleGet($primaryId),
    'merged_counts' => $counts,
]);
