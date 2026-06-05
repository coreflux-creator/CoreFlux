<?php
/**
 * /api/admin/secrets_health.php
 *
 * Cross-integration secrets-configured probe. master_admin only.
 *
 * Returns a boolean `configured` flag for every secret/credential the
 * platform depends on, plus non-sensitive metadata that helps the
 * operator confirm the right value loaded:
 *   - 5-char key_hint (e.g. "re_L5…")  — first 5 chars + ellipsis
 *   - loaded_from: "env" | "define" | "missing"
 *   - non-secret companion metadata (FROM_EMAIL for Resend, ENV for
 *     Plaid/QBO, REDIRECT_URI for QBO)
 *
 * NEVER returns the raw secret value. The 5-char hint exists only to
 * disambiguate "is the key I just rotated actually loaded?" — it
 * leaks too little to be exploitable on its own (a Resend key has
 * 36 chars of base32 entropy after the `re_` prefix).
 *
 * Designed for the Cloudways sidecar workflow:
 *   1. Operator SCPs `core/config.secrets.php` to the host.
 *   2. Reload PHP-FPM.
 *   3. Hit this endpoint — every integration should report
 *      `configured: true` and `loaded_from: "define"`.
 *   4. If anything reports `configured: false` or `loaded_from:
 *      "missing"`, the file isn't on the right path or PHP-FPM
 *      didn't pick up the reload.
 *
 * RBAC: master_admin (or is_global_admin) only — surfacing the
 * full integration-state map across tenants is a global-admin
 * privilege.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../core/api_bootstrap.php';
require_once __DIR__ . '/../../core/RBAC.php';

$ctx           = api_require_auth();
$role          = (string) ($ctx['role'] ?? 'employee');
$isGlobalAdmin = (bool) ($ctx['is_global_admin'] ?? false);

if (!$isGlobalAdmin && $role !== 'master_admin') {
    api_error('Forbidden — master_admin only', 403);
}
if (api_method() !== 'GET') api_error('Method not allowed', 405);

/**
 * Resolve a constant from env first, then define(). Returns
 * ['value' => string, 'loaded_from' => 'env'|'define'|'missing'].
 * Value is the raw secret — caller MUST NOT echo it back. Used only
 * to produce the redacted hint.
 */
$resolve = function (string $name): array {
    $envVal = (string) getenv($name);
    if ($envVal !== '') {
        return ['value' => $envVal, 'loaded_from' => 'env'];
    }
    if (defined($name)) {
        $defVal = (string) constant($name);
        if ($defVal !== '') {
            return ['value' => $defVal, 'loaded_from' => 'define'];
        }
    }
    return ['value' => '', 'loaded_from' => 'missing'];
};

/**
 * Build a {configured, loaded_from, key_hint, length} block. Never
 * leaks more than the first 5 chars of the secret.
 */
$probe = function (string $name) use ($resolve): array {
    $r = $resolve($name);
    $configured = $r['value'] !== '';
    return [
        'configured'  => $configured,
        'loaded_from' => $r['loaded_from'],
        'key_hint'    => $configured ? (substr($r['value'], 0, 5) . '…') : null,
        'length'      => $configured ? strlen($r['value']) : 0,
    ];
};

// ─── Resend ──────────────────────────────────────────────────────────
$resend = $probe('RESEND_API_KEY');
$resend['from_email'] = defined('RESEND_FROM_EMAIL') ? (string) constant('RESEND_FROM_EMAIL') : null;
$resend['from_name']  = defined('RESEND_FROM_NAME')  ? (string) constant('RESEND_FROM_NAME')  : null;
// Sanity check: from_email should look like name@verified.domain.
$resend['from_email_looks_valid'] = $resend['from_email'] !== null
    && filter_var($resend['from_email'], FILTER_VALIDATE_EMAIL) !== false;

// ─── OpenAI ──────────────────────────────────────────────────────────
$openai = $probe('OPENAI_API_KEY');

// ─── Plaid ───────────────────────────────────────────────────────────
$plaidClientId = $probe('PLAID_CLIENT_ID');
$plaidEnv      = defined('PLAID_ENV') ? (string) constant('PLAID_ENV') : null;
$plaidSecretActive = $plaidEnv === 'sandbox'
    ? $probe('PLAID_SECRET_SANDBOX')
    : $probe('PLAID_SECRET_PRODUCTION');
$plaid = [
    'configured'        => $plaidClientId['configured'] && $plaidSecretActive['configured'],
    'env'               => $plaidEnv,
    'client_id'         => $plaidClientId,
    'active_secret'     => $plaidSecretActive,
    'sandbox_secret'    => $probe('PLAID_SECRET_SANDBOX'),
    'production_secret' => $probe('PLAID_SECRET_PRODUCTION'),
];

// ─── QBO ─────────────────────────────────────────────────────────────
$qboClientId     = $probe('QBO_CLIENT_ID');
$qboClientSecret = $probe('QBO_CLIENT_SECRET');
$qbo = [
    'configured'    => $qboClientId['configured'] && $qboClientSecret['configured'],
    'env'           => defined('QBO_ENV')          ? (string) constant('QBO_ENV')          : null,
    'redirect_uri'  => defined('QBO_REDIRECT_URI') ? (string) constant('QBO_REDIRECT_URI') : null,
    'scopes'        => defined('QBO_SCOPES')       ? (string) constant('QBO_SCOPES')       : null,
    'client_id'     => $qboClientId,
    'client_secret' => $qboClientSecret,
];

// ─── COREFLUX_DATA_KEY (encryption-at-rest) ──────────────────────────
$dataKey = $probe('COREFLUX_DATA_KEY');
// Sanity check: base64 → 32 bytes (256-bit key).
$dataKey['decodes_to_32_bytes'] = $dataKey['configured']
    && ($raw = base64_decode((string) constant('COREFLUX_DATA_KEY'), true)) !== false
    && strlen((string) $raw) === 32;

// ─── Sidecar provisioning hint ───────────────────────────────────────
$sidecarPath = realpath(__DIR__ . '/../../core/config.secrets.php') ?: null;
$sidecarPresent = $sidecarPath !== null && is_file($sidecarPath);

$everythingConfigured = $resend['configured']
                     && $openai['configured']
                     && $plaid['configured']
                     && $qbo['configured']
                     && $dataKey['configured'];

api_ok([
    'ok'                  => true,
    'all_configured'      => $everythingConfigured,
    'sidecar_file'        => [
        'present'    => $sidecarPresent,
        'path_hint'  => $sidecarPresent ? basename(dirname((string) $sidecarPath)) . '/' . basename((string) $sidecarPath) : 'core/config.secrets.php (NOT FOUND)',
    ],
    'resend'              => $resend,
    'openai'              => $openai,
    'plaid'               => $plaid,
    'qbo'                 => $qbo,
    'data_key'            => $dataKey,
    'next_steps'          => $everythingConfigured
        ? 'All integrations are wired. Hit Admin → Notifications → Send test to confirm Resend delivers.'
        : 'One or more integrations are missing. See SECRETS_SIDECAR_DEPLOY.md — typically you need to SCP core/config.secrets.php to the host and reload PHP-FPM.',
]);
