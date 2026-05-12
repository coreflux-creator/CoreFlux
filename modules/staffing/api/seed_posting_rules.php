<?php
/**
 * POST /api/staffing/seed_posting_rules
 *
 * One-shot admin action to install the three default journal templates +
 * posting rules for `staffing.worker_hours.approved`.
 *
 * Body: {} (empty)
 *
 * master_admin only. Idempotent — safe to re-run.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../core/api_bootstrap.php';
require_once __DIR__ . '/../lib/posting_rules_seed.php';

$ctx = api_require_role(['master_admin']);
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$tenantId = currentTenantId();
$result   = staffingSeedPostingRules($tenantId);

if (!($result['ok'] ?? false)) {
    api_error('Seed failed: ' . ($result['reason'] ?? 'unknown'), 422, $result);
}
api_ok($result);
