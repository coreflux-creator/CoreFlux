<?php
/**
 * LayerFi sandbox configuration + feature-flag helpers.
 *
 * All values are environment-driven (12-factor) so the platform-level
 * LayerFi credentials never live in version control. Sensible sandbox
 * defaults are provided for every non-secret value.
 *
 * SECURITY: LAYER_CLIENT_SECRET is read here and used only inside the
 * backend OAuth call. It is NEVER returned to the frontend, logged, or
 * persisted (see core/integrations/layer/layer_audit.php scrubbing).
 */
declare(strict_types=1);

function layer_env_str(string $key, string $default = ''): string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : (string) $v;
}

function layer_config(): array
{
    return [
        'environment'  => layer_env_str('LAYER_ENV', 'sandbox'),
        'apiBaseUrl'   => rtrim(layer_env_str('LAYER_API_BASE_URL', 'https://sandbox.layerfi.com'), '/'),
        'authUrl'      => layer_env_str('LAYER_AUTH_URL', 'https://auth.layerfi.com/oauth2/token'),
        'oauthScope'   => layer_env_str('LAYER_OAUTH_SCOPE', 'https://sandbox.layerfi.com/sandbox'),
        'clientId'     => layer_env_str('LAYER_CLIENT_ID', ''),
        'clientSecret' => layer_env_str('LAYER_CLIENT_SECRET', ''),
        'tokenTtl'     => max(60, (int) layer_env_str('LAYER_BUSINESS_TOKEN_TTL_SECONDS', '3600')),
    ];
}

/** Whole-integration feature flag. When false every endpoint returns 404. */
function layer_enabled(): bool
{
    $v = strtolower(layer_env_str('ENABLE_LAYER_SANDBOX', 'false'));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

/**
 * True when real LayerFi credentials are absent or placeholders. In that
 * mode the backend uses an in-process sandbox stub so the full flow can be
 * exercised end-to-end without live keys. Flip to live LayerFi by simply
 * setting LAYER_CLIENT_ID + LAYER_CLIENT_SECRET.
 */
function layer_is_stub(): bool
{
    $c = layer_config();
    if ($c['clientId'] === '' || $c['clientSecret'] === '') return true;
    if (in_array(strtolower($c['clientId']), ['replace_me', 'changeme', 'your_client_id'], true)) return true;
    return false;
}

/**
 * Per-tenant allowlist (spec extension).
 *
 * `LAYER_TENANT_ALLOWLIST` is a comma-separated list of CoreFlux tenant ids.
 *   • empty / unset  → no per-tenant restriction (the global feature flag alone gates access).
 *   • non-empty      → ONLY the listed tenants may use LayerFi, even when the flag is on.
 *
 * This lets you ship + enable the feature globally but reveal it to just a
 * pilot set of tenants. The native ledger stays the only surface for everyone else.
 */
function layer_tenant_allowlist(): array
{
    $raw = layer_env_str('LAYER_TENANT_ALLOWLIST', '');
    if (trim($raw) === '') return [];
    $ids = array_map('intval', array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
    return array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
}

/** True when an allowlist is configured (restriction active). */
function layer_allowlist_mode(): bool
{
    return !empty(layer_tenant_allowlist());
}

/** Global default for tenants with no explicit DB enablement row. */
function layer_default_tenant_enabled(): bool
{
    $v = strtolower(layer_env_str('LAYER_TENANT_DEFAULT_ENABLED', 'false'));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}
