<?php
/**
 * Payroll — Gusto Track B sync.
 *
 *   POST /modules/payroll/api/gusto_sync.php?action=employees
 *   POST /modules/payroll/api/gusto_sync.php?action=pay_schedules
 *   POST /modules/payroll/api/gusto_sync.php?action=compensations
 *   POST /modules/payroll/api/gusto_sync.php?action=webhook_subscribe
 *   POST /modules/payroll/api/gusto_sync.php?action=all
 *
 * Each action returns { synced, skipped, failed, errors[], details[] }.
 * Gated by `payroll.run.disburse`. Idempotent — safe to call from cron.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/gusto_track_b.php';

$ctx = api_require_auth();
RBAC::requirePermission($ctx['user'], 'payroll.run.disburse');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$action = (string) ($_GET['action'] ?? '');
$conn = gustoActiveConnection((int) $ctx['tenant_id']);
if (!$conn || $conn['status'] !== 'active') {
    api_error('No active Gusto connection. Connect via Payroll → Settings first.', 409);
}

try {
    switch ($action) {
        case 'employees':
            api_ok(gustoSyncEmployees($conn));
        case 'pay_schedules':
            api_ok(gustoSyncPaySchedules($conn));
        case 'compensations':
            api_ok(gustoSyncCompensations($conn));
        case 'webhook_subscribe':
            api_ok(gustoEnsureWebhookSubscription($conn));
        case 'all': {
            $emp   = gustoSyncEmployees($conn);
            $sch   = gustoSyncPaySchedules($conn);
            $comp  = gustoSyncCompensations($conn);
            $hook  = gustoEnsureWebhookSubscription($conn);
            api_ok([
                'employees'         => $emp,
                'pay_schedules'     => $sch,
                'compensations'     => $comp,
                'webhook'           => $hook,
            ]);
        }
        default:
            api_error('action required: employees | pay_schedules | compensations | webhook_subscribe | all', 422);
    }
} catch (\Throwable $e) {
    api_error('Gusto sync failed: ' . $e->getMessage(), 502);
}
