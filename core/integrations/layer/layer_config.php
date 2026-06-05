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
