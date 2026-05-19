<?php
/**
 * QBO Slice 4a follow-on — Sync health email alerts.
 *
 * Daily cron evaluates the sync_health status for every active connection
 * and, when the status worsens since the last alert row (e.g. green→red
 * or yellow→red), emails the tenant admin and persists a dedupe row in
 * qbo_health_alerts. Recovery transitions (red→green) also fire a one-shot
 * "recovered" email so on-call doesn't have to manually verify the fix.
 *
 * No more alerts fire while status stays at the same severity — the
 * dedupe row blocks duplicates until the status changes again.
 *
 * Public surface:
 *   qboHealthEvaluate(int $tid): array  // {status, reasons, ...} same shape as /sync_health
 *   qboHealthMaybeAlert(int $tid): array // {fired, status_before, status_after, sent}
 */
declare(strict_types=1);

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../mailer.php';

const QBO_HEALTH_SEVERITY = ['green' => 0, 'not_connected' => 0, 'yellow' => 1, 'red' => 2];

/**
 * Re-implements the api/qbo.php sync_health decision tree without an
 * HTTP round-trip. Caller is responsible for ensuring the tenant has a
 * QBO connection row.
 */
function qboHealthEvaluate(int $tenantId): array
{
    $pdo = getDB();
    $row = qboConnection($tenantId);
    if (!$row || $row['status'] !== 'active') {
        return [
            'status'    => 'not_connected',
            'reasons'   => $row && $row['status'] === 'error'
                ? ['Connection in error: ' . ($row['last_probe_error'] ?? 'unknown')]
                : ['Not connected'],
        ];
    }
    $probeAt = $row['last_probe_at'] ? strtotime((string) $row['last_probe_at']) : 0;
    $probeAge = $probeAt ? max(0, time() - $probeAt) : null;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM qbo_sync_audit WHERE tenant_id = :t AND action = 'sync_je_skip' AND occurred_at >= (NOW() - INTERVAL 7 DAY)");
    $stmt->execute(['t' => $tenantId]);
    $blocked7d = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM qbo_sync_audit WHERE tenant_id = :t AND ok = 0 AND occurred_at >= (NOW() - INTERVAL 24 HOUR)");
    $stmt->execute(['t' => $tenantId]);
    $failed24h = (int) $stmt->fetchColumn();

    $status = 'green';
    $reasons = [];
    if ($probeAge === null || $probeAge > 24 * 3600) { $status = 'red'; $reasons[] = $probeAge === null ? 'never probed' : 'probe stale > 24h'; }
    elseif ($probeAge > 2 * 3600) { if ($status === 'green') $status = 'yellow'; $reasons[] = 'probe stale > 2h'; }
    if ($failed24h > 0) { $status = 'red'; $reasons[] = $failed24h . ' failed run(s) in last 24h'; }
    if ($blocked7d > 20) { $status = 'red'; $reasons[] = $blocked7d . ' JEs blocked on unmapped accounts (7d)'; }
    elseif ($blocked7d > 0) { if ($status === 'green') $status = 'yellow'; $reasons[] = $blocked7d . ' JE(s) blocked on unmapped accounts (7d)'; }

    return [
        'status'         => $status,
        'reasons'        => $reasons,
        'blocked_jes_7d' => $blocked7d,
        'failed_runs_24h'=> $failed24h,
        'probe_age_sec'  => $probeAge,
        'company_name'   => $row['company_name'],
    ];
}

/**
 * Compare current status against the last alert row. Fire (and persist)
 * exactly when the severity changes.
 */
function qboHealthMaybeAlert(int $tenantId): array
{
    $now = qboHealthEvaluate($tenantId);
    $pdo = getDB();
    $stmt = $pdo->prepare('SELECT status_after FROM qbo_health_alerts WHERE tenant_id = :t ORDER BY notified_at DESC LIMIT 1');
    $stmt->execute(['t' => $tenantId]);
    $lastStatus = $stmt->fetchColumn();
    $lastStatus = $lastStatus !== false ? (string) $lastStatus : null;

    if ($lastStatus === $now['status']) {
        return ['fired' => false, 'status_before' => $lastStatus, 'status_after' => $now['status'], 'sent' => false];
    }
    // Only escalate on severity change (transition between green ↔ yellow ↔ red
    // or any transition into/out of not_connected). Anything else fires.
    $recipient = _qboTenantAdminEmail($tenantId);
    if (!$recipient) {
        // No deliverable recipient — record but don't try to send.
        _qboLogAlert($tenantId, $lastStatus ?? 'unknown', $now['status'], $now['reasons'], null, false, 'no_recipient');
        return ['fired' => true, 'status_before' => $lastStatus, 'status_after' => $now['status'], 'sent' => false, 'error' => 'no_recipient'];
    }
    [$subject, $textBody, $htmlBody] = _qboBuildAlertEmail($tenantId, $now, $lastStatus);
    $sentOk = true; $err = null;
    try {
        sendEmail([
            'to'        => $recipient,
            'subject'   => $subject,
            'body_text' => $textBody,
            'body_html' => $htmlBody,
        ]);
    } catch (\Throwable $e) {
        $sentOk = false;
        $err = substr($e->getMessage(), 0, 500);
    }
    _qboLogAlert($tenantId, $lastStatus ?? 'unknown', $now['status'], $now['reasons'], $recipient, $sentOk, $err);
    return ['fired' => true, 'status_before' => $lastStatus, 'status_after' => $now['status'], 'sent' => $sentOk, 'error' => $err];
}

function _qboLogAlert(int $tid, string $before, string $after, array $reasons, ?string $recipient, bool $sentOk, ?string $err): void
{
    try {
        getDB()->prepare(
            'INSERT INTO qbo_health_alerts (tenant_id, status_before, status_after, reasons, recipient_email, sent_ok, send_error)
             VALUES (:t, :b, :a, :r, :e, :s, :err)'
        )->execute([
            't'  => $tid,
            'b'  => $before,
            'a'  => $after,
            'r'  => json_encode($reasons),
            'e'  => $recipient,
            's'  => $sentOk ? 1 : 0,
            'err'=> $err,
        ]);
    } catch (\Throwable $e) { /* best effort */ }
}

/**
 * Find a deliverable tenant admin email. Falls back to the connection
 * owner (qbo_connections.connected_by_user_id) if no master_admin exists.
 */
function _qboTenantAdminEmail(int $tenantId): ?string
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT email FROM users
          WHERE tenant_id = :t
            AND role IN ('master_admin','tenant_admin')
            AND email IS NOT NULL AND email <> ''
          ORDER BY (role='master_admin') DESC, id ASC
          LIMIT 1"
    );
    try {
        $stmt->execute(['t' => $tenantId]);
        $email = $stmt->fetchColumn();
        if ($email) return (string) $email;
    } catch (\Throwable $e) { /* fall through */ }
    // Connection owner fallback.
    $row = qboConnection($tenantId);
    if ($row && !empty($row['connected_by_user_id'])) {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        try {
            $stmt->execute(['id' => $row['connected_by_user_id']]);
            $email = $stmt->fetchColumn();
            if ($email) return (string) $email;
        } catch (\Throwable $e) { /* fall through */ }
    }
    return null;
}

function _qboBuildAlertEmail(int $tid, array $now, ?string $before): array
{
    $after = $now['status'];
    $improving = QBO_HEALTH_SEVERITY[$after] < QBO_HEALTH_SEVERITY[$before ?? 'green'];
    $emoji   = $improving ? 'recovered' : 'degraded';
    $subject = sprintf('[CoreFlux] QuickBooks sync %s — %s → %s', $emoji, $before ?: 'unknown', $after);
    $reasonList = $now['reasons'] ?: ['(no reasons recorded)'];
    $text = "QuickBooks sync health for tenant {$tid}\n"
          . "Status: " . ($before ?: 'unknown') . " → " . $after . "\n"
          . "Company: " . ($now['company_name'] ?? '—') . "\n\n"
          . "Reasons:\n - " . implode("\n - ", $reasonList) . "\n\n"
          . "Open in CoreFlux: /admin/integrations/qbo";
    $html = '<p>QuickBooks sync health for tenant ' . (int) $tid . '</p>'
          . '<p><strong>Status:</strong> ' . htmlspecialchars($before ?: 'unknown') . ' → ' . htmlspecialchars($after) . '</p>'
          . '<p><strong>Company:</strong> ' . htmlspecialchars((string) ($now['company_name'] ?? '—')) . '</p>'
          . '<p><strong>Reasons:</strong></p><ul>'
          . implode('', array_map(static fn ($r) => '<li>' . htmlspecialchars((string) $r) . '</li>', $reasonList))
          . '</ul>'
          . '<p><a href="/admin/integrations/qbo">Open QuickBooks settings</a></p>';
    return [$subject, $text, $html];
}
