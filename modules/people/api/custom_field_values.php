<?php
/**
 * People API — custom field VALUES (per-person)
 *
 *   GET  /api/people/custom_field_values?person_id=N
 *   PUT  /api/people/custom_field_values?person_id=N    body: { values: { field_key: value, ... } }
 *
 * Upserts via UNIQUE(person_id, field_def_id). Deletes are explicit by
 * passing null in the values map.
 *
 * SPEC: /app/modules/people/SPEC.md §5.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();

if ($method === 'GET') {
    RBAC::requirePermission($user, 'people.view');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    api_ok(['values' => peopleCustomFieldValues($personId)]);
}

if ($method === 'PUT' || $method === 'POST') {
    RBAC::requirePermission($user, 'people.manage');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    if (empty($body['values']) || !is_array($body['values'])) {
        api_error('values map required', 422);
    }

    $defs = peopleCustomFieldDefs();
    $byKey = [];
    foreach ($defs as $d) $byKey[$d['field_key']] = $d;

    $pdo = getDB();
    if (!$pdo) api_error('No database connection', 500);

    $touched = [];
    foreach ($body['values'] as $key => $value) {
        $def = $byKey[$key] ?? null;
        if (!$def) continue; // ignore unknown keys silently
        if ($def['pii']) RBAC::requirePermission($user, 'people.pii.manage');

        $col = match ($def['field_type']) {
            'number'        => 'value_number',
            'date'          => 'value_date',
            'boolean'       => 'value_boolean',
            default         => 'value_text', // text, select, multiselect (json string)
        };

        $coerced = $value;
        if ($def['field_type'] === 'multiselect' && is_array($value)) $coerced = json_encode($value);
        if ($def['field_type'] === 'boolean')   $coerced = $value === null ? null : (int) (bool) $value;
        if ($def['field_type'] === 'number')    $coerced = $value === null ? null : (float) $value;

        $stmt = $pdo->prepare(
            "INSERT INTO people_custom_field_values
             (tenant_id, person_id, field_def_id, value_text, value_number, value_date, value_boolean, updated_at)
             VALUES (:tenant_id, :person_id, :field_def_id, NULL, NULL, NULL, NULL, NOW())
             ON DUPLICATE KEY UPDATE
                value_text = NULL, value_number = NULL, value_date = NULL, value_boolean = NULL, updated_at = NOW()"
        );
        $stmt->execute([
            'tenant_id'    => currentTenantId(),
            'person_id'    => $personId,
            'field_def_id' => (int) $def['id'],
        ]);

        $stmt2 = $pdo->prepare(
            "UPDATE people_custom_field_values
             SET {$col} = :v, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND person_id = :person_id AND field_def_id = :field_def_id"
        );
        $stmt2->execute([
            'v'            => $coerced,
            'tenant_id'    => currentTenantId(),
            'person_id'    => $personId,
            'field_def_id' => (int) $def['id'],
        ]);
        $touched[] = $key;
    }
    peopleAudit('people.custom_field.value_set', ['person_id' => $personId, 'fields' => $touched], $personId);
    api_ok(['ok' => true, 'updated' => $touched]);
}

api_error('Method not allowed', 405);
