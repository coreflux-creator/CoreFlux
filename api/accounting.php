<?php
/**
 * /api/accounting.php — provider-neutral accounting backend router
 * (Slice 1, spec §15). The route is a single PHP file with an
 * action= dispatcher matching the rest of the CoreFlux API surface
 * (jobdiva.php, airtable.php, qbo.php, mercury_recipients.php).
 *
 * Connection management (§15.1):
 *   GET    ?action=status                  → current connection + scope
 *   POST   ?action=connect                 → upsert credentials (encrypted)
 *   POST   ?action=validate                → probe provider, persist status
 *   POST   ?action=rotate_key              → alias for connect (Mercury-style rotation)
 *   POST   ?action=disconnect              → revoke + zero secret
 *
 * Read APIs (§15.2):
 *   GET    ?action=chart_of_accounts&sub_tenant_id=…
 *   GET    ?action=trial_balance&as_of=YYYY-MM-DD
 *   GET    ?action=general_ledger&from=…&to=…&account=…
 *   GET    ?action=pnl|balance_sheet|ar_aging|ap_aging
 *
 * Command APIs (§15.3):
 *   POST   ?action=create_draft_bill | create_draft_invoice | create_draft_journal
 *   POST   ?action=approve_command  body:{ command_id }
 *   POST   ?action=execute_command  body:{ command_id }
 *   GET    ?action=command_status&command_id=N
 *
 * Provider selection: `provider` query param (defaults to 'jaz' in
 * Slice 1). All endpoints scope to the caller's active tenant.
 *
 * RBAC (per user confirmation — provider-neutral codes, NOT
 * accounting.jaz.*):
 *   accounting.connection.view     — GET status, reads
 *   accounting.connection.manage   — connect/validate/rotate/disconnect
 *   accounting.commands.draft      — create_draft_*
 *   accounting.commands.approve    — approve_command
 *   accounting.commands.execute    — execute_command
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/rbac/legacy_map.php';
require_once __DIR__ . '/../core/accounting/connection_service.php';
require_once __DIR__ . '/../core/accounting/command_service.php';
require_once __DIR__ . '/../core/accounting/provider_adapter.php';

$ctx     = api_require_auth();
$user    = $ctx['user'];
$tid     = (int) $ctx['tenant_id'];
$method  = api_method();
$action  = (string) ($_GET['action'] ?? '');
$provider = (string) ($_GET['provider'] ?? 'jaz');

function _accSubTenant(): int
{
    $st = (int) ($_GET['sub_tenant_id'] ?? 0);
    if (!$st) {
        $body = api_json_body_or_empty();
        $st = (int) ($body['sub_tenant_id'] ?? 0);
    }
    if ($st <= 0) api_error('sub_tenant_id required (legal entity id)', 422);
    return $st;
}
function api_json_body_or_empty(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = (string) file_get_contents('php://input');
    if ($raw === '') return $cache = [];
    $d = json_decode($raw, true);
    return $cache = is_array($d) ? $d : [];
}

// ============================================================================
// Connection Management — spec §15.1
// ============================================================================
if ($method === 'GET' && $action === 'status') {
    rbac_legacy_require($user, 'accounting.connection.view');
    $sub = _accSubTenant();
    api_ok(['connection' => accountingConnectionGet($tid, $sub, $provider)]);
}

// Tenant-wide rollup for the Integrations Hub badge. Returns whether
// ANY entity in the tenant has an active accounting connection +
// count by status. Does NOT leak secrets or per-entity detail.
if ($method === 'GET' && $action === 'tenant_status') {
    rbac_legacy_require($user, 'accounting.connection.view');
    try {
        $stmt = getDB()->prepare(
            'SELECT connection_status, COUNT(*) c
               FROM accounting_provider_connections
              WHERE tenant_id = :t AND provider = :p
              GROUP BY connection_status'
        );
        $stmt->execute(['t' => $tid, 'p' => $provider]);
        $by = []; $total = 0; $active = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
            $by[$r['connection_status']] = (int) $r['c'];
            $total += (int) $r['c'];
            if ($r['connection_status'] === 'active') $active = (int) $r['c'];
        }
        api_ok([
            'configured'         => $total > 0,
            'connected'          => $active > 0,
            'entities_total'     => $total,
            'entities_active'    => $active,
            'by_status'          => $by,
        ]);
    } catch (\Throwable $e) {
        api_ok(['configured' => false, 'connected' => false,
                'entities_total' => 0, 'entities_active' => 0,
                'by_status' => []]);
    }
}
if ($method === 'POST' && in_array($action, ['connect', 'rotate_key'], true)) {
    rbac_legacy_require($user, 'accounting.connection.manage');
    $body = api_json_body();
    $sub  = (int) ($body['sub_tenant_id'] ?? 0);
    if ($sub <= 0) api_error('sub_tenant_id required', 422);
    try {
        $conn = accountingConnectionUpsert($tid, $sub, $provider, $body, (int) ($user['id'] ?? 0) ?: null);
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('connect failed: ' . $e->getMessage(), 500);
    }
    api_ok(['connection' => $conn]);
}
if ($method === 'POST' && $action === 'validate') {
    rbac_legacy_require($user, 'accounting.connection.manage');
    $body = api_json_body();
    $sub  = (int) ($body['sub_tenant_id'] ?? 0);
    if ($sub <= 0) api_error('sub_tenant_id required', 422);
    try {
        api_ok(accountingConnectionValidate($tid, $sub, $provider));
    } catch (\Throwable $e) {
        api_error('validate failed: ' . $e->getMessage(), 500);
    }
}
if ($method === 'POST' && $action === 'disconnect') {
    rbac_legacy_require($user, 'accounting.connection.manage');
    $body = api_json_body();
    $sub  = (int) ($body['sub_tenant_id'] ?? 0);
    if ($sub <= 0) api_error('sub_tenant_id required', 422);
    accountingConnectionDisconnect($tid, $sub, $provider);
    api_ok(['ok' => true]);
}

// ============================================================================
// Read APIs — spec §15.2
// ============================================================================
$readActions = [
    'chart_of_accounts' => 'getChartOfAccounts',
    'trial_balance'     => 'getTrialBalance',
    'general_ledger'    => 'getGeneralLedger',
    'pnl'               => 'getProfitAndLoss',
    'balance_sheet'     => 'getBalanceSheet',
    'ar_aging'          => 'getArAging',
    'ap_aging'          => 'getApAging',
];
if ($method === 'GET' && isset($readActions[$action])) {
    rbac_legacy_require($user, 'accounting.connection.view');
    $sub = _accSubTenant();
    $filters = [
        'asOf'    => $_GET['as_of']   ?? null,
        'from'    => $_GET['from']    ?? null,
        'to'      => $_GET['to']      ?? null,
        'account' => $_GET['account'] ?? null,
    ];
    $adapter = accountingProviderAdapterFor($provider);
    $methodName = $readActions[$action];
    try {
        api_ok(['report' => $adapter->$methodName($tid, $sub, $filters)]);
    } catch (\Throwable $e) {
        api_error('read failed: ' . $e->getMessage(), 500);
    }
}

// ============================================================================
// Command APIs — spec §15.3
// ============================================================================
$draftMap = [
    'create_draft_bill'    => 'create_draft_bill',
    'create_draft_invoice' => 'create_draft_invoice',
    'create_draft_journal' => 'create_draft_journal',
];
if ($method === 'POST' && isset($draftMap[$action])) {
    rbac_legacy_require($user, 'accounting.commands.draft');
    $body = api_json_body();
    $sub  = (int) ($body['sub_tenant_id'] ?? 0);
    if ($sub <= 0) api_error('sub_tenant_id required', 422);
    $idem = (string) ($body['idempotency_key'] ?? '');
    if ($idem === '') api_error('idempotency_key required', 422);
    try {
        $cmd = accountingCommandEnqueue(
            $tid, $sub, $draftMap[$action],
            $body['payload'] ?? [],
            $idem,
            $body['source_event_id'] ?? null,
            (int) ($user['id'] ?? 0) ?: null,
            $provider
        );
    } catch (\InvalidArgumentException $e) {
        api_error($e->getMessage(), 422);
    } catch (\Throwable $e) {
        api_error('enqueue failed: ' . $e->getMessage(), 500);
    }
    api_ok(['command' => $cmd]);
}
if ($method === 'POST' && $action === 'approve_command') {
    rbac_legacy_require($user, 'accounting.commands.approve');
    $body = api_json_body();
    $cid  = (int) ($body['command_id'] ?? 0);
    if ($cid <= 0) api_error('command_id required', 422);
    try {
        api_ok(['command' => accountingCommandApprove($tid, $cid, (int) $user['id'])]);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
      catch (\Throwable $e)                  { api_error($e->getMessage(), 500); }
}
if ($method === 'POST' && $action === 'execute_command') {
    rbac_legacy_require($user, 'accounting.commands.execute');
    $body = api_json_body();
    $cid  = (int) ($body['command_id'] ?? 0);
    if ($cid <= 0) api_error('command_id required', 422);
    try {
        api_ok(['command' => accountingCommandExecute($tid, $cid)]);
    } catch (\InvalidArgumentException $e) { api_error($e->getMessage(), 422); }
      catch (\Throwable $e)                  { api_error($e->getMessage(), 500); }
}
if ($method === 'GET' && $action === 'command_status') {
    rbac_legacy_require($user, 'accounting.connection.view');
    $cid = (int) ($_GET['command_id'] ?? 0);
    if ($cid <= 0) api_error('command_id required', 422);
    $cmd = accountingCommandGetStatus($tid, $cid);
    if (!$cmd) api_error('command not found', 404);
    api_ok(['command' => $cmd]);
}

api_error("unknown action '{$action}' or wrong HTTP method", 400);
