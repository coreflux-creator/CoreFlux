<?php
/**
 * AP payment release controls smoke.
 *
 * Locks the rule that every release-to-rail surface applies the same SoD,
 * disputed/void bill, PWP, and audit controls before money movement.
 */
declare(strict_types=1);

$ROOT = realpath(__DIR__ . '/..');
$pass = 0;
$fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail): void {
    if ($cond) { echo "  OK  {$msg}\n"; $pass++; }
    else       { echo "  BAD {$msg}\n"; $fail++; }
};
$lint = function (string $rel) use ($ROOT): bool {
    $out = [];
    $rc = 0;
    exec('php -l ' . escapeshellarg("{$ROOT}/{$rel}") . ' 2>&1', $out, $rc);
    return $rc === 0;
};

$api = (string) file_get_contents("{$ROOT}/modules/ap/api/payments.php");
$manifest = (string) file_get_contents("{$ROOT}/modules/ap/manifest.php");

echo "Files parse\n";
$a('payments.php parses', $lint('modules/ap/api/payments.php'));
$a('manifest parses', $lint('modules/ap/manifest.php'));

echo "\nShared release gate\n";
$a('release gate helper exists',
    str_contains($api, 'function apPaymentReleaseGateOrError(')
    && str_contains($api, 'function apPaymentReleaseIssue('));
$a('release gate audits blocked attempts',
    str_contains($api, "apAudit('ap.payment.release_blocked'")
    && str_contains($manifest, "'ap.payment.release_blocked'"));
$a('release gate enforces maker/checker SoD',
    str_contains($api, "'code' => 'sod_created_by'")
    && str_contains($api, 'Segregation of duties: you cannot release your own payment.'));
$a('release gate refuses disputed/void allocated bills',
    str_contains($api, "b.status IN (\"disputed\",\"void\")")
    && str_contains($api, "'code' => 'bill_not_releasable'"));
$a('release gate carries PWP structured details',
    str_contains($api, '$pwpBlocked = apPwpAllocatedBillsAwaitingAr($tenantId, $paymentId);')
    && str_contains($api, "'code' => 'pwp_awaiting_ar'")
    && str_contains($api, "'blocked_bill_refs' => \$refs"));

echo "\nSingle send surface\n";
$a('send calls shared release gate before sent update',
    strpos($api, 'apPaymentReleaseGateOrError($tid, $row')
    < strpos($api, 'UPDATE ap_payments SET status = "sent"'));
$a('send still requires ap.payment.send',
    str_contains($api, "if (\$method === 'POST' && \$action === 'send')")
    && str_contains($api, "rbac_legacy_require(\$user, 'ap.payment.send')"));

echo "\nBatch origination surface\n";
$a('batch collects release issues before bank decrypt',
    strpos($api, '$releaseBlocked = [];') !== false
    && strpos($api, '$releaseBlocked = [];') < strpos($api, 'paymentRailsDecryptBank('));
$a('batch release gate runs before rail item build',
    strpos($api, '$releaseBlocked = [];') < strpos($api, 'paymentRailsBuildItem('));
$a('batch maps homogeneous PWP blocks to pwp_awaiting_ar',
    str_contains($api, "\$topCode === 'pwp_awaiting_ar'")
    && str_contains($api, "'code' => \$topCode, 'blocked' => \$releaseBlocked"));
$a('batch maps SoD blocks to HTTP 403',
    str_contains($api, "in_array('sod_created_by', \$codes, true) ? 403 : 409"));
$a('batch audits blocked release controls',
    str_contains($api, "'surface' => 'originate_batch'")
    && str_contains($api, "'blocked' => \$releaseBlocked"));

echo "\nAP payment release controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
