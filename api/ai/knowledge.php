<?php
/**
 * /api/ai/knowledge.php — Slice 7B Knowledge Graph endpoints.
 *
 *   GET  ?action=search&q=…        — FULLTEXT search documents
 *   GET  ?action=entity&id=N       — entity drill (1-hop neighbours)
 *   GET  ?action=entities          — list entities (filter by type)
 *   POST ?action=record            — body {doc_uri, title, content?, ...}
 *   POST ?action=entity_upsert     — body {entity_type, label, payload?}
 *   POST ?action=edge_create       — body {from_entity_id, to_entity_id, relation, ...}
 *
 * RBAC: `ai.knowledge.read` for reads, `ai.knowledge.write` for writes
 * (with legacy fallbacks).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/knowledge_graph.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$uid    = (int) ($user['id'] ?? 0) ?: null;
$method = api_method();
$action = (string) ($_GET['action'] ?? '');

$canRead  = rbac_legacy_can($user, 'ai.knowledge.read')
         || rbac_legacy_can($user, 'ai.audit.view')
         || rbac_legacy_can($user, 'accounting.read');
$canWrite = rbac_legacy_can($user, 'ai.knowledge.write')
         || rbac_legacy_can($user, 'accounting.write');

if ($method === 'GET' && $action === 'search') {
    if (!$canRead) api_error('Forbidden', 403);
    $q = (string) ($_GET['q'] ?? '');
    if (trim($q) === '') api_error('q required', 422);
    try {
        $r = knowledgeSearchFulltext($tid, $q,
            isset($_GET['limit']) ? (int) $_GET['limit'] : 20);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok($r);
}

if ($method === 'GET' && $action === 'entity') {
    if (!$canRead) api_error('Forbidden', 403);
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) api_error('id required', 422);
    $r = knowledgeNeighbours($tid, $id);
    if (!$r['entity']) api_error('entity not found', 404);
    api_ok($r);
}

if ($method === 'GET' && ($action === '' || $action === 'entities')) {
    if (!$canRead) api_error('Forbidden', 403);
    $rows = knowledgeEntityList($tid, [
        'entity_type' => $_GET['entity_type'] ?? null,
        'label_like'  => $_GET['label_like']  ?? null,
        'limit'       => isset($_GET['limit']) ? (int) $_GET['limit'] : 100,
    ]);
    api_ok(['entities' => $rows, 'count' => count($rows)]);
}

if ($method === 'POST' && $action === 'record') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $r = knowledgeDocumentUpsert($tid, array_merge($body, ['created_by_user_id' => $uid]));
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok(['document' => $r]);
}

if ($method === 'POST' && $action === 'entity_upsert') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $r = knowledgeEntityUpsert($tid,
            (string) ($body['entity_type'] ?? ''),
            (string) ($body['label']       ?? ''),
            array_merge($body, ['created_by_user_id' => $uid]));
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok(['entity' => $r]);
}

if ($method === 'POST' && $action === 'edge_create') {
    if (!$canWrite) api_error('Forbidden', 403);
    $body = api_json_body();
    try {
        $r = knowledgeEdgeCreate($tid,
            (int) ($body['from_entity_id'] ?? 0),
            (int) ($body['to_entity_id']   ?? 0),
            (string) ($body['relation']    ?? ''),
            ['weight' => $body['weight'] ?? null, 'payload' => $body['payload'] ?? null]);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
    api_ok(['edge' => $r]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
