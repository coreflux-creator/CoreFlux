<?php
/**
 * /api/admin/mail_test_send.php — confidence check for the mailer wiring.
 *
 * One-button "Send test email" for tenant admins who just dropped a
 * RESEND_API_KEY into config.local.php (or Cloudways env) and want
 * instant proof that:
 *   - the key is valid
 *   - the verified-domain from address is accepted
 *   - the message_id round-trip from Resend is healthy
 *
 *   POST /api/admin/mail_test_send.php
 *     Body: { recipient: "you@yourdomain.com", subject?: "...", body_html?: "..." }
 *
 *   Response:
 *     {
 *       ok: true|false,
 *       driver: "resend" | "log" | "phpmailer_smtp",
 *       message_id: "re_..." | null,
 *       error: null | "string",
 *       fallback: null | "reason",          # set when MailService couldn't deliver
 *                                            # and we fell back to PHPMailer SMTP
 *       resend_configured: true|false,      # true when RESEND_API_KEY is set
 *       tenant_id: int,
 *     }
 *
 * Auth: master_admin / tenant_admin / global. Rate-limited (1 send per
 *       admin per 10 seconds) so a runaway form-press can't drain a key.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/mailer.php';
require_once __DIR__ . '/../../core/audit.php';

$ctx = api_require_auth();
if (api_method() !== 'POST') api_error('Method not allowed', 405);

$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);
if (!$isGlobalAdmin && !in_array($role, ['master_admin', 'tenant_admin'], true)) {
    api_error('Forbidden — admin only', 403);
}

$tenantId = (int) ($ctx['tenant_id'] ?? 0);
$actorId  = (int) ($ctx['user']['id'] ?? 0);
if ($tenantId <= 0) api_error('No active tenant', 400);

$body      = api_json_body();
$recipient = trim((string) ($body['recipient'] ?? ''));
if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    api_error('recipient must be a valid email address', 422);
}
$subject  = trim((string) ($body['subject'] ?? '')) ?: 'CoreFlux mailer test — ' . gmdate('Y-m-d H:i:s') . ' UTC';
$bodyHtml = (string) ($body['body_html'] ?? '');
if ($bodyHtml === '') {
    $bodyHtml =
          "<p>This is a CoreFlux mailer test message.</p>"
        . "<p>If you received this, your <code>RESEND_API_KEY</code> is valid, the sending domain "
        . "is verified, and the <code>mailerSend()</code> shim is delivering correctly.</p>"
        . "<table style='font-size:13px;border-collapse:collapse;margin-top:12px'>"
        . "<tr><td><b>Tenant</b></td><td>" . htmlspecialchars((string) $tenantId) . "</td></tr>"
        . "<tr><td><b>Triggered by</b></td><td>" . htmlspecialchars((string) ($ctx['user']['email'] ?? 'admin')) . "</td></tr>"
        . "<tr><td><b>Sent at</b></td><td>" . gmdate('Y-m-d H:i:s') . " UTC</td></tr>"
        . "</table>";
}

// ----------------------------------------------------------------- rate limit
// In-process rate limit: actor_id → last_send_ts. Persisted on a tiny
// audit row so the limit survives across requests. Soft fail if audit_log
// isn't writable.
try {
    $pdo = getDB();
    if ($pdo) {
        $rl = $pdo->prepare(
            'SELECT created_at FROM audit_log
              WHERE tenant_id = :t AND actor_user_id = :u AND event = "mail.test_send"
              ORDER BY id DESC LIMIT 1'
        );
        $rl->execute(['t' => $tenantId, 'u' => $actorId]);
        $last = $rl->fetchColumn();
        if ($last) {
            $secs = time() - strtotime((string) $last);
            if ($secs >= 0 && $secs < 10) {
                api_error('Too many test sends — please wait ' . (10 - $secs) . 's', 429);
            }
        }
    }
} catch (\Throwable $_) { /* audit_log missing — proceed */ }

// ----------------------------------------------------------------- detect driver state
$resendConfigured =
       (string) getenv('RESEND_API_KEY') !== ''
    || (defined('RESEND_API_KEY') && (string) constant('RESEND_API_KEY') !== '');

// ----------------------------------------------------------------- dispatch
$result = mailerSend([
    'tenant_id' => $tenantId,
    'module'    => 'admin',
    'purpose'   => 'mail_test_send',
    'to'        => $recipient,
    'subject'   => $subject,
    'body_html' => $bodyHtml,
]);

// ----------------------------------------------------------------- audit
try {
    if (!empty($pdo)) {
        platformAuditLogWrite(
            $tenantId,
            $actorId ?: null,
            'mail.test_send',
            null,
            [
                'recipient' => $recipient,
                'ok'        => (bool) ($result['ok'] ?? false),
                'driver'    => (string) ($result['driver'] ?? 'unknown'),
                'error'     => $result['error'] ?? null,
                'fallback'  => $result['fallback'] ?? null,
            ],
            [
                'source' => 'mail',
                'object_type' => 'mail_test_send',
            ]
        );
    }
} catch (\Throwable $_) { /* audit failure non-fatal */ }

api_ok([
    'ok'                => (bool) ($result['ok'] ?? false),
    'driver'            => (string) ($result['driver'] ?? 'unknown'),
    'message_id'        => $result['message_id'] ?? null,
    'error'             => $result['error']      ?? null,
    'fallback'          => $result['fallback']   ?? null,
    'resend_configured' => $resendConfigured,
    'tenant_id'         => $tenantId,
    'recipient'         => $recipient,
    'subject'           => $subject,
]);
