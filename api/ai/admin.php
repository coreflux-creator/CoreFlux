<?php
/**
 * /api/ai/admin.php — read-only admin surface for the AI Layer.
 *
 * Spec §1 Phase 1: "AI audit admin page + trace drilldown". Powers
 * the new /admin/ai SPA page (Runs · Tools · Artifacts tabs).
 *
 * Actions:
 *   GET ?action=list_runs[&agent=&status=&since=&limit=&offset=]
 *   GET ?action=get_run&id=<uuid>
 *       → { run, tool_calls[] }
 *   GET ?action=list_tools[&active=1&risk_level=]
 *       → { rows: [tool_registry rows], invocation_counts: {tool_name: int} }
 *   GET ?action=list_invocations[&tool=&since=&limit=]
 *   GET ?action=list_artifacts[&artifact_type=&status=&source_module=&limit=]
 *   GET ?action=get_artifact&id=<uuid>
 *       → { artifact, outgoing[], incoming[], event_history[] }
 *
 * RBAC: `ai.audit.view`. POST mutations (admin-managed tool toggles,
 * artifact transitions) deferred to a follow-up endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/rbac/legacy_map.php';
require_once __DIR__ . '/../../core/ai/tool_gateway.php';
require_once __DIR__ . '/../../core/ai/artifacts.php';

$ctx    = api_require_auth();
$user   = $ctx['user'];
$tid    = (int) $ctx['tenant_id'];
$method = api_method();
$action = (string) (api_query('action') ?? '');

if ($method !== 'GET') api_error('Method not allowed', 405);
rbac_legacy_require($user, 'ai.audit.view');

// Best-effort: keep the DB tool_registry in lock-step with the PHP
// array on every admin page load.  Cheap (static-cached) and means
// new tools added in code show up in the admin page without a
// dedicated re-seed action.
try {
    aiToolRegistrySync();
} catch (\Throwable $e) {
    // Don't fail the admin page if the seed mirror trips; the
    // catalog query below will still surface whatever's persisted.
    error_log('[ai/admin] tool registry sync failed: ' . $e->getMessage());
}

$pdo = getDB();

switch ($action) {
    case 'list_runs': {
        $where  = ['tenant_id = :t'];
        $params = ['t' => $tid];
        if ($a = api_query('agent'))  { $where[] = 'agent_name = :a';   $params['a'] = (string) $a; }
        if ($s = api_query('status')) { $where[] = 'status = :s';        $params['s'] = (string) $s; }
        if ($sn = api_query('since')) { $where[] = 'created_at >= :sn';  $params['sn'] = (string) $sn; }
        $limit  = max(1, min(500, (int) (api_query('limit')  ?? 100)));
        $offset = max(0, (int) (api_query('offset') ?? 0));

        $st = $pdo->prepare(
            'SELECT id, tenant_id, sub_tenant_id, user_id, agent_name,
                    workflow_run_id, model_name, prompt_version, status,
                    input_summary, output_summary, worker_id, artifact_id,
                    error_code, error_message, created_at, completed_at
               FROM ai_runs
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY created_at DESC
              LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $st->execute($params);
        api_ok([
            'rows'   => $st->fetchAll(\PDO::FETCH_ASSOC),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    case 'get_run': {
        $id = trim((string) (api_query('id') ?? ''));
        if ($id === '') api_error('id required', 422);

        $st = $pdo->prepare('SELECT * FROM ai_runs WHERE id = :id AND tenant_id = :t LIMIT 1');
        $st->execute(['id' => $id, 't' => $tid]);
        $run = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$run) api_error('run not found', 404);

        $st = $pdo->prepare(
            'SELECT id, tool_name, args_json, status, http_status, latency_ms,
                    error_code, error_message, result_summary, created_at
               FROM ai_tool_invocations
              WHERE tenant_id = :t AND ai_run_id = :r
              ORDER BY id ASC'
        );
        $st->execute(['t' => $tid, 'r' => $id]);
        $toolCalls = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($toolCalls as &$tc) {
            $tc['args']           = $tc['args_json']      ? json_decode((string) $tc['args_json'], true)      : null;
            $tc['result_summary'] = $tc['result_summary'] ? json_decode((string) $tc['result_summary'], true) : null;
            unset($tc['args_json']);
        }
        api_ok(['run' => $run, 'tool_calls' => $toolCalls]);
    }

    case 'list_tools': {
        $where  = ['1=1'];
        $params = [];
        if ((string) api_query('active') === '1') { $where[] = 'active = 1'; }
        if ($rl = api_query('risk_level')) {
            $where[] = 'risk_level = :rl'; $params['rl'] = (string) $rl;
        }
        $st = $pdo->prepare(
            'SELECT id, tool_name, description, permission_required, risk_level,
                    args_schema, handler_ref, idempotency_args, active, source,
                    created_at, updated_at
               FROM tool_registry
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY tool_name ASC'
        );
        $st->execute($params);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['args_schema']      = $r['args_schema']      ? json_decode((string) $r['args_schema'], true)      : null;
            $r['idempotency_args'] = $r['idempotency_args'] ? json_decode((string) $r['idempotency_args'], true) : null;
        }
        // Invocation counts for the last 30 days, scoped to caller's tenant.
        $st = $pdo->prepare(
            'SELECT tool_name, COUNT(*) AS c
               FROM ai_tool_invocations
              WHERE tenant_id = :t AND created_at >= (NOW() - INTERVAL 30 DAY)
              GROUP BY tool_name'
        );
        $st->execute(['t' => $tid]);
        $counts = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $counts[$c['tool_name']] = (int) $c['c'];
        }
        api_ok(['rows' => $rows, 'invocation_counts_30d' => $counts]);
    }

    case 'list_invocations': {
        $where  = ['tenant_id = :t'];
        $params = ['t' => $tid];
        if ($tn = api_query('tool')) {
            $where[] = 'tool_name = :tn'; $params['tn'] = (string) $tn;
        }
        if ($sn = api_query('since')) {
            $where[] = 'created_at >= :sn'; $params['sn'] = (string) $sn;
        }
        $limit  = max(1, min(500, (int) (api_query('limit') ?? 100)));
        $st = $pdo->prepare(
            'SELECT id, ai_run_id, tool_name, status, http_status, latency_ms,
                    error_code, error_message, created_at
               FROM ai_tool_invocations
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY id DESC
              LIMIT ' . $limit
        );
        $st->execute($params);
        api_ok(['rows' => $st->fetchAll(\PDO::FETCH_ASSOC), 'limit' => $limit]);
    }

    case 'list_artifacts': {
        $filters = [];
        if ($t  = api_query('artifact_type'))  $filters['artifact_type']  = (string) $t;
        if ($s  = api_query('status'))         $filters['status']         = (string) $s;
        if ($m  = api_query('source_module'))  $filters['source_module']  = (string) $m;
        if ($sn = api_query('since'))          $filters['since']          = (string) $sn;
        $filters['limit']  = max(1, min(500, (int) (api_query('limit')  ?? 100)));
        $filters['offset'] = max(0, (int) (api_query('offset') ?? 0));

        $rows = artifactList($tid, $filters);
        // Top-level distribution for the admin dashboard cards.
        $st = $pdo->prepare(
            'SELECT artifact_type, status, COUNT(*) AS c
               FROM artifact_objects
              WHERE tenant_id = :t
              GROUP BY artifact_type, status'
        );
        $st->execute(['t' => $tid]);
        $dist = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $dist[$r['artifact_type']]                 = $dist[$r['artifact_type']] ?? ['total' => 0];
            $dist[$r['artifact_type']][$r['status']]   = (int) $r['c'];
            $dist[$r['artifact_type']]['total']        = ($dist[$r['artifact_type']]['total'] ?? 0) + (int) $r['c'];
        }
        api_ok([
            'rows'         => $rows,
            'distribution' => $dist,
            'limit'        => $filters['limit'],
            'offset'       => $filters['offset'],
        ]);
    }

    case 'get_artifact': {
        $id = trim((string) (api_query('id') ?? ''));
        if ($id === '') api_error('id required', 422);
        $art = artifactGet($tid, $id);
        if (!$art) api_error('artifact not found', 404);
        $lineage = artifactLineage($tid, $id);
        api_ok([
            'artifact'      => $art,
            'outgoing'      => $lineage['outgoing'],
            'incoming'      => $lineage['incoming'],
            'event_history' => $lineage['event_history'],
            'people_graph'  => $lineage['people_graph'] ?? null,
        ]);
    }
}

api_error('Unknown action: ' . $action, 400);
