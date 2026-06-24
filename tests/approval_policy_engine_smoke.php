<?php
/**
 * Smoke — Approval policy engine (SoD threshold rules) for Mercury
 * payment_instructions.
 *
 * Locks in:
 *   1. Migration 072 schema (tenant_approval_policies +
 *      payment_instruction_approvals + cool_off_until column)
 *   2. Core resolve / upsert / record-ack helpers exist with the right
 *      signatures and reject invalid input
 *   3. mpApprove() consults the engine, demands required role, requires
 *      N distinct acks, sets cool_off_until, and the auto-advance
 *      defers when the cool-off hasn't elapsed
 *   4. Cron worker skips rows whose cool_off_until is in the future
 *   5. /api/admin/treasury/approval_policies.php CRUD wiring
 *   6. UI page exists, route registered, action card linked
 *
 * Pure static + function-exists assertions (no DB round-trip). Unit
 * validation of the policy resolver is covered via a stubbed config
 * since the SQL engine is pure data-driven by row content.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/approval_policy.php';

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$mig    = (string) file_get_contents($root . '/core/migrations/072_mercury_approval_policies.sql');
$policy = (string) file_get_contents($root . '/core/approval_policy.php');
$svc    = (string) file_get_contents($root . '/core/mercury_payments.php');
$crn    = (string) file_get_contents($root . '/cron/mercury_payment_worker.php');
$apiF   = (string) file_get_contents($root . '/api/admin/treasury/approval_policies.php');
$ui     = (string) file_get_contents($root . '/dashboard/src/pages/ApprovalPoliciesAdmin.jsx');
$adm    = (string) file_get_contents($root . '/dashboard/src/pages/AdminModule.jsx');

echo "\n1. Migration 072 — schema shape\n";
$a('creates tenant_approval_policies',
    str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_approval_policies'));
foreach ([
    'min_amount_cents', 'max_amount_cents',
    'required_approver_role', 'min_approvers', 'cool_off_minutes',
    'applies_to_recipient_id', 'applies_to_account_id', 'sort_order',
] as $col) {
    $a("tenant_approval_policies has column '{$col}'", str_contains($mig, $col));
}
$a('creates payment_instruction_approvals',
    str_contains($mig, 'CREATE TABLE IF NOT EXISTS payment_instruction_approvals'));
$a('co-approval table enforces one ack per (instruction, user)',
    str_contains($mig, 'UNIQUE KEY uq_inst_user'));
$a('payment_instructions gains cool_off_until column',
    str_contains($mig, 'ADD COLUMN cool_off_until DATETIME NULL'));
$a('cool_off_until indexed for cron scans',
    str_contains($mig, 'idx_pi_cool_off'));

echo "\n2. Core helpers — public surface\n";
foreach ([
    'approvalPolicyList',     'approvalPolicyUpsert',
    'approvalPolicyDelete',   'approvalPolicyGet',
    'approvalPolicyResolve',  'approvalRecordAck',
    'approvalListAcksFor',
] as $fn) {
    $a("function {$fn} exported", function_exists($fn));
}
$a('Upsert validates min ≤ max',
    str_contains($policy, 'min_amount_cents must be <= max_amount_cents'));
$a('Upsert clamps min_approvers to 1..5',
    str_contains($policy, 'max(1, min(5, (int) ($data[\'min_approvers\'] ?? 1)))'));
$a('Upsert clamps cool_off_minutes to >= 0',
    str_contains($policy, 'max(0, (int) ($data[\'cool_off_minutes\'] ?? 0))'));
$a('Resolve uses distinct named placeholders for amount range',
    str_contains($policy, ':amt_min')
    && str_contains($policy, ':amt_max')
    && !preg_match('/<= :a\\b/', $policy));
$a('Resolve orders by specificity (recipient first, then account)',
    str_contains($policy, '(applies_to_recipient_id IS NOT NULL) DESC,')
    && str_contains($policy, '(applies_to_account_id   IS NOT NULL) DESC,'));
$a('RecordAck tolerates duplicate (user already acked) gracefully',
    str_contains($policy, "if (!str_contains(\$e->getMessage(), 'Duplicate')) throw \$e;"));

echo "\n3. Upsert input validation (unit)\n";
try { approvalPolicyUpsert(1, ['name' => ''], null); $a('blank name rejected', false); }
catch (\InvalidArgumentException $e) { $a('blank name rejected', str_contains($e->getMessage(), 'name required')); }
try { approvalPolicyUpsert(1, ['name' => 'x', 'integration' => 'bogus'], null); $a('unknown integration rejected', false); }
catch (\InvalidArgumentException $e) { $a('unknown integration rejected', str_contains($e->getMessage(), 'integration not supported')); }
try { approvalPolicyUpsert(1, ['name' => 'x', 'min_amount_cents' => 50000, 'max_amount_cents' => 100], null);
      $a('inverted band rejected', false); }
catch (\InvalidArgumentException $e) { $a('inverted band rejected', str_contains($e->getMessage(), 'min_amount_cents must be <= max_amount_cents')); }
try { approvalPolicyUpsert(1, ['name' => 'x', 'required_approver_role' => 'janitor'], null);
      $a('unknown role rejected', false); }
catch (\InvalidArgumentException $e) { $a('unknown role rejected', str_contains($e->getMessage(), 'required_approver_role unknown')); }

echo "\n4. mpApprove() — policy engine wiring\n";
$a('mpApprove resolves a policy before transitioning',
    str_contains($svc, '$policy = approvalPolicyResolve(')
    && strpos($svc, '$policy = approvalPolicyResolve(') < strpos($svc, "mpTransition(\$tenantId, \$id, 'Approved'"));
$a('mpApprove asserts required role via user object (not user_tenants read)',
    str_contains($svc, "(string) (\$approverUser['role'] ?? '') === \$requiredRole")
    && !preg_match('/FROM user_tenants[^;]*role = :r/', $svc));
$a('mpApprove records per-user ack on the co-approval chain',
    str_contains($svc, 'approvalRecordAck($tenantId, $id, $approverId'));
$a('mpApprove short-circuits when acks < min_approvers',
    str_contains($svc, '$ackCount < $minApprovers')
    && str_contains($svc, 'mercury.payment.coapproval_recorded')
    && str_contains($svc, 'return false; // not enough approvals yet'));
$a('mpApprove stamps cool_off_until when policy says so',
    str_contains($svc, "\$patch['cool_off_until'] = date('Y-m-d H:i:s', time() + \$coolOffMinutes * 60);"));
$a('mpApprove defers auto-advance when cool-off in the future',
    str_contains($svc, "strtotime(\$coolOff) > time()")
    && str_contains($svc, 'mercury.payment.cool_off_deferred'));

echo "\n5. Cron worker — cool-off enforcement\n";
$a('worker SELECT filters cool_off_until <= NOW()',
    str_contains($crn, 'cool_off_until IS NULL OR cool_off_until <= NOW()'));

echo "\n6. Admin API endpoint\n";
$a('strict_types declared',          str_contains($apiF, 'declare(strict_types=1)'));
$a('requires treasury.payment.approve',
    str_contains($apiF, "rbac_legacy_require(\$user, 'treasury.payment.approve')"));
$a('GET returns rows + roles + integrations',
    str_contains($apiF, "'rows'         => \$rows")
    && str_contains($apiF, "'roles'        => array_values"));
$a('POST routes through Upsert',     str_contains($apiF, "approvalPolicyUpsert(\$tid, \$body"));
$a('DELETE requires id query param', str_contains($apiF, "api_error('id required', 400)"));
$a('422 on validation failure',      str_contains($apiF, "api_error(\$e->getMessage(), 422)"));
$a('migration_pending fallback when table missing',
    str_contains($apiF, "tenant_approval_policies") && str_contains($apiF, "'migration_pending' => true"));

echo "\n7. UI — admin page wired\n";
$a('page testid present', str_contains($ui, 'data-testid="approval-policies-admin"'));
$a('table has empty-state copy',
    str_contains($ui, 'No policies configured'));
$a('form save button',    str_contains($ui, 'data-testid="approval-policy-save"'));
$a('form min/max inputs', str_contains($ui, 'data-testid="approval-policy-min-input"')
    && str_contains($ui, 'data-testid="approval-policy-max-input"'));
$a('form role select',    str_contains($ui, 'data-testid="approval-policy-role-select"'));
$a('form min-approvers',  str_contains($ui, 'data-testid="approval-policy-min-approvers-input"'));
$a('form cool-off',       str_contains($ui, 'data-testid="approval-policy-coolof-input"'));
$a('delete confirm copy mentions default rule',
    str_contains($ui, 'fall back to the default single-approver SoD rule'));
$a('migration banner rendered when migration_pending=true',
    str_contains($ui, 'data-testid="approval-policies-migration-banner"'));

echo "\n8. AdminModule wiring\n";
$a('ApprovalPoliciesAdmin imported',
    str_contains($adm, "import ApprovalPoliciesAdmin from './ApprovalPoliciesAdmin'"));
$a('Route /admin/treasury/approval-policies registered',
    str_contains($adm, '<Route path="/treasury/approval-policies"'));
$a('Action card on overview',
    str_contains($adm, 'Approval policies') && str_contains($adm, '/admin/treasury/approval-policies'));

echo "\n9. PHP syntax\n";
foreach ([
    $root . '/core/approval_policy.php',
    $root . '/core/mercury_payments.php',
    $root . '/cron/mercury_payment_worker.php',
    $root . '/api/admin/treasury/approval_policies.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Approval policy engine smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
