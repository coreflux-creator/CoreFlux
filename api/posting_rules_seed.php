<?php
/**
 * Posting-rules seed endpoint (Sprint 7c.1).
 *
 *   POST /api/posting_rules_seed.php
 *     → seeds the 17 system accounts (idempotent) + the default posting-
 *       rule pack (idempotent). Returns counts.
 *
 * RBAC: requires accounting.manage_posting_rules. Admin-gated since seed
 * pack edits the tenant's COA + posting-rule wiring.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../core/accounting/system_accounts.php';
require_once __DIR__ . '/../core/posting_engine/seed_defaults.php';

$ctx = api_require_auth();
$tid = (int) $ctx['tenant_id'];
RBAC::requirePermission($ctx['user'], 'accounting.manage_posting_rules');

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$accounts = accountingSeedSystemAccounts($tid);
$rules    = postingRulesSeedDefaults($tid);

api_ok([
    'tenant_id' => $tid,
    'accounts'  => $accounts,
    'rules'     => $rules,
]);
