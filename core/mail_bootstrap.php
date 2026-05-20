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
 *   - else                → LogDriver remains default (dev-safe).
 *
 * The outbox writer inserts into `mail_outbox` via PDO if the table exists,
 * else it silently skips (so modules still function during Phase A on a
 * database that hasn't run `core/migrations/003_mail_service.sql` yet).
 */

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

        // Resend key may live in env (Cloudways pattern) or as a define() in
        // /app/core/config.local.php (matches existing OpenAI / Plaid secrets).
        // Either path satisfies the "configured" check.
        $resendKey = (string) getenv('RESEND_API_KEY');
        if ($resendKey === '' && defined('RESEND_API_KEY')) {
            $resendKey = (string) constant('RESEND_API_KEY');
        }
        $default   = $resendKey !== '' ? new ResendDriver() : new LogDriver();

        $writer = function (array $row): int {
            try {
                $pdo = getDB();
                if (!$pdo) return 0;
                $stmt = $pdo->prepare(
                    'INSERT INTO mail_outbox
                      (tenant_id, module, purpose, connection_id, driver,
                       to_addresses_json, from_address, reply_to, subject,
                       body_text, body_html, attachments_json,
                       status, provider_message_id, sent_at, error,
                       created_at)
                     VALUES
                      (:tenant_id, :module, :purpose, :connection_id, :driver,
                       :to_addresses_json, :from_address, :reply_to, :subject,
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
        if ($resendKey !== '') {
            // LogDriver still useful for debugging alongside Resend.
            $booted->register_driver(new LogDriver());
        } else {
            $booted->register_driver(new ResendDriver()); // registered but not default; send fails cleanly if invoked without key
        }
        return $booted;
    }
}
