<?php
/**
 * Smoke — Mercury dual-leg auto-trigger on approval.
 *
 * The win the user explicitly called out:
 *   "the approval within the platform actually triggers two transactions —
 *    transfer in to mercury from funding account, transfer out to vendor."
 *
 * This test asserts:
 *   1. mpApprove() auto-invokes mpAdvance() unless opts['trigger_now']=false
 *   2. mpAdvance() failures NEVER roll back the approval (best-effort)
 *   3. The /api/mercury_payments.php?action=approve endpoint reflects the
 *      post-advance state (so the UI sees 'Funding', not 'Approved')
 *   4. Approval-time advance failure writes an audit row
 *   5. UI surfaces the dual-leg visualisation with both Mercury txn refs
 *
 * Pure static + string-presence assertions (no live DB / Mercury HTTP).
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$svc = (string) file_get_contents($root . '/core/mercury_payments.php');
$api = (string) file_get_contents($root . '/api/mercury_payments.php');
$ui  = (string) file_get_contents($root . '/modules/treasury/ui/MercuryPayments.jsx');

echo "\n1. mpApprove() — dual-leg auto-trigger contract\n";
$a('mpApprove signature accepts \$opts array',
    (bool) preg_match('/function mpApprove\([^)]*\?string \$note = null,\s*array \$opts = \[\]\)/', $svc));
$a('approval transition still happens before auto-advance',
    strpos($svc, 'mpTransition($tenantId, $id, \'Approved\'')
    < strpos($svc, "mpAdvance(\$tenantId, \$id);"));
$a('auto-advance gated by trigger_now flag (defaults true)',
    str_contains($svc, "(\$opts['trigger_now'] ?? true) !== false"));
$a('auto-advance is wrapped in try/catch (best-effort)',
    (bool) preg_match('/try\s*{\s*mpAdvance\(\$tenantId,\s*\$id\);/', $svc));
$a('auto-advance failure writes mercury.payment.auto_advance_failed audit',
    str_contains($svc, 'mercury.payment.auto_advance_failed'));
$a('auto-advance failure error_log mentions retry next worker tick',
    str_contains($svc, 'will retry next worker tick'));
$a('comment cites the user requirement verbatim (dual-leg framing)',
    str_contains($svc, 'transfer in to Mercury from')
    && str_contains($svc, 'transfer out to vendor'));

echo "\n2. mpAdvance() — leg 1 (funding pull) wiring intact\n";
$a('Approved branch dispatches to mpOriginateFunding',
    str_contains($svc, "return mpOriginateFunding(\$tenantId, \$row, \$apiToken, \$defaults);"));
$a('originate funding hits mercuryCreatePayment with funding idempotency key',
    str_contains($svc, "':funding'")
    && str_contains($svc, 'mercuryCreatePayment($apiToken, (string) $defaults[\'mercury_account_id\']'));
$a('originate funding stamps funding_mercury_txn_id on row',
    str_contains($svc, "'funding_mercury_txn_id'       => \$txnId"));

echo "\n3. mpAdvance() — leg 2 (vendor payout) wiring intact\n";
$a('Funding branch dispatches to mpVerifyAndOriginatePayout',
    str_contains($svc, "case 'Funding':  return mpVerifyAndOriginatePayout"));
$a('payout originated with separate idempotency key suffix',
    str_contains($svc, "':payout'"));
$a('vendor leg requires counterparty mapping',
    str_contains($svc, "mercury_kind = \"counterparty\""));
$a('payout stamps payout_mercury_txn_id + transition to Submitted',
    str_contains($svc, "'payout_mercury_txn_id'  => \$txnId")
    && str_contains($svc, "mpTransition(\$tenantId, (int) \$row['id'], 'Submitted'"));

echo "\n4. API endpoint reflects post-advance state\n";
$a('approve handler reads opts.trigger_now from request body',
    str_contains($api, "array_key_exists('trigger_now', \$approveBody)"));
$a('approve handler re-reads row after mpApprove to surface final state',
    str_contains($api, '$current = mpGet($tenantId, $id);')
    && strpos($api, '$current = mpGet') > strpos($api, 'mpApprove($tenantId, $id, $user'));
$a('approve response includes auto_advanced boolean',
    str_contains($api, "'auto_advanced' => \$current['state'] !== 'Approved'"));
$a('approve response returns row payload so UI can update inline',
    str_contains($api, "'row'      => \$current"));

echo "\n5. UI — operator sees both legs + post-approval feedback\n";
$a('approve handler surfaces dual-leg copy in flash banner',
    str_contains($ui, 'funding leg started'));
$a('approve handler falls back when adapter unreachable',
    str_contains($ui, 'Mercury adapter unreachable'));
$a('detail modal renders <DualLegProgress />',
    str_contains($ui, '<DualLegProgress row={row}'));
$a('DualLegProgress declared in same module',
    str_contains($ui, 'function DualLegProgress({ row })'));
$a('LegCard renders funding leg testid',
    str_contains($ui, 'testid="mercury-leg-funding"'));
$a('LegCard renders payout leg testid',
    str_contains($ui, 'testid="mercury-leg-payout"'));
$a('leg cards show subtitle "External funding account → operating Mercury"',
    str_contains($ui, 'External funding account → operating Mercury'));
$a('leg cards show subtitle "Operating Mercury → vendor counterparty"',
    str_contains($ui, 'Operating Mercury → vendor counterparty'));
$a('leg status pill is rendered with data-testid suffix',
    str_contains($ui, '`${testid}-status`'));
$a('funding txn id rendered when present',
    str_contains($ui, 'funding_mercury_txn_id'));
$a('payout txn id rendered when present',
    str_contains($ui, 'payout_mercury_txn_id'));
$a('legTone() maps cleared/settled to green',
    (bool) preg_match("/'cleared',\s*'settled',\s*'posted'/", $ui));
$a('legTone() maps failed/returned to red',
    (bool) preg_match("/'failed',\s*'returned',\s*'cancelled'/", $ui));
$a('header label "Two transactions, one approval"',
    str_contains($ui, 'Two transactions, one approval'));

echo "\n6. Backwards-compat: legacy callers without opts still work\n";
$a('mpCreateFromApPayment path unchanged',
    str_contains($svc, 'function mpCreateFromApPayment'));
$a('cron worker continues to drive states one step at a time',
    str_contains((string) file_get_contents($root . '/cron/mercury_payment_worker.php'),
                 'state IN ("Approved", "Funding", "Submitted")'));

echo "\n6b. mpList attaches inline approval counts (UI list-view badge)\n";
$a('mpList SQL pulls correlated acks_collected subquery',
    str_contains($svc, 'AS acks_collected')
    && str_contains($svc, 'FROM payment_instruction_approvals a'));
$a('mpList resolves acks_required via approvalPolicyResolve for PendingApproval',
    str_contains($svc, "if ((\$r['state'] ?? '') === 'PendingApproval')")
    && str_contains($svc, "approvalPolicyResolve("));
$a('mpList defaults acks_required to 1 when no policy matches',
    (bool) preg_match("/\\\$r\\['acks_required'\\]\\s*=\\s*1;/", $svc));
$a('UI renders InlineApprovalBadge on PendingApproval rows',
    str_contains($ui, "p.state === 'PendingApproval' && p.acks_required > 0")
    && str_contains($ui, '<InlineApprovalBadge'));
$a('InlineApprovalBadge exposes testid + data attrs for regression',
    str_contains($ui, 'data-testid={`mercury-payment-approval-inline-${paymentId}`}')
    && str_contains($ui, "data-collected={String(collected)}")
    && str_contains($ui, "data-required={String(required)}"));
$a('InlineApprovalBadge flips to "ready" tone when collected >= required',
    str_contains($ui, 'const complete = collected >= required;')
    && str_contains($ui, "complete ? 'ready' : 'acks'"));

echo "\n7. PHP syntax\n";
foreach ([
    $root . '/core/mercury_payments.php',
    $root . '/api/mercury_payments.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Mercury dual-leg approval smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
