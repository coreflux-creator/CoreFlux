<?php
/**
 * Core MailService bootstrap — registers drivers from env and installs the
 * mail_outbox DB writer. Idempotent; safe to require_once multiple times.
 *
 * Called by any module endpoint that needs to send mail. Keeps MailService
 * driver registration out of the per-module code path.
 *
 * Wiring rules:
 *   - RESEND_API_KEY set  → ResendDriver registered, becomes default outbound.
 *   - CoreAccounting mode → only COREFLUX_ACCOUNTING_RESEND_API_KEY and its sender are used.
 *   - key missing         → ResendDriver remains default and fails visibly.
 *   - MAIL_DRIVER=log     → LogDriver becomes default (explicit dev/test mode).
 *
 * The outbox writer inserts into `mail_outbox` via PDO if the table exists,
 * else it silently skips (so modules still function during Phase A on a
 * database that hasn't run `core/migrations/003_mail_service.sql` yet).
 */

// Eager-load host-only mail configuration before checking constants. Most web
// entry points require mail_bootstrap.php directly and do not otherwise load
// config.local.php. Without this, a valid RESEND_API_KEY defined on the host is
// invisible and production silently selects the non-delivering LogDriver.
$_mailLocalConfig = __DIR__ . '/config.local.php';
if (is_file($_mailLocalConfig)) require_once $_mailLocalConfig;
unset($_mailLocalConfig);

require_once __DIR__ . '/MailService.php';
require_once __DIR__ . '/mail/LogDriver.php';
require_once __DIR__ . '/mail/ResendDriver.php';
require_once __DIR__ . '/db.php';

use Core\MailService;
use Core\Mail\LogDriver;
use Core\Mail\ResendDriver;

if (!function_exists('cf_mail_bootstrap')) {
    function cf_mail_bootstrap(): MailService
    {
        static $booted = null;
        if ($booted instanceof MailService) return $booted;

        // The standalone service must not inherit an ERP provider key or sender
        // from a copied host-local config file.
        $standaloneAccounting = getenv('COREFLUX_ENV') === 'coreaccounting';
        $resendDriver = $standaloneAccounting
            ? new ResendDriver(
                coreAccountingMailSetting('COREFLUX_ACCOUNTING_RESEND_API_KEY'),
                coreAccountingMailSetting('COREFLUX_ACCOUNTING_FROM_EMAIL'),
                coreAccountingMailSetting('COREFLUX_ACCOUNTING_FROM_NAME'),
                null,
                true
            )
            : new ResendDriver();
        // Log-only delivery must be explicit. A missing production key should
        // produce a failed send (from ResendDriver's configuration guard), not
        // a false `sent` result from LogDriver when nothing left the server.
        $mailDriverOverride = strtolower(trim((string) getenv('MAIL_DRIVER')));
        $staging            = defined('COREFLUX_STAGING') && COREFLUX_STAGING;
        $logOnly            = $staging || $mailDriverOverride === 'log';
        $default            = $logOnly
            ? new LogDriver()
            : $resendDriver;

        $writer = function (array $row): int {
            try {
                $pdo = getDB();
                if (!$pdo) return 0;
                $stmt = $pdo->prepare(
                    'INSERT INTO mail_outbox
                      (tenant_id, module, purpose, connection_id, driver,
                       to_addresses_json, cc_addresses_json, from_address, reply_to, subject,
                       body_text, body_html, attachments_json,
                       status, provider_message_id, sent_at, error,
                       created_at)
                     VALUES
                      (:tenant_id, :module, :purpose, :connection_id, :driver,
                       :to_addresses_json, :cc_addresses_json, :from_address, :reply_to, :subject,
                       :body_text, :body_html, :attachments_json,
                       :status, :provider_message_id, :sent_at, :error,
                       NOW())'
                );
                $stmt->execute([
                    'tenant_id'           => $row['tenant_id'],
                    'module'              => $row['module'],
                    'purpose'             => $row['purpose'],
                    'connection_id'       => $row['connection_id'] ?? null,
                    'driver'              => $row['driver'],
                    'to_addresses_json'   => $row['to_addresses_json'],
                    'cc_addresses_json'   => $row['cc_addresses_json'] ?? null,
                    'from_address'        => $row['from_address'] ?? null,
                    'reply_to'            => $row['reply_to'] ?? null,
                    'subject'             => $row['subject'],
                    'body_text'           => $row['body_text'] ?? null,
                    'body_html'           => $row['body_html'] ?? null,
                    'attachments_json'    => $row['attachments_json'] ?? null,
                    'status'              => $row['status'],
                    'provider_message_id' => $row['provider_message_id'] ?? null,
                    'sent_at'             => $row['sent_at'] ?? null,
                    'error'               => $row['error'] ?? null,
                ]);
                return (int) $pdo->lastInsertId();
            } catch (\Throwable $e) {
                error_log('[mail_bootstrap] outbox-write-failed: ' . $e->getMessage());
                return 0;
            }
        };

        $booted = MailService::reset($default, $writer);
        // Keep both drivers addressable for diagnostics and explicit overrides.
        if ($default->driver_name() === 'resend') $booted->register_driver(new LogDriver());
        elseif (!$staging)                        $booted->register_driver($resendDriver);
        return $booted;
    }
}
