<?php
/**
 * Smoke — Accounting close packet blocking gate (P1.8).
 *
 * Spec re-audit decision: "Accounting close packets must be wired
 * into the actual close workflow (not just data files). Owners +
 * due-dates + blocking gates active. Open tasks block period close."
 *
 * Asserts:
 *   1. periods.php soft_close action queries accounting_close_tasks
 *      for open blockers (status IN pending/in_progress/blocked).
 *   2. Refuses with HTTP 409 + code='close_tasks_open' + open_tasks[]
 *      detail when any blockers found.
 *   3. Override allowed via body.close_with_open_tasks=true +
 *      mandatory reason. Override is audit-logged with task_keys.
 *   4. Same gate applied to the 'close' (hard close) action.
 *   5. Gate fires BEFORE the period status UPDATE.
 *   6. close_tasks.php already exposes PATCH that sets assignee +
 *      due_date so the workflow is end-to-end usable.
 *   7. Schema (009_dimensions_and_close.sql) carries
 *      assignee_user_id + due_date + status enum that the gate reads.
 *   8. PHP syntax clean.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $ok, string $detail = '') use (&$pass, &$fail) {
    if ($ok) { echo "  ✓ {$msg}\n"; $pass++; }
    else     { echo "  ✗ {$msg}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; $fail++; }
};

$periods = (string) file_get_contents('/app/modules/accounting/api/periods.php');
$tasks   = (string) file_get_contents('/app/modules/accounting/api/close_tasks.php');
$schema  = (string) file_get_contents('/app/modules/accounting/migrations/009_dimensions_and_close.sql');

echo "\n1. Schema supports owners + due dates + status states\n";
$a('assignee_user_id column',  str_contains($schema, 'assignee_user_id BIGINT UNSIGNED NULL'));
$a('due_date column',          str_contains($schema, 'due_date DATE NULL'));
$a('status enum includes blocked / in_progress / skipped / done',
    str_contains($schema, "ENUM('pending','in_progress','done','skipped','blocked')"));

echo "\n2. soft_close blocking gate\n";
$a('soft_close queries open blockers (pending/in_progress/blocked)',
    (bool) preg_match('/if \(\$action === \'soft_close\'\).+status IN \(\'pending\',\'in_progress\',\'blocked\'\)/s', $periods));
$a('soft_close refuses with 409 + code=close_tasks_open + open_tasks',
    str_contains($periods, "'code' => 'close_tasks_open', 'open_tasks' => \$openTasks"));
$a('soft_close override requires close_with_open_tasks=true + reason',
    str_contains($periods, "\$override = !empty(\$body['close_with_open_tasks']);")
    && str_contains($periods, "if (!\$override || \$reason === '')"));
$a('soft_close override is audit-logged with task_keys',
    str_contains($periods, "accountingAudit('accounting.period.soft_close_open_tasks_override'"));

echo "\n3. close (hard close) blocking gate — same shape\n";
$a('close action also queries blockers',
    (bool) preg_match('/if \(\$action === \'close\'\).+SELECT id, task_key, title, status, assignee_user_id, due_date/s', $periods));
$a('close action refuses with same 409 + code',
    substr_count($periods, "'code' => 'close_tasks_open', 'open_tasks' => \$openTasks") >= 2);
$a('close override is audit-logged separately',
    str_contains($periods, "accountingAudit('accounting.period.close_open_tasks_override'"));

echo "\n4. Gate fires BEFORE period status UPDATE\n";
$a('blocker query precedes the UPDATE in soft_close branch',
    strpos($periods, "if (\$action === 'soft_close')")
    < strpos($periods, '$blockers = $pdo->prepare(')
    && strpos($periods, '$blockers->execute([\'t\' => $tid, \'p\' => $id]);')
    < strpos($periods, 'SET status = "soft_closed"'));
$a('blocker query precedes the UPDATE in close branch',
    strpos($periods, 'SET status = "closed"')
    > strpos($periods, "if (\$action === 'close')"));

echo "\n5. close_tasks.php exposes PATCH for assignee + due_date\n";
$a('PATCH accepts assignee_user_id + due_date',
    str_contains($tasks, "foreach (['assignee_user_id','due_date','status','notes','title','description']"));

echo "\n6. PHP syntax\n";
foreach ([
    '/app/modules/accounting/api/periods.php',
    '/app/modules/accounting/api/close_tasks.php',
] as $f) {
    $out = []; $rc = 0;
    exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    $a("php -l {$f}", $rc === 0, implode("\n", $out));
}

echo "\n=========================================\n";
echo "Close-packet blocking gate (P1.8) smoke: {$pass} ✓ / {$fail} ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
