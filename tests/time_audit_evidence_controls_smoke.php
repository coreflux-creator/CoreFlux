<?php
/**
 * Time audit evidence controls smoke.
 *
 * Locks the rule that Time entry/timesheet approval evidence writes through the
 * platform audit writer with source-row snapshots.
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
$containsAll = function (string $haystack, array $needles): bool {
    foreach ($needles as $needle) {
        if (!str_contains($haystack, (string) $needle)) return false;
    }
    return true;
};

$lib = (string) file_get_contents("{$ROOT}/modules/time/lib/time.php");
$entries = (string) file_get_contents("{$ROOT}/modules/time/api/entries.php");
$sync = (string) file_get_contents("{$ROOT}/modules/time/lib/workflow_sync.php");
$staffing = (string) file_get_contents("{$ROOT}/modules/staffing/lib/timesheets.php");
$tokens = (string) file_get_contents("{$ROOT}/modules/time/api/approval_tokens.php");
$csv = (string) file_get_contents("{$ROOT}/modules/time/api/csv_import.php");
$external = (string) file_get_contents("{$ROOT}/core/staffing_email_approval.php");
$auditDoc = (string) file_get_contents("{$ROOT}/docs/AUDIT_GOVERNANCE.md");
$alignment = (string) file_get_contents("{$ROOT}/docs/PRODUCT_ARCHITECTURE_ALIGNMENT.md");

echo "Files parse\n";
foreach ([
    'modules/time/lib/time.php',
    'modules/time/api/entries.php',
    'modules/time/lib/workflow_sync.php',
    'modules/staffing/lib/timesheets.php',
    'modules/time/api/approval_tokens.php',
    'modules/time/api/csv_import.php',
    'core/staffing_email_approval.php',
] as $rel) {
    $a("php -l {$rel}", $lint($rel));
}

echo "\nTime audit writer\n";
$a('timeAudit requires shared platform audit writer',
    str_contains($lib, "require_once __DIR__ . '/../../../core/audit.php'")
    && str_contains($lib, 'platformAuditLogWrite('));
$a('timeAudit accepts platform audit options',
    str_contains($lib, 'function timeAudit(string $event, array $meta = [], ?int $targetId = null, array $opts = [])'));
$a('timeAudit stamps Time source/object metadata',
    $containsAll($lib, ["'object_type' => timeAuditObjectType(\$event)", "'source' => \$meta['source'] ?? 'time'"]));
$a('timeAudit maps high-risk Time object types',
    $containsAll($lib, ['time_entry', 'time_timesheet', 'time_approval_token', 'time_feed_bundle']));
$a('timeAudit no longer inserts audit_log directly',
    !preg_match('/function timeAudit[\s\S]*INSERT INTO audit_log/', $lib));
$a('entry/timesheet/token audit row helpers exist',
    $containsAll($lib, [
        'function timeEntryAuditRowForTenant(',
        'function timeEntryAuditRowsForTimesheet(',
        'function timeTimesheetAuditRowForTenant(',
        'function timeTokenAuditRowForTenant(',
    ]));

echo "\nEntry evidence\n";
$a('entry actions snapshot create/submit/reject/update/correct',
    $containsAll($entries, [
        "timeAudit('time.entry.created'",
        "'after' => \$createdEntry",
        "timeAudit('time.entry.submitted'",
        "'before' => \$entry",
        "timeAudit('time.entry.rejected'",
        "timeAudit('time.entry.updated'",
        "timeAudit('time.entry.superseded'",
        "'new_entry' => timeEntryGet(\$newId)",
    ]));
$a('manual approve passes before row to approved-entry emitter',
    $containsAll($entries, [
        "timeEntryApprovedEmit((int) \$id, \$approvedEntry, 'manual'",
        "'before' => \$entry",
    ]));
$a('approved-entry emitter moves evidence options out of metadata',
    $containsAll($lib, [
        "\$opts = ['after' => \$entry]",
        "foreach (['before', 'after', 'tenant_id', 'actor_user_id', 'actor_type', 'actor_email'] as \$key)",
        "timeAudit('time.entry.approved', \$meta, \$entryId, \$opts)",
    ]));

echo "\nTimesheet workflow evidence\n";
$a('workflow sync snapshots header and entries before decisions',
    $containsAll($sync, [
        '$beforeHeader = timeTimesheetAuditRowForTenant($tenantId, $timesheetId)',
        '$beforeEntries = timeEntryAuditRowsForTimesheet($tenantId, $timesheetId)',
        "'before' => [",
        "'timesheet' => \$beforeHeader",
        "'entries' => \$beforeEntries",
    ]));
$a('workflow approval sync passes per-entry before rows',
    $containsAll($sync, [
        '$beforeById[(int) ($beforeEntry[\'id\'] ?? 0)] = $beforeEntry',
        "timeEntryApprovedEmit((int) \$entry['id'], \$entry, 'manual'",
        "'before' => \$beforeById[(int) \$entry['id']] ?? null",
    ]));
$a('staffing bridge snapshots workflow start/submit/blocked decisions',
    $containsAll($staffing, [
        "timeAudit('time.timesheet.workflow_started'",
        "'before' => \$beforeHeader",
        "'after' => timeTimesheetAuditRowForTenant(\$tenantId, \$timesheetId)",
        "timeAudit('time.timesheet.submitted'",
        "'entries' => \$beforeEntries",
        "timeAudit('time.timesheet.approval_blocked'",
    ]));

echo "\nExternal approval evidence\n";
$a('tokenized client response uses platform audit writer',
    $containsAll($tokens, [
        "platformAuditLogWrite((int) \$row['tenant_id'], null, 'time.token.responded'",
        "'before' => \$row",
        "'after' => \$updatedToken",
        "'actor_type' => 'external_approver'",
    ]));
$a('tokenized client response no longer inserts audit_log directly',
    !preg_match('/INSERT INTO audit_log/', $tokens));
$a('tokenized client approval passes per-entry before rows',
    $containsAll($tokens, [
        '$beforeEntriesById[(int) $beforeEntry[\'id\']] = $beforeEntry',
        "'before'                 => \$beforeEntriesById[(int) \$approved['id']] ?? null",
    ]));
$a('token issue and revoke snapshot token rows',
    $containsAll($tokens, [
        "timeAudit('time.tokenized_email.issued'",
        "'after' => timeTokenAuditRowForTenant((int) \$ctx['tenant_id'], \$tokenId)",
        "timeAudit('time.tokenized_email.revoked'",
        "'before' => \$before",
    ]));
$a('external email approval snapshots timesheet and entries',
    $containsAll($external, [
        '$beforeHeader = timeTimesheetAuditRowForTenant($tenantId, $headerId) ?? $header',
        '$beforeEntries = timeEntryAuditRowsForTimesheet($tenantId, $headerId)',
        "timeEntryApprovedEmit((int) \$approved['id'], \$approved, 'external_email'",
        "'actor_type' => 'external_approver'",
        "'before' => \$beforeEntriesById[(int) \$approved['id']] ?? null",
    ]));

echo "\nCSV pre-approval evidence\n";
$a('CSV pre-approval keeps full before row and final approved row',
    $containsAll($csv, [
        'SELECT * FROM time_entries',
        '$approvedEntry = timeEntryGet($resultId) ?? $payload',
        "timeEntryApprovedEmit(\$resultId, \$approvedEntry, 'bulk_pre_approved'",
        "'before' => \$existing",
        "'after' => \$approvedEntry",
    ]));

echo "\nDocs\n";
$a('audit governance names Time approval controls',
    str_contains($auditDoc, 'Time entry/timesheet approvals'));
$a('architecture alignment records Time approval controls',
    $containsAll($alignment, [
        'Time Entry And Timesheet Controls',
        '`timeAudit` delegates to the shared `platformAuditLogWrite` writer',
        'workflow approval/rejection sync capture before/after entry',
    ]));

echo "\nTime audit evidence controls smoke: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
