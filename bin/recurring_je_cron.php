#!/usr/bin/env php
<?php
/**
 * Recurring JE cron — daily entrypoint.
 *
 * Usage on Cloudways:
 *   php /app/bin/recurring_je_cron.php          # run all tenants
 *   php /app/bin/recurring_je_cron.php 7        # run a single tenant
 *
 * Schedule once per day. Idempotent — same template + same run_date will
 * return the prior JE on the second invocation thanks to the standard
 * accounting_posting_idempotency table.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/tenant_scope.php';
require_once __DIR__ . '/../modules/accounting/lib/recurring_je.php';

$onlyTenant = isset($argv[1]) ? (int) $argv[1] : null;

$pdo = getDB();
if ($onlyTenant !== null) {
    $tenants = [['id' => $onlyTenant]];
} else {
    $tenants = $pdo->query('SELECT id FROM tenants WHERE status = "active"')->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

$total = ['ran' => 0, 'skipped' => 0, 'errors' => 0];
foreach ($tenants as $t) {
    $tid = (int) $t['id'];
    setTenantContextOverride($tid, ['user' => null, 'tenant_id' => $tid]);
    try {
        $r = recurringJeRunDueForTenant($tid, null);
        $total['ran']     += $r['ran'];
        $total['skipped'] += $r['skipped'];
        $total['errors']  += $r['errors'];
        fwrite(STDOUT, sprintf("[tenant %d] ran=%d skipped=%d errors=%d\n",
            $tid, $r['ran'], $r['skipped'], $r['errors']));
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf("[tenant %d] FATAL: %s\n", $tid, $e->getMessage()));
        $total['errors']++;
    }
}
fwrite(STDOUT, sprintf("DONE. ran=%d skipped=%d errors=%d\n",
    $total['ran'], $total['skipped'], $total['errors']));
exit($total['errors'] > 0 ? 1 : 0);
