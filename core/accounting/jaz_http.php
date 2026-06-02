<?php
/**
 * core/accounting/jaz_http.php — Jaz REST client.
 *
 * Mirrors core/mercury_adapter.php's HTTP shape: a single jazCall()
 * function that owns curl + headers + JSON decode + error
 * normalisation, with a $GLOBALS['__jaz_transport'] test seam so
 * smoke tests can stub the wire without external dependencies.
 *
 *   Auth:    Authorization: Bearer <api_key>   (per user-confirmed default)
 *   Base:    https://api.getjaz.com/api/v1     (override via JAZ_API_BASE)
 *
 * Errors map to JazApiException with httpStatus + raw body slice so
 * the adapter can present a stable {code, message} to the Command
 * Service outbox.
 */
declare(strict_types=1);

class JazApiException extends \RuntimeException
{
    public int $httpStatus = 0;
    public array $raw = [];
}

function jazApiBase(): string
{
    $override = (string) (getenv('JAZ_API_BASE') ?: '');
    if ($override !== '') return rtrim($override, '/');
    return 'https://api.getjaz.com/api/v1';
}

/**
 * Execute a Jaz API call. Returns the decoded JSON body on 2xx.
 * Throws JazApiException on transport/HTTP/decode failure.
 *
 * @param string $method GET|POST|PATCH|DELETE
 * @param string $path   Path WITHOUT leading slash, e.g. "organization"
 * @param array  $body   Request body (for non-GET); omitted for GET.
 * @param array  $query  Query-string params merged into the URL.
 */
function jazCall(string $apiKey, string $method, string $path, array $body = [], array $query = [], int $timeoutSec = 30): array
{
    if ($apiKey === '') throw new JazApiException('Jaz: api key required');

    $url = jazApiBase() . '/' . ltrim($path, '/');
    if ($query) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: CoreFlux/1.0 (+jaz_adapter.php)',
    ];
    $bodyJson = ($method === 'GET' || !$body) ? null : json_encode($body, JSON_UNESCAPED_SLASHES);

    if (isset($GLOBALS['__jaz_transport']) && is_callable($GLOBALS['__jaz_transport'])) {
        $resp    = ($GLOBALS['__jaz_transport'])($method, $url, $headers, $bodyJson);
        $status  = (int) ($resp['status'] ?? 0);
        $rawBody = (string) ($resp['body'] ?? '');
    } else {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($bodyJson !== null) $opts[CURLOPT_POSTFIELDS] = $bodyJson;
        curl_setopt_array($ch, $opts);
        $rawBody = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr    = curl_error($ch);
        curl_close($ch);
        if ($rawBody === false || $cerr) {
            $e = new JazApiException('Jaz cURL error: ' . ($cerr ?: 'unknown'));
            $e->httpStatus = $status;
            throw $e;
        }
        $rawBody = (string) $rawBody;
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data) && $rawBody !== '') {
        // Some endpoints (e.g. 204 No Content on delete) reply empty —
        // that's a legitimate 2xx. Treat empty body + 2xx as [].
    }

    if ($status < 200 || $status >= 300) {
        // Jaz error payloads vary: sometimes `{message: "..."}`, sometimes
        // `{error: {...}}`, sometimes `{errors: [...]}`. Stringify whichever
        // we find robustly — a flat `(string)` cast on a nested array yields
        // the literal text "Array" which is useless in an admin error toast.
        $extract = static function ($val) {
            if ($val === null) return null;
            if (is_scalar($val)) {
                $s = trim((string) $val);
                return $s === '' ? null : $s;
            }
            // Nested array/object — flatten to a compact JSON-ish summary.
            $j = json_encode($val, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $j !== false ? $j : null;
        };
        $msg = "HTTP {$status}";
        if (is_array($data)) {
            $msg = $extract($data['message'] ?? null)
                ?? $extract($data['error']   ?? null)
                ?? $extract($data['errors']  ?? null)
                ?? $extract($data['detail']  ?? null)
                ?? "HTTP {$status}";
        } elseif ($rawBody !== '') {
            // Non-JSON response body — surface the first 200 chars so admins
            // can see what the upstream actually said (HTML error page, etc.).
            $msg = substr($rawBody, 0, 200);
        }
        $e = new JazApiException('Jaz ' . $method . ' ' . $path . ': ' . substr($msg, 0, 200));
        $e->httpStatus = $status;
        $e->raw = ['body' => substr($rawBody, 0, 600)];
        throw $e;
    }
    return is_array($data) ? $data : [];
}
