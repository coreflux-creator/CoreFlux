<?php
/**
 * core/ai/tool_gateway.php — curated CoreFlux tool catalog for AI agents.
 *
 * Per spec §18: agents talk to CoreFlux, not directly to Jaz / QBO /
 * any backend. Tools are namespaced `coreflux.*` and ALWAYS go
 * through this gateway, which:
 *   1. Looks up the tool by canonical name in the registry.
 *   2. Enforces the tool's declared RBAC permission.
 *   3. Validates args against the tool's declared schema (light).
 *   4. Runs the tool handler — typically delegates to an existing
 *      CoreFlux service (Accounting adapter, AP/AR module, …).
 *   5. Records an audit row in ai_tool_invocations.
 *
 * Why a registry instead of one endpoint per tool? Spec demands the
 * catalog be DISCOVERABLE — agents fetch `coreflux.list_tools` to
 * know what's available — and uniformly AUDITABLE. A registry lets
 * a future LLM provider's "function_calling" interface dump a clean
 * JSON schema by walking `aiToolRegistry()`.
 *
 * Tools shipped in Slice 1 (read-only — agents can SEE the books):
 *   coreflux.list_tools                — discovery
 *   coreflux.get_chart_of_accounts     — list accounts (Jaz read)
 *   coreflux.get_trial_balance         — TB report (Jaz read)
 *   coreflux.get_general_ledger        — GL report (Jaz read)
 *   coreflux.list_outbox               — accounting outbox visibility
 *
 * Write tools (draft_bill, post_object, etc.) intentionally NOT
 * exposed yet — the approval gate needs human-in-the-loop before
 * agents can move money or post objects.
 */
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../RBAC.php';
require_once __DIR__ . '/../rbac/legacy_map.php';

/**
 * Returns the tool registry — declarative table of every callable.
 *
 * Each entry:
 *   - description: one-liner for agent discovery
 *   - permission:  legacy_map code the caller must hold
 *   - args:        light schema { name => ['type' => 'string|int|bool|date', 'required' => bool, 'desc' => '…'] }
 *   - handler:     callable(int $tenantId, ?int $subTenantId, array $args): array
 */
function aiToolRegistry(): array
{
    static $reg = null;
    if ($reg !== null) return $reg;

    $reg = [
        'coreflux.list_tools' => [
            'description' => 'List every CoreFlux tool the calling user can invoke. Returns name, description, and arg schema for each.',
            'permission'  => null, // anyone authenticated can discover
            'args'        => [],
            'handler'     => 'aiToolListToolsHandler',
        ],
        'coreflux.get_chart_of_accounts' => [
            'description' => 'Return the chart of accounts for a legal entity, from the active accounting backend.',
            'permission'  => 'accounting.connection.view',
            'args'        => [
                'sub_tenant_id' => ['type' => 'int',    'required' => true,  'desc' => 'Legal entity id'],
                'provider'      => ['type' => 'string', 'required' => false, 'desc' => "default 'jaz'"],
            ],
            'handler'     => 'aiToolGetChartOfAccountsHandler',
        ],
        'coreflux.get_trial_balance' => [
            'description' => 'Return the trial balance for a legal entity as of a given date.',
            'permission'  => 'accounting.connection.view',
            'args'        => [
                'sub_tenant_id' => ['type' => 'int',    'required' => true],
                'as_of'         => ['type' => 'date',   'required' => true, 'desc' => 'YYYY-MM-DD'],
                'provider'      => ['type' => 'string', 'required' => false],
            ],
            'handler'     => 'aiToolGetTrialBalanceHandler',
        ],
        'coreflux.get_general_ledger' => [
            'description' => 'Return the general ledger between two dates, optionally filtered by account.',
            'permission'  => 'accounting.connection.view',
            'args'        => [
                'sub_tenant_id' => ['type' => 'int',    'required' => true],
                'from'          => ['type' => 'date',   'required' => true],
                'to'            => ['type' => 'date',   'required' => true],
                'account'       => ['type' => 'string', 'required' => false, 'desc' => 'Jaz account resourceId'],
                'provider'      => ['type' => 'string', 'required' => false],
            ],
            'handler'     => 'aiToolGetGeneralLedgerHandler',
        ],
        'coreflux.list_outbox' => [
            'description' => 'List the accounting outbox rows for this tenant — useful for the agent to spot failed drafts.',
            'permission'  => 'accounting.connection.view',
            'args'        => [
                'status' => ['type' => 'string', 'required' => false, 'desc' => 'queued|processing|posted|failed|retrying|dead_letter'],
                'limit'  => ['type' => 'int',    'required' => false, 'desc' => 'default 25, max 100'],
            ],
            'handler'     => 'aiToolListOutboxHandler',
        ],
    ];
    return $reg;
}

/**
 * Invoke a tool by name. Returns a structured envelope so callers
 * never have to second-guess the shape:
 *   { ok: bool, status: 'ok'|'denied'|...,
 *     result?: any, error?: { code, message } }
 *
 * Records an audit row regardless of outcome.
 */
function aiToolInvoke(string $toolName, array $args, array $callerCtx): array
{
    $tenantId       = (int)  ($callerCtx['tenant_id']       ?? 0);
    $userId         = (int)  ($callerCtx['user_id']         ?? 0) ?: null;
    $sessionId      = (string) ($callerCtx['session_id']    ?? '');
    $userRow        = is_array($callerCtx['user'] ?? null) ? $callerCtx['user'] : ['role' => 'guest'];
    $startMs        = microtime(true);

    $registry = aiToolRegistry();
    if (!isset($registry[$toolName])) {
        $env = ['ok' => false, 'status' => 'validation_failed',
                'error' => ['code' => 'unknown_tool', 'message' => "no such tool '{$toolName}'"]];
        aiToolAudit($tenantId, null, $userId, $sessionId, $toolName, $args, $env, $startMs);
        return $env;
    }
    $tool = $registry[$toolName];

    // RBAC.
    if (!empty($tool['permission'])) {
        try { rbac_legacy_require($userRow, (string) $tool['permission']); }
        catch (\Throwable $e) {
            $env = ['ok' => false, 'status' => 'denied',
                    'error' => ['code' => 'rbac_denied', 'message' => 'permission required: ' . $tool['permission']]];
            aiToolAudit($tenantId, null, $userId, $sessionId, $toolName, $args, $env, $startMs);
            return $env;
        }
    }

    // Light arg validation.
    foreach (($tool['args'] ?? []) as $argName => $spec) {
        $required = (bool) ($spec['required'] ?? false);
        if ($required && (!array_key_exists($argName, $args) || $args[$argName] === '' || $args[$argName] === null)) {
            $env = ['ok' => false, 'status' => 'validation_failed',
                    'error' => ['code' => 'missing_arg', 'message' => "arg '{$argName}' required"]];
            aiToolAudit($tenantId, null, $userId, $sessionId, $toolName, $args, $env, $startMs);
            return $env;
        }
        // Type coercion (forgiving — agents often hand back strings).
        if (array_key_exists($argName, $args)) {
            $type = (string) ($spec['type'] ?? 'string');
            $args[$argName] = aiToolCoerceArg($args[$argName], $type);
        }
    }

    $subTenantId = isset($args['sub_tenant_id']) ? (int) $args['sub_tenant_id'] : null;
    try {
        $handler = $tool['handler'];
        $result = $handler($tenantId, $subTenantId, $args);
        $env = ['ok' => true, 'status' => 'ok', 'result' => $result];
    } catch (\InvalidArgumentException $e) {
        $env = ['ok' => false, 'status' => 'validation_failed',
                'error' => ['code' => 'invalid_arg', 'message' => substr($e->getMessage(), 0, 240)]];
    } catch (\Throwable $e) {
        $env = ['ok' => false, 'status' => 'provider_error',
                'error' => ['code' => 'handler_failed', 'message' => substr($e->getMessage(), 0, 240)]];
    }
    aiToolAudit($tenantId, $subTenantId, $userId, $sessionId, $toolName, $args, $env, $startMs);
    return $env;
}

function aiToolCoerceArg($v, string $type)
{
    switch ($type) {
        case 'int':    return is_numeric($v) ? (int)   $v : 0;
        case 'bool':   return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        case 'date':   return is_string($v) ? substr($v, 0, 10) : '';
        case 'string': default: return is_scalar($v) ? (string) $v : '';
    }
}

function aiToolAudit(
    int $tenantId, ?int $subTenantId, ?int $userId, string $sessionId,
    string $toolName, array $args, array $envelope, float $startMs
): void {
    try {
        $latency = (int) ((microtime(true) - $startMs) * 1000);
        $status  = (string) ($envelope['status'] ?? 'internal_error');
        $errCode = $envelope['error']['code']    ?? null;
        $errMsg  = $envelope['error']['message'] ?? null;
        // Compact result summary — never persist full bodies.
        $summary = null;
        if (!empty($envelope['result']) && is_array($envelope['result'])) {
            $summary = [];
            foreach (['as_of','from','to','total_debit_cents','total_credit_cents','provider','report_type'] as $k) {
                if (array_key_exists($k, $envelope['result'])) $summary[$k] = $envelope['result'][$k];
            }
            if (isset($envelope['result']['accounts']) && is_array($envelope['result']['accounts'])) {
                $summary['accounts_count'] = count($envelope['result']['accounts']);
            }
            if (isset($envelope['result']['lines']) && is_array($envelope['result']['lines'])) {
                $summary['lines_count'] = count($envelope['result']['lines']);
            }
            if (isset($envelope['result']['rows']) && is_array($envelope['result']['rows'])) {
                $summary['rows_count'] = count($envelope['result']['rows']);
            }
        }
        getDB()->prepare(
            'INSERT INTO ai_tool_invocations
                (tenant_id, sub_tenant_id, actor_user_id, agent_session_id,
                 tool_name, args_json, status, latency_ms,
                 error_code, error_message, result_summary)
             VALUES (:t, :st, :uid, :sid, :tn, :aj, :s, :lat, :ec, :em, :rs)'
        )->execute([
            't'   => $tenantId, 'st' => $subTenantId, 'uid' => $userId,
            'sid' => $sessionId !== '' ? $sessionId : null,
            'tn'  => $toolName,
            'aj'  => json_encode(_aiToolRedactArgs($args), JSON_UNESCAPED_SLASHES),
            's'   => $status, 'lat' => $latency,
            'ec'  => $errCode, 'em' => $errMsg,
            'rs'  => $summary !== null ? json_encode($summary, JSON_UNESCAPED_SLASHES) : null,
        ]);
    } catch (\Throwable $e) {
        // Audit must never block the call. Surface to system log.
        error_log('[aiToolAudit] ' . $e->getMessage());
    }
}

/** Strip values that look like secrets before persisting args. */
function _aiToolRedactArgs(array $args): array
{
    $clean = [];
    foreach ($args as $k => $v) {
        if (preg_match('/(key|secret|token|password|cred)/i', $k)) {
            $clean[$k] = '[REDACTED]';
        } else {
            $clean[$k] = $v;
        }
    }
    return $clean;
}

// ============================================================ HANDLERS

function aiToolListToolsHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    $out = [];
    foreach (aiToolRegistry() as $name => $tool) {
        $out[] = [
            'name'        => $name,
            'description' => $tool['description'],
            'permission'  => $tool['permission'],
            'args'        => $tool['args'] ?? [],
        ];
    }
    return ['tools' => $out];
}

function aiToolGetChartOfAccountsHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    require_once __DIR__ . '/../accounting/provider_adapter.php';
    if (!$subTenantId) throw new \InvalidArgumentException('sub_tenant_id required');
    $provider = (string) ($args['provider'] ?? 'jaz');
    $adapter  = accountingProviderAdapterFor($provider);
    return $adapter->getChartOfAccounts($tenantId, $subTenantId, $args);
}

function aiToolGetTrialBalanceHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    require_once __DIR__ . '/../accounting/provider_adapter.php';
    if (!$subTenantId) throw new \InvalidArgumentException('sub_tenant_id required');
    $provider = (string) ($args['provider'] ?? 'jaz');
    $adapter  = accountingProviderAdapterFor($provider);
    return $adapter->getTrialBalance($tenantId, $subTenantId, ['asOf' => $args['as_of'] ?? null]);
}

function aiToolGetGeneralLedgerHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    require_once __DIR__ . '/../accounting/provider_adapter.php';
    if (!$subTenantId) throw new \InvalidArgumentException('sub_tenant_id required');
    $provider = (string) ($args['provider'] ?? 'jaz');
    $adapter  = accountingProviderAdapterFor($provider);
    return $adapter->getGeneralLedger($tenantId, $subTenantId, [
        'from'    => $args['from']    ?? null,
        'to'      => $args['to']      ?? null,
        'account' => $args['account'] ?? null,
    ]);
}

function aiToolListOutboxHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    $status = (string) ($args['status'] ?? '');
    $limit  = max(1, min(100, (int) ($args['limit'] ?? 25)));
    $sql = "SELECT id, command_type, status, attempts, max_attempts,
                   error_code, error_message, created_at
              FROM accounting_outbox_events
             WHERE tenant_id = :t";
    $params = ['t' => $tenantId];
    if ($status !== '' && in_array($status, ['queued','processing','posted','failed','retrying','dead_letter'], true)) {
        $sql .= ' AND status = :s';
        $params['s'] = $status;
    }
    $sql .= " ORDER BY id DESC LIMIT {$limit}";
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['attempts']     = (int) $r['attempts'];
        $r['max_attempts'] = (int) $r['max_attempts'];
    }
    unset($r);
    return ['rows' => $rows, 'count' => count($rows)];
}
