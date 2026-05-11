<?php
/**
 * Daily dunning cron.
 *
 * Schedule:
 *   0 8 * * 1-5   /usr/bin/php /app/scripts/dunning_daily.php
 *   (skips weekends at the schedule level; the engine ALSO honors
 *   `policy.skip_weekends` if the cron is configured 7 days a week.)
 *
 * For every active tenant policy, walks billing_dunning_eligible_invoices()
 * and sends the next stage's email — respecting cadence_days,
 * do_not_contact, paused_until, recipient availability.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mail_bootstrap.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../modules/billing/lib/dunning.php';

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "no DB\n"); exit(2); }

$today  = date('Y-m-d');
$tenants = $pdo->query(
    "SELECT tenant_id, is_enabled, paused_until, skip_weekends FROM tenant_dunning_policy WHERE is_enabled = 1"
)->fetchAll(\PDO::FETCH_ASSOC) ?: [];

$svc = cf_mail_bootstrap();
$totalSent = 0; $totalSuppressed = 0; $errors = 0;

foreach ($tenants as $row) {
    $tid = (int) $row['tenant_id'];
    if (!empty($row['paused_until']) && $row['paused_until'] >= $today) continue;
    if ((int) $row['skip_weekends'] && billingDunningIsWeekend($today)) continue;

    $policy = billingDunningGetPolicy($tid);
    $dnc = array_map('strval', $policy['do_not_contact']);
    $tenant = $pdo->query("SELECT * FROM tenants WHERE id = {$tid} LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: ['name' => ''];

    foreach (billingDunningEligibleInvoices($tid, $today) as $inv) {
        if (in_array((string) $inv['client_name'], $dnc, true)) {
            $totalSuppressed++;
            billingDunningRecordSend($tid, (int) $inv['id'], ['stage_no' => 0, 'template_key' => 'soft'], '', [], 'suppressed', 'do_not_contact');
            continue;
        }
        if (billingDunningWithinCadence($inv, (int) $policy['cadence_days'])) continue;
        if ((int) $inv['dunning_attempts'] >= (int) $policy['max_attempts']) continue;

        $stage = billingDunningPickStage($inv, $policy, $today);
        if (!$stage) continue;

        $recipients = billingDunningResolveRecipients($tid, $inv, (int) $inv['dunning_attempts'] + 1, $policy);
        if (!$recipients['to']) {
            billingDunningRecordSend($tid, (int) $inv['id'], $stage, '', [], 'suppressed', 'no_contact');
            $totalSuppressed++; continue;
        }
        $email  = billingDunningRenderEmail((string) $stage['template_key'], $inv, $tenant);
        $sender = cf_tenant_mail_sender($tid, 'billing');
        try {
            $svc->send($tid, 'billing', "dunning_{$stage['template_key']}", [$recipients['to']],
                $email['subject'], $email['text'], $email['html'], [], [
                    'from'      => $sender['from'] ?? null,
                    'from_name' => $sender['from_name'] ?? null,
                    'reply_to'  => $sender['reply_to'] ?? null,
                    'cc'        => $recipients['cc'],
                    'idempotency_key' => 'dunning-' . $inv['id'] . '-' . $stage['stage_no'] . '-' . $today,
                ]
            );
            billingDunningRecordSend($tid, (int) $inv['id'], $stage, $recipients['to'], $recipients['cc'], 'sent');
            $totalSent++;
            echo "[ok] tenant={$tid} inv={$inv['invoice_number']} stage={$stage['stage_no']} to={$recipients['to']}\n";
        } catch (\Throwable $e) {
            $errors++;
            billingDunningRecordSend($tid, (int) $inv['id'], $stage, $recipients['to'], $recipients['cc'], 'failed', $e->getMessage());
            echo "[fail] tenant={$tid} inv={$inv['invoice_number']} err=" . $e->getMessage() . "\n";
        }
    }
}
echo "Summary: tenants=" . count($tenants) . " sent={$totalSent} suppressed={$totalSuppressed} errors={$errors}\n";
exit($errors > 0 ? 1 : 0);
