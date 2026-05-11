<?php
/**
 * Sunday-night AP Weekly Queue email.
 *
 * For every tenant with AP users, build a per-recipient digest listing
 * past-due + due-next-week AP bills with blockers. Sent via the standard
 * tenant Resend pipeline.
 *
 * Schedule (cron):
 *   0 22 * * 0  /usr/bin/php /app/scripts/ap_weekly_queue_sunday.php
 *
 * (Sunday 22:00 UTC ≈ Sunday evening US-East / Monday early US-West.)
 *
 * Tenant overrides (future, P1): `tenants.ap_queue_email_dow` to let each
 * tenant pick which day-of-week the digest ships. Skipped for v1.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/mail_bootstrap.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../core/RBAC.php';
require_once __DIR__ . '/../modules/ap/lib/weekly_queue.php';

$pdo = getDB();
if (!$pdo) { fwrite(STDERR, "no DB\n"); exit(2); }

// Discover tenants that have at least one active AP bill in scope; cheap
// guard to avoid emailing every tenant in the system even when AP isn't
// in use.
$tenants = $pdo->query(
    "SELECT DISTINCT tenant_id FROM ap_bills
      WHERE status NOT IN ('paid','void')
        AND (due_date < CURDATE() OR due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY))"
)->fetchAll(\PDO::FETCH_COLUMN) ?: [];

$totalRecipients = 0; $totalTenants = 0; $errors = 0;
$svc = cf_mail_bootstrap();

foreach ($tenants as $tid) {
    $tid = (int) $tid;
    $rows = apWeeklyQueueList($tid, 7);
    if (empty($rows)) continue;
    $bucketed = apWeeklyQueueBucket($rows);
    $summary  = apWeeklyQueueSummary($rows);

    // Recipients = every user with `ap.bill.view` permission on this tenant.
    $apUsers = ap_weekly_queue_resolve_recipients($pdo, $tid);
    if (empty($apUsers)) continue;
    $totalTenants++;

    $subject = sprintf(
        'Weekly AP queue — %d past due ($%s), %d due this week',
        $summary['past_due_count'], number_format($summary['past_due_amount'], 0),
        $summary['due_soon_count']
    );

    $sender = cf_tenant_mail_sender($tid, 'ap');
    $base   = defined('APP_URL') ? rtrim((string) APP_URL, '/') : (getenv('APP_URL') ?: '');
    $queueUrl = $base . '/#/modules/ap/weekly-queue';

    foreach ($apUsers as $u) {
        if (empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) continue;
        $html = ap_weekly_queue_email_html($u['name'] ?? '', $bucketed, $summary, $queueUrl);
        $text = ap_weekly_queue_email_text($u['name'] ?? '', $bucketed, $summary, $queueUrl);
        try {
            $svc->send($tid, 'ap', 'weekly_queue_digest', [$u['email']],
                $subject, $text, $html, [], [
                    'from' => $sender['from'] ?? null,
                    'from_name' => $sender['from_name'] ?? null,
                    'reply_to' => $sender['reply_to'] ?? null,
                    'idempotency_key' => 'ap-weekly-queue-' . $tid . '-' . $u['id'] . '-' . date('Y-m-d'),
                ]
            );
            $totalRecipients++;
            echo "[ok]   tenant={$tid} user={$u['email']} past={$summary['past_due_count']} soon={$summary['due_soon_count']}\n";
        } catch (\Throwable $e) {
            $errors++;
            echo "[fail] tenant={$tid} user={$u['email']} err=" . $e->getMessage() . "\n";
        }
    }
}
echo "Summary: tenants={$totalTenants} recipients={$totalRecipients} errors={$errors}\n";
exit($errors > 0 ? 1 : 0);

function ap_weekly_queue_resolve_recipients(\PDO $pdo, int $tenantId): array {
    // Heuristic: anyone with role containing 'ap' or role='admin'/'master_admin'
    // on this tenant. Tries `user_tenants.role` first, falls back to `users.role`.
    try {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT u.id, u.name, u.email
               FROM users u
               LEFT JOIN user_tenants ut ON ut.user_id = u.id AND ut.tenant_id = :t
              WHERE u.email IS NOT NULL AND u.email <> ''
                AND (
                  ut.role IN ('ap_clerk','ap_manager','admin','master_admin')
                  OR u.role IN ('ap_clerk','ap_manager','admin','master_admin')
                )"
        );
        $stmt->execute(['t' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $_) {
        // Schema fallback: just users.role
        try {
            $stmt = $pdo->prepare(
                "SELECT id, name, email FROM users
                  WHERE tenant_id = :t AND email IS NOT NULL AND email <> ''
                    AND role IN ('ap_clerk','ap_manager','admin','master_admin')"
            );
            $stmt->execute(['t' => $tenantId]);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $__) { return []; }
    }
}

function ap_weekly_queue_email_html(string $name, array $bucketed, array $summary, string $queueUrl): string {
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $row = function (array $b) use ($h) {
        $blockerColor = match ($b['blocker'] ?? 'none') {
            'awaiting_client' => '#0891b2',
            'missing_hours'   => '#a16207',
            'needs_review'    => '#7c3aed',
            'approver_pending'=> '#64748b',
            'disputed'        => '#dc2626',
            default           => '#16a34a',
        };
        $blockerLabel = match ($b['blocker'] ?? 'none') {
            'awaiting_client' => 'Client payment',
            'missing_hours'   => 'Hours not finalized',
            'needs_review'    => 'AP review',
            'approver_pending'=> 'Approver',
            'disputed'        => 'Disputed',
            default           => 'Ready',
        };
        return '<tr>'
             . '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9">' . $h($b['bill_number'] ?: $b['internal_ref']) . '</td>'
             . '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9">' . $h($b['vendor_name']) . '</td>'
             . '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9;text-align:right;font-variant-numeric:tabular-nums">$' . number_format((float) $b['amount_due'], 2) . '</td>'
             . '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9">' . $h($b['due_date']) . '</td>'
             . '<td style="padding:8px 6px;border-bottom:1px solid #f1f5f9"><span style="display:inline-block;padding:2px 8px;border-radius:10px;background:' . $blockerColor . '22;color:' . $blockerColor . ';font-size:11px;font-weight:600">' . $h($blockerLabel) . '</span><br><span style="font-size:11px;color:#64748b">' . $h((string) ($b['blocker_detail'] ?? '')) . '</span></td>'
             . '</tr>';
    };
    $table = function (string $title, array $rows) use ($row) {
        if (empty($rows)) return '';
        $body = '';
        foreach ($rows as $r) $body .= $row($r);
        return '<h3 style="margin:24px 0 4px;font-family:system-ui;color:#0f172a">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>'
             . '<table style="width:100%;border-collapse:collapse;font-family:system-ui;font-size:13px">'
             . '<thead><tr style="background:#f8fafc"><th style="text-align:left;padding:6px 6px;font-size:11px;text-transform:uppercase;color:#64748b">Bill</th><th style="text-align:left;padding:6px 6px;font-size:11px;text-transform:uppercase;color:#64748b">Vendor</th><th style="text-align:right;padding:6px 6px;font-size:11px;text-transform:uppercase;color:#64748b">Due amt</th><th style="text-align:left;padding:6px 6px;font-size:11px;text-transform:uppercase;color:#64748b">Due date</th><th style="text-align:left;padding:6px 6px;font-size:11px;text-transform:uppercase;color:#64748b">Blocker</th></tr></thead>'
             . '<tbody>' . $body . '</tbody></table>';
    };

    return '<div style="max-width:760px;margin:auto;padding:24px;font-family:system-ui;color:#0f172a">'
         . '<h2 style="margin:0 0 8px;color:#0f172a">Weekly AP queue</h2>'
         . '<p style="color:#475569;margin:0 0 16px;font-size:13px">Hi ' . $h($name ?: 'team') . ', here\'s the AP working set for the week ahead.</p>'
         . '<div style="background:#f1f5f9;border-radius:8px;padding:14px 18px;margin:16px 0;font-size:13px;color:#475569">'
         . '<strong style="color:#0f172a">' . $summary['past_due_count'] . '</strong> past due · '
         . '<strong style="color:#0f172a">$' . number_format($summary['past_due_amount'], 2) . '</strong>'
         . ' &nbsp;&middot;&nbsp; '
         . '<strong style="color:#0f172a">' . $summary['due_soon_count'] . '</strong> due in next 7 days · '
         . '<strong style="color:#0f172a">$' . number_format($summary['due_soon_amount'], 2) . '</strong>'
         . ' &nbsp;&middot;&nbsp; '
         . '<strong style="color:#0f172a">' . $summary['ready_count'] . '</strong> ready to finalize'
         . ' &nbsp;&middot;&nbsp; '
         . '<strong style="color:#dc2626">' . $summary['blocked_count'] . '</strong> blocked'
         . '</div>'
         . '<div style="margin:12px 0 24px"><a href="' . $h($queueUrl) . '" style="display:inline-block;background:#0f172a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px">Open the batch queue →</a></div>'
         . $table('Past due', $bucketed['past_due'])
         . $table('Due in next 7 days', $bucketed['due_soon'])
         . '<p style="margin-top:32px;font-size:11px;color:#94a3b8">Sent by CoreFlux AP. Open the batch queue to finalize bills — finalized bills are routed to the approver automatically with a one-tap email.</p>'
         . '</div>';
}

function ap_weekly_queue_email_text(string $name, array $bucketed, array $summary, string $queueUrl): string {
    $line = function (array $b): string {
        $amt = number_format((float) $b['amount_due'], 2);
        $blocker = $b['blocker'] ?? 'none';
        $detail = $b['blocker_detail'] ?? '';
        return sprintf("  - %-10s  %-30s  \$%-10s  due %s  [%s%s]\n",
            $b['bill_number'] ?: $b['internal_ref'],
            substr((string) $b['vendor_name'], 0, 30),
            $amt, $b['due_date'], $blocker, $detail ? " — {$detail}" : ''
        );
    };
    $out = "Weekly AP queue — hi " . ($name ?: 'team') . "\n\n";
    $out .= "Summary:\n";
    $out .= "  past due:        {$summary['past_due_count']} (\${$summary['past_due_amount']})\n";
    $out .= "  due in 7 days:   {$summary['due_soon_count']} (\${$summary['due_soon_amount']})\n";
    $out .= "  ready to finalize: {$summary['ready_count']}\n";
    $out .= "  blocked:         {$summary['blocked_count']}\n\n";
    if (!empty($bucketed['past_due'])) {
        $out .= "PAST DUE:\n";
        foreach ($bucketed['past_due'] as $b) $out .= $line($b);
        $out .= "\n";
    }
    if (!empty($bucketed['due_soon'])) {
        $out .= "DUE IN NEXT 7 DAYS:\n";
        foreach ($bucketed['due_soon'] as $b) $out .= $line($b);
        $out .= "\n";
    }
    $out .= "Open the batch queue: {$queueUrl}\n";
    return $out;
}
