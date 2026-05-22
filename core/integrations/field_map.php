<?php
/**
 * Tenant Integration Field Map — Slice 3 scaffolding (2026-02).
 *
 * Read/write helpers for the per-tenant registry that controls which
 * external-system fields map to which CoreFlux internal columns per
 * (integration, entity_type) pair.
 *
 * WIRING STATUS: scaffolding. The syncer (`core/jobdiva/sync.php` &
 * siblings) doesn't read this table yet; that's the next slice. This
 * file exists so the admin UI + API can be built and the schema
 * locked in before the syncer integration.
 *
 * Public surface:
 *   tenantIntegrationFieldMapList(tid, integration, entityType): array
 *   tenantIntegrationFieldMapUpsert(tid, payload, actorUserId): array
 *   tenantIntegrationFieldMapDelete(tid, id, actorUserId): bool
 *   tenantIntegrationFieldMapAllowedInternalFields(entityType): array
 *
 * Internal-field allow-list is enforced server-side so a tenant_admin
 * can't accidentally (or maliciously) map an external field into e.g.
 * `tenant_id` or `created_by_user_id`. Add entries as new entity types
 * gain admin-mappable fields.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS = [
    'none',
    'date_normalise',     // epoch ms / ISO / m/d/Y → Y-m-d
    'lowercase',
    'uppercase',
    'trim',
    'cents_to_dollars',   // divide by 100
    'dollars_to_cents',   // multiply by 100
];

/**
 * Internal-field allow-list per entity_type. Restricts what columns the
 * admin UI can target. Keys MUST match real CoreFlux columns on the
 * relevant table; the syncer trusts this list to be safe.
 */
function tenantIntegrationFieldMapAllowedInternalFields(string $entityType): array
{
    static $map = [
        'placement' => [
            'title', 'status', 'start_date', 'end_date',
            'end_client_name', 'engagement_type', 'worksite_state',
            'worksite_country', 'remote_policy', 'notes',
            'client_approver_name', 'client_approver_email',
        ],
        'person' => [
            'first_name', 'last_name', 'preferred_name',
            'email_primary', 'email_secondary',
            'phone_primary', 'phone_secondary',
            'classification', 'status', 'employment_type',
            'hire_date', 'termination_date', 'pay_frequency',
            'linkedin_url', 'recruiter_notes',
            'work_auth_status', 'work_auth_expiry',
        ],
        'company' => [
            'name', 'website', 'phone',
            'address_line1', 'address_line2', 'city', 'state',
            'postal_code', 'country',
        ],
        'contact' => [
            'name', 'title', 'email', 'phone', 'contact_role',
        ],
    ];
    return $map[$entityType] ?? [];
}

function tenantIntegrationFieldMapList(int $tenantId, ?string $integration = null, ?string $entityType = null): array
{
    $pdo = getDB();
    $where = ['tenant_id = :t'];
    $params = ['t' => $tenantId];
    if ($integration !== null && $integration !== '') {
        $where[] = 'integration = :i';
        $params['i'] = $integration;
    }
    if ($entityType !== null && $entityType !== '') {
        $where[] = 'entity_type = :e';
        $params['e'] = $entityType;
    }
    $stmt = $pdo->prepare(
        'SELECT id, integration, entity_type, external_field, internal_field,
                transform, enabled, notes, updated_by_user_id, created_at, updated_at
           FROM tenant_integration_field_map
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY integration, entity_type, internal_field'
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']      = (int) $r['id'];
        $r['enabled'] = (int) $r['enabled'] === 1;
        $r['updated_by_user_id'] = $r['updated_by_user_id'] !== null ? (int) $r['updated_by_user_id'] : null;
    }
    return $rows;
}

/**
 * Upsert a field-map row. If `id` is set, updates that row (tenant-scoped);
 * otherwise inserts a new row, or updates the existing row keyed by
 * (tenant_id, integration, entity_type, internal_field).
 *
 * Returns the resulting row. Throws InvalidArgumentException on
 * validation failure.
 */
function tenantIntegrationFieldMapUpsert(int $tenantId, array $payload, ?int $actorUserId): array
{
    $integration   = trim((string) ($payload['integration']    ?? ''));
    $entityType    = trim((string) ($payload['entity_type']    ?? ''));
    $externalField = trim((string) ($payload['external_field'] ?? ''));
    $internalField = trim((string) ($payload['internal_field'] ?? ''));
    $transform     = trim((string) ($payload['transform']      ?? 'none'));
    $enabled       = isset($payload['enabled']) ? (int) (bool) $payload['enabled'] : 1;
    $notes         = isset($payload['notes']) && $payload['notes'] !== '' ? (string) $payload['notes'] : null;

    if ($integration === '')   throw new \InvalidArgumentException('integration required');
    if ($entityType === '')    throw new \InvalidArgumentException('entity_type required');
    if ($externalField === '') throw new \InvalidArgumentException('external_field required');
    if ($internalField === '') throw new \InvalidArgumentException('internal_field required');

    $allowed = tenantIntegrationFieldMapAllowedInternalFields($entityType);
    if (!in_array($internalField, $allowed, true)) {
        throw new \InvalidArgumentException(
            sprintf('internal_field "%s" is not in the allow-list for entity_type "%s"', $internalField, $entityType)
        );
    }
    if (!in_array($transform, TENANT_INTEGRATION_FIELD_MAP_TRANSFORMS, true)) {
        throw new \InvalidArgumentException('unknown transform: ' . $transform);
    }

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO tenant_integration_field_map
            (tenant_id, integration, entity_type, external_field, internal_field,
             transform, enabled, notes, updated_by_user_id)
         VALUES (:t, :i, :e, :ef, :if, :tr, :en, :n, :u)
         ON DUPLICATE KEY UPDATE
             external_field = VALUES(external_field),
             transform      = VALUES(transform),
             enabled        = VALUES(enabled),
             notes          = VALUES(notes),
             updated_by_user_id = VALUES(updated_by_user_id)'
    )->execute([
        't'  => $tenantId, 'i' => $integration, 'e' => $entityType,
        'ef' => $externalField, 'if' => $internalField, 'tr' => $transform,
        'en' => $enabled, 'n' => $notes, 'u' => $actorUserId,
    ]);

    // Return the resulting canonical row.
    $stmt = $pdo->prepare(
        'SELECT id, integration, entity_type, external_field, internal_field,
                transform, enabled, notes, updated_by_user_id, created_at, updated_at
           FROM tenant_integration_field_map
          WHERE tenant_id = :t AND integration = :i AND entity_type = :e AND internal_field = :if
          LIMIT 1'
    );
    $stmt->execute(['t' => $tenantId, 'i' => $integration, 'e' => $entityType, 'if' => $internalField]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    if ($row) {
        $row['id']      = (int) $row['id'];
        $row['enabled'] = (int) $row['enabled'] === 1;
        $row['updated_by_user_id'] = $row['updated_by_user_id'] !== null ? (int) $row['updated_by_user_id'] : null;
    }
    return $row;
}

function tenantIntegrationFieldMapDelete(int $tenantId, int $id, ?int $actorUserId): bool
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'DELETE FROM tenant_integration_field_map WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $id, 't' => $tenantId]);
    return $stmt->rowCount() > 0;
}
