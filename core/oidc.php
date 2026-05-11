<?php
/**
 * OIDC core helpers — discovery, JWKS, PKCE, JWT verification.
 *
 * Pure protocol implementation against generic OIDC IdPs (Okta + Microsoft
 * Entra are both standard-compliant). NO vendor SDK. The functions in this
 * file are designed to be unit-testable: signature verification accepts an
 * injected JWKS array so tests can pass a known key set without hitting
 * a real IdP.
 *
 * See the integration playbook in /app/memory for the OIDC reasoning
 * behind PKCE S256, state/nonce, and the clock-skew window.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/* ─────────────────────────  PKCE  ───────────────────────── */

/** Base64url encode (no padding) — used for PKCE + JWT manipulation. */
function oidcB64UrlEncode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** Base64url decode. Returns raw bytes. */
function oidcB64UrlDecode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad > 0) $s .= str_repeat('=', 4 - $pad);
    $bin = base64_decode(strtr($s, '-_', '+/'), true);
    if ($bin === false) throw new \RuntimeException('oidcB64UrlDecode: invalid input');
    return $bin;
}

/** PKCE code verifier — RFC 7636 §4.1, base64url(random_bytes(32)) → 43 chars. */
function oidcGenerateCodeVerifier(): string
{
    return oidcB64UrlEncode(random_bytes(32));
}

/** PKCE S256 code challenge = base64url(SHA256(code_verifier)). */
function oidcGenerateCodeChallenge(string $verifier): string
{
    return oidcB64UrlEncode(hash('sha256', $verifier, true));
}

/* ─────────────────  OIDC discovery (cached)  ───────────────── */

/**
 * Fetch the `.well-known/openid-configuration` document for $issuerUrl,
 * caching the result for 24 hours. $fetcher is a callable (string $url) → string body
 * so tests can inject a static response.
 */
function oidcDiscovery(string $issuerUrl, ?callable $fetcher = null): array
{
    $pdo = getDB();
    try {
        $st = $pdo->prepare('SELECT discovery_json FROM oidc_discovery_cache
                              WHERE issuer_url = :i AND expires_at > NOW() LIMIT 1');
        $st->execute(['i' => $issuerUrl]);
        $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($row) {
            $decoded = json_decode((string) $row['discovery_json'], true);
            if (is_array($decoded) && !empty($decoded['authorization_endpoint'])) return $decoded;
        }
    } catch (\Throwable $_) { /* table may not be migrated yet */ }

    $url  = rtrim($issuerUrl, '/') . '/.well-known/openid-configuration';
    $body = $fetcher ? $fetcher($url) : oidcHttpGet($url);
    $doc  = json_decode($body, true);
    if (!is_array($doc) || empty($doc['authorization_endpoint']) || empty($doc['token_endpoint']) || empty($doc['jwks_uri'])) {
        throw new \RuntimeException('OIDC discovery: invalid document');
    }
    try {
        $pdo->prepare(
            'INSERT INTO oidc_discovery_cache (issuer_url, discovery_json, expires_at)
             VALUES (:i, :d, DATE_ADD(NOW(), INTERVAL 1 DAY))
             ON DUPLICATE KEY UPDATE
                discovery_json = VALUES(discovery_json),
                cached_at      = NOW(),
                expires_at     = VALUES(expires_at)'
        )->execute(['i' => $issuerUrl, 'd' => json_encode($doc)]);
    } catch (\Throwable $_) { /* cache miss is fine */ }
    return $doc;
}

/* ─────────────────────  JWKS (cached)  ───────────────────── */

function oidcJwks(string $issuerUrl, string $jwksUri, ?callable $fetcher = null, bool $forceRefresh = false): array
{
    $pdo = getDB();
    if (!$forceRefresh) {
        try {
            $st = $pdo->prepare('SELECT jwks_json FROM oidc_jwks_cache
                                  WHERE issuer_url = :i AND expires_at > NOW() LIMIT 1');
            $st->execute(['i' => $issuerUrl]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            if ($row) {
                $decoded = json_decode((string) $row['jwks_json'], true);
                if (is_array($decoded) && !empty($decoded['keys'])) return $decoded;
            }
        } catch (\Throwable $_) { /* table may not be migrated yet */ }
    }
    $body = $fetcher ? $fetcher($jwksUri) : oidcHttpGet($jwksUri);
    $jwks = json_decode($body, true);
    if (!is_array($jwks) || empty($jwks['keys'])) {
        throw new \RuntimeException('OIDC: invalid JWKS response');
    }
    try {
        $pdo->prepare(
            'INSERT INTO oidc_jwks_cache (issuer_url, jwks_json, expires_at)
             VALUES (:i, :j, DATE_ADD(NOW(), INTERVAL 1 HOUR))
             ON DUPLICATE KEY UPDATE
                jwks_json  = VALUES(jwks_json),
                cached_at  = NOW(),
                expires_at = VALUES(expires_at)'
        )->execute(['i' => $issuerUrl, 'j' => json_encode($jwks)]);
    } catch (\Throwable $_) { /* cache miss is fine */ }
    return $jwks;
}

/* ─────────────────  JWK → PEM (RSA only, RS256)  ───────────────── */

/**
 * Convert a JWK (RSA n,e) to OpenSSL PEM-encoded SubjectPublicKeyInfo so
 * openssl_verify() can verify RS256 signatures. Uses raw ASN.1/DER encoding
 * — no external dependency.
 *
 * Spec: RFC 7517 §9.3, RFC 8017 Appendix A.1.1.
 */
function oidcJwkToPem(array $jwk): string
{
    if (($jwk['kty'] ?? '') !== 'RSA') throw new \RuntimeException('oidcJwkToPem: only RSA keys supported');
    $n = oidcB64UrlDecode((string) ($jwk['n'] ?? ''));
    $e = oidcB64UrlDecode((string) ($jwk['e'] ?? ''));
    // Inner SEQUENCE { INTEGER n, INTEGER e }
    $inner = oidcAsn1Sequence(oidcAsn1Integer($n) . oidcAsn1Integer($e));
    // BIT STRING wrapping the inner sequence (preceded by an 0x00 unused-bits octet)
    $bitstring = oidcAsn1BitString("\x00" . $inner);
    // AlgorithmIdentifier: SEQUENCE { OBJECT 1.2.840.113549.1.1.1 (rsaEncryption), NULL }
    $algo = oidcAsn1Sequence(
        "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01" .   // OID 1.2.840.113549.1.1.1
        "\x05\x00"                                          // NULL
    );
    $spki = oidcAsn1Sequence($algo . $bitstring);
    $b64  = chunk_split(base64_encode($spki), 64, "\n");
    return "-----BEGIN PUBLIC KEY-----\n" . $b64 . "-----END PUBLIC KEY-----\n";
}

function oidcAsn1Length(int $len): string
{
    if ($len < 0x80) return chr($len);
    $bytes = '';
    while ($len > 0) { $bytes = chr($len & 0xff) . $bytes; $len >>= 8; }
    return chr(0x80 | strlen($bytes)) . $bytes;
}
function oidcAsn1Sequence(string $payload): string { return "\x30" . oidcAsn1Length(strlen($payload)) . $payload; }
function oidcAsn1Integer(string $raw): string
{
    // INTEGER must be twos-complement; if high bit set, prepend 0x00 to keep it positive.
    if ($raw !== '' && (ord($raw[0]) & 0x80)) $raw = "\x00" . $raw;
    return "\x02" . oidcAsn1Length(strlen($raw)) . $raw;
}
function oidcAsn1BitString(string $payload): string { return "\x03" . oidcAsn1Length(strlen($payload)) . $payload; }

/* ─────────────────  ID token verification  ───────────────── */

/**
 * Verify the signature + standard claims of an OIDC ID token.
 *
 * @param string $idToken           Compact-serialized JWT (header.payload.sig)
 * @param array  $jwks              Full JWKS array (the 'keys' field is what we read).
 * @param string $issuerUrl         Expected `iss` claim.
 * @param string $clientId          Expected `aud` claim (string or contained in array).
 * @param string $expectedNonce     Expected `nonce` claim — value we minted at /start.
 * @param int    $clockSkewSeconds  Allowed clock skew window (default 300s).
 * @return array  Verified token claims (the JWT payload as an assoc array).
 *
 * Throws \RuntimeException on any verification failure.
 */
function oidcVerifyIdToken(string $idToken, array $jwks, string $issuerUrl, string $clientId, string $expectedNonce, int $clockSkewSeconds = 300, ?int $now = null): array
{
    $now    = $now ?? time();
    $parts  = explode('.', $idToken);
    if (count($parts) !== 3) throw new \RuntimeException('id_token: malformed (not 3 parts)');
    $header  = json_decode(oidcB64UrlDecode($parts[0]), true);
    $payload = json_decode(oidcB64UrlDecode($parts[1]), true);
    if (!is_array($header) || !is_array($payload)) throw new \RuntimeException('id_token: bad header or payload');
    if (($header['alg'] ?? '') !== 'RS256') throw new \RuntimeException('id_token: only RS256 supported (got ' . ($header['alg'] ?? '?') . ')');
    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') throw new \RuntimeException('id_token: header missing kid');

    // Claim checks (do these BEFORE the heavy sig-verify — fail fast).
    $iss = (string) ($payload['iss'] ?? '');
    if (rtrim($iss, '/') !== rtrim($issuerUrl, '/')) throw new \RuntimeException('id_token: iss mismatch');

    $aud = $payload['aud'] ?? null;
    $audOK = is_string($aud)
        ? $aud === $clientId
        : (is_array($aud) && in_array($clientId, $aud, true));
    if (!$audOK) throw new \RuntimeException('id_token: aud mismatch');

    if (!isset($payload['nonce']) || !hash_equals($expectedNonce, (string) $payload['nonce'])) {
        throw new \RuntimeException('id_token: nonce mismatch (replay attempt or stale session)');
    }
    $exp = (int) ($payload['exp'] ?? 0);
    if ($exp <= 0 || $now > $exp + $clockSkewSeconds) throw new \RuntimeException('id_token: expired');
    $iat = (int) ($payload['iat'] ?? 0);
    if ($iat > 0 && $iat > $now + $clockSkewSeconds) throw new \RuntimeException('id_token: iat in future');

    // Locate the signing key by kid.
    $key = null;
    foreach ($jwks['keys'] ?? [] as $k) {
        if (($k['kid'] ?? null) === $kid && (($k['use'] ?? 'sig') === 'sig')) { $key = $k; break; }
    }
    if (!$key) throw new \RuntimeException("id_token: kid {$kid} not found in JWKS");
    $pem = oidcJwkToPem($key);
    $sig = oidcB64UrlDecode($parts[2]);
    $signed = $parts[0] . '.' . $parts[1];
    $ok = openssl_verify($signed, $sig, $pem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new \RuntimeException('id_token: signature verification failed');

    return $payload;
}

/* ─────────────────  HTTP fetcher (cURL)  ───────────────── */

function oidcHttpGet(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new \RuntimeException("HTTP GET failed: {$url}: {$err}");
    if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP {$code} from {$url}");
    return (string) $body;
}

function oidcHttpPostForm(string $url, array $form): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form, '', '&', PHP_QUERY_RFC3986),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new \RuntimeException("HTTP POST failed: {$url}: {$err}");
    if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP {$code} from {$url}: " . substr((string) $body, 0, 300));
    return (string) $body;
}
