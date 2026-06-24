<?php
/**
 * core/ai/workflows/engine.php — PHP-native workflow runtime
 * (Slice 3, AI Tool Gateway).
 *
 * Spec §6 (LangGraph MVP). LangGraph is a Python library; CoreFlux is
 * PHP. Rather than introduce a Python sidecar, we reproduce the
 * operational contract in PHP:
 *
 *   Graphs are registered as { nodes, edges, entry, version }. Each
 *   node is a callable(array $state, array $ctx): array — returns
 *   the new state. After every node we persist a row in
 *   workflow_checkpoints. Edges may be deterministic (string) or
 *   computed (callable returning the next node name). The special
 *   sentinel '__end__' terminates the run.
 *
 *   Nodes may throw WorkflowAwaitingApproval (constructed via
 *   workflowRequireApproval) — the engine catches it, parks the
 *   workflow in status='awaiting_approval', writes a
 *   workflow_approvals row, and returns. Callers later resume by
 *   POSTing the approval decision.
 *
 *   Nodes may throw any other exception; the engine marks the
 *   workflow 'failed', writes a failed checkpoint, and returns.
 *
 * Graph registration is in-memory per-request — graphs are loaded
 * from core/ai/workflows/graphs/*.php. State is persisted as JSON in
 * workflow_runs.state_json after every node, so a crash mid-run
 * doesn't lose work (resume picks up at the last completed node).
 *
 * Surfaces:
 *   workflowRegisterGraph(array $definition): void
 *   workflowGraphs(): array
 *   workflowStart(int $tenantId, ?int $userId, string $graphName,
 *                 array $input, array $ctx = []): array
 *   workflowResume(int $tenantId, string $workflowRunId,
 *                  array $ctx = []): array
 *   workflowGet(int $tenantId, string $workflowRunId): ?array
 *   workflowList(int $tenantId, array $filters = [], int $limit = 100): array
 *   workflowRequireApproval(string $approvalType, int $riskLevel,
 *                           array $requestPayload, ?string $assignedRole = null): void
 *   workflowDecideApproval(int $tenantId, int $approvalId,
 *                          string $decision, ?int $userId = null,
 *                          array $decisionPayload = []): array
 *
 * Direct-PDO. Never throws across the audit boundary on failure to
 * write a checkpoint — those are best-effort and surfaced to log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../audit.php';

/**
 * Sentinel thrown by a node to halt and request human approval.
 * Caught by the engine in workflowRun(), never propagates further.
 */
class WorkflowAwaitingApproval extends \RuntimeException
{
    public string $approvalType;
    public int    $riskLevel;
    public array  $requestPayload;
    public ?string $assignedRole;
    public function __construct(string $type, int $risk, array $payload, ?string $role = null)
    {
        parent::__construct("workflow awaiting approval: {$type}");
        $this->approvalType   = $type;
        $this->riskLevel      = $risk;
        $this->requestPayload = $payload;
        $this->assignedRole   = $role;
    }
}

/** Per-process graph registry. Graph files self-register on require. */
function &_workflowRegistry(): array
{
    static $reg = [];
    return $reg;
}

/**
 * Register a graph. Definition shape:
 *   [
 *     'name'    => 'transaction_classification',
 *     'version' => '2026-02-r1',
 *     'entry'   => 'vendor_resolution',
 *     'nodes'   => [
 *       'vendor_resolution' => callable(array $state, array $ctx): array,
 *       'retrieval'         => callable(...),
 *       'classify'          => callable(...),
 *       …
 *     ],
 *     'edges'   => [
 *       'vendor_resolution' => 'retrieval',                       // static edge
 *       'classify'          => callable(array $state): string,    // conditional edge
 *       'draft_branch'      => '__end__',
 *     ],
 *   ]
 *
 * Edges may be:
 *   - string target node name
 *   - '__end__' to terminate
 *   - callable that returns one of the above
 */
function workflowRegisterGraph(array $definition): void
{
    foreach (['name','version','entry','nodes','edges'] as $req) {
        if (!array_key_exists($req, $definition)) {
            throw new \InvalidArgumentException("graph definition missing '{$req}'");
        }
    }
    $reg = &_workflowRegistry();
    $reg[$definition['name']] = $definition;
}

function workflowGraphs(): array
{
    $out = [];
    foreach (_workflowRegistry() as $name => $def) {
        $out[] = [
            'name'    => $name,
            'version' => $def['version'],
            'entry'   => $def['entry'],
            'nodes'   => array_keys($def['nodes']),
        ];
    }
    return $out;
}

/** UUIDv4 — matches the same shape used in core/ai/gateway.php. */
function _workflowUuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Start a new workflow run. Creates the workflow_runs row, drives
 * the engine to either completion or the first approval interrupt.
 *
 * $ctx is passed through to every node — typical contents:
 *   ['user' => $userRow, 'session_id' => 'sess_…',
 *    'ai_run_id' => 'a4f0…', 'sub_tenant_id' => 7]
 *
 * Returns the final workflow shape:
 *   ['workflow_run_id' => str, 'status' => str,
 *    'current_node' => str|null, 'state' => array,
 *    'output' => array|null, 'pending_approval_id' => int|null]
 */
function workflowStart(int $tenantId, ?int $userId, string $graphName, array $input, array $ctx = []): array
{
    $reg = _workflowRegistry();
    if (!isset($reg[$graphName])) {
        throw new \InvalidArgumentException("unknown graph '{$graphName}'");
    }
    $graph = $reg[$graphName];
    $runId = _workflowUuid();

    $subTenantId = isset($ctx['sub_tenant_id']) ? (int) $ctx['sub_tenant_id'] : null;
    $aiRunId     = isset($ctx['ai_run_id'])     ? (string) $ctx['ai_run_id'] : null;

    getDB()->prepare(
        'INSERT INTO workflow_runs
            (id, tenant_id, sub_tenant_id, user_id, ai_run_id,
             graph_name, graph_version, status, current_node,
             input_json, state_json, created_at)
         VALUES
            (:id, :t, :st, :u, :ai, :gn, :gv, "running", :en, :ij, :sj, NOW())'
    )->execute([
        'id' => $runId, 't' => $tenantId, 'st' => $subTenantId, 'u' => $userId,
        'ai' => $aiRunId, 'gn' => $graphName, 'gv' => (string) $graph['version'],
        'en' => (string) $graph['entry'],
        'ij' => json_encode($input, JSON_UNESCAPED_SLASHES) ?: null,
        'sj' => json_encode($input, JSON_UNESCAPED_SLASHES) ?: null,
    ]);

    return _workflowDrive($tenantId, $runId, $graph, $input, $graph['entry'], $ctx);
}

/**
 * Resume a paused workflow. Reads state from workflow_runs, finds
 * the most recent approval, applies its decision_payload onto the
 * state (under key `_approval`) and drives the engine from the
 * NEXT node per the graph's edge map.
 *
 * Approval status must be 'approved' or 'rejected'. 'rejected' just
 * flips the run to status='failed' with code 'approval_rejected'.
 */
function workflowResume(int $tenantId, string $workflowRunId, array $ctx = []): array
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, graph_name, graph_version, status, current_node, state_json
           FROM workflow_runs
          WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $workflowRunId, 't' => $tenantId]);
    $run = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$run) throw new \InvalidArgumentException('workflow run not found');
    if ($run['status'] !== 'awaiting_approval') {
        throw new \InvalidArgumentException("workflow not in awaiting_approval (status={$run['status']})");
    }

    $reg = _workflowRegistry();
    if (!isset($reg[$run['graph_name']])) {
        throw new \InvalidArgumentException("unknown graph '{$run['graph_name']}'");
    }
    $graph = $reg[$run['graph_name']];

    // Pull the latest decided approval for this run.
    $stmt = $pdo->prepare(
        "SELECT id, node_name, status, decision_payload
           FROM workflow_approvals
          WHERE workflow_run_id = :id AND tenant_id = :t
            AND status IN ('approved','rejected')
          ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute(['id' => $workflowRunId, 't' => $tenantId]);
    $appr = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$appr) throw new \InvalidArgumentException('no decided approval found; nothing to resume');

    $state = is_string($run['state_json']) && $run['state_json'] !== ''
        ? (json_decode($run['state_json'], true) ?: [])
        : [];
    $decisionPayload = is_string($appr['decision_payload']) && $appr['decision_payload'] !== ''
        ? (json_decode($appr['decision_payload'], true) ?: [])
        : [];
    $state['_approval'] = [
        'id'              => (int) $appr['id'],
        'node_name'       => (string) $appr['node_name'],
        'status'          => (string) $appr['status'],
        'decision_payload'=> $decisionPayload,
    ];

    // Approval REJECTED → terminate the run as failed.
    if ($appr['status'] === 'rejected') {
        $pdo->prepare(
            'UPDATE workflow_runs
                SET status = "failed",
                    error_code = "approval_rejected",
                    error_message = "Approval was rejected; workflow halted.",
                    completed_at = NOW()
              WHERE id = :id AND tenant_id = :t'
        )->execute(['id' => $workflowRunId, 't' => $tenantId]);
        return [
            'workflow_run_id' => $workflowRunId,
            'status'          => 'failed',
            'current_node'    => $run['current_node'],
            'state'           => $state,
            'output'          => null,
            'pending_approval_id' => null,
        ];
    }

    // Move into running again and pick the next node.
    $pdo->prepare(
        'UPDATE workflow_runs SET status = "running" WHERE id = :id AND tenant_id = :t'
    )->execute(['id' => $workflowRunId, 't' => $tenantId]);

    $nextNode = _workflowNextNode($graph, (string) $appr['node_name'], $state);
    if ($nextNode === '__end__') {
        return _workflowCompleteRun($tenantId, $workflowRunId, $state, []);
    }
    // Slice 4: thread the approval id into node ctx so write-tools
    // (risk_level >= 4) can prove they were invoked downstream of an
    // approved approval. aiToolInvoke()'s risk gate reads
    // callerCtx['_approval_id'].
    $ctx['_approval_id'] = (int) $appr['id'];
    return _workflowDrive($tenantId, $workflowRunId, $graph, $state, $nextNode, $ctx);
}

/**
 * Inner driver: walks the graph from $currentNode until the run
 * either terminates (__end__), pauses (WorkflowAwaitingApproval),
 * or errors. Persists state + checkpoints along the way.
 */
function _workflowDrive(int $tenantId, string $runId, array $graph, array $state, string $currentNode, array $ctx): array
{
    $pdo = getDB();
    $maxNodes = 30;                                              // defensive loop cap
    $visited = 0;

    while ($currentNode !== '__end__' && $visited++ < $maxNodes) {
        if (!isset($graph['nodes'][$currentNode])) {
            $msg = "graph '{$graph['name']}' has no node '{$currentNode}'";
            _workflowMarkFailed($tenantId, $runId, 'unknown_node', $msg, $state, $currentNode);
            return ['workflow_run_id' => $runId, 'status' => 'failed', 'current_node' => $currentNode,
                    'state' => $state, 'output' => null, 'pending_approval_id' => null];
        }

        $startMs = microtime(true);
        $pdo->prepare(
            'UPDATE workflow_runs SET current_node = :cn WHERE id = :id AND tenant_id = :t'
        )->execute(['cn' => $currentNode, 'id' => $runId, 't' => $tenantId]);

        try {
            $handler = $graph['nodes'][$currentNode];
            $state   = $handler($state, $ctx + ['_workflow_run_id' => $runId, '_tenant_id' => $tenantId, '_node' => $currentNode]);
            if (!is_array($state)) $state = ['_node_returned_non_array' => true];
            _workflowCheckpoint($tenantId, $runId, $currentNode, 'completed', $state, (int) ((microtime(true) - $startMs) * 1000));
        } catch (WorkflowAwaitingApproval $w) {
            // Park the workflow.
            $apprId = _workflowInsertApproval($tenantId, $runId, $currentNode, $w);
            _workflowCheckpoint($tenantId, $runId, $currentNode, 'paused', $state, (int) ((microtime(true) - $startMs) * 1000));
            _workflowPersistState($tenantId, $runId, $state);
            $pdo->prepare(
                'UPDATE workflow_runs SET status = "awaiting_approval" WHERE id = :id AND tenant_id = :t'
            )->execute(['id' => $runId, 't' => $tenantId]);
            return [
                'workflow_run_id' => $runId, 'status' => 'awaiting_approval',
                'current_node'    => $currentNode, 'state' => $state,
                'output'          => null, 'pending_approval_id' => $apprId,
            ];
        } catch (\Throwable $e) {
            _workflowCheckpoint($tenantId, $runId, $currentNode, 'failed', $state, (int) ((microtime(true) - $startMs) * 1000), 'node_threw', substr($e->getMessage(), 0, 240));
            _workflowMarkFailed($tenantId, $runId, 'node_threw', substr($e->getMessage(), 0, 240), $state, $currentNode);
            return ['workflow_run_id' => $runId, 'status' => 'failed', 'current_node' => $currentNode,
                    'state' => $state, 'output' => null, 'pending_approval_id' => null];
        }

        _workflowPersistState($tenantId, $runId, $state);
        $currentNode = _workflowNextNode($graph, $currentNode, $state);
    }

    if ($visited >= $maxNodes && $currentNode !== '__end__') {
        _workflowMarkFailed($tenantId, $runId, 'node_budget', "exceeded {$maxNodes} node steps", $state, $currentNode);
        return ['workflow_run_id' => $runId, 'status' => 'failed', 'current_node' => $currentNode,
                'state' => $state, 'output' => null, 'pending_approval_id' => null];
    }
    return _workflowCompleteRun($tenantId, $runId, $state, []);
}

function _workflowNextNode(array $graph, string $fromNode, array $state): string
{
    $edge = $graph['edges'][$fromNode] ?? '__end__';
    if (is_callable($edge)) {
        $next = $edge($state);
        if (!is_string($next) || $next === '') return '__end__';
        return $next;
    }
    return is_string($edge) ? $edge : '__end__';
}

function _workflowCheckpoint(int $tenantId, string $runId, string $node, string $status, array $state, int $durationMs, ?string $errCode = null, ?string $errMsg = null): void
{
    try {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES) ?: '{}';
        getDB()->prepare(
            'INSERT INTO workflow_checkpoints
                (workflow_run_id, tenant_id, node_name, status,
                 state_hash, state_json, duration_ms,
                 error_code, error_message, created_at)
             VALUES (:rid, :t, :n, :s, :h, :sj, :d, :ec, :em, NOW())'
        )->execute([
            'rid' => $runId, 't' => $tenantId, 'n' => $node, 's' => $status,
            'h'   => hash('sha256', $json), 'sj' => $json, 'd' => $durationMs,
            'ec'  => $errCode, 'em' => $errMsg,
        ]);
    } catch (\Throwable $e) {
        error_log('[_workflowCheckpoint] ' . $e->getMessage());
    }
}

function _workflowPersistState(int $tenantId, string $runId, array $state): void
{
    try {
        getDB()->prepare(
            'UPDATE workflow_runs SET state_json = :sj WHERE id = :id AND tenant_id = :t'
        )->execute(['sj' => json_encode($state, JSON_UNESCAPED_SLASHES) ?: null,
                    'id' => $runId, 't' => $tenantId]);
    } catch (\Throwable $e) {
        error_log('[_workflowPersistState] ' . $e->getMessage());
    }
}

function _workflowInsertApproval(int $tenantId, string $runId, string $node, WorkflowAwaitingApproval $w): int
{
    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO workflow_approvals
            (workflow_run_id, tenant_id, node_name, approval_type,
             risk_level, assigned_to_role, request_payload, status, created_at)
         VALUES (:rid, :t, :n, :at, :r, :role, :p, "pending", NOW())'
    )->execute([
        'rid'  => $runId, 't' => $tenantId, 'n' => $node,
        'at'   => $w->approvalType, 'r' => $w->riskLevel,
        'role' => $w->assignedRole,
        'p'    => json_encode($w->requestPayload, JSON_UNESCAPED_SLASHES) ?: '{}',
    ]);
    return (int) $pdo->lastInsertId();
}

function _aiWorkflowAuditEvent(int $tenantId, ?int $userId, string $event, int $targetId, array $meta): void
{
    try {
        platformAuditLogWrite(
            $tenantId,
            $userId,
            $event,
            $targetId,
            $meta,
            [
                'source' => 'workflow',
                'object_type' => 'workflow_run',
                'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64),
            ]
        );
    } catch (\Throwable $e) {
        error_log('[_aiWorkflowAuditEvent] ' . $e->getMessage());
    }
}

function _workflowMarkFailed(int $tenantId, string $runId, string $code, string $msg, array $state, ?string $node): void
{
    try {
        getDB()->prepare(
            'UPDATE workflow_runs
                SET status        = "failed",
                    error_code    = :ec,
                    error_message = :em,
                    state_json    = :sj,
                    current_node  = COALESCE(:cn, current_node),
                    completed_at  = NOW()
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'ec' => $code, 'em' => $msg,
            'sj' => json_encode($state, JSON_UNESCAPED_SLASHES) ?: null,
            'cn' => $node, 'id' => $runId, 't' => $tenantId,
        ]);
    } catch (\Throwable $e) {
        error_log('[_workflowMarkFailed] ' . $e->getMessage());
    }
}

function _workflowCompleteRun(int $tenantId, string $runId, array $state, array $output): array
{
    $finalOutput = !empty($output) ? $output
        : (isset($state['_output']) && is_array($state['_output']) ? $state['_output'] : []);
    try {
        getDB()->prepare(
            'UPDATE workflow_runs
                SET status       = "completed",
                    state_json   = :sj,
                    output_json  = :oj,
                    completed_at = NOW()
              WHERE id = :id AND tenant_id = :t'
        )->execute([
            'sj' => json_encode($state, JSON_UNESCAPED_SLASHES) ?: null,
            'oj' => json_encode($finalOutput, JSON_UNESCAPED_SLASHES) ?: null,
            'id' => $runId, 't' => $tenantId,
        ]);
    } catch (\Throwable $e) {
        error_log('[_workflowCompleteRun] ' . $e->getMessage());
    }
    return [
        'workflow_run_id' => $runId, 'status' => 'completed',
        'current_node'    => null, 'state' => $state,
        'output'          => $finalOutput, 'pending_approval_id' => null,
    ];
}

/**
 * Read API: returns {run, checkpoints[], approvals[]}.
 */
function workflowGet(int $tenantId, string $workflowRunId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, user_id, ai_run_id,
                graph_name, graph_version, status, current_node,
                input_json, state_json, output_json,
                error_code, error_message,
                created_at, updated_at, completed_at
           FROM workflow_runs
          WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $workflowRunId, 't' => $tenantId]);
    $run = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$run) return null;
    foreach (['tenant_id','sub_tenant_id','user_id'] as $k) {
        $run[$k] = $run[$k] !== null ? (int) $run[$k] : null;
    }
    foreach (['input_json','state_json','output_json'] as $jk) {
        if (is_string($run[$jk]) && $run[$jk] !== '') {
            $d = json_decode($run[$jk], true);
            $run[$jk] = $d !== null ? $d : $run[$jk];
        }
    }

    $stmt = getDB()->prepare(
        'SELECT id, node_name, status, state_hash, duration_ms,
                error_code, error_message, created_at
           FROM workflow_checkpoints
          WHERE workflow_run_id = :id AND tenant_id = :t
          ORDER BY id ASC'
    );
    $stmt->execute(['id' => $workflowRunId, 't' => $tenantId]);
    $ckpts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $stmt = getDB()->prepare(
        'SELECT id, node_name, approval_type, risk_level, status,
                assigned_to_role, request_payload, decision_payload,
                decided_by_user_id, decided_at, created_at
           FROM workflow_approvals
          WHERE workflow_run_id = :id AND tenant_id = :t
          ORDER BY id ASC'
    );
    $stmt->execute(['id' => $workflowRunId, 't' => $tenantId]);
    $apprs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($apprs as &$ap) {
        foreach (['request_payload','decision_payload'] as $jk) {
            if (is_string($ap[$jk]) && $ap[$jk] !== '') {
                $d = json_decode($ap[$jk], true);
                $ap[$jk] = $d !== null ? $d : $ap[$jk];
            }
        }
    } unset($ap);

    return ['run' => $run, 'checkpoints' => $ckpts, 'approvals' => $apprs];
}

/**
 * Read API: list workflows. Filters: graph_name, status, user_id.
 */
function workflowList(int $tenantId, array $filters = [], int $limit = 100): array
{
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT id, graph_name, graph_version, status, current_node,
                   user_id, error_code, created_at, completed_at
              FROM workflow_runs
             WHERE tenant_id = :t';
    $params = ['t' => $tenantId];
    if (!empty($filters['graph_name'])) { $sql .= ' AND graph_name = :gn'; $params['gn'] = (string) $filters['graph_name']; }
    if (!empty($filters['status']) && in_array($filters['status'], ['queued','running','awaiting_approval','completed','failed','cancelled'], true)) {
        $sql .= ' AND status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['user_id'])) { $sql .= ' AND user_id = :u'; $params['u'] = (int) $filters['user_id']; }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
    } unset($r);
    return $rows;
}

/**
 * From INSIDE a node: park the workflow for approval. The engine
 * catches this exception, persists state, writes the
 * workflow_approvals row, and returns. The node is NOT considered
 * completed — when resumed, the engine moves to the *next* node per
 * the graph's edge from the paused node.
 */
function workflowRequireApproval(string $approvalType, int $riskLevel, array $requestPayload, ?string $assignedRole = null): void
{
    throw new WorkflowAwaitingApproval($approvalType, $riskLevel, $requestPayload, $assignedRole);
}

/**
 * Canonical "ask for permission to post a draft JE" pause-for-approval
 * helper. Use this from any workflow node that wants to promote a
 * draft journal entry — it snapshots the gate-compatible request
 * payload (je_id + draft_hash + snapshot_at) via
 * accountingApprovalRequestPayloadForJe() so the
 * coreflux.post_approved_journal_entry gate accepts the approval
 * later. Throws WorkflowAwaitingApproval (caught by the engine).
 *
 *   workflowRequireJePromotionApproval($tenantId, $jeId);
 *   workflowRequireJePromotionApproval($tenantId, $jeId, 'accounting_reviewer');
 *
 * Any caller who builds request_payload by hand without snapshotting
 * draft_hash WILL be refused at promotion time with code
 * 'approval_missing_hash'. This helper is the supported path.
 */
function workflowRequireJePromotionApproval(int $tenantId, int $jeId, ?string $assignedRole = 'accounting_reviewer'): void
{
    require_once __DIR__ . '/../../accounting/post_approval_gates.php';
    $payload = accountingApprovalRequestPayloadForJe($tenantId, $jeId);
    throw new WorkflowAwaitingApproval('post_journal_entry', 4, $payload, $assignedRole);
}

/**
 * Out-of-graph variant: directly INSERT a workflow_approvals row that
 * is gate-compatible with coreflux.post_approved_journal_entry. Use
 * this from seed scripts, admin manual-review surfaces, or any
 * non-workflow code path that needs to open a JE-promotion approval.
 *
 * Returns the new workflow_approvals.id. Caller is responsible for
 * tying it to a workflow_run row if relevant (the seed script creates
 * a synthetic 'manual_je_post' run for traceability).
 *
 * @param int    $tenantId
 * @param string $runId      workflow_runs.id (UUIDv4) to link the approval to
 * @param int    $jeId
 * @param ?string $assignedRole  default 'accounting_reviewer'
 * @param string $node       node_name stamp; default 'await_je_approval'
 */
function workflowOpenJePromotionApproval(
    int $tenantId,
    string $runId,
    int $jeId,
    ?string $assignedRole = 'accounting_reviewer',
    string $node = 'await_je_approval'
): int {
    require_once __DIR__ . '/../../accounting/post_approval_gates.php';
    $payload = accountingApprovalRequestPayloadForJe($tenantId, $jeId);
    $w = new WorkflowAwaitingApproval('post_journal_entry', 4, $payload, $assignedRole);
    return _workflowInsertApproval($tenantId, $runId, $node, $w);
}

/**
 * Record an approval decision. Does NOT auto-resume the workflow —
 * callers (the API endpoint) call workflowResume() after this. That
 * keeps the data write and the engine drive separate so partial
 * failures are easy to reason about.
 */
function workflowDecideApproval(int $tenantId, int $approvalId, string $decision, ?int $userId = null, array $decisionPayload = []): array
{
    if (!in_array($decision, ['approved','rejected','cancelled'], true)) {
        throw new \InvalidArgumentException("invalid decision '{$decision}'");
    }
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT id, workflow_run_id, status FROM workflow_approvals
          WHERE id = :id AND tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $approvalId, 't' => $tenantId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) throw new \InvalidArgumentException('approval not found');
    if ($row['status'] !== 'pending') {
        throw new \InvalidArgumentException("approval already {$row['status']}");
    }
    $pdo->prepare(
        'UPDATE workflow_approvals
            SET status = :s,
                decision_payload = :dp,
                decided_by_user_id = :u,
                decided_at = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'  => $decision,
        'dp' => json_encode($decisionPayload, JSON_UNESCAPED_SLASHES) ?: null,
        'u'  => $userId, 'id' => $approvalId, 't' => $tenantId,
    ]);
    _aiWorkflowAuditEvent($tenantId, $userId, "ai.workflow.approval_{$decision}", $approvalId, [
        'approval_id' => $approvalId,
        'workflow_run_id' => (string) $row['workflow_run_id'],
        'decision' => $decision,
        'decision_payload_keys' => array_values(array_keys($decisionPayload)),
    ]);
    return ['approval_id' => $approvalId,
            'workflow_run_id' => (string) $row['workflow_run_id'],
            'status' => $decision];
}
