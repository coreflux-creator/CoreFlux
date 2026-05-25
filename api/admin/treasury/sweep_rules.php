<?php
/**
 * /api/admin/treasury/sweep_rules.php — CRUD for cash-allocation sweep rules.
 *
 *   GET    /api/admin/treasury/sweep_rules.php
 *            → { rows: [...], frequencies: [...] }
 *
 *   POST   /api/admin/treasury/sweep_rules.php
 *            body: { id?, name, source_account_id, destination_account_id,
 *                    target_min_balance_cents?, sweep_above_cents?,
 *                    frequency?, require_approval_policy_id?,
 *                    enabled?, sort_order?, notes? }
 *            → { row: {...} }
 *
 *   DELETE /api/admin/treasury/sweep_rules.php?id=42
 *            → { ok: true }
 *
 * RBAC: accounting.bank.manage (same perm as Mercury settings — only
 * users who can wire Mercury accounts up should be able to author the
 * sweep rules that move money between them).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../../../core/RBAC.php';
require_once __DIR__ . '/../../../core/sweep_rules.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
$method = api_method();

rbac_legacy_require($user, 'accounting.bank.manage');

try {
    switch ($method) {
        case 'GET':
            api_ok([
                'rows'        => sweepRuleList($tid),
                'frequencies' => SWEEP_RULE_FREQUENCIES,
            ]);

        case 'POST': {
            $body = api_json_body();
            try {
                $row = sweepRuleUpsert($tid, $body, $user['id'] ?? null);
            } catch (\InvalidArgumentException $e) {
                api_error($e->getMessage(), 422);
            }
            api_ok(['row' => $row]);
        }

        case 'DELETE': {
            $id = (int) (api_query('id') ?? 0);
            if ($id <= 0) api_error('id required', 400);
            $ok = sweepRuleDelete($tid, $id, $user['id'] ?? null);
            api_ok(['ok' => $ok]);
        }
    }
} catch (\PDOException $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'tenant_sweep_rules') && str_contains($msg, "doesn't exist")) {
        api_ok([
            'rows'              => [],
            'frequencies'       => SWEEP_RULE_FREQUENCIES,
            'migration_pending' => true,
            'migration_hint'    => 'Run /api/admin/migrate.php to create tenant_sweep_rules (migration 073).',
        ]);
    }
    error_log('[sweep_rules.php] PDOException: ' . $msg);
    api_error('Database error: ' . $msg, 500, ['code' => $e->getCode()]);
} catch (\Throwable $e) {
    error_log('[sweep_rules.php] ' . get_class($e) . ': ' . $e->getMessage());
    api_error('Server error: ' . $e->getMessage(), 500, ['class' => get_class($e)]);
}

api_error('Method not allowed', 405);
