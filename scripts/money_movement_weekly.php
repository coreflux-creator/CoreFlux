<?php
/**
 * Weekly Money Movement digest cron — runs every Monday at 13:00 UTC
 * (≈ 9am ET).
 *
 *   0 13 * * 1   /usr/bin/php /app/scripts/money_movement_weekly.php
 *
 * Iterates every tenant that has at least one finance signal in the last
 * 7 days (collections OR AP payments) — keeps the recipient list lean
 * and prevents inactive tenants from getting spurious "$0 in / $0 out"
 * emails. Per-recipient idempotency keyed `money-mvmt-{tid}-{uid}-{Y-m-d}`
 * so accidental re-runs in the same day don't double-send.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mail_bootstrap.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../core/digest_schedules.php';
require_once __DIR__ . '/../modules/billing/lib/money_movement.php';

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "no DB\n"); exit(2); }

$asOf  = date('Y-m-d');
$start = date('Y-m-d', strtotime($asOf . ' -6 days'));

// Active tenants = anyone with movement in either direction this week.
$tenants = [];
try {
    $st = $pdo->prepare(
        "SELECT DISTINCT tenant_id FROM billing_payments WHERE received_at BETWEEN :s AND :e
         UNION
         SELECT DISTINCT tenant_id FROM ap_payments WHERE pay_date BETWEEN :s2 AND :e2
                                                     AND status NOT IN ('draft','void','failed')"
    );
    $st->execute(['s' => $start, 'e' => $asOf, 's2' => $start, 'e2' => $asOf]);
    $tenants = $st->fetchAll(\PDO::FETCH_COLUMN) ?: [];
} catch (\Throwable $e) {
    fwrite(STDERR, "tenant discovery failed: {$e->getMessage()}\n"); exit(2);
}

$svc = cf_mail_bootstrap();
$sent = 0; $failed = 0; $tenantsRun = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;

    // Per-tenant schedule gate. Cron runs hourly; skip unless THIS hour
    // matches the tenant's configured dow + hour for the money_movement digest.
    $schedule = cf_digest_schedule_get($tid, 'money_movement');
    if (!cf_digest_schedule_should_fire($schedule, time())) {
        echo "[skip] tenant={$tid} not_scheduled_this_hour\n";
        continue;
    }

    $snapshot   = moneyMovementSnapshot($tid, $asOf);
    moneyMovementWriteSnapshot($snapshot);
    $recipients = moneyMovementResolveRecipients($pdo, $tid);
    if (empty($recipients)) continue;
    $tenantsRun++;

    $tenantRow = null;
    try {
        $tn = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
        $tn->execute(['id' => $tid]);
        $tenantRow = $tn->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { /* shrug */ }
    $tenantName = (string) ($tenantRow['name'] ?? 'CoreFlux');
    $sender = cf_tenant_mail_sender($tid, 'billing');

    foreach ($recipients as $r) {
        if (empty($r['email']) || !filter_var($r['email'], FILTER_VALIDATE_EMAIL)) continue;
        $email = moneyMovementRenderEmail($snapshot, $tenantName, trim((string) ($r['name'] ?? '')));
        try {
            $svc->send($tid, 'billing', 'money_movement_digest', [$r['email']],
                $email['subject'], $email['text'], $email['html'], [], [
                    'from'      => $sender['from']      ?? null,
                    'from_name' => $sender['from_name'] ?? null,
                    'reply_to'  => $sender['reply_to']  ?? null,
                    'idempotency_key' => "money-mvmt-{$tid}-{$r['id']}-{$asOf}",
                ]
            );
            $sent++;
            echo "[ok]   tenant={$tid} to={$r['email']}\n";
        } catch (\Throwable $e) {
            $failed++;
            echo "[fail] tenant={$tid} to={$r['email']} err=" . $e->getMessage() . "\n";
        }
    }
}

echo "Summary: as_of={$asOf} tenants_run={$tenantsRun} sent={$sent} failed={$failed} candidates=" . count($tenants) . "\n";
exit($failed > 0 ? 1 : 0);
