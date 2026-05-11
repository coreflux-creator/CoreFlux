<?php
/**
 * Daily generator for recurring AR invoice contracts.
 *
 * Schedule (cron):
 *   30 6 * * *  /usr/bin/php /app/scripts/billing_recurring_generate.php
 *
 * For each tenant, finds every active contract whose `next_due_at` is on
 * or before today and generates its draft invoice. Idempotent — re-running
 * the same morning is safe (the lib's existence guard handles dedup).
 *
 * After generation we send a single "X recurring invoices ready to send"
 * digest email to every user with `billing.invoice.create` permission on
 * the tenant. The invoices land in `draft` state — AR still owns the
 * decision to approve+send.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mail_bootstrap.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../modules/billing/lib/recurring.php';

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "no DB\n"); exit(2); }

$asOf = date('Y-m-d');
$tenants = $pdo->query(
    "SELECT DISTINCT tenant_id FROM billing_invoice_contracts
      WHERE status = 'active'
        AND start_date <= CURDATE()
        AND (end_date IS NULL OR end_date >= CURDATE())
        AND (next_due_at IS NULL OR next_due_at <= CURDATE())"
)->fetchAll(\PDO::FETCH_COLUMN) ?: [];

$svc = cf_mail_bootstrap();
$totalGenerated = 0; $totalSkipped = 0; $errors = 0;

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    $contracts = billingRecurringEligibleContracts($tid, $asOf);
    if (empty($contracts)) continue;

    $generated = []; $skipped = [];
    foreach ($contracts as $c) {
        $forDate = $c['next_due_at'] ?: $c['start_date'];
        try {
            $res = billingRecurringGenerateInvoice($tid, $c, $forDate, null);
            if ($res['existed'] || !empty($res['skipped'])) {
                $skipped[] = $res + ['contract_id' => (int) $c['id'], 'contract_name' => $c['contract_name']];
            } else {
                $generated[] = $res + ['contract_id' => (int) $c['id'], 'contract_name' => $c['contract_name'], 'client_name' => $c['client_name']];
                $totalGenerated++;
            }
        } catch (\Throwable $e) {
            $errors++;
            echo "[fail] tenant={$tid} contract={$c['id']} err=" . $e->getMessage() . "\n";
        }
    }
    $totalSkipped += count($skipped);

    if (!empty($generated)) {
        // Notify everyone with billing.invoice.create on this tenant.
        $recipients = bric_resolve_billing_users($pdo, $tid);
        if (!empty($recipients)) {
            $sender = cf_tenant_mail_sender($tid, 'billing');
            $subject = sprintf('%d recurring invoice%s ready to send', count($generated), count($generated) === 1 ? '' : 's');
            $html = bric_render_html($generated);
            $text = bric_render_text($generated);
            foreach ($recipients as $u) {
                try {
                    $svc->send($tid, 'billing', 'recurring_generated_digest', [$u['email']],
                        $subject, $text, $html, [], [
                            'from'      => $sender['from'] ?? null,
                            'from_name' => $sender['from_name'] ?? null,
                            'reply_to'  => $sender['reply_to'] ?? null,
                            'idempotency_key' => 'billing-recurring-' . $tid . '-' . $u['id'] . '-' . $asOf,
                        ]
                    );
                } catch (\Throwable $e) { $errors++; }
            }
        }
        echo "[ok]   tenant={$tid} generated=" . count($generated) . " skipped=" . count($skipped) . "\n";
    }
}

echo "Summary: tenants=" . count($tenants) . " generated={$totalGenerated} skipped={$totalSkipped} errors={$errors}\n";
exit($errors > 0 ? 1 : 0);

function bric_resolve_billing_users(\PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare(
            "SELECT DISTINCT u.id, u.name, u.email
               FROM users u
               LEFT JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :t
              WHERE u.email IS NOT NULL AND u.email <> ''
                AND (
                  ut.role IN ('ar_clerk','ar_manager','billing','admin','master_admin','manager')
                  OR u.role IN ('ar_clerk','ar_manager','billing','admin','master_admin','manager')
                )"
        );
        $st->execute(['t' => $tenantId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        try {
            $st = $pdo->prepare("SELECT id, name, email FROM users WHERE tenant_id = :t AND email IS NOT NULL AND email <> ''");
            $st->execute(['t' => $tenantId]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $__) { return []; }
    }
}

function bric_render_html(array $generated): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($generated as $g) {
        $rows .= '<tr>'
              . '<td style="padding:6px;border-bottom:1px solid #f1f5f9">' . $h($g['contract_name']) . '</td>'
              . '<td style="padding:6px;border-bottom:1px solid #f1f5f9">' . $h($g['client_name']) . '</td>'
              . '<td style="padding:6px;border-bottom:1px solid #f1f5f9">' . $h($g['period_start']) . ' → ' . $h($g['period_end']) . '</td>'
              . '<td style="padding:6px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums">$' . number_format((float) $g['amount'], 2) . '</td>'
              . '</tr>';
    }
    return '<div style="max-width:720px;margin:auto;font-family:system-ui;color:#0f172a;padding:24px">'
         . '<h2 style="margin:0 0 8px">' . count($generated) . ' recurring invoice(s) ready to send</h2>'
         . '<p style="color:#475569;margin:0 0 16px;font-size:13px">These drafts were auto-generated this morning. Review and send when ready.</p>'
         . '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr style="background:#f8fafc">'
         . '<th style="text-align:left;padding:6px;font-size:11px;color:#64748b;text-transform:uppercase">Contract</th>'
         . '<th style="text-align:left;padding:6px;font-size:11px;color:#64748b;text-transform:uppercase">Client</th>'
         . '<th style="text-align:left;padding:6px;font-size:11px;color:#64748b;text-transform:uppercase">Period</th>'
         . '<th style="text-align:right;padding:6px;font-size:11px;color:#64748b;text-transform:uppercase">Amount</th>'
         . '</tr></thead><tbody>' . $rows . '</tbody></table>'
         . '<p style="margin-top:16px"><a href="/#/modules/billing/contracts" style="background:#0f172a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px;display:inline-block">Open contracts → </a></p>'
         . '</div>';
}

function bric_render_text(array $generated): string {
    $out = count($generated) . " recurring invoice(s) ready to send:\n\n";
    foreach ($generated as $g) {
        $out .= sprintf("  - %-30s %-20s %s → %s  \$%s\n",
            substr((string) $g['contract_name'], 0, 30),
            substr((string) $g['client_name'], 0, 20),
            $g['period_start'], $g['period_end'],
            number_format((float) $g['amount'], 2)
        );
    }
    return $out;
}
