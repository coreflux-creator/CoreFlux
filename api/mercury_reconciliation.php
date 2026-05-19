<?php
/**
 * /api/mercury_reconciliation.php — Slice 4 reconciliation REST surface.
 *
 *   GET  /api/mercury_reconciliation.php?action=stats   → summary tile data
 *   GET  /api/mercury_reconciliation.php?action=matches[&outcome=discrepancy][&instruction_id=N]
 *   POST /api/mercury_reconciliation.php?action=run     → run engine for tenant
 *
 * Reads need `accounting.bank.view` or `accounting.bank.manage`.
 * POST run needs `accounting.bank.manage`.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/mercury_reconciliation.php';

$ctx      = api_require_auth();
$user     = $ctx['user'];
$tenantId = (int) $ctx['tenant_id'];

$method = api_method();
$action = (string) ($_GET['action'] ?? 'stats');

$canView   = rbac_legacy_can($user, 'accounting.bank.view')
          || rbac_legacy_can($user, 'accounting.bank.manage');
$canManage = rbac_legacy_can($user, 'accounting.bank.manage');

if ($method === 'GET' && $action === 'stats') {
    if (!$canView) api_error('Permission denied', 403);
    api_ok(['stats' => mercuryReconciliationStats($tenantId)]);
}

if ($method === 'GET' && $action === 'matches') {
    if (!$canView) api_error('Permission denied', 403);
    $instr = !empty($_GET['instruction_id']) ? (int) $_GET['instruction_id'] : null;
    $oc    = !empty($_GET['outcome']) ? (string) $_GET['outcome'] : null;
    api_ok(['rows' => mercuryReconciliationMatches($tenantId, $instr, $oc)]);
}

if ($method === 'POST' && $action === 'run') {
    if (!$canManage) api_error('Permission denied', 403);
    $out = mercuryReconcileTenant($tenantId);
    // Best-effort audit
    try {
        $pdo = getDB();
        $pdo->prepare(
            'INSERT INTO audit_log (tenant_id, actor_user_id, event, target_id, meta_json, created_at)
             VALUES (:t, :u, "mercury.reconciliation.run", NULL, :m, NOW())'
        )->execute([
            't' => $tenantId, 'u' => $user['id'] ?? null, 'm' => json_encode($out),
        ]);
    } catch (\Throwable $e) {}
    api_ok(['ok' => true] + $out);
}

api_error('Method/action not allowed', 405);
