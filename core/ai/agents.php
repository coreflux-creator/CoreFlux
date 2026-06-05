<?php
/**
 * core/ai/agents.php — Slice 7C Agent Registry + Handoff helpers.
 *
 * Spec §7 ("Agent Coordination"): named agents own a bundle of tools
 * + a system prompt. Handoffs let one agent delegate to another so
 * the Close Agent can pass a finished close packet to the Cash Agent
 * for forecasting, the AP Agent can hand a duplicate alert to the
 * Bookkeeping Agent, etc.
 *
 * Public API:
 *   agentRegistryUpsert  — register / refresh an agent
 *   agentRegistryGet     — read by id or by (tenant, key)
 *   agentRegistryList    — list, optionally filtered
 *   agentHandoffCreate   — open a handoff between two agents
 *   agentHandoffResolve  — accept / refuse / complete a handoff
 *   agentHandoffList     — recent handoffs, filterable
 *
 * tenant-leak-allow: every read / write is parameterized on tenant_id
 * (with NULL = platform-shared agent rows).
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';

const AGENT_HANDOFF_STATUSES = ['pending','accepted','refused','completed','cancelled'];

/**
 * Upsert an agent. Idempotent on (tenant_id NULL-safe, agent_key).
 * Use tenant_id=null to register a platform-shared agent.
 *
 * @return array {id, action: 'created'|'updated'}
 */
function agentRegistryUpsert(?int $tenantId, string $agentKey, array $opts = []): array
{
    $agentKey = trim($agentKey);
    if ($agentKey === '')   throw new \InvalidArgumentException('agent_key required');
    if (!preg_match('/^[a-z][a-z0-9_]{1,118}$/', $agentKey)) {
        throw new \InvalidArgumentException("agent_key must be snake_case ASCII ('$agentKey')");
    }
    $label = trim((string) ($opts['label'] ?? $agentKey));
    if ($label === '')      throw new \InvalidArgumentException('label required');

    $pdo = getDB();
    // tenant-leak-allow: platform-shared agents are intentionally tenant_id IS NULL
    $existing = $tenantId === null
        ? $pdo->prepare('SELECT id FROM agent_registry WHERE tenant_id IS NULL AND agent_key = :k LIMIT 1')
        : $pdo->prepare('SELECT id FROM agent_registry WHERE tenant_id = :t AND agent_key = :k LIMIT 1');
    if ($tenantId === null) $existing->execute(['k' => $agentKey]);
    else                    $existing->execute(['t' => $tenantId, 'k' => $agentKey]);
    $row = $existing->fetch(\PDO::FETCH_ASSOC);

    if ($row) {
        // tenant-leak-allow: row id was just resolved tenant-scoped above via $existing
        $pdo->prepare(
            'UPDATE agent_registry
                SET label              = :l,
                    description        = :d,
                    owner_module       = :om,
                    default_tools_json = :dt,
                    system_prompt      = :sp,
                    status             = :st,
                    updated_at         = NOW()
              WHERE id = :id'
        )->execute([
            'l'  => mb_substr($label, 0, 200),
            'd'  => isset($opts['description']) ? mb_substr((string) $opts['description'], 0, 2000) : null,
            'om' => $opts['owner_module'] ?? null,
            'dt' => isset($opts['default_tools']) ? json_encode($opts['default_tools'], JSON_UNESCAPED_SLASHES) : null,
            'sp' => $opts['system_prompt'] ?? null,
            'st' => in_array($opts['status'] ?? '', ['draft','active','retired'], true)
                        ? (string) $opts['status'] : 'active',
            'id' => (int) $row['id'],
        ]);
        return ['id' => (int) $row['id'], 'action' => 'updated'];
    }

    $pdo->prepare(
        'INSERT INTO agent_registry
            (tenant_id, agent_key, label, description, owner_module,
             default_tools_json, system_prompt, status,
             created_by_user_id, created_at, updated_at)
         VALUES
            (:t, :k, :l, :d, :om, :dt, :sp, :st, :cu, NOW(), NOW())'
    )->execute([
        't'  => $tenantId,
        'k'  => $agentKey,
        'l'  => mb_substr($label, 0, 200),
        'd'  => isset($opts['description']) ? mb_substr((string) $opts['description'], 0, 2000) : null,
        'om' => $opts['owner_module'] ?? null,
        'dt' => isset($opts['default_tools']) ? json_encode($opts['default_tools'], JSON_UNESCAPED_SLASHES) : null,
        'sp' => $opts['system_prompt'] ?? null,
        'st' => in_array($opts['status'] ?? '', ['draft','active','retired'], true)
                    ? (string) $opts['status'] : 'active',
        'cu' => isset($opts['created_by_user_id']) ? (int) $opts['created_by_user_id'] : null,
    ]);
    return ['id' => (int) $pdo->lastInsertId(), 'action' => 'created'];
}

function agentRegistryGet(int $agentId): ?array
{
    if ($agentId <= 0) return null;
    // tenant-leak-allow: admin lookup; caller handles tenant scope via list/getByKey above
    $stmt = getDB()->prepare('SELECT * FROM agent_registry WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $agentId]);
    return agentRegistryNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}

function agentRegistryGetByKey(?int $tenantId, string $agentKey): ?array
{
    // tenant-leak-allow: platform-shared agents are intentionally tenant_id IS NULL
    $stmt = $tenantId === null
        ? getDB()->prepare('SELECT * FROM agent_registry WHERE tenant_id IS NULL AND agent_key = :k LIMIT 1')
        : getDB()->prepare('SELECT * FROM agent_registry WHERE tenant_id = :t AND agent_key = :k LIMIT 1');
    if ($tenantId === null) $stmt->execute(['k' => $agentKey]);
    else                    $stmt->execute(['t' => $tenantId, 'k' => $agentKey]);
    return agentRegistryNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}

/**
 * List agents — pulls BOTH platform-shared (tenant_id IS NULL) AND
 * tenant-specific rows.  Filter by owner_module / status optionally.
 */
function agentRegistryList(?int $tenantId, array $filters = []): array
{
    $where  = ['(tenant_id IS NULL' . ($tenantId !== null ? ' OR tenant_id = :t' : '') . ')'];
    $params = [];
    if ($tenantId !== null) $params['t'] = $tenantId;
    if (!empty($filters['status'])) {
        $where[] = 'status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['owner_module'])) {
        $where[] = 'owner_module = :om'; $params['om'] = (string) $filters['owner_module'];
    }
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
    $stmt = getDB()->prepare(
        'SELECT * FROM agent_registry
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY (tenant_id IS NULL) ASC, agent_key ASC LIMIT ' . $limit
    );
    $stmt->execute($params);
    return array_map('agentRegistryNormalize', $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

/**
 * Open a handoff between two agents.  Looks them up by key first so
 * callers don't need internal IDs.
 *
 * @return array  The new handoff row.
 */
function agentHandoffCreate(int $tenantId, string $fromAgentKey, string $toAgentKey, array $opts = []): array
{
    if ($tenantId <= 0)             throw new \InvalidArgumentException('tenantId required');
    if ($fromAgentKey === '')       throw new \InvalidArgumentException('from_agent_key required');
    if ($toAgentKey === '')         throw new \InvalidArgumentException('to_agent_key required');
    if ($fromAgentKey === $toAgentKey) {
        throw new \InvalidArgumentException('from_agent_key and to_agent_key cannot be equal');
    }

    // Look up the agents — prefer tenant-specific over platform-shared.
    $from = agentRegistryGetByKey($tenantId, $fromAgentKey) ?? agentRegistryGetByKey(null, $fromAgentKey);
    $to   = agentRegistryGetByKey($tenantId, $toAgentKey)   ?? agentRegistryGetByKey(null, $toAgentKey);
    if (!$from) throw new \RuntimeException("from_agent_key '$fromAgentKey' not registered");
    if (!$to)   throw new \RuntimeException("to_agent_key '$toAgentKey' not registered");

    $pdo = getDB();
    $pdo->prepare(
        'INSERT INTO agent_handoffs
            (tenant_id, from_agent_id, to_agent_id, reason, payload_json,
             status, parent_workflow_run_id, parent_handoff_id,
             initiated_by_user_id, created_at)
         VALUES
            (:t, :f, :to, :r, :p, "pending", :wf, :ph, :u, NOW())'
    )->execute([
        't'  => $tenantId,
        'f'  => (int) $from['id'], 'to' => (int) $to['id'],
        'r'  => isset($opts['reason']) ? mb_substr((string) $opts['reason'], 0, 500) : null,
        'p'  => isset($opts['payload']) ? json_encode($opts['payload'], JSON_UNESCAPED_SLASHES) : null,
        'wf' => $opts['parent_workflow_run_id'] ?? null,
        'ph' => isset($opts['parent_handoff_id']) ? (int) $opts['parent_handoff_id'] : null,
        'u'  => isset($opts['initiated_by_user_id']) ? (int) $opts['initiated_by_user_id'] : null,
    ]);
    return agentHandoffGet($tenantId, (int) $pdo->lastInsertId());
}

/**
 * Resolve a handoff to accepted / refused / completed / cancelled.
 */
function agentHandoffResolve(int $tenantId, int $handoffId, string $newStatus, array $opts = []): array
{
    if ($tenantId <= 0 || $handoffId <= 0) throw new \InvalidArgumentException('tenantId + handoffId required');
    if (!in_array($newStatus, AGENT_HANDOFF_STATUSES, true)) {
        throw new \InvalidArgumentException("status must be one of: " . implode(',', AGENT_HANDOFF_STATUSES));
    }
    if ($newStatus === 'pending') {
        throw new \InvalidArgumentException("cannot resolve to 'pending'");
    }

    $existing = agentHandoffGet($tenantId, $handoffId);
    if (!$existing) throw new \RuntimeException("handoff #{$handoffId} not found");
    if ($existing['status'] !== 'pending' && $existing['status'] !== 'accepted') {
        throw new \RuntimeException("handoff #{$handoffId} is already '{$existing['status']}', cannot transition to '$newStatus'");
    }

    getDB()->prepare(
        'UPDATE agent_handoffs
            SET status              = :s,
                resolution_note     = :n,
                resolved_by_user_id = :u,
                resolved_at         = NOW()
          WHERE id = :id AND tenant_id = :t'
    )->execute([
        's' => $newStatus,
        'n' => isset($opts['note']) ? mb_substr((string) $opts['note'], 0, 1000) : null,
        'u' => isset($opts['resolved_by_user_id']) ? (int) $opts['resolved_by_user_id'] : null,
        'id'=> $handoffId, 't' => $tenantId,
    ]);
    return agentHandoffGet($tenantId, $handoffId);
}

function agentHandoffGet(int $tenantId, int $handoffId): ?array
{
    if ($tenantId <= 0 || $handoffId <= 0) return null;
    $stmt = getDB()->prepare(
        'SELECT h.*,
                fa.agent_key  AS from_agent_key,  fa.label  AS from_agent_label,
                ta.agent_key  AS to_agent_key,    ta.label  AS to_agent_label
           FROM agent_handoffs h
           JOIN agent_registry fa ON fa.id = h.from_agent_id
           JOIN agent_registry ta ON ta.id = h.to_agent_id
          WHERE h.id = :id AND h.tenant_id = :t LIMIT 1'
    );
    $stmt->execute(['id' => $handoffId, 't' => $tenantId]);
    return agentHandoffNormalize($stmt->fetch(\PDO::FETCH_ASSOC) ?: null);
}

function agentHandoffList(int $tenantId, array $filters = []): array
{
    if ($tenantId <= 0) return [];
    $where  = ['h.tenant_id = :t'];
    $params = ['t' => $tenantId];
    if (!empty($filters['status'])) {
        $where[] = 'h.status = :s'; $params['s'] = (string) $filters['status'];
    }
    if (!empty($filters['from_agent_key'])) {
        $where[] = 'fa.agent_key = :fk'; $params['fk'] = (string) $filters['from_agent_key'];
    }
    if (!empty($filters['to_agent_key'])) {
        $where[] = 'ta.agent_key = :tk'; $params['tk'] = (string) $filters['to_agent_key'];
    }
    $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
    $stmt = getDB()->prepare(
        'SELECT h.*,
                fa.agent_key  AS from_agent_key,  fa.label  AS from_agent_label,
                ta.agent_key  AS to_agent_key,    ta.label  AS to_agent_label
           FROM agent_handoffs h
           JOIN agent_registry fa ON fa.id = h.from_agent_id
           JOIN agent_registry ta ON ta.id = h.to_agent_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY h.id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);
    return array_map('agentHandoffNormalize', $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []);
}

/* ── Internal normalization. ──────────────────────────────────────── */

function agentRegistryNormalize(?array $row): ?array
{
    if (!$row) return null;
    foreach (['id','tenant_id','created_by_user_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['default_tools'] = $row['default_tools_json']
        ? (json_decode((string) $row['default_tools_json'], true) ?: [])
        : [];
    return $row;
}

function agentHandoffNormalize(?array $row): ?array
{
    if (!$row) return null;
    foreach (['id','tenant_id','from_agent_id','to_agent_id',
              'parent_handoff_id','initiated_by_user_id','resolved_by_user_id'] as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== null) $row[$k] = (int) $row[$k];
    }
    $row['payload'] = $row['payload_json']
        ? (json_decode((string) $row['payload_json'], true) ?: [])
        : [];
    return $row;
}
