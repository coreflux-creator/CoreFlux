<?php
/**
 * MailDriver — pluggable backend interface for Core\MailService.
 *
 * Implementations (Phase A skinny):
 *   - LogDriver       — records to mail_outbox, never sends. Default in dev.
 *
 * Implementations (later phases, NOT in skinny 3b):
 *   - M365GraphDriver — Microsoft 365 via Graph API (inbound + outbound).
 *   - GmailApiDriver  — Google Workspace via Gmail API.
 *   - ImapDriver      — IMAP fallback (Phase B).
 *   - ResendDriver    — outbound only via shared Resend account.
 *
 * The active driver is selected per-connection at runtime by MailService,
 * NOT by a single env var (because different tenants pick different providers).
 *
 * SPEC: /app/core/MailService.SPEC.md
 */

namespace Core\Mail;

interface MailDriver
{
    /**
     * Poll a folder for new messages since the last cursor.
     * Returns ['messages' => MailMessage[], 'next_cursor' => string|null].
     *
     * For LogDriver this is a no-op returning an empty list.
     */
    public function poll(int $folderId, ?string $cursor): array;

    /**
     * Send an outbound message. Returns
     * ['provider_message_id' => string|null, 'sent_at' => string|null,
     *  'status' => 'sent'|'failed', 'error' => string|null].
     */
    public function send(array $envelope): array;

    /**
     * Refresh OAuth tokens for the connection if expired or near expiry.
     * No-op for non-OAuth drivers (LogDriver, ResendDriver).
     */
    public function refresh_oauth(int $connectionId): void;

    /**
     * Revoke connection at the provider (e.g. delete Graph subscription).
     * No-op for non-OAuth drivers.
     */
    public function revoke(int $connectionId): void;

    /** Provider slug for logs, e.g. 'log', 'm365', 'google', 'resend'. */
    public function driver_name(): string;
}
