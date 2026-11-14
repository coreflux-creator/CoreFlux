<?php
/**
 * Smoke — entry-level approval audit (P1.a — accrual-at-approval companion).
 *
 * Bundle-level approval drives GL recognition; entry-level approval is
 * pure audit. This smoke validates the corrective wiring:
 *
 *   1. `timeEntryApprovedEmit()` is declared in time.php and emits a
 *      stable per-entry payload via `timeAudit('time.entry.approved', ...)`.
 *   2. All three approve-transition sites call the helper:
 *      - manual approve (entries.php)
 *      - tokenized client email approve (approval_tokens.php)
 *      - CSV intake remains pending until the shared independent approval path
 *   3. None of the three sites call `accountingPostJe()` or any GL-write
 *      helper directly — entry-level is strictly audit-only.
 *   4. The helper payload includes the discriminating fields downstream
 *      dashboards need (work_date, placement_id, hours, approved_via).
 *   5. The bulk paths skip the audit emit on reject — only approvals
 *      generate `time.entry.approved` rows.
 *
 * Source-level static analysis. The audit-log DB writes are covered by
 * existing bookkeeping smokes for `timeAudit`.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/modules/time/lib/time.php';

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

echo "\n1. Helper declared + signature stable\n";
$a('timeEntryApprovedEmit() function exists',
   function_exists('timeEntryApprovedEmit'));
$ref = new ReflectionFunction('timeEntryApprovedEmit');
$params = array_map(fn($p) => $p->getName(), $ref->getParameters());
$a('signature: (entryId, entry, approvedVia, approverContext)',
   $params === ['entryId', 'entry', 'approvedVia', 'approverContext']);

echo "\n2. Helper writes to audit_log via timeAudit (not direct PDO)\n";
$lib = (string) file_get_contents($root . '/modules/time/lib/time.php');
$a('helper delegates to timeAudit (single source of truth)',
   str_contains($lib, "timeAudit('time.entry.approved', \$meta, \$entryId);"));
$a('helper has NO direct INSERT INTO audit_log call',
   substr_count($lib, "function timeEntryApprovedEmit") === 1
   && !preg_match('/timeEntryApprovedEmit\([^)]*\)\s*[^{]*\{[^}]*INSERT INTO audit_log/s', $lib));

echo "\n3. Helper payload carries discriminating fields\n";
foreach ([
    'placement_id', 'person_id', 'period_id', 'work_date',
    'category', 'hours', 'rate_snapshot_id', 'approved_via',
] as $field) {
    $a("payload includes '{$field}'",
       (bool) preg_match("/'{$field}'\s*=>/", $lib));
}

echo "\n4. Manual approve site (entries.php)\n";
$entries = (string) file_get_contents($root . '/modules/time/api/entries.php');
$review = (string) file_get_contents($root . '/modules/time/lib/native_review.php');
$a('manual approve uses the shared approval helper',
   str_contains($entries, 'timeNativeDecisionCommit(')
   && str_contains((string)file_get_contents($root.'/modules/time/lib/native_decisions.php'), "timeApproveEntry(\$id,['id'=>\$actor],'bulk_review')"));
$a('shared approval helper emits the approver context',
   str_contains($lib, 'timeNativeWriteApproval($entry,$snapshot,$actor,$approvedVia')
   && str_contains($review, "timeEntryApprovedEmit((int)\$entry['id'],\$approved,\$via,['approver_user_id'=>\$actor]")
   && str_contains($lib, "timeNativeRequiredAudit('time.entry.approved'"));
$a('manual approve no longer calls timeAudit(time.entry.approved) directly (helper owns it)',
   !preg_match("/timeAudit\(\s*'time\.entry\.approved'/", $entries));
$a('manual approve site does NOT call accountingPostJe (audit-only)',
   !str_contains($entries, 'accountingPostJe'));

echo "\n5. Tokenized email approve site (approval_tokens.php)\n";
$tokens = (string) file_get_contents($root . '/modules/time/api/approval_tokens.php');
$client = (string) file_get_contents($root . '/modules/time/lib/client_token_review.php');
$a('public response delegates to protected client decision', str_contains($tokens,'timeClientTokenRespond('));
$a('client approval uses the shared mandatory per-entry receipt', str_contains($client,'timeNativeWriteApproval('));
$a('client approval retains the external channel', str_contains($client,"'tokenized_client_email'"));
$a('client decision retains token, email and IP context', str_contains($client,"'token_id'=>") && str_contains($client,"'client_approver_email'=>") && str_contains($client,"'ip_address'=>"));
$a('client final receipt is required before transaction completion', str_contains($client,"timeNativeRequiredAudit('time.tokenized_email.responded'"));
$a('client token decisions do not directly post journals', !str_contains($client,'accountingPostJe('));

echo "\n6. CSV source evidence and independent approval\n";
$csv = (string)file_get_contents($root.'/modules/time/lib/csv_intake.php');
$a('CSV imports pending time without inventing an approver', str_contains($csv,"'status'=>'pending_review'") && str_contains($csv,"'approved_by_user_id'=>null") && !str_contains($csv,'timeEntryApprovedEmit('));
$a('CSV source assertion is retained separately',str_contains($csv,'source_approval_json') && str_contains($csv,"'requires_independent_review'=>true"));
$a('CSV entry changes require an audit receipt',str_contains($csv,"timeNativeRequiredAudit(\$existing?'time.entry.updated':'time.entry.created'"));
$a('CSV intake cannot post journals',!str_contains($csv,'accountingPostJe(') && !str_contains($csv,'accountingPostBundleAccrual('));

echo "\n7. Bundle accrual hook is the SOLE GL-writing approval path\n";
$time = (string) file_get_contents($root . '/modules/time/lib/time.php');
$a('only timeBuildBundlesForPeriod calls accountingPostBundleAccrual',
   substr_count($time, 'accountingPostBundleAccrual(') === 1);
$a('helper itself does NOT trigger GL post',
   !preg_match('/function timeEntryApprovedEmit[^}]*accountingPostJe/s', $time)
   && !preg_match('/function timeEntryApprovedEmit[^}]*accountingPostBundleAccrual/s', $time));

echo "\n8. PHP syntax\n";
foreach ([
    $root . '/modules/time/lib/time.php',
    $root . '/modules/time/api/entries.php',
    $root . '/modules/time/api/approval_tokens.php',
    $root . '/modules/time/api/csv_import.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Entry-level approval audit smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
