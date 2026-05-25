<?php
/**
 * /api/mercury_payments.php — payment_instructions REST surface (Slice 3).
 *
 *   GET   /api/mercury_payments.php[?state=Approved]      → list
 *   GET   /api/mercury_payments.php?id=N                  → single + audit trail
 *   POST  /api/mercury_payments.php                       → create (Draft)
 *           body: { recipient_id, amount_cents, currency?, description?, notes?, source_module?, source_ref?, idempotency_key? }
 *   POST  /api/mercury_payments.php?action=submit&id=N    → Draft → PendingApproval
 *   POST  /api/mercury_payments.php?action=approve&id=N   → PendingApproval → Approved (SoD enforced)
 *   POST  /api/mercury_payments.php?action=reject&id=N    → PendingApproval → Draft
 *           body: { reason }
 *   POST  /api/mercury_payments.php?action=cancel&id=N    → → Cancelled
 *   POST  /api/mercury_payments.php?action=advance&id=N   → run the worker one step
 *           (manual trigger; cron does this automatically)
 *
 * RBAC: writes/actions gated by `accounting.bank.manage`. Reads accept
 * `accounting.bank.view` or `accounting.bank.manage`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/mercury_payments.php';

$ctx = api_require_auth();
$user = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

$method = api_method();
$action = (string) ($_GET['action'] ?? '');
$id     = (int) ($_GET['id'] ?? 0);

$canView   = rbac_legacy_can($user, 'accounting.bank.view')
          || rbac_legacy_can($user, 'accounting.bank.manage');
$canManage = rbac_legacy_can($user, 'accounting.bank.manage');

// ----------------------------------------------------------------- GET
if ($method === 'GET') {
    if (!$canView) api_error('Permission denied', 403);
    if ($id > 0) {
        try {
            $row = mpGet($tenantId, $id);
        } catch (\Throwable $e) { api_error('Not found', 404); }
        // Eager-load audit trail for the row.
        try {
            $pdo = getDB();
            $st  = $pdo->prepare(
                'SELECT from_state, to_state, reason, actor_user_id, meta_json, created_at
                   FROM payment_instruction_audit
                  WHERE tenant_id = :t AND instruction_id = :id
                  ORDER BY id'
            );
            $st->execute(['t' => $tenantId, 'id' => $id]);
            $audit = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) { $audit = []; }
        api_ok(['row' => $row, 'audit' => $audit]);
    }
    $opts = [];
    if (!empty($_GET['state'])) $opts['state'] = (string) $_GET['state'];
    api_ok(['rows' => mpList($tenantId, $opts)]);
}

if (!$canManage) api_error('Permission denied', 403);

// ----------------------------------------------------------------- POST create
if ($method === 'POST' && $action === '') {
    $body = api_json_body();
    try {
        $row = mpCreate($tenantId, $body, $user['id'] ?? null);
    } catch (\Throwable $e) {
        api_error($e->getMessage(), 422);
    }
    api_ok(['ok' => true, 'row' => $row], 201);
}

// ----------------------------------------------------------------- POST actions
if ($method !== 'POST' || $id <= 0) api_error('Method/action not allowed', 405);

try {
    switch ($action) {
        case 'submit':
            mpSubmitForApproval($tenantId, $id, $user['id'] ?? null);
            api_ok(['ok' => true, 'state' => 'PendingApproval']);
            break;
        case 'approve':
            $approveBody = api_json_body();
            // Dual-leg auto-trigger is on by default; smoke tests + ops
            // who want the legacy "Approved waiting for cron" behaviour
            // can opt out via {trigger_now: false} in the request body.
            $opts = [];
            if (array_key_exists('trigger_now', $approveBody)) {
                $opts['trigger_now'] = (bool) $approveBody['trigger_now'];
            }
            mpApprove($tenantId, $id, $user, $approveBody['note'] ?? null, $opts);
            // Re-read the row so the UI gets the actual state — typically
            // "Funding" once the dual-leg auto-trigger fired, or
            // "Approved" if the adapter wasn't reachable yet (worker will
            // retry on its next tick).
            $current = mpGet($tenantId, $id);
            api_ok([
                'ok'       => true,
                'state'    => (string) $current['state'],
                'auto_advanced' => $current['state'] !== 'Approved',
                'row'      => $current,
            ]);
            break;
        case 'reject':
            $reason = trim((string) (api_json_body()['reason'] ?? ''));
            if ($reason === '') api_error('reason required', 422);
            mpRejectToDraft($tenantId, $id, $user['id'] ?? null, $reason);
            api_ok(['ok' => true, 'state' => 'Draft']);
            break;
        case 'cancel':
            mpCancel($tenantId, $id, $user['id'] ?? null, api_json_body()['reason'] ?? null);
            api_ok(['ok' => true, 'state' => 'Cancelled']);
            break;
        case 'advance':
            $newState = mpAdvance($tenantId, $id);
            api_ok(['ok' => true, 'state' => $newState]);
            break;
        default:
            api_error('Unknown action: ' . $action, 422);
    }
} catch (\Throwable $e) {
    api_error($e->getMessage(), 422);
}
