<?php
/**
 * /api/admin/mail_status.php
 *
 * Diagnostic endpoint for the mail subsystem. Returns the currently-
 * configured default driver, whether the Resend API key is detectable
 * (without leaking the key itself), how many drivers are registered,
 * and the last 5 mail_outbox rows so the operator can quickly verify
 * external delivery is wired correctly.
 *
 * Why this endpoint:
 *   The handoff summary said `mailerSend()` was mocked; in reality the
 *   ResendDriver implementation is complete and `mail_bootstrap.php`
 *   wires it as the default when RESEND_API_KEY is present. Operators
 *   had no quick way to confirm that — leading to "still mocked"
 *   reports even when delivery was actually live. This endpoint
 *   surfaces the state in one call.
 *
 * RBAC: tenant_admin.integrations.
 *
 * Response:
 *   {
 *     ok: true,
 *     default_driver:    "resend" | "log",
 *     resend_configured: true | false,
 *     resend_from_email: "no-reply@..."  | null,
 *     registered_drivers:[ "resend", "log", … ],
 *     outbox_recent: [
 *       { id, module, purpose, status, driver, sent_at, provider_message_id, error }, ...
 *     ]
 *   }
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';
require_once __DIR__ . '/../../core/mail_bootstrap.php';

$ctx  = api_require_auth();
$user = $ctx['user'];
$tid  = (int) $ctx['tenant_id'];
rbac_legacy_require($user, 'tenant_admin.integrations');

// Detect RESEND_API_KEY without leaking the value.
$resendKey = (string) getenv('RESEND_API_KEY');
if ($resendKey === '' && defined('RESEND_API_KEY')) {
    $resendKey = (string) constant('RESEND_API_KEY');
}
$resendConfigured = $resendKey !== '';
$resendFrom = (string) getenv('RESEND_FROM_EMAIL');
if ($resendFrom === '' && defined('RESEND_FROM_EMAIL')) {
    $resendFrom = (string) constant('RESEND_FROM_EMAIL');
}

$defaultDriver = 'log';
$registered    = ['log'];
try {
    $svc = cf_mail_bootstrap();
    // Inspect via the same logic mail_bootstrap uses — if RESEND_API_KEY
    // was set when the service booted, ResendDriver became default.
    $defaultDriver = $resendConfigured ? 'resend' : 'log';
    $registered    = $resendConfigured ? ['resend', 'log'] : ['log', 'resend'];
} catch (\Throwable $e) {
    // Even if MailService can't boot, we still tell the operator the
    // env-var state so they can debug.
}

// Pull the 5 most recent mail_outbox rows for this tenant.
$outboxRecent = [];
try {
    $pdo = getDB();
    if ($pdo) {
        $st = $pdo->prepare(
            'SELECT id, module, purpose, status, driver, sent_at,
                    provider_message_id, error, created_at
               FROM mail_outbox
              WHERE tenant_id = :t
              ORDER BY id DESC
              LIMIT 5'
        );
        $st->execute(['t' => $tid]);
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            unset($r['tenant_id']);
            $outboxRecent[] = $r;
        }
    }
} catch (\Throwable $e) {
    // Soft-fail — endpoint stays useful even without outbox history.
}

api_ok([
    'ok'                 => true,
    'default_driver'     => $defaultDriver,
    'resend_configured'  => $resendConfigured,
    'resend_key_hint'    => $resendConfigured ? (substr($resendKey, 0, 5) . '…') : null,
    'resend_from_email'  => $resendFrom !== '' ? $resendFrom : null,
    'registered_drivers' => $registered,
    'outbox_recent'      => $outboxRecent,
    'hint'               => $resendConfigured
        ? 'Resend is the active default driver. mailerSend() calls deliver via api.resend.com.'
        : 'RESEND_API_KEY is not set. Add `define(\'RESEND_API_KEY\', \'re_…\');` to /app/core/config.local.php (or set the env var), then ensure RESEND_FROM_EMAIL is configured with a verified sender domain.',
]);
