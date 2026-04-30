<?php
/**
 * Core\MailService — single platform-wide email primitive.
 *
 * Modules MUST go through this service. Never call SMTP / IMAP / Graph API /
 * Gmail API / Resend directly from module code (HARD_RULES — see
 * /app/core/MailService.SPEC.md §1, §2).
 *
 * Skinny 3b scope (this file):
 *   - Public API surface for outbound send + inbound poll
 *   - Driver registry keyed by provider slug
 *   - mail_outbox persistence via injected DB writer (or null in tests)
 *   - LogDriver as default driver (dev-safe, no real provider needed)
 *
 * Out of scope for skinny 3b (added in later phases when Time module ships):
 *   - M365GraphDriver, GmailApiDriver, ResendDriver, ImapDriver
 *   - OAuth start/callback HTTP endpoints (route stubs only)
 *   - Polling cron loop (entry-point script only)
 *
 * SPEC: /app/core/MailService.SPEC.md
 */

namespace Core;

use Core\Mail\MailDriver;
use Core\Mail\LogDriver;

require_once __DIR__ . '/mail/MailDriver.php';
require_once __DIR__ . '/mail/LogDriver.php';

class MailService
{
    private static ?self $instance = null;

    /** @var array<string, MailDriver> provider_slug => driver instance */
    private array $drivers = [];

    /** Default driver used when no per-connection driver is registered. */
    private MailDriver $default;

    /** Optional callable(array $row): int — persists a row to mail_outbox, returns id. */
    private $outboxWriter;

    public static function getInstance(): self
    {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    /** Test reset hook. */
    public static function reset(?MailDriver $default = null, ?callable $outboxWriter = null): self
    {
        self::$instance = new self($default, $outboxWriter);
        return self::$instance;
    }

    public function __construct(?MailDriver $default = null, ?callable $outboxWriter = null)
    {
        $this->default = $default ?? new LogDriver();
        $this->register_driver($this->default);
        $this->outboxWriter = $outboxWriter;
    }

    public function register_driver(MailDriver $driver): void
    {
        $this->drivers[$driver->driver_name()] = $driver;
    }

    public function driver(string $slug): ?MailDriver
    {
        return $this->drivers[$slug] ?? null;
    }

    public function default_driver_name(): string
    {
        return $this->default->driver_name();
    }

    /**
     * Send an outbound message. Returns
     *   ['outbox_id' => int|null, 'status' => 'sent'|'failed',
     *    'provider_message_id' => string|null, 'error' => string|null].
     *
     * @param array $opts ['from' => string, 'reply_to' => string, 'driver' => string,
     *                     'connection_id' => int, 'attachments' => array<int>]
     */
    public function send(
        int    $tenantId,
        string $module,
        string $purpose,
        array  $to,
        string $subject,
        string $bodyText,
        ?string $bodyHtml = null,
        array  $attachments = [],
        array  $opts = []
    ): array {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('MailService::send tenant_id must be > 0');
        }
        if (empty($to)) {
            throw new \InvalidArgumentException('MailService::send recipients required');
        }
        $to = array_values(array_unique(array_filter(array_map(
            fn($x) => is_string($x) ? trim($x) : '',
            $to
        ))));
        if (empty($to)) {
            throw new \InvalidArgumentException('MailService::send recipients required');
        }
        foreach ($to as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException("MailService::send invalid recipient: {$addr}");
            }
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('MailService::send subject required');
        }

        $driverSlug = $opts['driver'] ?? $this->default->driver_name();
        $driver     = $this->drivers[$driverSlug] ?? $this->default;

        $envelope = [
            'tenant_id'     => $tenantId,
            'module'        => $module,
            'purpose'       => $purpose,
            'from'          => $opts['from']      ?? null,
            'from_name'     => $opts['from_name'] ?? null,
            'reply_to'      => $opts['reply_to']  ?? null,
            'to'            => $to,
            'subject'       => $subject,
            'body_text'     => $bodyText,
            'body_html'     => $bodyHtml,
            'attachments'   => $attachments,
            'connection_id' => $opts['connection_id'] ?? null,
            'idempotency_key' => $opts['idempotency_key'] ?? null,
        ];

        $result = $driver->send($envelope);

        $outboxId = null;
        if (is_callable($this->outboxWriter)) {
            $outboxId = (int) call_user_func($this->outboxWriter, [
                'tenant_id'           => $tenantId,
                'module'              => $module,
                'purpose'             => $purpose,
                'connection_id'       => $opts['connection_id'] ?? null,
                'to_addresses_json'   => json_encode($to),
                'from_address'        => $opts['from'] ?? null,
                'reply_to'            => $opts['reply_to'] ?? null,
                'subject'             => $subject,
                'body_text'           => $bodyText,
                'body_html'           => $bodyHtml,
                'attachments_json'    => json_encode(array_values($attachments)),
                'status'              => $result['status'] ?? 'failed',
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'sent_at'             => $result['sent_at'] ?? null,
                'error'               => $result['error'] ?? null,
                'driver'              => $driver->driver_name(),
            ]);
        }

        return [
            'outbox_id'           => $outboxId,
            'status'              => $result['status'] ?? 'failed',
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'error'               => $result['error'] ?? null,
            'driver'              => $driver->driver_name(),
        ];
    }

    /**
     * Poll a configured folder for new messages.
     * Skinny 3b: dispatches to whichever driver matches the connection's
     * provider slug. With only LogDriver registered, this returns no messages.
     */
    public function poll_folder(int $folderId, ?string $providerSlug = null, ?string $cursor = null): array
    {
        $slug   = $providerSlug ?? $this->default->driver_name();
        $driver = $this->drivers[$slug] ?? $this->default;
        return $driver->poll($folderId, $cursor);
    }

    /**
     * Stub OAuth start for a tenant. Real M365/Gmail flows are added in a
     * later phase; for skinny 3b this returns a synthetic state token so
     * the route plumbing can be wired and tested.
     */
    public function start_oauth_flow(int $tenantId, string $provider, string $purpose): array
    {
        if (!in_array($provider, ['m365', 'google'], true)) {
            throw new \InvalidArgumentException("Unsupported OAuth provider: {$provider}");
        }
        $state = bin2hex(random_bytes(16));
        return [
            'provider'     => $provider,
            'purpose'      => $purpose,
            'tenant_id'    => $tenantId,
            'state'        => $state,
            'authorize_url' => null, // populated by real driver in later phase
            'message'      => 'OAuth flow scaffold — real provider driver not wired yet',
        ];
    }
}
