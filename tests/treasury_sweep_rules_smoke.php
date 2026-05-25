<?php
/**
 * Smoke — Treasury sweep rules CRUD (P1 cash-allocation workflow).
 *
 * Locks in:
 *   1. Migration 073 (tenant_sweep_rules) schema shape
 *   2. core/sweep_rules.php helpers exist with input validation
 *   3. /api/admin/treasury/sweep_rules.php CRUD + RBAC gate
 *   4. SweepRulesAdmin.jsx UI affordances
 *   5. TreasuryModule route + nav tab wired
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/sweep_rules.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$mig  = (string) file_get_contents('/app/core/migrations/073_treasury_sweep_rules.sql');
$svc  = (string) file_get_contents('/app/core/sweep_rules.php');
$apiF = (string) file_get_contents('/app/api/admin/treasury/sweep_rules.php');
$ui   = (string) file_get_contents('/app/modules/treasury/ui/SweepRulesAdmin.jsx');
$tmod = (string) file_get_contents('/app/modules/treasury/ui/TreasuryModule.jsx');

echo "\n1. Migration 073 — schema shape\n";
$a('CREATE TABLE tenant_sweep_rules',
    str_contains($mig, 'CREATE TABLE IF NOT EXISTS tenant_sweep_rules'));
foreach ([
    'source_account_id', 'destination_account_id',
    'target_min_balance_cents', 'sweep_above_cents', 'frequency',
    'require_approval_policy_id',
    'last_run_at', 'last_outcome', 'last_run_amount_cents',
] as $col) {
    $a("column '{$col}' present", str_contains($mig, $col));
}
$a('frequency-aware index for the worker scan',
    str_contains($mig, 'idx_sweep_tenant_enabled (tenant_id, enabled, frequency)'));

echo "\n2. Core helpers + validation\n";
foreach (['sweepRuleList','sweepRuleGet','sweepRuleUpsert','sweepRuleDelete'] as $fn) {
    $a("function {$fn} exported", function_exists($fn));
}
$a('SWEEP_RULE_FREQUENCIES exposes weekly_fri + daily + monthly_15',
    in_array('weekly_fri', SWEEP_RULE_FREQUENCIES, true)
 && in_array('daily',      SWEEP_RULE_FREQUENCIES, true)
 && in_array('monthly_15', SWEEP_RULE_FREQUENCIES, true));

try { sweepRuleUpsert(1, ['name' => ''], null); $a('blank name rejected', false); }
catch (\InvalidArgumentException $e) { $a('blank name rejected', str_contains($e->getMessage(), 'name required')); }

try { sweepRuleUpsert(1, ['name' => 'x', 'source_account_id' => '', 'destination_account_id' => 'd'], null);
      $a('blank source rejected', false); }
catch (\InvalidArgumentException $e) { $a('blank source rejected', str_contains($e->getMessage(), 'source_account_id and destination_account_id required')); }

try { sweepRuleUpsert(1, ['name' => 'x', 'source_account_id' => 'a', 'destination_account_id' => 'a'], null);
      $a('source==dest rejected', false); }
catch (\InvalidArgumentException $e) { $a('source==dest rejected', str_contains($e->getMessage(), 'must be distinct accounts')); }

try { sweepRuleUpsert(1, ['name' => 'x', 'source_account_id' => 'a',
                          'destination_account_id' => 'b', 'frequency' => 'hourly'], null);
      $a('unknown frequency rejected', false); }
catch (\InvalidArgumentException $e) { $a('unknown frequency rejected', str_contains($e->getMessage(), 'frequency unknown')); }

try { sweepRuleUpsert(1, ['name' => 'x', 'source_account_id' => 'a',
                          'destination_account_id' => 'b', 'target_min_balance_cents' => -50], null);
      $a('negative target_min_balance rejected', false); }
catch (\InvalidArgumentException $e) { $a('negative target_min_balance rejected', str_contains($e->getMessage(), 'cannot be negative')); }

echo "\n3. Admin API endpoint\n";
$a('strict types declared',           str_contains($apiF, 'declare(strict_types=1)'));
$a('requires accounting.bank.manage', str_contains($apiF, "rbac_legacy_require(\$user, 'accounting.bank.manage')"));
$a('GET returns rows + frequencies',  str_contains($apiF, "'rows'        => sweepRuleList(\$tid)"));
$a('POST upserts via sweepRuleUpsert',str_contains($apiF, 'sweepRuleUpsert($tid, $body'));
$a('DELETE validates id',             str_contains($apiF, "api_error('id required', 400)"));
$a('422 on validation error',         str_contains($apiF, "api_error(\$e->getMessage(), 422)"));
$a('migration_pending fallback when table missing',
    str_contains($apiF, 'tenant_sweep_rules') && str_contains($apiF, "'migration_pending' => true"));

echo "\n4. UI page\n";
$a('section testid',  str_contains($ui, 'data-testid="sweep-rules-admin"'));
$a('table testid',    str_contains($ui, 'data-testid="sweep-rules-table"'));
$a('save button',     str_contains($ui, 'data-testid="sweep-rule-save"'));
$a('source input',    str_contains($ui, 'data-testid="sweep-rule-source-input"'));
$a('destination input', str_contains($ui, 'data-testid="sweep-rule-destination-input"'));
$a('keep-min input',  str_contains($ui, 'data-testid="sweep-rule-keep-input"'));
$a('above input',     str_contains($ui, 'data-testid="sweep-rule-above-input"'));
$a('frequency select',str_contains($ui, 'data-testid="sweep-rule-frequency-select"'));
$a('policy input',    str_contains($ui, 'data-testid="sweep-rule-policy-input"'));
$a('empty state',     str_contains($ui, 'data-testid="sweep-rules-empty"'));
$a('migration banner copy',
    str_contains($ui, 'data-testid="sweep-rules-migration-banner"'));

echo "\n5. TreasuryModule wiring\n";
$a('SweepRulesAdmin imported',
    str_contains($tmod, "import SweepRulesAdmin        from './SweepRulesAdmin'"));
$a('Route /sweep-rules registered',
    str_contains($tmod, '<Route path="sweep-rules"'));
$a('Tab link added',
    str_contains($tmod, '<TreasuryTab to="sweep-rules"'));

echo "\n6. PHP syntax\n";
foreach ([
    '/app/core/sweep_rules.php',
    '/app/api/admin/treasury/sweep_rules.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Treasury sweep rules smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
