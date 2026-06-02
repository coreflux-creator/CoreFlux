<?php
/**
 * ResendDriver — Core\MailDriver implementation backed by Resend's REST API.
 *
 * Outbound only (Resend is a send-only transactional provider). poll() returns
 * empty. OAuth hooks are no-ops (Resend uses a static API key).
 *
 * Config via env:
 *   RESEND_API_KEY       — required; starts with "re_"
 *   RESEND_FROM_EMAIL    — optional fallback from address (e.g. no-reply@yourdomain)
 *   RESEND_FROM_NAME     — optional fallback from name
 *
 * The driver accepts an injected HTTP transport closure (for tests) but defaults
 * to PHP cURL. No SDK, no Guzzle.
 *
 * SPEC: /app/core/MailService.SPEC.md §11 (outbound driver contract)
 */

namespace Core\Mail;

require_once __DIR__ . '/MailDriver.php';

class ResendDriver implements MailDriver
{
    private const API_URL = 'https://api.resend.com/emails';

    private string $apiKey;
    private ?string $defaultFromEmail;
    private ?string $defaultFromName;

    /** @var (callable(array):array)|null Optional test transport: fn($envelope) => ['ok'=>bool,'id'=>?,'error'=>?,'http'=>int] */
    private $transport;

    public function __construct(?string $apiKey = null, ?string $defaultFromEmail = null, ?string $defaultFromName = null, ?callable $transport = null)
    {
        // Accept both `define()` and `getenv()` conventions. `define()` matches
        // the existing OpenAI / Plaid secrets in /app/core/config.local.php;
        // `getenv()` matches the original driver contract + Cloudways env-var
        // ops workflow. Caller-supplied $apiKey still wins (used by tests).
        $envKey      = (string) getenv('RESEND_API_KEY');
        $envFrom     = (string) getenv('RESEND_FROM_EMAIL');
        $envFromName = (string) getenv('RESEND_FROM_NAME');

        $defKey      = defined('RESEND_API_KEY')    ? (string) constant('RESEND_API_KEY')    : '';
        $defFrom     = defined('RESEND_FROM_EMAIL') ? (string) constant('RESEND_FROM_EMAIL') : '';
        $defFromName = defined('RESEND_FROM_NAME')  ? (string) constant('RESEND_FROM_NAME')  : '';

        $this->apiKey           = $apiKey            ?? ($envKey !== '' ? $envKey : $defKey);
        $this->defaultFromEmail = $defaultFromEmail  ?? ($envFrom !== '' ? $envFrom : ($defFrom !== '' ? $defFrom : null));
        $this->defaultFromName  = $defaultFromName   ?? ($envFromName !== '' ? $envFromName : ($defFromName !== '' ? $defFromName : null));
        $this->transport = $transport;
    }

    public function driver_name(): string { return 'resend'; }

    /** Resend is outbound only — never emits messages. */
    public function poll(int $folderId, ?string $cursor): array
    {
        return ['messages' => [], 'next_cursor' => $cursor];
    }

    public function refresh_oauth(int $connectionId): void { /* Resend uses static API keys */ }
    public function revoke(int $connectionId): void        { /* no-op */ }

    public function send(array $envelope): array
    {
        if ($this->apiKey === '') {
            return $this->fail('RESEND_API_KEY not configured');
        }

        $to = array_values(array_filter((array) ($envelope['to'] ?? [])));
        if (empty($to)) {
            return $this->fail('No recipients');
        }

        $fromEmail = !empty($envelope['from']) ? $envelope['from'] : $this->defaultFromEmail;
        if (!$fromEmail) {
            return $this->fail('From address not configured (set RESEND_FROM_EMAIL)');
        }
        $fromName = !empty($envelope['from_name']) ? $envelope['from_name'] : $this->defaultFromName;
        $fromHeader = $fromName
            ? sprintf('%s <%s>', $fromName, $fromEmail)
            : $fromEmail;

        $payload = [
            'from'    => $fromHeader,
            'to'      => $to,
            'subject' => (string) ($envelope['subject'] ?? ''),
        ];
        if (!empty($envelope['body_html']))      $payload['html']     = $envelope['body_html'];
        if (!empty($envelope['body_text']))      $payload['text']     = $envelope['body_text'];
        if (!empty($envelope['reply_to']))       $payload['reply_to'] = $envelope['reply_to'];
        if (!empty($envelope['tags']) && is_array($envelope['tags'])) {
            $payload['tags'] = $envelope['tags'];
        }

        // Build idempotency key — prevents duplicate sends if a retry sneaks in.
        // Key combines tenant + purpose + a stable snapshot of the envelope.
        $idempotencyKey = $envelope['idempotency_key']
            ?? sprintf(
                'cf-%s-%s-%s',
                $envelope['tenant_id'] ?? '0',
                $envelope['module']    ?? 'core',
                substr(hash('sha256', json_encode([
                    'to'      => $to,
                    'subject' => $payload['subject'],
                    'purpose' => $envelope['purpose'] ?? '',
                ])), 0, 24)
            );

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        $result = $this->transport
            ? ($this->transport)(['url' => self::API_URL, 'headers' => $headers, 'payload' => $payload])
            : $this->http_post(self::API_URL, $headers, $payload);

        if (!($result['ok'] ?? false)) {
            return $this->fail($result['error'] ?? ('Resend HTTP ' . ($result['http'] ?? 0)));
        }

        return [
            'provider_message_id' => $result['id'] ?? null,
            'sent_at'             => gmdate('Y-m-d H:i:s'),
            'status'              => 'sent',
            'error'               => null,
        ];
    }

    private function http_post(string $url, array $headers, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http' => 0, 'error' => 'cURL error: ' . $err];
        }
        $decoded = json_decode((string) $body, true) ?: [];
        if ($http >= 200 && $http < 300) {
            return ['ok' => true, 'http' => $http, 'id' => $decoded['id'] ?? null];
        }
        $msg = $decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $http);
        return ['ok' => false, 'http' => $http, 'error' => (string) $msg];
    }

    private function fail(string $message): array
    {
        return [
            'provider_message_id' => null,
            'sent_at'             => null,
            'status'              => 'failed',
            'error'               => $message,
        ];
    }
}
