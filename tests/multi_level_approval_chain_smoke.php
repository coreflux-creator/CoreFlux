<?php
/**
 * Smoke — Multi-level approval chain advancement (P1.7).
 *
 * Spec re-audit decision: "Multi-level approval chain must
 * actually fire (not stored-only). Rules evaluate by level; each
 * level gates the next."
 *
 * Previously the router materialised step 1 only; subsequent
 * steps lived in chain_json but never became ap_bill_approvals
 * rows. The chain silently terminated at step 1.
 *
 * Fix:
 *   - bill_approvals.php approve-action reads chain_json from the
 *     latest ap_approval_policy_evaluations row, detects step
 *     completion, and INSERTs step (n+1) rows.
 *   - Bill only flips to status='approved' once NO step in the
 *     chain has pending approvers.
 *   - approval_router.php now explicitly sets step_no=1 on step 1
 *     so advancement reads a canonical step number.
 *
 * Asserts source-level wire-up (live DB exercise belongs in the
 * existing approval-routing functional smokes).
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$ba  = (string) file_get_contents('/app/modules/ap/api/bill_approvals.php');
$rtr = (string) file_get_contents('/app/modules/ap/lib/approval_router.php');

echo "\n1. Router seeds step 1 with explicit step_no=1\n";
$a('router INSERT includes step_no column',
    str_contains($rtr, '(tenant_id, bill_id, approver_user_id, step_no, state, created_at)'));
$a('router INSERT sets step_no=1 literal',
    str_contains($rtr, "VALUES (:t, :b, :u, 1, 'pending', NOW())"));

echo "\n2. approve-action detects step completion + materialises next step\n";
$a('reads chain_json from latest ap_approval_policy_evaluations',
    str_contains($ba, "SELECT chain_json FROM ap_approval_policy_evaluations\n                  WHERE tenant_id = :t AND bill_id = :b\n                  ORDER BY id DESC LIMIT 1"));
$a('detects step-completion via per-step pending=0 query',
    str_contains($ba, "SELECT COUNT(*) FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND step_no = :s AND state = 'pending'"));
$a('only materialises next step when current step is done',
    str_contains($ba, '$stepDone = (int) $stepPending->fetchColumn() === 0;')
    && str_contains($ba, 'if ($stepDone) {'));
$a('chain index check (0-indexed) — chain[stepNo] is the NEXT step',
    str_contains($ba, "if (isset(\$chain[\$stepNo])) {  // 0-indexed chain[1] === step 2"));
$a('idempotent — skip insert when step (n+1) rows already exist',
    str_contains($ba, "SELECT COUNT(*) FROM ap_bill_approvals
                      WHERE tenant_id = :t AND bill_id = :b AND step_no = :s LIMIT 1"));
$a('INSERTs next step with step_no = $nextNo + state=pending',
    str_contains($ba, '$nextNo = $stepNo + 1;')
    && str_contains($ba, "VALUES (:t, :b, :u, :s, 'pending', NOW())")
    && str_contains($ba, "'s' => \$nextNo,"));

echo "\n3. Bill only flips to 'approved' when ENTIRE chain is done\n";
$a('cross-step pending check still gates the final UPDATE',
    str_contains($ba, "SELECT COUNT(*) FROM ap_bill_approvals
              WHERE tenant_id = :t AND bill_id = :b AND state = 'pending'")
    && str_contains($ba, "UPDATE ap_bills SET status = 'approved'"));
$a('chain advancement fires BEFORE the cross-step pending count',
    strpos($ba, "if (\$stepDone) {")
    < strpos($ba, "if ((int) \$pending->fetchColumn() === 0) {"));

echo "\n4. Per-step insert errors don't break the chain\n";
$a('individual approver insert wrapped in try/catch',
    str_contains($ba, "} catch (\\Throwable \$_) { /* duplicate / schema drift — non-fatal */ }"));

echo "\n5. PHP syntax\n";
foreach ([
    '/app/modules/ap/api/bill_approvals.php',
    '/app/modules/ap/lib/approval_router.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Multi-level approval chain (P1.7) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
