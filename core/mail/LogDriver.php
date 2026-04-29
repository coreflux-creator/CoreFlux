<?php
/**
 * LogDriver — dev-only MailDriver that records outbound mail and reports
 * an empty inbox. Suitable for local development, CI, and modules that
 * want to integrate against MailService before any real OAuth provider
 * is wired.
 *
 * Outbound: writes the envelope to a JSONL log on disk and (when MailService
 * persists it) to mail_outbox with status='sent'. NOTHING actually leaves
 * the box.
 *
 * Inbound: poll() always returns an empty result.
 *
 * SPEC: /app/core/MailService.SPEC.md §11 (skinny 3b cut)
 */

namespace Core\Mail;

class LogDriver implements MailDriver
{
    private string $logPath;

    public function __construct(?string $logPath = null)
    {
        $this->logPath = $logPath
            ?? (getenv('MAIL_LOG_PATH') ?: '/app/storage/_dev/mail_outbox.log');
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    public function poll(int $folderId, ?string $cursor): array
    {
        // LogDriver is outbound-shaped; no inbox to poll.
        return ['messages' => [], 'next_cursor' => $cursor];
    }

    public function send(array $envelope): array
    {
        $line = json_encode([
            'at'        => gmdate(DATE_ATOM),
            'tenant_id' => $envelope['tenant_id'] ?? null,
            'module'    => $envelope['module']    ?? null,
            'purpose'   => $envelope['purpose']   ?? null,
            'from'      => $envelope['from']      ?? null,
            'to'        => $envelope['to']        ?? [],
            'subject'   => $envelope['subject']   ?? '',
            'has_html'  => !empty($envelope['body_html']),
            'attach_n'  => count($envelope['attachments'] ?? []),
        ], JSON_UNESCAPED_SLASHES);

        @file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX);

        return [
            'provider_message_id' => 'log-' . bin2hex(random_bytes(8)),
            'sent_at'             => gmdate('Y-m-d H:i:s'),
            'status'              => 'sent',
            'error'               => null,
        ];
    }

    public function refresh_oauth(int $connectionId): void { /* no-op */ }
    public function revoke(int $connectionId): void { /* no-op */ }

    public function driver_name(): string { return 'log'; }

    /** Test helper: returns the log path so tests can assert on contents. */
    public function log_path(): string { return $this->logPath; }
}
