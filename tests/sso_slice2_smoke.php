<?php
/**
 * Smoke: SSO Slice 2 — real OIDC redirect/callback + JIT user creation.
 *
 * The headline assertion: oidcVerifyIdToken() correctly verifies an RS256
 * id_token forged with a known RSA private key and a JWK derived from
 * its public counterpart. If our hand-rolled JWK→PEM ASN.1 conversion is
 * wrong, openssl_verify() returns 0 and this test fails.
 */
declare(strict_types=1);

require_once __DIR__ . '/../core/oidc.php';

$pass = 0; $fail = 0;
$a = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  ok    $name\n"; }
    else     { $fail++; echo "  FAIL  $name\n"; }
};
$parses = function (string $p): bool {
    if (!is_file($p)) return false;
    exec(PHP_BINARY . ' -l ' . escapeshellarg($p), $out, $code);
    return $code === 0;
};

/* ────────────────────────────  PKCE  ──────────────────────────── */
echo "PKCE (RFC 7636)\n";
$v = oidcGenerateCodeVerifier();
$a('verifier length within 43..128', strlen($v) >= 43 && strlen($v) <= 128);
$a('verifier is unreserved base64url chars', preg_match('/^[A-Za-z0-9._~\-]+$/', $v) === 1);
$c = oidcGenerateCodeChallenge($v);
$expected = rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=');
$a('challenge = base64url(sha256(verifier))', $c === $expected);
$a('two verifiers differ',                    oidcGenerateCodeVerifier() !== oidcGenerateCodeVerifier());

/* ──────────────────  RS256 round trip with hand-rolled JWK→PEM  ────────────────── */
echo "\nJWK → PEM conversion (RSA, RS256) — real openssl round trip\n";

// Use a deterministic RSA fixture so this smoke does not depend on host
// OpenSSL key-generation config. The product path still uses OpenSSL to
// load keys, verify signatures, and validate tokens end to end.
$fixturePrivateKey = <<<'PEM'
-----BEGIN RSA PRIVATE KEY-----
MIIEowIBAAKCAQEAohvs2JFl5tOF4DCvSnuGAvnWSlrRC00DdtyzV1Mu47Y/98cn
pt9MN7CAjXKqnHWv5BNsmJrm/ds7s22kq/ypYpDOV0clkazKYvo5HN6pEwgbpmxC
TDYzpYwsLjmj67sfrNYwHnqbNB85Tc/OY583tSsDctA1NN+5KtM29FivFTpkYsBd
ECK9zGRBds4ieb3yc6ax5dsR9o5z4wpu7J6Wfatx3bxllHdjSjgJgu4WZO5G+X/I
gwy5LYw10iL1lsoIHACsPAe7epmYb9SDN2cDsSiRcnSJzYQ95K9XLV7QBB2w0lAF
C+L8I7T4PAceZuyzLNReejQlEkDUv+vwghtMzQIDAQABAoIBACDOg/UkH7pCDnLb
h24MZ4eMpihwDqQ51rykV4sRo4ij5ngvjr+/qv4OM0Xs8cguLQV8RNrxZlPznTZn
tw6zWFhBM/EHzfuYO3EicJJ+ITtfxbC9cgFYasVTA9HrClh3iyaARka0y1oWA5PS
vVL98tkwNkdzCYGE0UVwb0ut8ujZkOhxouFyblwKvFwLDxUK2fiMhEgTZp8Of0nw
XU+9aqvk1cb80wax1DqfMips8dHuDaX7DPsJxPG0dfVg0HYYUdNE9QRnaRGP2Nes
F1x1HucAvDQomf07rNELAQt9/r/xFFUN9U0cYQ8wd6oatSETdpfSZwCIByS5T/ay
qyIaPoUCgYEAzQQgjeuFfblFXkN3OdesQEChEPRGnLtGrxUE8Yts8MVzN5eFr6sm
CGMrZTbyJained3jdsWrM0VQzlV6j8gWTjdiP0QU5gpJfdCcd71+huxJZrQ8cQt0
OQeU8UW2BC5nKWdm29jQMa3gGVI3KIJbeoUd6+7sjeEICRY/2wNncssCgYEAymw4
tj34iEYOalFJrz8v7CepwVv6R+bt2q9C03updw+lp+5q3SRYdapbNEuxRrVKsGLX
EyRrO3Ntzssa0mumPFe4Q+gX8SgbP3CWRpFZvlW9JzZ+FQ4oJTMdj9aTlDBbjeGS
n7/7myjsRHL7saVStYhKFya3c25ItM8p1qwEE8cCgYB4karLi+1PyPugujCN1ea5
SsjufZphZknlgYkMvKBu4NAnq3a1nwOY/ylwNuYle5AyvWmeWhWa63LgRaj0kgl8
KlofNtzLhNU/psW+LbURiDiKrAi3urK5L1pKomKvBtMoqGT3egTGkqkuewlxS2id
H1g/fp2juunM3kbjeJcIDQKBgACSR6K0EBSKZhYEvrmA6yi2f/MsyEsVqsw4PG8O
ZU8Ruzz7HlAbfyht364JHKn/bwOKc+L48liLnd68kgnQBfsboEiIyjCDFXibX8E5
PdCcu1j1/WsfzBs2xrmWOHptnISNA3Xx+8rXVbtnu7AnsFEU3misUk5AHHJuN0cE
20oXAoGBAMjy0Irgow2DmCXrfgZzNQgAyyyElhzBLnvgO/SLpqcDmCPraKz/Y/i+
BrhqX7wnxiTqaeBXBU3IffpBsjYuKMFMmipfDNK5mzfoG3Kye8rcoR5VQI55h9/f
UOErd5GxTzV3zheMj3jeXLp6hFLJaP7Jxzh3sbVRScX1A8jBARN/
-----END RSA PRIVATE KEY-----
PEM;
$kp = openssl_pkey_get_private($fixturePrivateKey);
$a('fixture private key loads', $kp !== false);
if ($kp === false) exit(1);
$details = openssl_pkey_get_details($kp);
$a('fixture exposes RSA details', is_array($details) && isset($details['rsa']['n'], $details['rsa']['e']));
if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) exit(1);
$jwk = [
    'kty' => 'RSA',
    'kid' => 'test-kid-1',
    'use' => 'sig',
    'alg' => 'RS256',
    'n'   => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
    'e'   => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
];
$pem = oidcJwkToPem($jwk);
$a('PEM begins with -----BEGIN PUBLIC KEY-----', str_starts_with($pem, '-----BEGIN PUBLIC KEY-----'));
$loaded = openssl_pkey_get_public($pem);
$a('openssl can load reconstructed PEM',        $loaded !== false);
$loadedDetails = openssl_pkey_get_details($loaded);
$a('reconstructed PEM has same modulus',        $loadedDetails['rsa']['n'] === $details['rsa']['n']);
$a('reconstructed PEM has same exponent',       $loadedDetails['rsa']['e'] === $details['rsa']['e']);

// Sign a payload with the private key, then verify against the JWK-derived PEM.
$msg = 'hello-oidc';
openssl_sign($msg, $sig, $kp, OPENSSL_ALGO_SHA256);
$a('openssl_verify against JWK-derived PEM',    openssl_verify($msg, $sig, $pem, OPENSSL_ALGO_SHA256) === 1);

/* ──────────────────────  Forge id_token + verify  ───────────────────── */
echo "\noidcVerifyIdToken() — happy path + tamper detection\n";

$forgeIdToken = function (array $headerOverride, array $payloadOverride, string $sigKey = '') use ($kp): string {
    $header  = array_merge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-kid-1'], $headerOverride);
    $payload = array_merge([
        'iss'   => 'https://acme.okta.com',
        'aud'   => 'client-abc',
        'sub'   => 'okta-user-1',
        'email' => 'user@acme.com',
        'name'  => 'Sam Tester',
        'given_name' => 'Sam', 'family_name' => 'Tester',
        'nonce' => 'NONCE_VALUE',
        'iat'   => time(),
        'exp'   => time() + 3600,
    ], $payloadOverride);
    $h = oidcB64UrlEncode(json_encode($header));
    $p = oidcB64UrlEncode(json_encode($payload));
    openssl_sign("$h.$p", $sig, $kp, OPENSSL_ALGO_SHA256);
    if ($sigKey === 'TAMPER') $sig = strrev($sig);
    return "$h.$p." . oidcB64UrlEncode($sig);
};
$jwks = ['keys' => [$jwk]];

$tok = $forgeIdToken([], []);
$claims = oidcVerifyIdToken($tok, $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE');
$a('valid token passes',                        $claims['email'] === 'user@acme.com');

// Tamper detection
try { oidcVerifyIdToken($forgeIdToken([], [], 'TAMPER'), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('flipped signature rejected', !$bad);

// iss mismatch
try { oidcVerifyIdToken($forgeIdToken([], ['iss' => 'https://evil.com']), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('iss mismatch rejected', !$bad);

// aud mismatch
try { oidcVerifyIdToken($forgeIdToken([], ['aud' => 'wrong-client']), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('aud mismatch rejected', !$bad);

// aud array containing client_id should still pass
$tok = $forgeIdToken([], ['aud' => ['other', 'client-abc']]);
try { $r = oidcVerifyIdToken($tok, $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $ok = true; }
catch (\Throwable $e) { $ok = false; }
$a('aud as array containing client_id passes', $ok);

// nonce mismatch
try { oidcVerifyIdToken($forgeIdToken([], ['nonce' => 'WRONG']), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('nonce mismatch rejected (replay protection)', !$bad);

// expired
try { oidcVerifyIdToken($forgeIdToken([], ['exp' => time() - 7200]), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('expired token rejected', !$bad);

// clock skew tolerance — exp is in the past by 60s but within 300s skew
try { $r = oidcVerifyIdToken($forgeIdToken([], ['exp' => time() - 60]), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $ok = true; }
catch (\Throwable $e) { $ok = false; }
$a('60s past exp accepted within 5min skew', $ok);

// future iat outside skew
try { oidcVerifyIdToken($forgeIdToken([], ['iat' => time() + 7200]), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('iat way in future rejected', !$bad);

// alg HS256 should be rejected (algorithm confusion attack)
try { oidcVerifyIdToken($forgeIdToken(['alg' => 'HS256'], []), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('non-RS256 alg rejected (alg confusion)', !$bad);

// missing kid in JWKS
try { oidcVerifyIdToken($forgeIdToken(['kid' => 'nope'], []), $jwks, 'https://acme.okta.com', 'client-abc', 'NONCE_VALUE'); $bad = true; }
catch (\Throwable $e) { $bad = false; }
$a('unknown kid rejected (forces JWKS refresh)', !$bad);

/* ──────────────────────  Files / wiring  ───────────────────── */
echo "\nMigrations + endpoint wiring\n";

$mig = (string) file_get_contents(__DIR__ . '/../core/migrations/031_oidc_session_state.sql');
$a('migration: creates oidc_session_state',     str_contains($mig, 'CREATE TABLE IF NOT EXISTS oidc_session_state'));
$a('migration: creates oidc_jwks_cache',        str_contains($mig, 'CREATE TABLE IF NOT EXISTS oidc_jwks_cache'));
$a('migration: creates oidc_discovery_cache',   str_contains($mig, 'CREATE TABLE IF NOT EXISTS oidc_discovery_cache'));
$a('state has unique constraint',               str_contains($mig, 'UNIQUE KEY uq_oss_state (state)'));
$a('session expires_at index',                  str_contains($mig, 'idx_oss_expires (expires_at)'));
$a('idempotent (IF NOT EXISTS)',                substr_count($mig, 'IF NOT EXISTS') >= 3);

$startPath = __DIR__ . '/../api/sso/start.php';
$startSrc  = (string) file_get_contents($startPath);
$a('start.php parses',                          $parses($startPath));
$a('start: slug validated',                     str_contains($startSrc, "preg_match('/^[a-z0-9]"));
$a('start: requires is_enabled',                str_contains($startSrc, "!\$row['is_enabled']"));
$a('start: mints PKCE verifier+challenge',      str_contains($startSrc, 'oidcGenerateCodeVerifier()')
                                                && str_contains($startSrc, 'oidcGenerateCodeChallenge('));
$a('start: state + nonce are 64 hex chars',     str_contains($startSrc, 'bin2hex(random_bytes(32))'));
$a('start: 15-minute TTL on session_state',     str_contains($startSrc, 'INTERVAL 15 MINUTE'));
$a('start: response_type=code',                 str_contains($startSrc, "'response_type'         => 'code'"));
$a('start: scope openid profile email',         str_contains($startSrc, "'scope'                 => 'openid profile email'"));
$a('start: code_challenge_method=S256',         str_contains($startSrc, "'code_challenge_method' => 'S256'"));
$a('start: 302 to authorization_endpoint',      str_contains($startSrc, "header('Location: ' . \$url, true, 302)"));

$cbPath = __DIR__ . '/../api/sso/callback.php';
$cb     = (string) file_get_contents($cbPath);
$a('callback.php parses',                       $parses($cbPath));
$a('callback: atomic state consume (FOR UPDATE)', str_contains($cb, "WHERE state = :st AND expires_at > NOW() FOR UPDATE"));
$a('callback: refuses already-consumed state',  str_contains($cb, "\$sess['consumed_at']"));
$a('callback: state ↔ slug must match',         str_contains($cb, "\$sess['sso_slug'] !== \$slug"));
$a('callback: decrypts client_secret on read',  str_contains($cb, "decryptField(\$cfg['client_secret_enc'])"));
$a('callback: PKCE code_verifier in token req', str_contains($cb, "'code_verifier' => (string) \$sess['code_verifier']"));
$a('callback: verifies id_token via oidcVerifyIdToken', str_contains($cb, 'oidcVerifyIdToken('));
$a('callback: retries JWKS on kid miss',        str_contains($cb, "str_contains(\$e->getMessage(), 'not found in JWKS')"));
$a('callback: enforces allowed_email_domains',  str_contains($cb, "!in_array(\$emailDomain, \$allowed, true)"));
$a('callback: JIT user creation by email',      str_contains($cb, 'ssoFindOrCreateUser'));
$a('callback: ensures tenant membership',       str_contains($cb, 'ssoEnsureTenantMembership'));
$a('callback: sets $_SESSION[auth_method] = oidc', str_contains($cb, "\$_SESSION['auth_method']      = 'oidc'"));
$a('callback: open-redirect guard (return path)', str_contains($cb, "str_starts_with(\$sess['return_path'], '/')"));
$a('callback: audit log emits auth.sso.login',  str_contains($cb, "'auth.sso.login'"));
$a('callback: error page has testid',           str_contains($cb, 'data-testid="sso-callback-error"'));

echo "\nTotal: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
