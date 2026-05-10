<?php
/**
 * POST /api/auth/request_magic_link.php
 *
 *   body: { email: string, redirect_path?: string, tenant_id?: number }
 *   resp: { ok: true, message: "If <email> exists, we've sent a sign-in link." }
 *
 * Behavior:
 *   • Generic success regardless of whether email exists (prevents enumeration).
 *   • Issues link via magicLinkIssue() — rate-limit handled inside.
 *   • Sends email via Core\MailService when bootstrap is available; if mail
 *     isn't configured (dev / phase A tenants), surfaces the link in the
 *     response under `_dev_link` so the developer can copy/paste. Production
 *     never sees this field because mail bootstrap WILL be configured there.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/magic_link.php';
require_once __DIR__ . '/../../core/mail_bootstrap.php';

if (api_method() !== 'POST') api_error('Method not allowed', 405);

$body = api_json_body();
$email = strtolower(trim((string) ($body['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    api_error('Invalid email', 422);
}
$redirectPath = (string) ($body['redirect_path'] ?? '/');
$tenantId     = isset($body['tenant_id']) ? (int) $body['tenant_id'] : null;

$genericResponse = [
    'ok'      => true,
    'message' => "If an account exists for {$email}, we've sent a sign-in link. Check your inbox.",
];

try {
    $issued = magicLinkIssue(
        $email,
        $tenantId,
        $redirectPath,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
    );
} catch (\RuntimeException $e) {
    // Rate-limited. Still return generic message to avoid leaking that the
    // address was hit before — but include a 429 hint header for legitimate
    // tooling.
    header('Retry-After: 3600');
    api_ok($genericResponse);
}

$url = magicLinkUrl($issued['raw_token']);

// Best-effort email send. Failures don't break the flow — we still
// return generic success (don't leak whether the address has mail config).
$mailSent = false;
$devLink  = null;
try {
    $mail = cf_mail_bootstrap();
    if ($mail !== null && $tenantId) {
        $envelope = [
            'tenant_id'  => $tenantId,
            'module'     => 'core',
            'recipients' => [$email],
            'subject'    => 'Your CoreFlux sign-in link',
            'html'       => _magicLinkHtmlBody($url, $issued['expires_at']),
            'text'       => _magicLinkTextBody($url, $issued['expires_at']),
        ];
        $mail->send($envelope);
        $mailSent = true;
    }
} catch (\Throwable $e) {
    error_log('[magic_link] mail send failed: ' . $e->getMessage());
}

// Development convenience — only when no mail bootstrap is configured AND
// PHP is running with display_errors (i.e. not production).
if (!$mailSent && ini_get('display_errors')) {
    $devLink = $url;
}

$resp = $genericResponse;
if ($devLink) $resp['_dev_link'] = $devLink;
api_ok($resp);

function _magicLinkHtmlBody(string $url, string $expiresAt): string {
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!doctype html>
<html><body style="font-family:-apple-system,Segoe UI,sans-serif;color:#0f172a;background:#f8fafc;padding:32px">
  <div style="max-width:520px;margin:0 auto;background:white;border-radius:12px;padding:32px;border:1px solid #e2e8f0">
    <h1 style="font-size:22px;margin:0 0 12px">Sign in to CoreFlux</h1>
    <p style="font-size:14px;color:#475569;margin:0 0 24px">Click the button below to sign in. This link expires at <strong>{$expiresAt}</strong> UTC and can only be used once.</p>
    <p style="margin:0 0 24px"><a href="{$safeUrl}" style="display:inline-block;background:#0ea5e9;color:white;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:600">Sign in</a></p>
    <p style="font-size:12px;color:#64748b;margin:0">Or copy &amp; paste this URL: <br><code style="word-break:break-all">{$safeUrl}</code></p>
    <p style="font-size:11px;color:#94a3b8;margin-top:24px">If you didn't request this, you can ignore the email — no account changes were made.</p>
  </div>
</body></html>
HTML;
}

function _magicLinkTextBody(string $url, string $expiresAt): string {
    return "Sign in to CoreFlux\n\n"
        . "Click this link to sign in (expires at {$expiresAt} UTC, single use):\n"
        . $url . "\n\n"
        . "If you didn't request this, ignore this email.\n";
}
