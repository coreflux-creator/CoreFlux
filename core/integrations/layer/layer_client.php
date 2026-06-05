<?php
/**
 * LayerFi HTTP client — low-level platform OAuth + business calls.
 *
 * Each call has an in-process **sandbox stub** fallback (layer_is_stub())
 * so the full CoreFlux ⇄ LayerFi flow can be exercised without live keys.
 * Setting LAYER_CLIENT_ID + LAYER_CLIENT_SECRET flips every call to real
 * LayerFi sandbox HTTP automatically.
 *
 * SECURITY: the platform OAuth token is cached in-process only (never
 * persisted), and is never returned to callers outside this module.
 */
declare(strict_types=1);

require_once __DIR__ . '/layer_config.php';

class LayerApiException extends \RuntimeException
{
    public int $httpStatus;
    public ?string $errorCode;

    public function __construct(string $msg, int $httpStatus = 502, ?string $errorCode = null)
    {
        parent::__construct($msg);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
    }
}

/** Low-level HTTP via curl. Returns decoded JSON array; throws on non-2xx. */
function layer_http(string $method, string $url, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_HTTPHEADER     => $opts['headers'] ?? [],
    ]);
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $netErr = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new LayerApiException('LayerFi network error: ' . $netErr, 502, 'network_error');
    }
    $json = json_decode((string) $raw, true);
    if ($status < 200 || $status >= 300) {
        $code = is_array($json) ? ($json['error'] ?? $json['code'] ?? null) : null;
        throw new LayerApiException(
            'LayerFi API ' . $status . ': ' . mb_substr((string) $raw, 0, 300),
            $status,
            $code !== null ? (string) $code : null
        );
    }
    return is_array($json) ? $json : [];
}

/**
 * Platform OAuth token via client_credentials. Cached in-memory until 5
 * minutes before expiry. Returns ['access_token','expires_in','token_type',
 * 'obtained_at','expires_at','stub'].
 */
function layer_get_platform_token(): array
{
    static $cached = null;
    if (is_array($cached) && time() < ((int) $cached['expires_at'] - 300)) {
        return $cached;
    }

    $cfg = layer_config();

    if (layer_is_stub()) {
        return $cached = [
            'access_token' => 'stub-platform-token-' . bin2hex(random_bytes(8)),
            'expires_in'   => 3600,
            'token_type'   => 'Bearer',
            'obtained_at'  => gmdate('c'),
            'expires_at'   => time() + 3600,
            'stub'         => true,
        ];
    }

    $basic = base64_encode($cfg['clientId'] . ':' . $cfg['clientSecret']);
    $form  = http_build_query([
        'grant_type' => 'client_credentials',
        'scope'      => $cfg['oauthScope'],
        'client_id'  => $cfg['clientId'],
    ]);
    $json = layer_http('POST', $cfg['authUrl'], [
        'headers' => [
            'Authorization: Basic ' . $basic,
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        'body' => $form,
    ]);

    $ttl = (int) ($json['expires_in'] ?? 3600);
    return $cached = [
        'access_token' => (string) ($json['access_token'] ?? ''),
        'expires_in'   => $ttl,
        'token_type'   => (string) ($json['token_type'] ?? 'Bearer'),
        'obtained_at'  => gmdate('c'),
        'expires_at'   => time() + $ttl,
        'stub'         => false,
    ];
}

/** Connectivity check (`/whoami`). Used by the smoke test. */
function layer_whoami(string $platformToken): array
{
    if (layer_is_stub()) {
        return ['ok' => true, 'stub' => true, 'client' => 'coreflux-sandbox-stub'];
    }
    $cfg = layer_config();
    return layer_http('GET', $cfg['apiBaseUrl'] . '/whoami', [
        'headers' => ['Authorization: Bearer ' . $platformToken, 'Accept: application/json'],
    ]);
}

/** Create a LayerFi Business. Returns the LayerFi business object. */
function layer_create_business(string $platformToken, array $payload): array
{
    if (layer_is_stub()) {
        return [
            'id'          => 'stub_biz_' . substr(hash('sha256', json_encode($payload)), 0, 24),
            'external_id' => $payload['external_id'] ?? null,
            'legal_name'  => $payload['legal_name'] ?? null,
            'status'      => 'active',
            'stub'        => true,
        ];
    }
    $cfg = layer_config();
    return layer_http('POST', $cfg['apiBaseUrl'] . '/v1/businesses', [
        'headers' => [
            'Authorization: Bearer ' . $platformToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES),
    ]);
}

/** Issue a business-scoped session token for the embedded UI. */
function layer_create_business_auth_token(string $platformToken, string $businessId, int $sessionDuration): array
{
    if (layer_is_stub()) {
        return [
            'access_token' => 'stub-biz-token-' . bin2hex(random_bytes(16)),
            'expires_in'   => $sessionDuration,
            'token_type'   => 'Bearer',
            'stub'         => true,
        ];
    }
    $cfg = layer_config();
    return layer_http('POST', $cfg['apiBaseUrl'] . '/v1/businesses/' . rawurlencode($businessId) . '/auth-token', [
        'headers' => [
            'Authorization: Bearer ' . $platformToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        'body' => json_encode(['session_duration' => $sessionDuration], JSON_UNESCAPED_SLASHES),
    ]);
}
