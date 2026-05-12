<?php
/**
 * Smoke: bugfix — staffing approvals queue `list` query had ambiguous
 * `tenant_id` after the JOIN onto `people` (both tables have tenant_id).
 *
 * Reported in prod: 2026-02 via Staffing → Approvals page showing
 *   SQLSTATE[23000]: Integrity constraint violation: 1052
 *   Column 'tenant_id' in WHERE is ambiguous
 *
 * Fix: qualify all WHERE-clause columns on the timesheet header with `t.`
 * so the auto-injected `:tenant_id` binding is unambiguous.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$src = (string) file_get_contents(__DIR__ . '/../modules/staffing/api/timesheets.php');

// Find the `list` action block.
$start = strpos($src, "\$action === 'list'");
$end   = strpos($src, "api_ok(['rows' => \$rows]);", $start ?: 0);
$listBlock = $start !== false && $end !== false
    ? substr($src, $start, $end - $start)
    : '';

$a('list block located',                              $listBlock !== '');
$a('WHERE filter uses t.tenant_id (not unqualified)', str_contains($listBlock, 't.tenant_id = :tenant_id'));
$a('status filter qualified',                         str_contains($listBlock, "'t.status = :s'"));
$a('period_start filter qualified',                   str_contains($listBlock, "'t.period_start >= :ps'"));
$a('period_end filter qualified',                     str_contains($listBlock, "'t.period_end <= :pe'"));
$a('person_id filter qualified',                      str_contains($listBlock, "'t.person_id = :pid'"));
$a('ORDER BY qualified to t.period_start',            str_contains($listBlock, 'ORDER BY t.period_start DESC, t.id DESC'));
$a('no unqualified bare tenant_id in WHERE list',     !preg_match("/'tenant_id\s*=\s*:tenant_id'/", $listBlock));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
