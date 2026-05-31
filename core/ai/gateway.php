<?php
/**
 * core/ai/gateway.php — AI Tool Gateway, Slice 1 (Foundation).
 *
 * Spec ref: CoreFlux AI-Native Extension v1.2 §1, §2, §15.
 *
 * The Gateway is the central control plane every AI-originated
 * request flows through. It:
 *
 *   1. Materializes an `ai_runs` row scoped to (tenant, user, agent).
 *   2. Emits the canonical audit events into the existing `audit_log`
 *      table per spec §15 (`ai_run_created`, `ai_tool_call_requested`,
 *      `ai_tool_call_executed`, `ai_tool_call_blocked`). Reusing the
 *      single audit_log keeps platform admins on one queryable
 *      stream — the per-tool-call detail still lives in
 *      ai_tool_invocations (089).
 *   3. Cross-links every tool call back to its parent run by stamping
 *      `ai_tool_invocations.ai_run_id` (added in 090).
 *   4. Provides a clean read API for the admin trace explorer.
 *
 * Slice 1 ships PLUMBING ONLY — there is no LLM provider call here.
 * `aiGatewayRunIntent()` simply persists a run, optionally invokes
 * permitted tools by name, completes the run with the tool outputs,
 * and returns the assembled envelope. Slice 2 will wire the actual
 * model and replace the deterministic tool dispatch with the LLM's
 * planned tool calls.
 *
 * Surfaces:
 *   aiGatewayCreateRun(int $tenantId, ?int $userId, string $agent,
 *                      ?string $promptVersion, ?int $subTenantId,
 *                      ?string $inputSummary): string         // returns run_id
 *   aiGatewayCompleteRun(string $runId, string $status,
 *                        ?string $outputSummary, ?string $errCode,
 *                        ?string $errMsg): void
 *   aiGatewayInvokeTool(string $runId, string $toolName,
 *                       array $args, array $callerCtx): array // envelope
 *   aiGatewayGetRun(int $tenantId, string $runId): ?array     // run + tool calls
 *   aiGatewayListRuns(int $tenantId, ?int $userId, ?string $agent,
 *                     ?string $status, int $limit = 100): array
 *   aiGatewayAuditEvent(int $tenantId, ?int $userId, string $event,
 *                       array $payload): void
 *
 * All functions are direct-PDO and never throw on audit failures —
 * AI auditing must never block a workflow.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/tool_gateway.php';

/**
 * Cheap RFC4122-v4 UUID. We don't need cryptographic strength here;
 * a 122-bit random space is plenty for run id collision avoidance
 * across the lifetime of any single tenant.
 */
function _aiGatewayUuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/**
 * Create an `ai_runs` row and emit `ai_run_created` into audit_log.
 * Returns the run UUID. Never throws on audit-log failures.
 */
function aiGatewayCreateRun(
    int $tenantId,
    ?int $userId,
    string $agent,
    ?string $promptVersion = null,
    ?int $subTenantId = null,
    ?string $inputSummary = null,
    ?string $modelName = null
): string {
    $runId = _aiGatewayUuid();
    getDB()->prepare(
        'INSERT INTO ai_runs
            (id, tenant_id, sub_tenant_id, user_id, agent_name,
             model_name, prompt_version, status, input_summary, created_at)
         VALUES
            (:id, :t, :st, :u, :a, :m, :pv, "running", :is, NOW())'
    )->execute([
        'id' => $runId, 't' => $tenantId, 'st' => $subTenantId,
        'u'  => $userId, 'a' => $agent, 'm' => $modelName,
        'pv' => $promptVersion,
        // input_summary may include user-typed text; truncate to keep
        // PII budgets predictable in the audit stream.
        'is' => $inputSummary !== null ? mb_substr($inputSummary, 0, 2000) : null,
    ]);

    aiGatewayAuditEvent($tenantId, $userId, 'ai_run_created', [
        'ai_run_id'      => $runId,
        'agent_name'     => $agent,
        'prompt_version' => $promptVersion,
        'model_name'     => $modelName,
        'sub_tenant_id'  => $subTenantId,
    ]);
    return $runId;
}

/**
 * Move a run to a terminal state. `status` must be one of
 * 'completed' | 'failed' | 'cancelled' | 'awaiting_approval'.
 *
 * tenant_id is required for the WHERE clause — even though the run
 * id is a globally-unique UUID, scoping by (id, tenant_id) keeps the
 * tenant-leak sentry green and gives us a defense-in-depth guarantee
 * that one tenant's run cannot mutate another's by accident.
 */
function aiGatewayCompleteRun(
    int $tenantId,
    string $runId,
    string $status,
    ?string $outputSummary = null,
    ?string $errCode = null,
    ?string $errMsg = null
): void {
    if (!in_array($status, ['completed','failed','cancelled','awaiting_approval'], true)) {
        throw new \InvalidArgumentException("invalid run status '{$status}'");
    }
    getDB()->prepare(
        'UPDATE ai_runs
            SET status         = :s,
                output_summary = :os,
                error_code     = :ec,
                error_message  = :em,
                completed_at   = IF(:s2 IN ("completed","failed","cancelled"), NOW(), completed_at)
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's'  => $status, 's2' => $status,
        'os' => $outputSummary !== null ? mb_substr($outputSummary, 0, 2000) : null,
        'ec' => $errCode, 'em' => $errMsg !== null ? mb_substr($errMsg, 0, 240) : null,
        'id' => $runId, 't' => $tenantId,
    ]);
}

/**
 * Invoke a tool from within a run. Thin wrapper around
 * `aiToolInvoke()` (Slice-0 catalog) that:
 *   • stamps the parent run id on the resulting
 *     ai_tool_invocations row;
 *   • emits the spec-mandated audit events
 *     (`ai_tool_call_requested`, then either `ai_tool_call_executed`
 *     or `ai_tool_call_blocked`).
 *
 * The returned envelope matches `aiToolInvoke`:
 *   { ok, status, result?, error? }
 */
function aiGatewayInvokeTool(string $runId, string $toolName, array $args, array $callerCtx): array
{
    $tenantId = (int) ($callerCtx['tenant_id'] ?? 0);
    $userId   = isset($callerCtx['user_id']) ? (int) $callerCtx['user_id'] : null;

    aiGatewayAuditEvent($tenantId, $userId, 'ai_tool_call_requested', [
        'ai_run_id'  => $runId,
        'tool_name'  => $toolName,
        'input_hash' => hash('sha256', json_encode($args, JSON_UNESCAPED_SLASHES) ?: ''),
    ]);

    $envelope = aiToolInvoke($toolName, $args, $callerCtx);

    // Stamp the freshly-written ai_tool_invocations row with this
    // run id. aiToolInvoke wrote the row inside its own audit step;
    // we patch the latest row for (tenant, tool, user, no run yet).
    // This is intentionally a "best-effort link" — auditing must
    // never block.
    try {
        getDB()->prepare(
            'UPDATE ai_tool_invocations
                SET ai_run_id = :rid
              WHERE tenant_id = :t
                AND tool_name = :tn
                AND ai_run_id IS NULL
              ORDER BY id DESC
              LIMIT 1'
        )->execute(['rid' => $runId, 't' => $tenantId, 'tn' => $toolName]);
    } catch (\Throwable $e) {
        error_log('[aiGatewayInvokeTool] back-link failed: ' . $e->getMessage());
    }

    $blocked  = !$envelope['ok'] && in_array((string) ($envelope['status'] ?? ''), ['denied','validation_failed'], true);
    $eventType = $blocked ? 'ai_tool_call_blocked' : 'ai_tool_call_executed';
    aiGatewayAuditEvent($tenantId, $userId, $eventType, [
        'ai_run_id'  => $runId,
        'tool_name'  => $toolName,
        'status'     => $envelope['status'] ?? 'unknown',
        'error_code' => $envelope['error']['code'] ?? null,
    ]);

    return $envelope;
}

/**
 * Read API: returns {run, tool_calls[]} for the admin trace
 * explorer. Tenant-scoped. Returns null if not found.
 */
function aiGatewayGetRun(int $tenantId, string $runId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT id, tenant_id, sub_tenant_id, user_id, agent_name,
                workflow_run_id, model_name, prompt_version, status,
                input_summary, output_summary, worker_id, artifact_id,
                error_code, error_message, created_at, completed_at
           FROM ai_runs
          WHERE id = :id AND tenant_id = :t'
    );
    $stmt->execute(['id' => $runId, 't' => $tenantId]);
    $run = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$run) return null;
    foreach (['tenant_id','sub_tenant_id','user_id'] as $k) {
        $run[$k] = $run[$k] !== null ? (int) $run[$k] : null;
    }

    $stmt = getDB()->prepare(
        'SELECT id, tool_name, status, latency_ms,
                error_code, error_message, result_summary, args_json, created_at
           FROM ai_tool_invocations
          WHERE ai_run_id = :rid AND tenant_id = :t
          ORDER BY id ASC'
    );
    $stmt->execute(['rid' => $runId, 't' => $tenantId]);
    $calls = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($calls as &$c) {
        $c['id']         = (int) $c['id'];
        $c['latency_ms'] = $c['latency_ms'] !== null ? (int) $c['latency_ms'] : null;
        foreach (['result_summary','args_json'] as $jk) {
            if (isset($c[$jk]) && is_string($c[$jk]) && $c[$jk] !== '') {
                $decoded = json_decode($c[$jk], true);
                $c[$jk]  = $decoded !== null ? $decoded : $c[$jk];
            }
        }
    }
    unset($c);
    return ['run' => $run, 'tool_calls' => $calls];
}

/**
 * Read API: list recent runs for the admin trace explorer. All
 * filters optional. Limit clamped to [1, 500].
 */
function aiGatewayListRuns(
    int $tenantId,
    ?int $userId = null,
    ?string $agent = null,
    ?string $status = null,
    int $limit = 100
): array {
    $limit = max(1, min(500, $limit));
    $sql = 'SELECT id, agent_name, status, user_id, prompt_version,
                   input_summary, output_summary, error_code,
                   created_at, completed_at
              FROM ai_runs
             WHERE tenant_id = :t';
    $params = ['t' => $tenantId];
    if ($userId !== null) { $sql .= ' AND user_id = :u'; $params['u'] = $userId; }
    if ($agent  !== null && $agent  !== '') { $sql .= ' AND agent_name = :a';   $params['a'] = $agent; }
    if ($status !== null && in_array($status, ['queued','running','completed','failed','cancelled','awaiting_approval'], true)) {
        $sql .= ' AND status = :s'; $params['s'] = $status;
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['user_id'] = $r['user_id'] !== null ? (int) $r['user_id'] : null;
    }
    unset($r);
    return $rows;
}

/**
 * Write a canonical spec §15 audit event into the existing audit_log
 * table. Best-effort: failures are surfaced to system log but never
 * thrown back to the caller. The audit_log column convention is
 * (tenant_id, actor_user_id, event, target_id, meta_json, created_at);
 * we use `event` for the spec event name and bury the run/tool
 * payload in `meta_json`.
 */
function aiGatewayAuditEvent(int $tenantId, ?int $userId, string $event, array $payload): void
{
    try {
        getDB()->prepare(
            'INSERT INTO audit_log
                (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
             VALUES (:t, :u, :e, NULL, :m, NOW())'
        )->execute([
            't' => $tenantId, 'u' => $userId, 'e' => $event,
            'm' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: null,
        ]);
    } catch (\Throwable $e) {
        error_log('[aiGatewayAuditEvent] ' . $event . ': ' . $e->getMessage());
    }
}
