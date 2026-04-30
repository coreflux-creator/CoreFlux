<?php
/**
 * Core tenant-mail helpers (Model B).
 *
 * Resolves the effective sender for an outbound email given (tenant, module).
 * Model B rule: From is always the platform-wide RESEND_FROM_EMAIL, but the
 * display name can be tenant-overridden and Reply-To defaults to the tenant's
 * inbox so replies land with them (not the platform operator).
 *
 * Model C (tenant-verified custom From domain) will bolt on later in
 * `core/migrations/005_*.sql` + this helper without breaking callers.
 *
 * Returns:
 *   [
 *     'from'       => string  // always platform RESEND_FROM_EMAIL in Model B
 *     'from_name'  => ?string // tenant override else platform default
 *     'reply_to'   => ?string // tenant override else null (header omitted)
 *     'model'      => 'B'     // reserved for C later
 *   ]
 */

require_once __DIR__ . '/db.php';

if (!function_exists('cf_tenant_mail_sender')) {
    function cf_tenant_mail_sender(int $tenantId, string $module = 'core'): array
    {
        $platformFrom = getenv('RESEND_FROM_EMAIL') ?: (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : null);
        $platformName = getenv('RESEND_FROM_NAME')  ?: (defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME  : null);

        $replyTo  = null;
        $fromName = $platformName;

        if ($tenantId > 0) {
            try {
                $pdo = getDB();
                if ($pdo) {
                    $stmt = $pdo->prepare(
                        'SELECT mail_reply_to, mail_from_name_override
                         FROM tenants WHERE id = :id LIMIT 1'
                    );
                    $stmt->execute(['id' => $tenantId]);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
                    if ($row) {
                        if (!empty($row['mail_reply_to']))            $replyTo  = (string) $row['mail_reply_to'];
                        if (!empty($row['mail_from_name_override']))  $fromName = (string) $row['mail_from_name_override'];
                    }
                }
            } catch (\Throwable $e) {
                // Schema not migrated yet? fall back to platform defaults.
                error_log('[tenant_mail] lookup failed: ' . $e->getMessage());
            }
        }

        return [
            'from'      => $platformFrom,
            'from_name' => $fromName,
            'reply_to'  => $replyTo,
            'model'     => 'B',
        ];
    }
}
