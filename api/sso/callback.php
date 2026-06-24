<?php
/**
 * Public OIDC callback endpoint — Slice 2.
 *
 *   GET /api/sso/callback.php?slug={sso_slug}&state={state}&code={authcode}
 *
 * 1. Look up the oidc_session_state row keyed by `state` (consumed-once).
 * 2. Exchange the authorization code for tokens at the IdP token endpoint,
 *    including PKCE code_verifier.
 * 3. Verify the ID-token signature against the IdP's JWKS + check standard
 *    claims (iss, aud, nonce, exp, iat).
 * 4. Resolve the local user by email (subject to allowed_email_domains
 *    whitelist), JIT-create + attach to tenant if necessary.
 * 5. Establish a normal PHP session — same shape as password / magic-link
 *    login — and redirect to the SPA.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/db.php';
require_once __DIR__ . '/../../core/auth.php';
require_once __DIR__ . '/../../core/modules.php';
require_once __DIR__ . '/../../core/encryption.php';
require_once __DIR__ . '/../../core/oidc.php';
require_once __DIR__ . '/../../core/audit.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Content-Type: text/html; charset=utf-8');

$slug  = strtolower((string) ($_GET['slug']  ?? ''));
$state = (string) ($_GET['state'] ?? '');
$code  = (string) ($_GET['code']  ?? '');
$err   = (string) ($_GET['error'] ?? '');

if ($err !== '') {
    ssoCallbackError('Identity provider returned error: ' . $err);
}
if ($slug === '' || !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/', $slug)) {
    ssoCallbackError('Invalid SSO slug in callback.');
}
if (!preg_match('/^[a-f0-9]{64}$/', $state)) {
    ssoCallbackError('Invalid state parameter.');
}
if ($code === '') {
    ssoCallbackError('Missing authorization code.');
}

$pdo = getDB();

// Atomic consume of the state row — fail closed if already consumed.
$pdo->beginTransaction();
try {
    $st = $pdo->prepare(
        'SELECT id, tenant_id, sso_slug, state, nonce, code_verifier, return_path, consumed_at
           FROM oidc_session_state WHERE state = :st AND expires_at > NOW() FOR UPDATE'
    );
    $st->execute(['st' => $state]);
    $sess = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    if (!$sess) { $pdo->rollBack(); ssoCallbackError('Session not found or expired. Please start the sign-in again.'); }
    if ($sess['sso_slug'] !== $slug) { $pdo->rollBack(); ssoCallbackError('State / slug mismatch.'); }
    if ($sess['consumed_at']) { $pdo->rollBack(); ssoCallbackError('This sign-in link has already been used.'); }
    // tenant-leak-allow: oidc state is a 256-bit one-time nonce; row carries tenant_id
    $pdo->prepare('UPDATE oidc_session_state SET consumed_at = NOW() WHERE id = :i')->execute(['i' => $sess['id']]);
    $pdo->commit();
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    ssoCallbackError('Session error: ' . $e->getMessage());
}

// Fetch tenant SSO config (we already validated slug; pull issuer + creds).
$st = $pdo->prepare(
    'SELECT tenant_id, provider_type, issuer_url, client_id, client_secret_enc,
            allowed_email_domains, is_enabled
       FROM tenant_sso_domains WHERE sso_slug = :s LIMIT 1'
);
$st->execute(['s' => $slug]);
$cfg = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
if (!$cfg || !$cfg['is_enabled']) ssoCallbackError('SSO is no longer enabled for this organisation.');
$clientSecret = decryptField($cfg['client_secret_enc']);
if (!$clientSecret) ssoCallbackError('SSO configuration incomplete — no client secret on file.');

// Discovery + token exchange.
try {
    $discovery = oidcDiscovery((string) $cfg['issuer_url']);
} catch (\Throwable $e) { ssoCallbackError('Discovery failed: ' . $e->getMessage()); }

$base        = ssoCallbackBaseUrl();
$redirectUri = $base . '/api/sso/callback.php?slug=' . urlencode($slug);

try {
    $tokenBody = oidcHttpPostForm($discovery['token_endpoint'], [
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'client_id'     => (string) $cfg['client_id'],
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'code_verifier' => (string) $sess['code_verifier'],
    ]);
} catch (\Throwable $e) { ssoCallbackError('Token exchange failed: ' . $e->getMessage()); }

$tokens = json_decode($tokenBody, true);
if (!is_array($tokens) || empty($tokens['id_token'])) ssoCallbackError('Token response missing id_token.');

// Verify the ID token signature + claims.
try {
    $jwks = oidcJwks((string) $cfg['issuer_url'], (string) $discovery['jwks_uri']);
    $claims = oidcVerifyIdToken(
        (string) $tokens['id_token'],
        $jwks,
        (string) $cfg['issuer_url'],
        (string) $cfg['client_id'],
        (string) $sess['nonce']
    );
} catch (\Throwable $e) {
    // If the kid was missing from cached JWKS, try one forced refresh
    // (handles IdP key rotation).
    if (str_contains($e->getMessage(), 'not found in JWKS')) {
        try {
            $jwks = oidcJwks((string) $cfg['issuer_url'], (string) $discovery['jwks_uri'], null, true);
            $claims = oidcVerifyIdToken(
                (string) $tokens['id_token'], $jwks,
                (string) $cfg['issuer_url'], (string) $cfg['client_id'],
                (string) $sess['nonce']
            );
        } catch (\Throwable $e2) { ssoCallbackError('ID token verification failed: ' . $e2->getMessage()); }
    } else {
        ssoCallbackError('ID token verification failed: ' . $e->getMessage());
    }
}

$email = strtolower(trim((string) ($claims['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) ssoCallbackError('ID token missing valid email claim.');

// Optional email-domain whitelist.
$allowed = [];
if (!empty($cfg['allowed_email_domains'])) {
    $decoded = json_decode((string) $cfg['allowed_email_domains'], true);
    if (is_array($decoded)) $allowed = array_map('strtolower', $decoded);
}
$emailDomain = strtolower(substr($email, strpos($email, '@') + 1));
if (!empty($allowed) && !in_array($emailDomain, $allowed, true)) {
    ssoCallbackError("Email domain {$emailDomain} is not authorised for SSO on this organisation.");
}

// JIT user creation / find by email.
try {
    $userId = ssoFindOrCreateUser($pdo, $email, $claims, (int) $cfg['tenant_id']);
    ssoEnsureTenantMembership($pdo, $userId, (int) $cfg['tenant_id']);
} catch (\Throwable $e) { ssoCallbackError('JIT user creation failed: ' . $e->getMessage()); }

// Session handoff.
$uSt = $pdo->prepare('SELECT id, email, first_name, last_name, role, status FROM users WHERE id = :id LIMIT 1');
$uSt->execute(['id' => $userId]);
$user = $uSt->fetch(\PDO::FETCH_ASSOC);
if (!$user) ssoCallbackError('User not found after JIT.');
if (($user['status'] ?? 'active') === 'disabled') ssoCallbackError('Account disabled.');

initSession();
$sessionUser = [
    'id'         => (int) $user['id'],
    'first_name' => (string) ($user['first_name'] ?? ''),
    'last_name'  => (string) ($user['last_name']  ?? ''),
    'email'      => (string) $user['email'],
    'role'       => (string) ($user['role']       ?? 'employee'),
    'avatar'     => null,
];
$_SESSION['user']             = $sessionUser;
$_SESSION['modules']          = getUserModules($sessionUser['role']);
$_SESSION['tenant_id']        = (int) $cfg['tenant_id'];
$_SESSION['active_tenant_id'] = (int) $cfg['tenant_id'];
$_SESSION['active_module']    = $_SESSION['modules'][0] ?? null;
$_SESSION['auth_method']      = 'oidc';
$_SESSION['oidc_provider']    = (string) $cfg['provider_type'];

$returnPath = ($sess['return_path'] && str_starts_with($sess['return_path'], '/'))
    ? $sess['return_path'] : '/';

// Best-effort audit
try {
    platformAuditLogWrite(
        (int) $cfg['tenant_id'],
        $userId,
        'auth.sso.login',
        null,
        ['provider' => $cfg['provider_type'], 'sub' => $claims['sub'] ?? null, 'email' => $email],
        [
            'source' => 'sso',
            'object_type' => 'auth_session',
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
} catch (\Throwable $_) { /* swallow */ }

header('Location: ' . $base . $returnPath, true, 302);
exit;

/* ─────────────────  helpers  ───────────────── */

function ssoCallbackBaseUrl(): string {
    if (defined('APP_URL') && APP_URL) return rtrim((string) APP_URL, '/');
    $envUrl = getenv('APP_URL');
    if ($envUrl) return rtrim($envUrl, '/');
    $scheme = (($_SERVER['HTTPS'] ?? '') === 'on') ? 'https' : 'http';
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
}

function ssoFindOrCreateUser(\PDO $pdo, string $email, array $claims, int $tenantId = 0): int {
    $st = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
    $st->execute(['e' => $email]);
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row) return (int) $row['id'];

    $given  = (string) ($claims['given_name']  ?? '');
    $family = (string) ($claims['family_name'] ?? '');
    if ($given === '' && !empty($claims['name'])) {
        $parts  = preg_split('/\s+/', trim((string) $claims['name']), 2);
        $given  = $parts[0] ?? '';
        $family = $parts[1] ?? '';
    }
    // Schema-tolerant — production envs vary in which user columns exist
    // (some carry a legacy NOT-NULL `tenant_id`, some only have `name`
    // instead of first/last). Introspect and INSERT only existing cols.
    $cols = array_map('strval', array_column(
        $pdo->query('SHOW COLUMNS FROM users')->fetchAll(\PDO::FETCH_ASSOC) ?: [], 'Field'
    ));
    $row = ['email' => $email];
    if (in_array('first_name', $cols, true)) $row['first_name'] = $given;
    if (in_array('last_name',  $cols, true)) $row['last_name']  = $family;
    if (in_array('name',       $cols, true)) $row['name']       = trim($given . ' ' . $family) ?: $email;
    if (in_array('role',       $cols, true)) $row['role']       = 'employee';
    if (in_array('status',     $cols, true)) $row['status']     = 'active';
    if (in_array('is_active',  $cols, true)) $row['is_active']  = 1;
    if (in_array('tenant_id',  $cols, true)) $row['tenant_id']  = $tenantId;
    // password columns are NOT NULL on some legacy schemas — seed an
    // unusable bcrypt placeholder (SSO is the only auth path here).
    $ph = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    if (in_array('password',      $cols, true)) $row['password']      = $ph;
    if (in_array('password_hash', $cols, true)) $row['password_hash'] = $ph;

    $insertCols = []; $placeholders = []; $bind = [];
    foreach ($row as $k => $v) { $insertCols[] = $k; $placeholders[] = ':' . $k; $bind[$k] = $v; }
    if (in_array('created_at', $cols, true)) { $insertCols[] = 'created_at'; $placeholders[] = 'NOW()'; }

    $sql = 'INSERT INTO users (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $placeholders) . ')';
    $pdo->prepare($sql)->execute($bind);
    return (int) $pdo->lastInsertId();
}

function ssoEnsureTenantMembership(\PDO $pdo, int $userId, int $tenantId): void {
    // provisionMembership() dual-writes both user_tenants + tenant_memberships
    // so the legacy bridge keeps working until user_tenants is fully retired.
    try {
        require_once __DIR__ . '/../../core/memberships.php';
        provisionMembership($userId, $tenantId, 'user', [
            'persona_label' => 'Primary',
            'status'        => 'active',
        ]);
    } catch (\Throwable $_) { /* table may not exist on minimal installs */ }
}

function ssoCallbackError(string $msg): void {
    http_response_code(401);
    $h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Sign-in failed</title>'
       . '<style>body{font-family:system-ui;background:#f8fafc;color:#0f172a;padding:40px 16px;margin:0}'
       . '.card{max-width:480px;margin:auto;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.06);padding:28px}'
       . 'h1{color:#dc2626;margin:0 0 12px}.btn{display:inline-block;margin-top:16px;background:#0f172a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:600;font-size:13px}</style>'
       . '</head><body><div class="card" data-testid="sso-callback-error">'
       . '<h1>Sign-in failed</h1>'
       . '<p>' . $h($msg) . '</p>'
       . '<a class="btn" href="/login">Back to sign-in</a>'
       . '</div></body></html>';
    exit;
}
