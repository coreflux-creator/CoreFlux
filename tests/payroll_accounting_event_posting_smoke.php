<?php
/**
 * Payroll accounting must flow through the event registry and posting engine.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$assert = static function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$posting = $read('modules/payroll/lib/accounting_posting.php');
$defaults = $read('core/posting_engine/seed_defaults.php');
$seed = $read('core/seeds/posting_rules_seed_all.php');
$deployPath = $root . '/.github/workflows/deploy-light-workspace.yml';
$deploy = is_file($deployPath) ? (string) file_get_contents($deployPath) : null;

$assert('payroll uses the central posting engine',
    str_contains($posting, 'core/posting_engine/process.php')
    && substr_count($posting, 'accountingProcessEvent(') >= 2);
$assert('payroll accrual emits payroll.run.approved',
    str_contains($posting, "'payroll.run.approved'"));
$assert('payroll cash emits payroll.cash.disbursed',
    str_contains($posting, "'payroll.cash.disbursed'"));
$assert('payroll module never posts a journal directly',
    !str_contains($posting, 'accountingPostJe('));
$assert('events carry dynamic balanced lines',
    substr_count($posting, "'lines' =>") >= 2
    && str_contains($posting, "'account_code'")
    && str_contains($posting, "'debit'")
    && str_contains($posting, "'credit'"));
$assert('payroll lines carry worker, organization, assignment, and legal-entity dimensions',
    str_contains($posting, "'worker' =>")
    && str_contains($posting, "'department' =>")
    && str_contains($posting, "'branch' =>")
    && str_contains($posting, '$assignmentDimensions[\'legal_entity\']')
    && substr_count($posting, "'dims' => \$dimensions") >= 2);
$assert('default rules register both payroll event types',
    str_contains($defaults, "'payroll.run.approved'")
    && str_contains($defaults, "'payroll.cash.disbursed'")
    && substr_count($defaults, "'line_source'    => 'payload'") >= 2);
$assert('all-tenant seed installs system accounts and posting defaults',
    str_contains($seed, 'accountingSeedSystemAccounts($tenantId)')
    && str_contains($seed, 'postingRulesSeedDefaults($tenantId)')
    && str_contains($seed, 'FROM tenants WHERE is_active = 1'));
if ($deploy !== null) {
    $assert('deployment packages and executes the all-tenant seed',
        substr_count($deploy, 'core/seeds/posting_rules_seed_all.php') >= 2);
}

foreach ([
    'modules/payroll/lib/accounting_posting.php',
    'core/posting_engine/seed_defaults.php',
    'core/seeds/posting_rules_seed_all.php',
] as $path) {
    $output = [];
    $status = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $status);
    $assert("PHP parses: {$path}", $status === 0);
}

echo PHP_EOL . "Total: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
