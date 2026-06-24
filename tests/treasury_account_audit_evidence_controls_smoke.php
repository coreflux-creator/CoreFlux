<?php
/**
 * Treasury account audit evidence controls smoke.
 *
 * Ensures deposit/liability account create and hide/delete actions emit
 * platform audit rows through the shared writer with before/after snapshots.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0; $fail = 0;
$a = function (string $label, bool $ok) use (&$pass, &$fail): void {
    echo ($ok ? "  OK  " : "  BAD ") . $label . PHP_EOL;
    $ok ? $pass++ : $fail++;
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$dep = (string) file_get_contents("{$ROOT}/modules/treasury/api/deposit_accounts.php");
$lia = (string) file_get_contents("{$ROOT}/modules/treasury/api/liability_accounts.php");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'modules/treasury/api/deposit_accounts.php',
    'modules/treasury/api/liability_accounts.php',
    'tests/treasury_account_audit_evidence_controls_smoke.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nDeposit account controls\n";
$a('deposit API requires platform audit writer',
    $containsAll($dep, [
        "require_once __DIR__ . '/../../../core/audit.php'",
        'platformAuditLogWrite(',
        'function treasuryDepositAuditRow(',
    ]));
$a('deposit create and hide/delete emit before/after evidence',
    $containsAll($dep, [
        "treasury.deposit.created",
        "treasury.deposit.' . (\$mode === 'delete' ? 'deleted' : 'hidden')",
        "'before' => \$before",
        "'after' => treasuryDepositAuditRow(\$tenantId, \$id)",
    ]));
$a('deposit API no longer inserts audit_log directly',
    !preg_match('/INSERT INTO audit_log/', $dep));

echo "\nLiability account controls\n";
$a('liability API requires platform audit writer',
    $containsAll($lia, [
        "require_once __DIR__ . '/../../../core/audit.php'",
        'platformAuditLogWrite(',
        'function treasuryLiabilityAuditRow(',
    ]));
$a('liability create and hide/delete emit before/after evidence',
    $containsAll($lia, [
        "treasury.liability.created",
        "treasury.liability.' . (\$mode === 'delete' ? 'deleted' : 'hidden')",
        "'before' => \$before",
        "'after' => treasuryLiabilityAuditRow(\$tenantId, \$id)",
    ]));
$a('liability API no longer inserts audit_log directly',
    !preg_match('/INSERT INTO audit_log/', $lia));

echo "\nArchitecture record\n";
$a('alignment doc includes Treasury money movement writer statement',
    str_contains($alignment, 'Treasury payment and transfer create, submit, approve/reject, execute'));

echo "\nTreasury account audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
