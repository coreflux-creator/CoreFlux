<?php
/**
 * Daily approval reminder emails.
 *
 * Walks every active tenant. For each tenant, finds users with pending
 * approvals (AP bills + workflow tasks) and sends them ONE consolidated
 * email per day with magic-link CTAs to review now.
 *
 * Distinct from the weekly AI digest:
 *   - Daily cadence (not weekly)
 *   - Only sends when there's something pending (zero email = no email)
 *   - One email per (user, tenant) per day max — idempotent via
 *     reminder_sent_at column on users (per-tenant scoped via user_tenants).
 *
 * Idempotency strategy: we use `auth_magic_link_attempts.last_attempt`
 * as a proxy — if we already minted a reminder link for this email in
 * the last 20 hours, skip. Cheap and self-resetting.
 *
 * Run from cron: every weekday at 09:00 tenant local time. For now we
 * run UTC and let send_dow filter; tenant-local scheduling is a follow-up.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/api_bootstrap.php';
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/magic_link.php';
require_once __DIR__ . '/../core/mailer.php';
require_once __DIR__ . '/../core/tenant_mail.php';
require_once __DIR__ . '/../core/workflow_engine.php';
require_once __DIR__ . '/../core/ai_agents.php';

$pdo = getDB();
if (!$pdo) {
    fwrite(STDERR, "[approval-reminders] DB unavailable\n");
    exit(2);
}

$tenants = $pdo->query("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$totalSent = 0;
$totalSkipped = 0;
$totalFailed = 0;

foreach ($tenants as $tenant) {
    $tenantId = (int) $tenant['id'];

    // Pull every (user, email) on this tenant who *might* need a reminder.
    // We deliberately scope to active memberships only.
    $stmt = $pdo->prepare(
        'SELECT u.id, u.email
           FROM users u
           JOIN user_tenants ut ON ut.user_id = u.id
          WHERE ut.tenant_id = :t AND ut.status = \'active\'
            AND u.email IS NOT NULL AND u.email <> \'\''
    );
    $stmt->execute(['t' => $tenantId]);
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $u) {
        $email = (string) $u['email'];
        $userId = (int) $u['id'];

        // Cheap idempotency: did we mint a reminder for this email recently?
        $lastSent = _approvalReminderLastSent($pdo, $email, $tenantId);
        if ($lastSent && (time() - $lastSent) < 20 * 3600) {
            $totalSkipped++;
            continue;
        }

        $counts = aiAgentDigestRecipientCounts($tenantId, $email);
        if ($counts['pending_total'] <= 0) {
            $totalSkipped++;
            continue;
        }

        try {
            $body = _approvalReminderBody($tenantId, $email, $counts, (string) $tenant['name']);
            $sender = cf_tenant_mail_sender($tenantId, 'approvals');
            sendEmail([
                'to'         => [$email],
                'subject'    => $body['subject'],
                'body_html'  => $body['html'],
                'body_text'  => $body['text'],
                'from_email' => $sender['from']      ?? null,
                'from_name'  => $sender['from_name'] ?? null,
                'reply_to'   => $sender['reply_to']  ?? null,
            ]);
            _approvalReminderMarkSent($pdo, $tenantId, $userId, $email);
            $totalSent++;
        } catch (\Throwable $e) {
            error_log('[approval-reminders] send failed for ' . $email . ' tenant=' . $tenantId . ': ' . $e->getMessage());
            $totalFailed++;
        }
    }
}

echo "[approval-reminders] sent={$totalSent} skipped={$totalSkipped} failed={$totalFailed}\n";
exit($totalFailed > 0 ? 1 : 0);

/* ----- helpers --------------------------------------------------------- */

/**
 * Best-effort idempotency: latest `auth_magic_link_attempts.last_attempt`
 * for (sha256(localhost+email)). Cron runs from a single host so the IP
 * is stable. If table doesn't exist yet, returns null (we'll send).
 */
function _approvalReminderLastSent(PDO $pdo, string $email, int $tenantId): ?int {
    try {
        // We use a dedicated audit row in tenant_provisioning_log to mark
        // sends keyed by (tenant_id, user_email, action='approval_reminder').
        $st = $pdo->prepare(
            "SELECT UNIX_TIMESTAMP(MAX(at)) AS sent_at
               FROM tenant_provisioning_log
              WHERE parent_tenant_id = :t
                AND action = 'approval_reminder'
                AND meta_json LIKE :em"
        );
        $st->execute(['t' => $tenantId, 'em' => '%"' . $email . '"%']);
        $row = $st->fetch();
        return $row && !empty($row['sent_at']) ? (int) $row['sent_at'] : null;
    } catch (\Throwable $_) {
        return null;
    }
}

function _approvalReminderMarkSent(PDO $pdo, int $tenantId, int $userId, string $email): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO tenant_provisioning_log
              (parent_tenant_id, sub_tenant_id, actor_user_id, action, meta_json, at)
             VALUES (:t, NULL, :u, 'approval_reminder', :meta, NOW())"
        );
        $st->execute([
            't'    => $tenantId,
            'u'    => $userId,
            'meta' => json_encode(['email' => $email], JSON_UNESCAPED_SLASHES),
        ]);
    } catch (\Throwable $_) { /* schema-tolerant */ }
}

/**
 * Render the reminder envelope. Reuses the same magic-link pattern as the
 * weekly digest — single-use, 24h TTL (shorter than digest's 72h since this
 * email is meant to be acted on today).
 */
function _approvalReminderBody(int $tenantId, string $email, array $counts, string $tenantName): array {
    $apN = (int) $counts['ap_approvals_pending'];
    $wfN = (int) $counts['workflow_pending'];

    $url = null;
    try {
        $issued = magicLinkIssue(
            $email,
            $tenantId,
            $counts['deep_link'] ?? '/workflow',
            null,
            'CoreFlux/approval-reminder',
            /* ttlMinutes */ 24 * 60
        );
        $url = magicLinkUrl($issued['raw_token']);
    } catch (\Throwable $_) { /* fall back to passwordful login */ }

    $parts = [];
    if ($apN > 0) $parts[] = "<strong>{$apN}</strong> AP bill" . ($apN === 1 ? '' : 's') . " awaiting your approval";
    if ($wfN > 0) $parts[] = "<strong>{$wfN}</strong> workflow task" . ($wfN === 1 ? '' : 's') . " in your inbox";
    $headlinePlain = "You have " . strtolower(strip_tags(implode(' and ', $parts))) . ".";

    $cta = '';
    $ctaText = '';
    if ($url) {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $cta = "<div style=\"margin:18px 0\"><a href=\"{$safeUrl}\" "
             . "style=\"display:inline-block;background:#0ea5e9;color:#fff;text-decoration:none;"
             . "padding:11px 20px;border-radius:8px;font-family:system-ui;font-size:14px;font-weight:600\">"
             . "Review now →</a></div>";
        $ctaText = "\nReview now (link expires in 24 hours): {$url}\n";
    }

    $safeTenant = htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8');
    $safeHeadline = implode(' and ', $parts);
    $html = "<div style=\"max-width:560px;margin:auto;padding:20px;font-family:system-ui;color:#0f172a\">"
          . "<h2 style=\"margin:0 0 8px;color:#0f172a;font-size:20px\">Approvals waiting on you</h2>"
          . "<p style=\"margin:0 0 6px;color:#475569;font-size:13px\">in {$safeTenant}</p>"
          . "<p style=\"margin:14px 0 0;font-size:15px;line-height:1.5\">{$safeHeadline}.</p>"
          . $cta
          . "<p style=\"margin-top:24px;color:#94a3b8;font-size:11px\">This is your daily reminder. "
          . "Sign-in link is personal, single-use, and expires in 24 hours. "
          . "Reply to reach your tenant administrator.</p>"
          . "</div>";

    $text = "Approvals waiting on you in {$tenantName}\n\n"
          . $headlinePlain . "\n"
          . $ctaText
          . "\n--\nDaily reminder · CoreFlux\n";

    $subject = "[" . $tenantName . "] " . count($parts) . " approval"
             . (count($parts) === 1 ? '' : 's') . " waiting";

    return ['subject' => $subject, 'html' => $html, 'text' => $text];
}
