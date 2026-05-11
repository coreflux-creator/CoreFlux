<?php
/**
 * Public OIDC start endpoint — Slice 2.
 *
 *   GET /api/sso/start.php?slug={sso_slug}[&return=/dashboard]
 *
 * Looks up the tenant_sso_domains row for this slug, mints state+nonce+
 * PKCE code_verifier, persists them in oidc_session_state, then 302's to
 * the IdP's authorization endpoint with the standard OIDC params.
 *
 * NO session required — this endpoint is the entry point for users who
 * are not yet logged in. The /api router enforces a module RBAC gate on
 * everything under /api/<module>/* — this file lives directly under /api
 * so it bypasses that gate (same as approve_by_email.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/encryption.php';
require_once __DIR__ . '/../../core/oidc.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$slug   = strtolower((string) ($_GET['slug'] ?? ''));
$return = (string) ($_GET['return'] ?? '/');
if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
    http_response_code(400);
    echo 'Invalid SSO slug.';
    exit;
}

$pdo = getDB();
try {
    $st = $pdo->prepare(
        'SELECT tenant_id, provider_type, issuer_url, client_id, is_enabled, sso_slug
           FROM tenant_sso_domains WHERE sso_slug = :s LIMIT 1'
    );
    $st->execute(['s' => $slug]);
    $row = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
} catch (\Throwable $e) {
    http_response_code(503);
    echo 'SSO not provisioned yet.';
    exit;
}
if (!$row) { http_response_code(404); echo 'Unknown SSO slug.'; exit; }
if (!$row['is_enabled']) { http_response_code(403); echo 'SSO is disabled for this organisation.'; exit; }

try {
    $discovery = oidcDiscovery((string) $row['issuer_url']);
} catch (\Throwable $e) {
    http_response_code(502);
    echo 'IdP discovery failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}

$state         = bin2hex(random_bytes(32));
$nonce         = bin2hex(random_bytes(32));
$codeVerifier  = oidcGenerateCodeVerifier();
$codeChallenge = oidcGenerateCodeChallenge($codeVerifier);

$pdo->prepare(
    'INSERT INTO oidc_session_state
        (tenant_id, sso_slug, state, nonce, code_verifier, return_path, expires_at)
     VALUES (:t, :s, :st, :n, :cv, :r, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
)->execute([
    't'  => (int) $row['tenant_id'],
    's'  => $slug,
    'st' => $state,
    'n'  => $nonce,
    'cv' => $codeVerifier,
    'r'  => substr($return, 0, 512),
]);

$base = ssoBaseUrl();
$redirectUri = $base . '/api/sso/callback.php?slug=' . urlencode($slug);

$params = [
    'client_id'             => (string) $row['client_id'],
    'redirect_uri'          => $redirectUri,
    'response_type'         => 'code',
    'scope'                 => 'openid profile email',
    'state'                 => $state,
    'nonce'                 => $nonce,
    'code_challenge'        => $codeChallenge,
    'code_challenge_method' => 'S256',
];

$url = $discovery['authorization_endpoint']
     . (str_contains($discovery['authorization_endpoint'], '?') ? '&' : '?')
     . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

header('Location: ' . $url, true, 302);
exit;

function ssoBaseUrl(): string {
    if (defined('APP_URL') && APP_URL) return rtrim((string) APP_URL, '/');
    $envUrl = getenv('APP_URL');
    if ($envUrl) return rtrim($envUrl, '/');
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}
