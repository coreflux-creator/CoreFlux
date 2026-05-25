<?php
/**
 * /api/admin/treasury/approval_policies.php — CRUD for approval rules.
 *
 *   GET    /api/admin/treasury/approval_policies.php[?integration=mercury]
 *            → { rows: [...], roles: [...], integrations: [...] }
 *
 *   POST   /api/admin/treasury/approval_policies.php
 *            body: { id?, name, integration, enabled?, min_amount_cents?,
 *                    max_amount_cents?, required_approver_role?,
 *                    min_approvers?, cool_off_minutes?,
 *                    applies_to_recipient_id?, applies_to_account_id?,
 *                    sort_order?, notes? }
 *            → { row: {...} }
 *
 *   DELETE /api/admin/treasury/approval_policies.php?id=42
 *            → { ok: true }
 *
 * RBAC: treasury.payment.approve (matches the role that USES the
 * policies — admins who can approve should be the ones who can write
 * the rules that gate themselves).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/approval_policy.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

rbac_legacy_require($user, 'treasury.payment.approve');

try {
    switch ($method) {
        case 'GET': {
            $integration = trim((string) (api_query('integration') ?? 'mercury'));
            $rows = approvalPolicyList($tid, $integration);
            api_ok([
                'rows'         => $rows,
                'roles'        => array_values(array_filter(APPROVAL_POLICY_ROLES, static fn($r) => $r !== null && $r !== '')),
                'integrations' => APPROVAL_POLICY_INTEGRATIONS,
            ]);
        }

        case 'POST': {
            $body = api_json_body();
            try {
                $row = approvalPolicyUpsert($tid, $body, $user['id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
            api_ok(['row' => $row]);
        }

        case 'DELETE': {
            $id = (int) (api_query('id') ?? 0);
            if ($id <= 0) api_error('id required', 400);
            $ok = approvalPolicyDelete($tid, $id, $user['id'] ?? null);
            api_ok(['ok' => $ok]);
        }
    }
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'tenant_approval_policies') && str_contains($msg, "doesn't exist")) {
        api_ok([
            'rows'              => [],
            'roles'             => array_values(array_filter(APPROVAL_POLICY_ROLES, static fn($r) => $r !== null && $r !== '')),
            'integrations'      => APPROVAL_POLICY_INTEGRATIONS,
            'migration_pending' => true,
            'migration_hint'    => 'Run /api/admin/migrate.php to create tenant_approval_policies (migration 072).',
        ]);
    }
    error_log('[approval_policies.php] PDOException: ' . $msg);
    api_error('Database error: ' . $msg, 500, ['code' => $e->getCode()]);
} catch (\Throwable $e) {
    error_log('[approval_policies.php] ' . get_class($e) . ': ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}

api_error('Method not allowed', 405);
