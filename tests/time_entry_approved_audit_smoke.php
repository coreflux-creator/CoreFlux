<?php
/**
 * Smoke — entry-level approval audit (P1.a — accrual-at-approval companion).
 *
 * Bundle-level approval drives GL recognition; entry-level approval is
 * pure audit. This smoke validates the corrective wiring:
 *
 *   1. `timeEntryApprovedEmit()` is declared in time.php and emits a
 *      stable per-entry payload via `timeAudit('time.entry.approved', ...)`
 *      with platform audit before/after options.
 *   2. All three approve-transition sites call the helper:
 *      - manual approve (entries.php)
 *      - tokenized client email approve (approval_tokens.php)
 *      - bulk CSV pre-approved import (csv_import.php)
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

$ROOT = dirname(__DIR__);
require_once $ROOT . '/modules/time/lib/time.php';

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
$lib = (string) file_get_contents($ROOT . '/modules/time/lib/time.php');
$a('helper delegates to timeAudit with platform evidence options',
   str_contains($lib, "timeAudit('time.entry.approved', \$meta, \$entryId, \$opts);"));
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
$entries = (string) file_get_contents($ROOT . '/modules/time/api/entries.php');
$a('manual approve calls timeEntryApprovedEmit',
   str_contains($entries, "timeEntryApprovedEmit((int) \$id, \$approvedEntry, 'manual'"));
$a('manual approve passes before row for platform before/after evidence',
   str_contains($entries, "'before' => \$entry,"));
$a('manual approve passes approver_user_id in context',
   str_contains($entries, "'approver_user_id' => \$user['id'] ?? null,"));
$a('manual approve no longer calls timeAudit(time.entry.approved) directly (helper owns it)',
   !preg_match("/timeAudit\(\s*'time\.entry\.approved'/", $entries));
$a('manual approve site does NOT call accountingPostJe (audit-only)',
   !str_contains($entries, 'accountingPostJe'));

echo "\n5. Tokenized email approve site (approval_tokens.php)\n";
$tokens = (string) file_get_contents($ROOT . '/modules/time/api/approval_tokens.php');
$a('emits per-entry audit only on approve (skips reject)',
   str_contains($tokens, "if (\$choice === 'approve' && !empty(\$entryIds)) {"));
$a('fetches POST-commit approved rows by id list',
   str_contains($tokens, "SELECT *\n                   FROM time_entries\n                  WHERE tenant_id = :t AND id IN ({\$in}) AND status = 'approved'"));
$a('loops through approved rows and emits per-entry',
   str_contains($tokens, "timeEntryApprovedEmit(\n                    (int) \$approved['id'],"));
$a('passes tokenized_client_email as approved_via',
   str_contains($tokens, "'tokenized_client_email',"));
$a('includes token_id + email + ip_address in context',
   str_contains($tokens, "'token_id'              => (int) \$row['id'],")
   && str_contains($tokens, "'client_approver_email' => \$row['client_approver_email'],")
   && str_contains($tokens, "'ip_address'            => \$ip,"));
$a('log-and-swallows per-entry emit failures (does not break the token response)',
   str_contains($tokens, '[time.token.respond] per-entry audit emit failed:'));
$a('tokenized approve site does NOT call accountingPostJe (audit-only)',
   !str_contains($tokens, 'accountingPostJe'));

echo "\n6. Bulk CSV pre-approved site (csv_import.php)\n";
$csv = (string) file_get_contents($ROOT . '/modules/time/api/csv_import.php');
$a('emits per-entry audit only when preApproved is true',
   str_contains($csv, "if (\$preApproved) {\n            \$approvedEntry = timeEntryGet(\$resultId) ?? \$payload;")
   && str_contains($csv, "timeEntryApprovedEmit(\$resultId, \$approvedEntry, 'bulk_pre_approved'"));
$a('passes bulk_pre_approved as approved_via',
   str_contains($csv, "'bulk_pre_approved',"));
$a('preserves source=bulk_upload in context',
   str_contains($csv, "'source'           => 'bulk_upload',"));
$a('bulk CSV approve site does NOT call accountingPostJe (audit-only)',
   !str_contains($csv, 'accountingPostJe'));

echo "\n7. Bundle accrual hook is the SOLE GL-writing approval path\n";
$time = (string) file_get_contents($ROOT . '/modules/time/lib/time.php');
$a('only timeBuildBundlesForPeriod calls accountingPostBundleAccrual',
   substr_count($time, 'accountingPostBundleAccrual(') === 1);
$a('helper itself does NOT trigger GL post',
   !preg_match('/function timeEntryApprovedEmit[^}]*accountingPostJe/s', $time)
   && !preg_match('/function timeEntryApprovedEmit[^}]*accountingPostBundleAccrual/s', $time));

echo "\n8. PHP syntax\n";
foreach ([
    $ROOT . '/modules/time/lib/time.php',
    $ROOT . '/modules/time/api/entries.php',
    $ROOT . '/modules/time/api/approval_tokens.php',
    $ROOT . '/modules/time/api/csv_import.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Entry-level approval audit smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
