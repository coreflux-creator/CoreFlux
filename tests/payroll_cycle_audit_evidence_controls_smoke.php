<?php
/**
 * Payroll cycle audit evidence controls smoke
 *
 * Pay-cycle advancement creates pay periods and draft payroll runs. Cycle
 * create/update/deactivate/advance events must therefore use the platform
 * audit writer and preserve reconstructable cycle/period/run evidence.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$containsAll = static function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, $needle)) return false;
    }
    return true;
};

$payroll = (string) file_get_contents($root . '/modules/payroll/lib/payroll.php');
$cycles = (string) file_get_contents($root . '/modules/payroll/lib/cycles.php');
$api = (string) file_get_contents($root . '/modules/payroll/api/cycles.php');
$auditDoc = (string) file_get_contents($root . '/docs/AUDIT_GOVERNANCE.md');
$alignmentDoc = (string) file_get_contents($root . '/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md');

echo "Payroll audit writer options\n";
$a('payrollAudit respects explicit tenant/actor options',
    $containsAll($payroll, [
        "array_key_exists('tenant_id', \$opts)",
        "array_key_exists('actor_user_id', \$opts)",
        'platformAuditLogWrite($tenantId, $actorUserId, $event, $targetId, $meta',
    ]));
$a('payrollAudit maps cycle events to payroll_cycle',
    $containsAll($payroll, [
        "if (str_contains(\$event, '.cycle.')) return 'payroll_cycle'",
        "'source' => \$meta['source'] ?? 'payroll'",
    ]));

echo "\nCycle audit helper\n";
$a('cycles lib loads payrollAudit and no longer inserts audit_log directly',
    $containsAll($cycles, [
        "require_once __DIR__ . '/payroll.php'",
        'function payrollAuditLight(string $event, array $meta = [], ?int $targetId = null, array $opts = [])',
        'payrollAudit($event, $meta, $targetId, $opts)',
    ])
    && !preg_match('/INSERT INTO audit_log/', $cycles)
    && !str_contains($cycles, 'currentTenantContext'));
$a('cycle/period/run audit row helpers exist',
    $containsAll($cycles, [
        'function payrollCycleAuditRow(int $tenantId, int $cycleId): ?array',
        'function payrollPayPeriodAuditRow(int $tenantId, int $periodId): ?array',
        'function payrollCycleRunAuditRow(int $tenantId, int $runId): ?array',
    ]));
$a('cycle advance captures before/after cycle, period, and run rows',
    $containsAll($cycles, [
        '$beforeCycle = $cycle',
        "payrollAuditLight('payroll.cycle.advanced'",
        "'before' => ['cycle' => \$beforeCycle]",
        "'cycle' => payrollCycleAuditRow(\$tenantId, \$cycleId)",
        "'period' => payrollPayPeriodAuditRow(\$tenantId, \$periodId)",
        "'run' => payrollCycleRunAuditRow(\$tenantId, \$runId)",
    ]));

echo "\nCycle API evidence\n";
$a('cycle API derives tenant and actor once',
    $containsAll($api, [
        '$tenantId = (int) $ctx[\'tenant_id\']',
        '$actorUserId = isset($ctx[\'user\'][\'id\']) ? (int) $ctx[\'user\'][\'id\'] : null',
    ]));
$a('cycle create captures after row',
    $containsAll($api, [
        "payrollAuditLight('payroll.cycle.created'",
        "'tenant_id' => \$tenantId",
        "'actor_user_id' => \$actorUserId",
        "'after' => payrollCycleAuditRow(\$tenantId, \$id)",
    ]));
$a('cycle update captures before/after rows',
    $containsAll($api, [
        '$before = payrollCycleAuditRow($tenantId, $id)',
        "payrollAuditLight('payroll.cycle.updated'",
        "'before' => \$before",
        "'after' => payrollCycleAuditRow(\$tenantId, \$id)",
    ]));
$a('cycle deactivate captures before/after rows',
    $containsAll($api, [
        "payrollAuditLight('payroll.cycle.deactivated'",
        "'before' => \$before",
        "'after' => payrollCycleAuditRow(\$tenantId, \$id)",
    ]));

echo "\nDocs\n";
$a('audit governance names pay-cycle generation',
    str_contains($auditDoc, 'pay-cycle generation'));
$a('architecture alignment records pay-cycle audit evidence',
    $containsAll($alignmentDoc, [
        'Pay-cycle create/update/deactivate/advance events',
        '`payrollAuditLight`',
        'emits cycle,',
        'period, and draft-run snapshots',
    ]));

echo "\nPayroll cycle audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
