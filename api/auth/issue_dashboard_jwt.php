<?php
/**
 * POST /api/auth/issue_dashboard_jwt.php
 *
 * Mints a short-lived JWT for the React dashboard so it can authenticate
 * cross-origin requests to the Apollo Router at graphql.corefluxapp.com.
 *
 * The dashboard itself runs on corefluxapp.com and authenticates via the
 * PHP session cookie. The Apollo Router and Node subgraphs verify HS256
 * JWTs signed with JWT_SECRET — same secret core/jwt.php uses.
 *
 * Flow:
 *   1. Dashboard SPA loads, has a valid PHP session cookie.
 *   2. SPA calls this endpoint (same-origin, cookie sent automatically).
 *   3. We verify the session and mint a JWT with { user_id, tenant_id,
 *      name, email, role } — same shape as mobile_login.php.
 *   4. SPA caches the JWT in-memory + uses it as `Authorization: Bearer …`
 *      against graphql.corefluxapp.com.
 *   5. The subgraph decodes the JWT, calls back to corefluxapp.com PHP REST
 *      forwarding the SAME JWT, and PHP's api_require_auth() accepts it
 *      via the existing jwtFromRequest() bearer path.
 *
 * TTL: 8h, matching the existing mobile access-token TTL. The SPA can
 * call this again silently before expiry.
 *
 * RBAC: no extra permission required beyond a valid session. The JWT
 * carries the same identity the user already has via session; this is
 * not an escalation.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/jwt.php';

if (api_method() !== 'POST' && api_method() !== 'GET') {
    api_error('Method not allowed', 405);
}

$ctx      = api_require_auth();
$user     = $ctx['user']      ?? [];
$tenantId = $ctx['tenant_id'] ?? null;

if (!$user || !$tenantId) {
    api_error('Session is missing user or tenant', 403);
}

$accessTtl = 8 * 60 * 60; // 8 hours
$token = jwtSign([
    'user_id'   => (int)    ($user['id']    ?? 0),
    'tenant_id' => (int)    $tenantId,
    'name'      => (string) ($user['name']  ?? ''),
    'email'     => (string) ($user['email'] ?? ''),
    'role'      => (string) ($user['role']  ?? 'employee'),
], $accessTtl);

api_ok([
    'jwt'        => $token,
    'expires_in' => $accessTtl,
    'expires_at' => time() + $accessTtl,
]);
