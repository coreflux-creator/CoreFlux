<?php
/**
 * Mercury reconciliation worker (cron driver).
 *
 * Cron: */ /*15 * * * php /home/master/applications/<app>/public_html/cron/mercury_reconciliation.php
 *
 * Walks every tenant with at least one payment_instruction row, calls
 * mercuryReconcileTenant(). Reconciliation is pure DB work (no Mercury
 * API calls), so this can run frequently without rate-limit concerns.
 *
 * Per-tenant try/catch — one bad tenant never aborts the whole cron.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mercury_reconciliation.php';

try {
    $pdo  = getDB();
    $stmt = $pdo->query(
        'SELECT DISTINCT tenant_id FROM payment_instructions
          WHERE state = "Settled" AND reconciled_at IS NULL'
    );
    $tenants = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Mercury reconciliation cron: migration 051 not applied yet ({$e->getMessage()})\n");
    exit(0);
}

if (!$tenants) {
    fwrite(STDOUT, "Mercury reconciliation cron: nothing to reconcile.\n");
    exit(0);
}

$ok = 0; $fail = 0; $totals = ['matched' => 0, 'discrepancies' => 0, 'missing' => 0, 'scanned' => 0];
foreach ($tenants as $tid) {
    $tid = (int) $tid;
    try {
        $r = mercuryReconcileTenant($tid);
        foreach (['scanned','matched','discrepancies','missing'] as $k) {
            $totals[$k] += (int) ($r[$k] ?? 0);
        }
        fwrite(STDOUT, "tenant {$tid}: scanned={$r['scanned']} matched={$r['matched']} discrepancies={$r['discrepancies']} missing={$r['missing']}\n");
        $ok++;
    } catch (\Throwable $e) {
        $fail++;
        fwrite(STDERR, "tenant {$tid}: FAILED — {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf(
    "Mercury reconciliation cron done: tenants_ok=%d tenants_failed=%d scanned=%d matched=%d discrepancies=%d missing=%d\n",
    $ok, $fail, $totals['scanned'], $totals['matched'], $totals['discrepancies'], $totals['missing']
));
exit($fail > 0 ? 1 : 0);
