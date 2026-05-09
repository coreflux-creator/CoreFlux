<?php
/**
 * Auto-reverse accrual cron (Sprint P2).
 *
 * Scans posted JEs where auto_reverses_on <= today AND reverses_je_id IS NULL
 * (this is an original, not a reversal) AND no entry already exists that
 * reverses it. Calls accountingReverseJe() — same helper the manual reverse
 * UI uses — and clears auto_reverses_on on success.
 *
 * Usage:
 *   php scripts/auto_reverse_accruals.php
 *
 * Crontab (daily, e.g. 06:00):
 *   0 6 * * * /usr/bin/php /app/scripts/auto_reverse_accruals.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../modules/accounting/lib/accounting.php';

$pdo = getDB();
$today = date('Y-m-d');

// Scan: every tenant's eligible JEs in one pass. The accountingReverseJe()
// helper takes ($tenantId, $jeId, $reason, ?$actorUserId).
$stmt = $pdo->prepare(
    "SELECT je.id, je.tenant_id, je.je_number, je.auto_reverses_on
       FROM accounting_journal_entries je
      WHERE je.status = 'posted'
        AND je.reverses_je_id IS NULL
        AND je.auto_reverses_on IS NOT NULL
        AND je.auto_reverses_on <= :today
        AND NOT EXISTS (
            SELECT 1 FROM accounting_journal_entries r
             WHERE r.tenant_id = je.tenant_id
               AND r.reverses_je_id = je.id
        )
      ORDER BY je.auto_reverses_on ASC, je.id ASC"
);
$stmt->execute(['today' => $today]);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$ran = 0; $failed = 0;
foreach ($rows as $r) {
    $tid = (int) $r['tenant_id'];
    $jeId = (int) $r['id'];
    try {
        $reason = "Auto-reverse on " . $r['auto_reverses_on'];
        accountingReverseJe($tid, $jeId, $reason, null);
        // Clear the trigger so it never fires twice + record success.
        $pdo->prepare(
            'UPDATE accounting_journal_entries
                SET auto_reverses_on        = NULL,
                    auto_reverse_attempted_at = NOW(),
                    auto_reverse_last_error   = NULL
              WHERE id = :id AND tenant_id = :t'
        )->execute(['id' => $jeId, 't' => $tid]);
        $ran++;
        echo "[ok]   tenant={$tid} je_id={$jeId} number={$r['je_number']} reverse_date={$r['auto_reverses_on']}\n";
    } catch (\Throwable $e) {
        $failed++;
        @$pdo->prepare(
            'UPDATE accounting_journal_entries
                SET auto_reverse_attempted_at = NOW(),
                    auto_reverse_last_error   = :err
              WHERE id = :id AND tenant_id = :t'
        )->execute(['err' => substr($e->getMessage(), 0, 500), 'id' => $jeId, 't' => $tid]);
        echo "[fail] tenant={$tid} je_id={$jeId} error=" . $e->getMessage() . "\n";
    }
}
echo "Summary: candidates=" . count($rows) . " reversed={$ran} failed={$failed}\n";
exit($failed > 0 ? 1 : 0);
