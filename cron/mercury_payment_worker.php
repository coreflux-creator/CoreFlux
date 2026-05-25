<?php
/**
 * Mercury Payment Engine worker (cron driver).
 *
 * Cron: */ /*5 * * * php /home/master/applications/<app>/public_html/cron/mercury_payment_worker.php
 *
 * Walks every payment_instructions row in actionable states
 * (Approved, Funding, Submitted) across every tenant and calls
 * mpAdvance() exactly once per row.
 *
 * NEVER calls Mercury synchronously from a user-facing endpoint — all
 * adapter calls live here so the UI stays fast.
 */

declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/encryption.php';
require_once __DIR__ . '/../core/mercury_payments.php';

$ACTIONABLE = ['Approved', 'Funding', 'Submitted'];
$MAX_PER_TENANT = 50;

$pdo = getDB();
try {
    $stmt = $pdo->prepare(
        'SELECT id, tenant_id, state FROM payment_instructions
          WHERE state IN ("Approved", "Funding", "Submitted")
            AND (cool_off_until IS NULL OR cool_off_until <= NOW())
          ORDER BY tenant_id, state_changed_at ASC
          LIMIT 1000'
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "Mercury payment worker: migration 050 not applied yet ({$e->getMessage()})\n");
    exit(0);
}

if (!$rows) {
    fwrite(STDOUT, "Mercury payment worker: no actionable rows.\n");
    exit(0);
}

$perTenant = []; $advanced = 0; $errors = 0;
foreach ($rows as $r) {
    $tid = (int) $r['tenant_id'];
    $id  = (int) $r['id'];
    $perTenant[$tid] = ($perTenant[$tid] ?? 0) + 1;
    if ($perTenant[$tid] > $MAX_PER_TENANT) continue;
    try {
        $newState = mpAdvance($tid, $id);
        fwrite(STDOUT, "tenant {$tid} pi #{$id}: {$r['state']} → {$newState}\n");
        $advanced++;
    } catch (\Throwable $e) {
        $errors++;
        fwrite(STDERR, "tenant {$tid} pi #{$id}: FAILED — {$e->getMessage()}\n");
    }
}

fwrite(STDOUT, "Mercury payment worker done: advanced={$advanced} errors={$errors}\n");
exit($errors > 0 ? 1 : 0);
