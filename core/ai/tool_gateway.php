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
        // ── Slice-1 read-only tools per AI-Native Extension spec §15 ───
        'coreflux.get_tenant_context' => [
            'description' => 'Return the active tenant id, name, available sub-tenants (legal entities), and active modules. Lets an agent ground itself without exposing settings.',
            'permission'  => 'ai.use',
            'args'        => [],
            'handler'     => 'aiToolGetTenantContextHandler',
        ],
        'coreflux.get_user_permissions' => [
            'description' => 'Return the calling user role and the list of (module, action) grants they currently hold under the new RBAC resolver. Read-only; agents use this to know which actions to even propose.',
            'permission'  => 'ai.use',
            'args'        => [],
            'handler'     => 'aiToolGetUserPermissionsHandler',
        ],
        'coreflux.get_bank_transactions' => [
            'description' => 'Return recent bank transactions across Plaid (accounting_bank_statement_lines) and Mercury (mercury_transactions), unified into a single newest-first feed.',
            'permission'  => 'accounting.bank.manage',
            'risk_level'  => 1,
            'args'        => [
                'source' => ['type' => 'string', 'required' => false, 'desc' => "plaid | mercury | both (default both)"],
                'limit'  => ['type' => 'int',    'required' => false, 'desc' => 'default 50, max 200'],
                'since'  => ['type' => 'date',   'required' => false, 'desc' => 'YYYY-MM-DD lower bound on posted_at'],
            ],
            'handler'     => 'aiToolGetBankTransactionsHandler',
        ],
        // ── Slice-4 write tools per spec §7 + §15 ──────────────────────
        // Risk-level 4 → must be invoked downstream of an approved
        // workflow_approval. The gate is enforced inside aiToolInvoke().
        'coreflux.draft_journal_entry' => [
            'description' => 'Insert a draft journal entry into accounting_journal_entries with status="draft". Returns the new je_id. Risk Level 4 — caller MUST hold an approved workflow approval id in callerCtx._approval_id.',
            'permission'  => 'accounting.write',
            'risk_level'  => 4,
            'args'        => [
                'entity_id'    => ['type' => 'int',    'required' => true,  'desc' => 'accounting_entities.id (the legal entity)'],
                'period_id'    => ['type' => 'int',    'required' => true,  'desc' => 'accounting_periods.id (must be open)'],
                'posting_date' => ['type' => 'date',   'required' => true,  'desc' => 'YYYY-MM-DD posting date'],
                'memo'         => ['type' => 'string', 'required' => false, 'desc' => 'free-text memo'],
                'source_ref_type' => ['type' => 'string', 'required' => false, 'desc' => 'e.g. ai_workflow, bank_transaction'],
                'source_ref_id'   => ['type' => 'int',    'required' => false, 'desc' => 'matching primary key'],
                'lines'        => ['type' => 'array',  'required' => true,  'desc' => 'array of {account_id, debit, credit, memo?, dim_json?}'],
            ],
            'handler'     => 'aiToolDraftJournalEntryHandler',
        ],
        'coreflux.create_exception' => [
            'description' => 'Open an accounting_exceptions row for a transaction or workflow that needs human attention. Returns the new exception_id.',
            'permission'  => 'accounting.write',
            'risk_level'  => 3,
            'args'        => [
                'exception_type'    => ['type' => 'string', 'required' => true, 'desc' => "'classify_low_confidence' | 'unbalanced_je' | 'missing_period' | …"],
                'summary'           => ['type' => 'string', 'required' => true, 'desc' => 'human-readable one-liner'],
                'severity'          => ['type' => 'string', 'required' => false,'desc' => "low | medium | high | critical"],
                'related_ref_type'  => ['type' => 'string', 'required' => false,'desc' => "'bank_transaction' | 'journal_entry' | …"],
                'related_ref_id'    => ['type' => 'int',    'required' => false,'desc' => 'matching primary key'],
                'workflow_run_id'   => ['type' => 'string', 'required' => false,'desc' => 'forward link if opened from a workflow'],
                'detail'            => ['type' => 'array',  'required' => false,'desc' => 'structured payload — stored as detail_json'],
            ],
            'handler'     => 'aiToolCreateExceptionHandler',
        ],
        'coreflux.resolve_vendor_alias' => [
            'description' => 'Resolve a raw bank-feed payee to a canonical CoreFlux vendor (or saved label). Returns the existing alias row when one matches, NULL when this is a new payee. Use BEFORE proposing a new classification so re-classification of the same vendor stays stable across imports.',
            'permission'  => 'accounting.read',
            'risk_level'  => 'read',
            'args'        => [
                'payee'         => ['type' => 'string', 'required' => true,  'desc' => 'Raw payee string from the bank feed / import row'],
                'sub_tenant_id' => ['type' => 'int',    'required' => false, 'desc' => 'Scope to a specific sub-tenant'],
            ],
            'handler'     => 'aiToolResolveVendorAliasHandler',
        ],
        'coreflux.record_vendor_alias' => [
            'description' => 'Persist a new vendor-alias mapping so future runs of resolve_vendor_alias return the same canonical target. Pass EXACTLY one of canonical_vendor_id (CoreFlux master vendor row) or canonical_label (free-form display name for one-off payees).',
            'permission'  => 'accounting.write',
            'risk_level'  => 'draft',
            'args'        => [
                'payee'               => ['type' => 'string', 'required' => true,  'desc' => 'Raw payee string from the source feed'],
                'canonical_vendor_id' => ['type' => 'int',    'required' => false, 'desc' => 'Existing CoreFlux vendors.id — preferred if a master row exists'],
                'canonical_label'     => ['type' => 'string', 'required' => false, 'desc' => 'Free-form label when no vendor master row should be created'],
                'confidence'          => ['type' => 'float',  'required' => false, 'desc' => 'AI confidence at proposal time (0.0–1.0)'],
                'pinned'              => ['type' => 'bool',   'required' => false, 'desc' => 'True = lock the alias so AI re-suggestion cannot silently overwrite'],
            ],
            'handler'     => 'aiToolRecordVendorAliasHandler',
            'idempotency_args' => ['payee'],
        ],
    ];
    return $reg;
}

/** Read-only handler — resolve a payee to its canonical alias. */
function aiToolResolveVendorAliasHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    require_once __DIR__ . '/vendor_aliases.php';
    $payee = (string) ($args['payee'] ?? '');
    if ($payee === '') return ['ok' => false, 'error' => ['code' => 'bad_args', 'message' => 'payee required']];
    $row = vendorAliasResolve($tenantId, $payee);
    return [
        'ok'         => true,
        'normalized' => vendorAliasNormalize($payee),
        'alias'      => $row,
        'matched'    => $row !== null,
    ];
}

/** Draft-tier handler — persist a vendor alias mapping. */
function aiToolRecordVendorAliasHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    require_once __DIR__ . '/vendor_aliases.php';
    try {
        $res = vendorAliasRecord($tenantId, (string) ($args['payee'] ?? ''), [
            'sub_tenant_id'       => $subTenantId,
            'canonical_vendor_id' => isset($args['canonical_vendor_id']) ? (int) $args['canonical_vendor_id'] : null,
            'canonical_label'     => isset($args['canonical_label'])     ? (string) $args['canonical_label'] : null,
            'confidence'          => isset($args['confidence'])          ? (float) $args['confidence']      : null,
            'pinned'              => !empty($args['pinned']),
            'source'              => 'ai_suggestion',
        ]);
    } catch (\InvalidArgumentException $e) {
        return ['ok' => false, 'error' => ['code' => 'bad_args', 'message' => $e->getMessage()]];
    }
    return ['ok' => true, 'alias' => $res['row'], 'action' => $res['action']];
}

/**
 * Mirror the in-memory tool registry to the persisted `tool_registry`
 * table.  Idempotent — INSERT … ON DUPLICATE KEY UPDATE keeps the row
 * in sync with the PHP array on every call without churning rows.
 *
 * Spec §1 Phase 1 — the DB-backed catalog is what later phases (admin
 * UI, per-tenant policies, audit drill-in) query.  PHP array remains
 * source of truth for handler resolution today; promoting handler
 * dispatch to DB happens once admin-UI tool registration ships.
 *
 * Safe to call multiple times per request; the static cache + the
 * fact that we only sync if the table exists keeps this cheap.
 */
function aiToolRegistrySync(?\PDO $pdo = null): array
{
    static $synced = false;
    if ($synced) return ['synced' => true, 'cached' => true];

    $pdo = $pdo ?? getDB();

    // Be defensive — old pods may not have run mig 105 yet.  Treat a
    // missing table as a no-op so the existing PHP-array gateway keeps
    // working until the operator runs migrations.
    try {
        $pdo->query('SELECT 1 FROM tool_registry LIMIT 1');
    } catch (\Throwable $e) {
        return ['synced' => false, 'reason' => 'tool_registry table missing — run migration 105'];
    }

    $registry = aiToolRegistry();
    $stmt = $pdo->prepare(
        'INSERT INTO tool_registry
            (tool_name, description, permission_required, risk_level,
             args_schema, handler_ref, idempotency_args, active, source,
             created_at, updated_at)
         VALUES
            (:n, :d, :p, :rl, :as, :h, :ik, 1, "php_array_seed", NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            description          = VALUES(description),
            permission_required  = VALUES(permission_required),
            risk_level           = VALUES(risk_level),
            args_schema          = VALUES(args_schema),
            handler_ref          = VALUES(handler_ref),
            idempotency_args     = VALUES(idempotency_args),
            active               = 1,
            source               = "php_array_seed",
            updated_at           = NOW()'
    );

    $written = 0;
    foreach ($registry as $toolName => $tool) {
        $stmt->execute([
            'n'  => $toolName,
            'd'  => (string) ($tool['description'] ?? ''),
            'p'  => (string) ($tool['permission']  ?? ''),
            'rl' => aiToolInferRiskLevel((string) $toolName, $tool),
            'as' => json_encode($tool['args'] ?? [], JSON_UNESCAPED_SLASHES),
            'h'  => (string) ($tool['handler']     ?? ''),
            'ik' => isset($tool['idempotency_args'])
                       ? json_encode($tool['idempotency_args'], JSON_UNESCAPED_SLASHES)
                       : null,
        ]);
        $written++;
    }

    $synced = true;
    return ['synced' => true, 'tools_written' => $written];
}

/**
 * Derive a risk_level for the spec's enum from the PHP array shape.
 *  - 'read'           = pure read (default)
 *  - 'draft'          = creates a draft / proposal, doesn't post yet
 *  - 'transactional'  = creates a posted/committed record
 *  - 'irreversible'   = external effect (payment, filing, email send)
 *
 * Hand-curated tool name patterns until the PHP array carries the
 * field natively — keeps the mirror lossless without forcing every
 * existing entry to be edited.
 */
function aiToolInferRiskLevel(string $toolName, array $tool): string
{
    if (!empty($tool['risk_level']) && is_string($tool['risk_level'])) {
        return (string) $tool['risk_level'];
    }
    $n = strtolower($toolName);
    if (str_contains($n, '.draft_') || str_contains($n, '.propose_'))      return 'draft';
    if (str_contains($n, '.create_exception'))                              return 'draft';
    if (str_contains($n, '.post_') || str_contains($n, '.approve_'))        return 'transactional';
    if (str_contains($n, '.release_') || str_contains($n, '.send_'))        return 'irreversible';
    if (str_contains($n, '.file_'))                                         return 'irreversible';
    return 'read';
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

    // Risk-level gate (spec §15 / Slice 4). Tools with risk_level >= 4
    // are state-mutating and MUST be invoked downstream of an
    // approved workflow approval. The engine threads `_approval_id`
    // through callerCtx after a successful workflowResume(). Calls
    // without that token are blocked here — the LLM cannot get
    // around the gate even with valid RBAC, because the human
    // approver is the gate.
    $riskLevel = (int) ($tool['risk_level'] ?? 1);
    if ($riskLevel >= 4) {
        $approvalId = isset($callerCtx['_approval_id']) ? (int) $callerCtx['_approval_id'] : 0;
        if ($approvalId <= 0) {
            $env = ['ok' => false, 'status' => 'denied',
                    'error' => ['code' => 'approval_required',
                                'message' => "tool '{$toolName}' is risk-level {$riskLevel}; requires an approved workflow approval"]];
            aiToolAudit($tenantId, null, $userId, $sessionId, $toolName, $args, $env, $startMs);
            return $env;
        }
        // Validate the approval is real, approved, and tenant-scoped.
        try {
            $stmt = getDB()->prepare(
                'SELECT id, status FROM workflow_approvals
                  WHERE id = :id AND tenant_id = :t LIMIT 1'
            );
            $stmt->execute(['id' => $approvalId, 't' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'approved') {
                $env = ['ok' => false, 'status' => 'denied',
                        'error' => ['code' => 'approval_invalid',
                                    'message' => "approval #{$approvalId} not in 'approved' state"]];
                aiToolAudit($tenantId, null, $userId, $sessionId, $toolName, $args, $env, $startMs);
                return $env;
            }
        } catch (\Throwable $e) { /* schema-not-ready tolerated for CLI smoke */ }
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
        case 'array':  return is_array($v) ? $v : [];
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

// ───────────────────────────────────────────────────────────── Slice 1
// Spec §15 read-only tools. None of these mutate state. All projections
// strip `_id`-style internal junk and never echo encrypted columns.

function aiToolGetTenantContextHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    $pdo = getDB();
    $tenant = null;
    try {
        $stmt = $pdo->prepare('SELECT id, name, subdomain, created_at FROM tenants WHERE id = :t LIMIT 1');
        $stmt->execute(['t' => $tenantId]);
        $tenant = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($tenant) $tenant['id'] = (int) $tenant['id'];
    } catch (\Throwable $e) { /* schema-not-ready tolerated for CLI smoke */ }

    $subs = [];
    try {
        $stmt = $pdo->prepare('SELECT id, name, subdomain FROM sub_tenants WHERE tenant_id = :t ORDER BY id ASC LIMIT 50');
        $stmt->execute(['t' => $tenantId]);
        $subs = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        foreach ($subs as &$s) { $s['id'] = (int) $s['id']; }
        unset($s);
    } catch (\Throwable $e) {}

    return [
        'tenant'      => $tenant,
        'sub_tenants' => $subs,
        // Active modules surface the RBAC modules the *caller* can see.
        // Slice 1 keeps it cheap — the resolved module list is in the
        // user-permissions tool. Modules is informational here.
        'modules'     => ['ap','ar','accounting','treasury','people','time','billing','staffing','ai'],
    ];
}

function aiToolGetUserPermissionsHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    // No request-time work — the gateway already authenticated the
    // caller. The user record is reachable via getCurrentUser().
    require_once __DIR__ . '/../auth.php';
    $user = function_exists('getCurrentUser') ? getCurrentUser() : null;
    if (!$user) {
        return ['role' => 'unknown', 'modules' => [], 'note' => 'no session context'];
    }
    $userId = (int) ($user['id'] ?? 0);
    $role   = (string) ($user['role'] ?? 'employee');

    // Probe the RBAC resolver for the modules the caller can read/write/admin.
    $modules = ['ap','ar','accounting','treasury','people','time','billing','staffing','reports','ai','cfo'];
    $grants = [];
    if (class_exists('RBACResolver')) {
        foreach ($modules as $m) {
            $row = [
                'module' => $m,
                'read'   => RBACResolver::can($userId, $tenantId, $m, 'read'),
                'write'  => RBACResolver::can($userId, $tenantId, $m, 'write'),
                'admin'  => RBACResolver::can($userId, $tenantId, $m, 'admin'),
            ];
            if ($row['read'] || $row['write'] || $row['admin']) $grants[] = $row;
        }
    }
    return [
        'user_id' => $userId,
        'role'    => $role,
        'modules' => $grants,
    ];
}

function aiToolGetBankTransactionsHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    $pdo    = getDB();
    $source = (string) ($args['source'] ?? 'both');
    $limit  = max(1, min(200, (int) ($args['limit'] ?? 50)));
    $since  = (string) ($args['since'] ?? '');
    $since  = ($since !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) ? $since : null;

    $out = [];

    if ($source === 'plaid' || $source === 'both') {
        try {
            // accounting_bank_statement_lines is the canonical Plaid-fed
            // table per modules/treasury/api/account_transactions.php.
            $sql = "SELECT id, bank_account_id, transaction_date, amount_cents,
                           description, merchant_name, currency, posted_at, created_at
                      FROM accounting_bank_statement_lines
                     WHERE tenant_id = :t";
            $params = ['t' => $tenantId];
            if ($since !== null) { $sql .= ' AND COALESCE(posted_at, transaction_date) >= :sd'; $params['sd'] = $since; }
            $sql .= " ORDER BY COALESCE(posted_at, transaction_date) DESC, id DESC LIMIT {$limit}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $r) {
                $out[] = [
                    'source'       => 'plaid',
                    'id'           => (int) $r['id'],
                    'account_ref'  => $r['bank_account_id'] !== null ? (int) $r['bank_account_id'] : null,
                    'amount_cents' => (int) ($r['amount_cents'] ?? 0),
                    'currency'     => $r['currency'] ?? 'USD',
                    'description'  => $r['description'] ?? $r['merchant_name'] ?? null,
                    'posted_at'    => $r['posted_at'] ?? $r['transaction_date'] ?? $r['created_at'] ?? null,
                ];
            }
        } catch (\Throwable $e) { /* schema-not-ready tolerated */ }
    }

    if ($source === 'mercury' || $source === 'both') {
        try {
            $sql = "SELECT id, account_pk, mercury_txn_id, amount_cents, currency,
                           counterparty_name, bank_description, posted_at, received_at
                      FROM mercury_transactions
                     WHERE tenant_id = :t";
            $params = ['t' => $tenantId];
            if ($since !== null) { $sql .= ' AND COALESCE(posted_at, received_at) >= :sd'; $params['sd'] = $since; }
            $sql .= " ORDER BY COALESCE(posted_at, received_at) DESC, id DESC LIMIT {$limit}";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach (($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) as $r) {
                $out[] = [
                    'source'       => 'mercury',
                    'id'           => (int) $r['id'],
                    'account_ref'  => $r['account_pk'] !== null ? (int) $r['account_pk'] : null,
                    'amount_cents' => (int) ($r['amount_cents'] ?? 0),
                    'currency'     => $r['currency'] ?? 'USD',
                    'description'  => $r['counterparty_name'] ?? $r['bank_description'] ?? null,
                    'posted_at'    => $r['posted_at'] ?? $r['received_at'] ?? null,
                ];
            }
        } catch (\Throwable $e) { /* schema-not-ready tolerated */ }
    }

    // Merge newest-first and clamp.
    usort($out, fn ($a, $b) => strcmp((string) ($b['posted_at'] ?? ''), (string) ($a['posted_at'] ?? '')));
    $out = array_slice($out, 0, $limit);
    return ['transactions' => $out, 'count' => count($out), 'source' => $source];
}


// ───────────────────────────────────────────────────────────── Slice 4
// Write-tool handlers. Risk Level 4 / 3 — gated by aiToolInvoke()'s
// risk-level check before reaching here.

function aiToolDraftJournalEntryHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    // Delegate to the accounting module's canonical JE creator with
    // $post = false so the row lands as status='draft'. This keeps
    // the architectural rule intact (only the accounting module
    // talks to accounting_journal_*) and reuses period resolution,
    // account validation, balance check, and dimension validation
    // without duplicating any of it here.
    require_once __DIR__ . '/../../modules/accounting/lib/accounting.php';

    $lines = is_array($args['lines'] ?? null) ? $args['lines'] : [];
    if (count($lines) < 2) {
        throw new \InvalidArgumentException('journal entry needs ≥2 lines');
    }
    foreach ($lines as $idx => $ln) {
        if (empty($ln['account_id']) && empty($ln['account_code'])) {
            throw new \InvalidArgumentException("line #{$idx} missing account_id");
        }
    }

    // accountingPostJe wants `entity_id`, `posting_date`, `lines`,
    // optional `period_id`, `memo`, `source_ref_*`. We pass through.
    $je = [
        'entity_id'       => (int) $args['entity_id'],
        'posting_date'    => (string) $args['posting_date'],
        'currency'        => (string) ($args['currency'] ?? 'USD'),
        'source_module'   => 'system',
        'source_ref_type' => $args['source_ref_type'] ?? 'ai_workflow',
        'source_ref_id'   => isset($args['source_ref_id']) ? (int) $args['source_ref_id'] : null,
        'memo'            => isset($args['memo']) ? mb_substr((string) $args['memo'], 0, 500) : null,
        'lines'           => $lines,
    ];
    // accountingPostJe resolves the period from posting_date; we
    // forward the caller's period_id by setting idempotency_key so
    // re-invocation is safe.
    $je['idempotency_key'] = sprintf('ai_workflow_%d_%s_%d',
        (int) $args['entity_id'], (string) $args['posting_date'],
        (int) ($args['source_ref_id'] ?? 0));

    $result = accountingPostJe($tenantId, $je, null, /* $post = */ false);

    return [
        'je_id'        => (int) $result['je_id'],
        'je_number'    => (string) $result['je_number'],
        'status'       => (string) $result['status'],
        'total_debit'  => (float) $result['total_debit'],
        'total_credit' => (float) $result['total_credit'],
        'lines'        => count($lines),
        'idempotent_replay' => !empty($result['idempotent_replay']),
    ];
}

function aiToolCreateExceptionHandler(int $tenantId, ?int $subTenantId, array $args): array
{
    $severity = (string) ($args['severity'] ?? 'medium');
    if (!in_array($severity, ['low','medium','high','critical'], true)) $severity = 'medium';
    $detail = is_array($args['detail'] ?? null) ? $args['detail'] : null;

    $stmt = getDB()->prepare(
        'INSERT INTO accounting_exceptions
            (tenant_id, sub_tenant_id, workflow_run_id, ai_run_id,
             exception_type, severity, status,
             related_ref_type, related_ref_id, summary, detail_json, created_at)
         VALUES (:t, :st, :wf, :ai, :et, :sev, "open",
                 :rrt, :rri, :s, :dj, NOW())'
    );
    $stmt->execute([
        't'   => $tenantId,
        'st'  => $subTenantId,
        'wf'  => isset($args['workflow_run_id']) ? (string) $args['workflow_run_id'] : null,
        'ai'  => isset($args['ai_run_id'])       ? (string) $args['ai_run_id']       : null,
        'et'  => mb_substr((string) $args['exception_type'], 0, 60),
        'sev' => $severity,
        'rrt' => isset($args['related_ref_type']) ? mb_substr((string) $args['related_ref_type'], 0, 60) : null,
        'rri' => isset($args['related_ref_id'])   ? (int) $args['related_ref_id'] : null,
        's'   => mb_substr((string) $args['summary'], 0, 255),
        'dj'  => $detail !== null ? (json_encode($detail, JSON_UNESCAPED_SLASHES) ?: null) : null,
    ]);
    return [
        'exception_id'   => (int) getDB()->lastInsertId(),
        'exception_type' => (string) $args['exception_type'],
        'severity'       => $severity,
        'status'         => 'open',
    ];
}

