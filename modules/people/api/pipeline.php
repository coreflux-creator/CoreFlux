<?php
/**
 * People API — pipeline stages
 *
 *   GET    /api/people/pipeline?person_id=N      → history (newest first)
 *   GET    /api/people/pipeline?summary=1        → tenant summary by top-level stage
 *   POST   /api/people/pipeline?person_id=N      → append stage { stage, substage_id?, note?, placement_id? }
 *   GET    /api/people/pipeline?substages=1      → list tenant pipeline substages
 *   POST   /api/people/pipeline?action=substage  → create substage
 *   PATCH  /api/people/pipeline?action=substage&id=N
 *   DELETE /api/people/pipeline?action=substage&id=N (soft via active=0)
 *
 * SPEC: /app/modules/people/SPEC.md §3.1, §5.3
 */

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../lib/people.php';
require_once __DIR__ . '/../lib/audit.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$method = api_method();
$action = $_GET['action'] ?? '';

const PIPELINE_STAGES = [
    'sourced','screened','submitted','interview',
    'offer','placed','bench','terminated','rejected',
];

// ─── Substages CRUD ───
if ($action === 'substage') {
    if ($method === 'POST') {
        rbac_legacy_require($user, 'people.pipeline.substages.manage');
        $body = api_json_body();
        api_require_fields($body, ['parent_stage', 'label']);
        if (!in_array($body['parent_stage'], PIPELINE_STAGES, true)) {
            api_error('Invalid parent_stage', 422, ['allowed' => PIPELINE_STAGES]);
        }
        $id = scopedInsert('tenant_pipeline_substages', [
            'parent_stage' => $body['parent_stage'],
            'label'        => $body['label'],
            'order_index'  => (int) ($body['order_index'] ?? 0),
            'active'       => isset($body['active']) ? (int) (bool) $body['active'] : 1,
        ]);
        peopleAudit('people.pipeline.substage.created', [
            'id' => $id, 'parent_stage' => $body['parent_stage'], 'label' => $body['label']
        ], $id);
        api_ok(['id' => $id], 201);
    }
    if ($method === 'PATCH') {
        rbac_legacy_require($user, 'people.pipeline.substages.manage');
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        $body = api_json_body();
        unset($body['id'], $body['tenant_id']);
        if (!$body) api_error('No fields to update', 422);
        $rows = scopedUpdate('tenant_pipeline_substages', $id, $body);
        if ($rows === 0) api_error('Not found or no change', 404);
        peopleAudit('people.pipeline.substage.updated', ['id' => $id, 'fields' => array_keys($body)], $id);
        api_ok(['ok' => true]);
    }
    if ($method === 'DELETE') {
        rbac_legacy_require($user, 'people.pipeline.substages.manage');
        $id = (int) api_query('id', 0);
        if ($id <= 0) api_error('id required', 400);
        $rows = scopedUpdate('tenant_pipeline_substages', $id, ['active' => 0]);
        if ($rows === 0) api_error('Not found', 404);
        peopleAudit('people.pipeline.substage.deactivated', ['id' => $id], $id);
        api_ok(['ok' => true]);
    }
}

if ($method === 'GET') {
    if (!empty($_GET['substages'])) {
        rbac_legacy_require($user, 'people.view');
        $rows = scopedQuery(
            'SELECT * FROM tenant_pipeline_substages
             WHERE tenant_id = :tenant_id AND active = 1
             ORDER BY parent_stage, order_index, label'
        );
        api_ok(['substages' => $rows]);
    }
    if (!empty($_GET['summary'])) {
        rbac_legacy_require($user, 'people.view');
        // Latest stage per person → bucketed
        $rows = scopedQuery(
            "SELECT stage, COUNT(*) AS c FROM (
                SELECT ps.person_id,
                       (SELECT stage FROM people_pipeline_stages
                         WHERE tenant_id = ps.tenant_id AND person_id = ps.person_id
                         ORDER BY entered_at DESC LIMIT 1) AS stage
                  FROM people_pipeline_stages ps
                 WHERE ps.tenant_id = :tenant_id
              GROUP BY ps.person_id
             ) latest
             GROUP BY stage"
        );
        $byStage = array_fill_keys(PIPELINE_STAGES, 0);
        foreach ($rows as $r) $byStage[$r['stage']] = (int) $r['c'];
        api_ok(['summary' => $byStage]);
    }
    rbac_legacy_require($user, 'people.view');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    api_ok(['stages' => peoplePipelineHistory($personId)]);
}

if ($method === 'POST') {
    rbac_legacy_require($user, 'people.manage');
    $personId = (int) api_query('person_id', 0);
    if ($personId <= 0) api_error('person_id required', 400);
    $body = api_json_body();
    api_require_fields($body, ['stage']);
    if (!in_array($body['stage'], PIPELINE_STAGES, true)) {
        api_error('Invalid stage', 422, ['allowed' => PIPELINE_STAGES]);
    }
    // SPEC §9: cannot be 'placed' without a placement_id
    if ($body['stage'] === 'placed' && empty($body['placement_id'])) {
        api_error("Cannot enter 'placed' without placement_id", 422);
    }
    $id = scopedInsert('people_pipeline_stages', [
        'person_id'          => $personId,
        'stage'              => $body['stage'],
        'substage_id'        => $body['substage_id']  ?? null,
        'entered_at'         => $body['entered_at']   ?? date('Y-m-d H:i:s'),
        'entered_by_user_id' => $user['id']           ?? null,
        'note'               => $body['note']         ?? null,
        'placement_id'       => $body['placement_id'] ?? null,
    ]);
    peopleAudit('people.pipeline.stage_added',
        ['person_id' => $personId, 'stage' => $body['stage'], 'pipeline_stage_id' => $id], $personId);
    api_ok(['id' => $id], 201);
}

api_error('Method not allowed', 405);
