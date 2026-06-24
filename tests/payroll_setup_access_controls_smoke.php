<?php
/**
 * Payroll setup/access controls smoke.
 *
 * Locks payroll setup endpoints behind explicit RBAC and audit events:
 * profiles, tenant settings, schedules, cycles, anomalies, stubs, and
 * advisory AI summaries.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "  ok    " : "  FAIL  ") . $label . PHP_EOL;
    if ($ok) $pass++; else $fail++;
};
$read = static fn(string $rel): string => (string) file_get_contents($root . '/' . $rel);
$lint = static function (string $rel) use ($root): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $rel) . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$profiles = $read('modules/payroll/api/profiles.php');
$settings = $read('modules/payroll/api/settings.php');
$schedules = $read('modules/payroll/api/pay_schedules.php');
$cycles = $read('modules/payroll/api/cycles.php');
$anomalies = $read('modules/payroll/api/anomalies.php');
$payStub = $read('modules/payroll/api/pay_stub.php');
$aiSummary = $read('modules/payroll/api/ai_run_summary.php');
$manifest = $read('modules/payroll/manifest.php');
$legacyMap = $read('core/rbac/legacy_map.php');
$mappingDoc = $read('memory/RBAC_B4_PERMISSION_MAPPING.md');

echo "Files parse" . PHP_EOL;
foreach ([
    'modules/payroll/api/profiles.php',
    'modules/payroll/api/settings.php',
    'modules/payroll/api/pay_schedules.php',
    'modules/payroll/api/cycles.php',
    'modules/payroll/api/anomalies.php',
    'modules/payroll/api/pay_stub.php',
    'modules/payroll/api/ai_run_summary.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo PHP_EOL . "RBAC gates" . PHP_EOL;
$a('profiles GET requires payroll.profiles.view',
    str_contains($profiles, "rbac_legacy_require(\$user, 'payroll.profiles.view')"));
$a('profiles writes require payroll.profiles.manage',
    substr_count($profiles, "rbac_legacy_require(\$user, 'payroll.profiles.manage')") >= 2);
$a('settings GET requires payroll.settings.view',
    str_contains($settings, "rbac_legacy_require(\$user, 'payroll.settings.view')"));
$a('settings writes require payroll.settings.manage',
    str_contains($settings, "rbac_legacy_require(\$user, 'payroll.settings.manage')"));
$a('schedules GET requires payroll.view',
    str_contains($schedules, "rbac_legacy_require(\$user, 'payroll.view')"));
$a('schedule mutations require payroll.schedules.manage',
    substr_count($schedules, "rbac_legacy_require(\$user, 'payroll.schedules.manage')") >= 3);
$a('cycles GET requires payroll.view',
    str_contains($cycles, "rbac_legacy_require(\$user, 'payroll.view')"));
$a('cycle mutations require payroll.cycles.manage',
    substr_count($cycles, "rbac_legacy_require(\$user, 'payroll.cycles.manage')") >= 5);
$a('anomaly reads require payroll.anomalies.view',
    str_contains($anomalies, "rbac_legacy_require(\$user, 'payroll.anomalies.view')"));
$a('anomaly detection requires payroll.anomalies.detect',
    str_contains($anomalies, "rbac_legacy_require(\$user, 'payroll.anomalies.detect')"));
$a('anomaly acknowledgement requires payroll.anomalies.acknowledge',
    str_contains($anomalies, "rbac_legacy_require(\$user, 'payroll.anomalies.acknowledge')"));
$a('pay stub requires payroll.view',
    str_contains($payStub, "rbac_legacy_require(\$ctx['user'], 'payroll.view')"));
$a('AI run summary requires payroll.view',
    str_contains($aiSummary, "rbac_legacy_require(\$ctx['user'], 'payroll.view')"));

echo PHP_EOL . "Audit events" . PHP_EOL;
$a('profile mutations audit create/update/disable',
    str_contains($profiles, "payrollAudit('payroll.profile.created'")
    && str_contains($profiles, "payrollAudit('payroll.profile.updated'")
    && str_contains($profiles, "payrollAudit('payroll.profile.disabled'"));
$a('settings writes audit field names only',
    str_contains($settings, "payrollAudit('payroll.settings.updated'")
    && str_contains($settings, "payrollAudit('payroll.settings.created'")
    && str_contains($settings, "'changed_fields' => array_keys"));
$a('schedules audit lifecycle',
    str_contains($schedules, "payrollAudit('payroll.schedule.created'")
    && str_contains($schedules, "payrollAudit('payroll.schedule.updated'")
    && str_contains($schedules, "payrollAudit('payroll.schedule.deactivated'"));

echo PHP_EOL . "Declarations" . PHP_EOL;
foreach ([
    'payroll.settings.view',
    'payroll.settings.manage',
    'payroll.anomalies.detect',
    'payroll.profile.disabled',
    'payroll.settings.created',
    'payroll.settings.updated',
] as $needle) {
    $a("manifest declares {$needle}", str_contains($manifest, "'{$needle}'"));
}
foreach ([
    "'payroll.settings.view'",
    "'payroll.settings.manage'",
    "'payroll.anomalies.detect'",
] as $needle) {
    $a("RBAC map declares {$needle}", str_contains($legacyMap, $needle));
}
foreach ([
    '`payroll.settings.view`',
    '`payroll.settings.manage`',
    '`payroll.anomalies.detect`',
] as $needle) {
    $a("RBAC mapping doc declares {$needle}", str_contains($mappingDoc, $needle));
}

echo PHP_EOL . "Payroll setup access controls smoke: {$pass} passed, {$fail} failed" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
