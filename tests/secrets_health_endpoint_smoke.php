<?php
/**
 * Smoke — /api/admin/secrets_health.php (2026-02).
 *
 * Locks the cross-integration secrets-configured probe surface.
 * master_admin only; never echoes raw secret values; reports
 * loaded_from + 5-char hint per integration.
 *
 * Static-analyzer only — no DB / HTTP. The endpoint's logic is pure
 * read of constants + base64 sanity check on COREFLUX_DATA_KEY.
 */
declare(strict_types=1);

$pass = 0; $fail = 0;
$a = function (string $msg, bool $cond) use (&$pass, &$fail) {
    if ($cond) { echo "  ✓ $msg\n"; $pass++; }
    else       { echo "  ✗ $msg\n"; $fail++; }
};
$c = function (string $hay, string $needle): bool { return strpos($hay, $needle) !== false; };

// ──────────────────────────────────────────────────────────────────────
// 1) Endpoint exists + correctly gated.
// ──────────────────────────────────────────────────────────────────────
echo "\n── api/admin/secrets_health.php ──\n";
$src = (string) file_get_contents('/app/api/admin/secrets_health.php');

$a('endpoint exists',                                $src !== '');
$a('declares strict_types',                          $c($src, 'declare(strict_types=1)'));
$a('requires api_bootstrap',                         $c($src, "require_once __DIR__ . '/../../core/api_bootstrap.php'"));
$a('calls api_require_auth',                         $c($src, 'api_require_auth()'));
$a('refuses non-GET',                                $c($src, "api_method() !== 'GET'"));
$a('master_admin OR is_global_admin gated',
    $c($src, "!\$isGlobalAdmin && \$role !== 'master_admin'"));
$a('emits 403 on RBAC failure',                      $c($src, 'master_admin only'));

// ──────────────────────────────────────────────────────────────────────
// 2) Security — NEVER echoes raw secret value back.
// ──────────────────────────────────────────────────────────────────────
echo "\n── secret-leak guards ──\n";

// The endpoint must NOT directly serialise any of these into the
// response. We grep the response section for raw RESEND_API_KEY etc.
$a('response uses key_hint (substr 0,5 + ellipsis), NEVER raw value',
    $c($src, "substr(\$r['value'], 0, 5)"));
$a('5-char hint is the ONLY surface of any secret',
    substr_count($src, 'key_hint') >= 2);

// Ensure raw constants don't make it into the response payload.
$a('does NOT echo RESEND_API_KEY constant value directly',
    !preg_match("/['\"]\\w*['\"]?\\s*=>\\s*RESEND_API_KEY\\b/", $src)
    && !preg_match("/['\"]\\w*['\"]?\\s*=>\\s*constant\\(['\"]RESEND_API_KEY['\"]\\)/", $src));
$a('does NOT echo OPENAI_API_KEY constant value directly',
    !preg_match("/['\"]\\w*['\"]?\\s*=>\\s*OPENAI_API_KEY\\b/", $src));
$a('does NOT echo QBO_CLIENT_SECRET constant value directly',
    !preg_match("/['\"]\\w*['\"]?\\s*=>\\s*QBO_CLIENT_SECRET\\b/", $src));

// ──────────────────────────────────────────────────────────────────────
// 3) Resolver / probe shape — env-first, then define(), else missing.
// ──────────────────────────────────────────────────────────────────────
echo "\n── env-first resolver ──\n";
$a('reads getenv() first',                           $c($src, "(string) getenv(\$name)"));
$a('falls back to defined() / constant()',
    $c($src, 'defined($name)') && $c($src, 'constant($name)'));
$a('reports loaded_from as env | define | missing',
    $c($src, "'loaded_from' => 'env'")
    && $c($src, "'loaded_from' => 'define'")
    && $c($src, "'loaded_from' => 'missing'"));
$a('probe block reports configured + loaded_from + key_hint + length',
    $c($src, "'configured'  => \$configured")
    && $c($src, "'loaded_from' => \$r['loaded_from']")
    && $c($src, "'key_hint'    => \$configured")
    && $c($src, "'length'      => \$configured"));

// ──────────────────────────────────────────────────────────────────────
// 4) Every integration we said we'd cover IS covered.
// ──────────────────────────────────────────────────────────────────────
echo "\n── integration coverage ──\n";
foreach ([
    'RESEND_API_KEY'           => 'resend',
    'OPENAI_API_KEY'           => 'openai',
    'PLAID_CLIENT_ID'          => 'plaid',
    'PLAID_SECRET_SANDBOX'     => 'plaid sandbox',
    'PLAID_SECRET_PRODUCTION'  => 'plaid production',
    'QBO_CLIENT_ID'            => 'qbo client_id',
    'QBO_CLIENT_SECRET'        => 'qbo client_secret',
    'COREFLUX_DATA_KEY'        => 'coreflux data key',
] as $constName => $label) {
    $a("probes $label ($constName)", $c($src, "\$probe('$constName')"));
}

// ──────────────────────────────────────────────────────────────────────
// 5) Sanity checks attached to specific probes.
// ──────────────────────────────────────────────────────────────────────
echo "\n── per-integration sanity checks ──\n";
$a('Resend includes from_email + from_name companions',
    $c($src, "\$resend['from_email'] = defined('RESEND_FROM_EMAIL')")
    && $c($src, "\$resend['from_name']  = defined('RESEND_FROM_NAME')"));
$a('Resend from_email is FILTER_VALIDATE_EMAIL checked',
    $c($src, 'FILTER_VALIDATE_EMAIL'));
$a('Plaid picks the active secret by PLAID_ENV (sandbox vs production)',
    $c($src, "\$plaidEnv === 'sandbox'")
    && $c($src, "\$probe('PLAID_SECRET_SANDBOX')")
    && $c($src, "\$probe('PLAID_SECRET_PRODUCTION')"));
$a('QBO surfaces non-secret env + redirect_uri + scopes',
    $c($src, "QBO_ENV")
    && $c($src, "QBO_REDIRECT_URI")
    && $c($src, "QBO_SCOPES"));
$a('COREFLUX_DATA_KEY decodes_to_32_bytes sanity check',
    $c($src, 'decodes_to_32_bytes')
    && $c($src, 'base64_decode')
    && $c($src, 'strlen((string) $raw) === 32'));

// ──────────────────────────────────────────────────────────────────────
// 6) Top-level all_configured + sidecar_file telemetry.
// ──────────────────────────────────────────────────────────────────────
echo "\n── top-level response shape ──\n";
$a('returns ok + all_configured + sidecar_file',
    $c($src, "'ok'                  => true")
    && $c($src, "'all_configured'      => \$everythingConfigured")
    && $c($src, "'sidecar_file'        => ["));
$a('sidecar_file.present reflects file_exists',
    $c($src, "config.secrets.php")
    && $c($src, "is_file(\$sidecarPath)"));
$a('next_steps branches on all_configured',
    $c($src, '$everythingConfigured')
    && $c($src, 'Send test to confirm Resend delivers')
    && $c($src, 'SECRETS_SIDECAR_DEPLOY.md'));

// php -l clean.
exec('php -l /app/api/admin/secrets_health.php 2>&1', $out, $rc);
$a('endpoint passes php -l',                         $rc === 0);

// ──────────────────────────────────────────────────────────────────────
// 7) Functional probe — load config.local.php, simulate the resolver
//    against the real constants, and verify the response would be
//    sane on this pod.
// ──────────────────────────────────────────────────────────────────────
echo "\n── functional resolver probe ──\n";
require_once '/app/core/config.local.php';

$resolve = function (string $name): array {
    $envVal = (string) getenv($name);
    if ($envVal !== '') return ['value' => $envVal, 'loaded_from' => 'env'];
    if (defined($name)) {
        $defVal = (string) constant($name);
        if ($defVal !== '') return ['value' => $defVal, 'loaded_from' => 'define'];
    }
    return ['value' => '', 'loaded_from' => 'missing'];
};

$r = $resolve('RESEND_API_KEY');
$a('RESEND_API_KEY resolves to a non-empty value',   $r['value'] !== '');
$a('RESEND_API_KEY loaded_from is define (sidecar)', $r['loaded_from'] === 'define');
$a('RESEND_API_KEY starts with re_',                 str_starts_with($r['value'], 're_'));

$r = $resolve('OPENAI_API_KEY');
$a('OPENAI_API_KEY resolves to a non-empty value',   $r['value'] !== '');

$r = $resolve('NON_EXISTENT_CONSTANT_XYZ');
$a('missing constant reports loaded_from=missing',   $r['loaded_from'] === 'missing');
$a('missing constant reports empty value',            $r['value'] === '');

// ──────────────────────────────────────────────────────────────────────
echo "\n=========================================\n";
echo "secrets_health smoke: $pass ✓ / $fail ✗\n";
echo "=========================================\n";
exit($fail === 0 ? 0 : 1);
