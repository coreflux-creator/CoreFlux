<?php
/**
 * Smoke — Dual-Leg Approval Progress UI panel + Sweep Destination
 * Setup CLI helper.
 *
 * 1. UI panel: mounted in MercuryPayments PaymentDetailModal, surfaces
 *    acks/required, cool-off countdown, viewer eligibility hints.
 * 2. CLI helper: required args + pre-flight checks + dry-run guard +
 *    rule wiring + go-live summary.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. UI panel — MercuryPayments.jsx integration\n";
$ui = (string) file_get_contents('/app/modules/treasury/ui/MercuryPayments.jsx');
$a('PaymentDetailModal renders ApprovalProgressPanel ABOVE DualLegProgress',
   strpos($ui, '<ApprovalProgressPanel') < strpos($ui, '<DualLegProgress'));
$a('panel reads approval_progress from API response',
   str_contains($ui, 'progress={detail.data.approval_progress}'));
$a('panel hides itself when backend returned no progress',
   str_contains($ui, 'if (!progress || !progress.acks_required) return null;'));
$a('panel renders acks_collected / acks_required ratio',
   str_contains($ui, '{acks_collected}')
   && str_contains($ui, '{acks_required}'));
$a('panel renders policy_name with fallback to "Default policy"',
   str_contains($ui, "{policy_name || 'Default policy (1 approver, no cool-off)'}"));
$a('panel renders required_approver_role when policy specifies one',
   str_contains($ui, '{required_approver_role && ('));
$a('panel surfaces creator_name',
   str_contains($ui, '{creator_name && ('));

echo "\n2. UI panel — eligibility messaging\n";
foreach ([
    'no-viewer'              => 'Not signed in',
    'creator-cannot-approve' => 'Segregation of Duties',
    'already-acked'          => 'You already approved this',
] as $reason => $needle) {
    $a("eligibility '{$reason}' renders human hint",
       str_contains($ui, "'{$reason}':")
       && str_contains($ui, $needle));
}
$a('role-mismatch:<role> renders the specific role name',
   str_contains($ui, "if (can_approve_reason?.startsWith('role-mismatch:'))")
   && str_contains($ui, 'Requires role: ${r}'));
$a('state-<state> renders the state name',
   str_contains($ui, "if (can_approve_reason?.startsWith('state-'))"));
$a('eligible viewer sees green CTA with remaining count',
   str_contains($ui, 'data-testid="mercury-payment-approval-eligible"'));
$a('ineligible viewer carries reason data attribute',
   str_contains($ui, 'data-reason={can_approve_reason}'));

echo "\n3. UI panel — cool-off countdown\n";
$a('panel maintains client-side countdown via setInterval',
   str_contains($ui, 'setInterval(() => setTick(t => Math.max(0, t - 1)), 1000)'));
$a('panel formats countdown as mins+secs',
   str_contains($ui, 'function fmtCoolOff(secs)'));
$a('cool-off banner has testid for assertions',
   str_contains($ui, 'data-testid="mercury-payment-approval-cooloff"'));
$a('cool-off only renders when tick > 0',
   str_contains($ui, '{tick > 0 && ('));

echo "\n4. UI panel — testid coverage\n";
foreach ([
    'mercury-payment-approval-progress',
    'mercury-payment-approval-policy',
    'mercury-payment-approval-count',
    'mercury-payment-approval-acks',
    'mercury-payment-approval-acks-empty',
] as $tid) {
    $a("testid: {$tid}", str_contains($ui, "data-testid=\"{$tid}\""));
}
$a('per-ack testid uses ack.id',
   str_contains($ui, 'data-testid={`mercury-payment-approval-ack-${a.id}`}'));
$a('panel data-complete attribute switches with state',
   str_contains($ui, "data-complete={complete ? 'yes' : 'no'}"));

echo "\n5. CLI helper — argument parsing + usage\n";
$cli = (string) file_get_contents('/app/scripts/sweep_destination_setup.php');
$a('parses --key=value flags',
   str_contains($cli, '$kv = explode(\'=\', substr($arg, 2), 2);'));
$a('--help prints usage',
   str_contains($cli, "isset(\$opts['help']) || isset(\$opts['h'])"));
$a('need() exits 2 with helpful message on missing required flag',
   str_contains($cli, "fwrite(STDERR, \"ERROR: --{\$key}=… is required\\n\");")
   && str_contains($cli, 'exit(2);'));

echo "\n6. CLI helper — required args\n";
foreach (['tenant', 'account-id', 'routing', 'account-number', 'name'] as $req) {
    $a("requires --{$req}", str_contains($cli, "need(\$opts, '{$req}'"));
}
foreach (['rule-id', 'no-push', 'dry-run'] as $opt) {
    $a("accepts --{$opt} optional flag",
       str_contains($cli, "isset(\$opts['{$opt}'])"));
}

echo "\n7. CLI helper — pre-flight checks (each gives a specific remediation)\n";
$a('checks tenant exists (exit 3)',
   str_contains($cli, 'tenant ' . '{$tenantId} not found') || str_contains($cli, 'tenant {$tenantId} not found'));
$a('checks migration 075 applied (exit 4)',
   str_contains($cli, "COLUMN_NAME  = 'destination_recipient_id'")
   && str_contains($cli, 'migration 075'));
$a('warns when Mercury connection inactive (auto --no-push)',
   str_contains($cli, 'WARN: tenant has no active Mercury connection. Continuing with --no-push.'));
$a('rejects when destination_account_id matches source_account_id (exit 6)',
   str_contains($cli, "A sweep can't pull from and deposit to the same account."));
$a('rejects when --rule-id not found for tenant (exit 5)',
   str_contains($cli, 'tenant_sweep_rules id={$ruleId} not found for tenant {$tenantId}'));

echo "\n8. CLI helper — dry-run guards against writes\n";
$a('dry-run bails BEFORE recipient create',
   strpos($cli, 'Dry-run complete') < strpos($cli, 'mercuryRecipientCreate('));

echo "\n9. CLI helper — full happy path\n";
$a('creates recipient via mercuryRecipientCreate with kind=sweep_destination',
   str_contains($cli, "'kind' => 'sweep_destination',")
   && str_contains($cli, 'mercuryRecipientCreate($tenantId,'));
$a('pushes to Mercury via mercuryRecipientPushToMercury (unless --no-push)',
   str_contains($cli, 'mercuryRecipientPushToMercury($tenantId, $recipientId, null)'));
$a('push failure does NOT exit (recipient still useful locally)',
   (bool) preg_match('/WARN push to Mercury failed.*?Don\'t exit/s', $cli));
$a('wires destination_recipient_id + destination_account_id on rule when --rule-id provided',
   str_contains($cli, 'UPDATE tenant_sweep_rules')
   && str_contains($cli, 'destination_recipient_id = :r,')
   && str_contains($cli, 'destination_account_id   = :acct'));
$a('UPDATE pins WHERE id=:id AND tenant_id=:t (defense-in-depth)',
   str_contains($cli, 'WHERE id = :id AND tenant_id = :t'));
$a('UPDATE carries tenant-leak-allow comment',
   str_contains($cli, '// tenant-leak-allow:'));

echo "\n10. CLI helper — go-live readiness summary\n";
$a('prints the go-live summary footer',
   str_contains($cli, 'Setup complete — go-live readiness'));
$a('summary lists next steps explicitly (tail divergence, flip env var)',
   str_contains($cli, 'Tail the divergence alert email for 7+ consecutive clean dry-run days.')
   && str_contains($cli, 'flip TREASURY_SWEEP_LIVE=1'));
$a('summary distinguishes wired-vs-not-wired rule state',
   str_contains($cli, 'NOT YET — set destination_recipient_id='));

echo "\n11. PHP syntax\n";
foreach ([
    '/app/scripts/sweep_destination_setup.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Dual-leg UI + sweep CLI smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
