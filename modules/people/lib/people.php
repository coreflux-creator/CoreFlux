<?php
/**
 * People Module — SPEC-aligned cross-module library
 *
 * STABLE INTERFACE for other modules (placements, time, payroll) to read
 * person data. Sibling modules MUST NOT SELECT from people_* tables
 * directly — go through these helpers; tenant scoping is enforced.
 *
 * SPEC: /app/modules/people/SPEC.md §5.4
 *
 * The legacy /app/modules/people/lib/employees.php remains alive for any
 * caller still on the old people_employees schema. New code should use
 * THIS file (operates on the SPEC-aligned `people` table).
 */

require_once __DIR__ . '/../../../core/tenant_scope.php';

/** Safe non-PII fields ALWAYS allowed for SELECT. */
function peopleSafeFields(): string
{
    return 'id, tenant_id, external_id, first_name, middle_name, last_name, preferred_name, '
         . 'email_primary, email_secondary, phone_primary, phone_secondary, '
         . 'classification, status, work_auth_status, work_auth_expiry, requires_sponsorship, '
         . 'employment_type, hire_date, termination_date, pay_frequency, '
         . 'linkedin_url, resume_storage_object_id, recruiter_notes, source, referred_by_person_id, '
         . 'created_by_user_id, created_at, updated_at, deleted_at';
}

/** PII fields — gated by people.pii.view + audit-logged via peoplePIIRead(). */
function peoplePIIFields(): string
{
    return 'dob, ssn_last4, gender, marital_status, '
         . 'home_address_line1, home_address_line2, home_city, home_state, home_postal_code, home_country, '
         . 'mailing_address_line1, mailing_address_line2, mailing_city, mailing_state, mailing_postal_code, mailing_country';
}

/**
 * Get a person by id (non-PII fields only).
 */
function peopleGet(int $personId): ?array
{
    $sql = 'SELECT ' . peopleSafeFields() . '
            FROM people
            WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL';
    return scopedFind($sql, ['id' => $personId]);
}

/**
 * Get a person WITH PII. Caller MUST have already checked
 * RBAC::hasPermission($user, 'people.pii.view') AND have written a
 * `people_pii_access_log` entry via peopleLogPIIAccess().
 */
function peopleGetWithPII(int $personId): ?array
{
    $sql = 'SELECT ' . peopleSafeFields() . ', ' . peoplePIIFields() . '
            FROM people
            WHERE tenant_id = :tenant_id AND id = :id AND deleted_at IS NULL';
    return scopedFind($sql, ['id' => $personId]);
}

/**
 * Paginated directory list. Filters per SPEC §5.1.
 *
 * @param array $filters {q, classification, status, work_auth_expiry_before,
 *                        skill, pipeline_stage, page, per_page}
 * @return array {rows: array, total: int, page: int, per_page: int}
 */
function peopleList(array $filters = []): array
{
    $where  = ['p.tenant_id = :tenant_id', 'p.deleted_at IS NULL'];
    $params = [];

    if (!empty($filters['q'])) {
        $where[] = '(p.first_name LIKE :q OR p.last_name LIKE :q OR p.preferred_name LIKE :q '
                 . 'OR p.email_primary LIKE :q OR p.external_id = :qexact)';
        $params['q']      = '%' . $filters['q'] . '%';
        $params['qexact'] = $filters['q'];
    }
    if (!empty($filters['classification'])) {
        $where[] = 'p.classification = :classification';
        $params['classification'] = $filters['classification'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = $filters['status'];
    }
    if (!empty($filters['work_auth_expiry_before'])) {
        $where[] = 'p.work_auth_expiry IS NOT NULL AND p.work_auth_expiry <= :wa';
        $params['wa'] = $filters['work_auth_expiry_before'];
    }
    if (!empty($filters['skill'])) {
        $where[] = 'EXISTS (SELECT 1 FROM people_skills s '
                 . 'WHERE s.person_id = p.id AND s.tenant_id = p.tenant_id AND s.skill = :skill)';
        $params['skill'] = $filters['skill'];
    }
    if (!empty($filters['pipeline_stage'])) {
        $where[] = 'EXISTS (SELECT 1 FROM people_pipeline_stages ps '
                 . 'WHERE ps.person_id = p.id AND ps.tenant_id = p.tenant_id AND ps.stage = :stage)';
        $params['stage'] = $filters['pipeline_stage'];
    }

    $page    = max(1, (int) ($filters['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($filters['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $whereSql = implode(' AND ', $where);

    // Total count (separate query for accurate pagination)
    $countSql = "SELECT COUNT(*) AS c FROM people p WHERE {$whereSql}";
    $totalRow = scopedFind($countSql, $params);
    $total = (int) ($totalRow['c'] ?? 0);

    $listSql = 'SELECT ' . str_replace('p.', 'p.', peopleSafeFieldsAliased('p')) . '
                FROM people p
                WHERE ' . $whereSql . '
                ORDER BY p.last_name, p.first_name
                LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $rows = scopedQuery($listSql, $params);

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
    ];
}

/** Aliased version of peopleSafeFields() for joined queries. */
function peopleSafeFieldsAliased(string $alias): string
{
    $fields = explode(', ', peopleSafeFields());
    return implode(', ', array_map(fn($f) => $alias . '.' . $f, $fields));
}

/**
 * Append-only PII access log entry. Required before any PII read/write.
 * Captures actor, target person, fields, ip, user_agent, request_id.
 */
function peopleLogPIIAccess(int $actorUserId, ?int $targetPersonId, string $eventType, array $fields = []): void
{
    $pdo = getDB();
    if (!$pdo) return;
    $stmt = $pdo->prepare(
        'INSERT INTO people_pii_access_log
         (tenant_id, actor_user_id, target_person_id, event_type, fields_json, ip_address, user_agent, request_id, created_at)
         VALUES (:tenant_id, :actor, :target, :event, :fields, :ip, :ua, :rid, NOW())'
    );
    $stmt->execute([
        'tenant_id' => currentTenantId(),
        'actor'     => $actorUserId,
        'target'    => $targetPersonId,
        'event'     => $eventType,
        'fields'    => $fields ? json_encode(array_values($fields)) : null,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? null,
        'ua'        => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'rid'       => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ]);
}

/**
 * Pipeline history for a person (newest first).
 */
function peoplePipelineHistory(int $personId, int $limit = 50): array
{
    return scopedQuery(
        'SELECT ps.*, sub.label AS substage_label
         FROM people_pipeline_stages ps
         LEFT JOIN tenant_pipeline_substages sub
                ON sub.id = ps.substage_id AND sub.tenant_id = ps.tenant_id
         WHERE ps.tenant_id = :tenant_id AND ps.person_id = :person_id
         ORDER BY ps.entered_at DESC
         LIMIT ' . (int) $limit,
        ['person_id' => $personId]
    );
}

/**
 * Skills for a person.
 */
function peopleSkills(int $personId): array
{
    return scopedQuery(
        'SELECT * FROM people_skills
         WHERE tenant_id = :tenant_id AND person_id = :person_id
         ORDER BY skill',
        ['person_id' => $personId]
    );
}

/**
 * Documents for a person (non-deleted).
 */
function peopleDocuments(int $personId): array
{
    return scopedQuery(
        'SELECT * FROM people_documents
         WHERE tenant_id = :tenant_id AND person_id = :person_id AND deleted_at IS NULL
         ORDER BY created_at DESC',
        ['person_id' => $personId]
    );
}

/**
 * Custom field definitions for the current tenant (active).
 */
function peopleCustomFieldDefs(): array
{
    return scopedQuery(
        'SELECT * FROM people_custom_field_defs
         WHERE tenant_id = :tenant_id AND deleted_at IS NULL
         ORDER BY order_index, field_label',
        []
    );
}

/**
 * Custom field values for a person (joined with defs).
 */
function peopleCustomFieldValues(int $personId): array
{
    return scopedQuery(
        'SELECT v.*, d.field_key, d.field_label, d.field_type, d.options_json, d.pii AS field_pii
         FROM people_custom_field_values v
         JOIN people_custom_field_defs d ON d.id = v.field_def_id AND d.tenant_id = v.tenant_id
         WHERE v.tenant_id = :tenant_id AND v.person_id = :person_id AND d.deleted_at IS NULL
         ORDER BY d.order_index, d.field_label',
        ['person_id' => $personId]
    );
}
