<?php
/**
 * CoreFlux Platform Configuration
 * Central configuration for the platform core
 */

// Cloudways default domains and explicit staging runs must never use the
// legacy production database fallback. Each staging app needs its own file.
$dbLocalConfig = __DIR__ . '/db.local.php';
if (is_file($dbLocalConfig)) {
    require_once $dbLocalConfig;
}
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost);
$standaloneAccounting = getenv('COREFLUX_ENV') === 'coreaccounting';
$requiresExplicitDb = getenv('COREFLUX_ENV') === 'staging'
    || (!$standaloneAccounting && ((bool) preg_match('/(^|\.)cloudwaysapps\.com$/', $requestHost)
        || in_array($requestHost, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true)));
if (!defined('COREFLUX_STAGING')) {
    define('COREFLUX_STAGING', $requiresExplicitDb);
}
if ($requiresExplicitDb || $standaloneAccounting) {
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
        if (!defined($setting) || trim((string) constant($setting)) === '') {
            throw new RuntimeException($standaloneAccounting
                ? 'CoreAccounting database configuration is incomplete.'
                : 'Staging database configuration is incomplete.');
        }
    }
}
if ($standaloneAccounting) {
    $expectedDatabase = trim((string) (getenv('COREFLUX_STANDALONE_DATABASE') ?: ''));
    if ($expectedDatabase === '' || !hash_equals($expectedDatabase, (string) DB_NAME)) {
        throw new RuntimeException('CoreAccounting database identity is missing or does not match.');
    }
}

// Database Configuration
if (!defined('DB_HOST')) {
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'grcudkpvcd');
define('DB_USER', getenv('DB_USER') ?: 'grcudkpvcd');
define('DB_PASS', getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '7DgX7F4RPz');

}

// SMTP Configuration
if ($standaloneAccounting) {
    define('SMTP_HOST', trim((string) (getenv('SMTP_HOST') ?: '')));
    define('SMTP_PORT', (int) (getenv('SMTP_PORT') ?: 587));
    define('SMTP_USER', trim((string) (getenv('SMTP_USER') ?: '')));
    define('SMTP_PASS', (string) (getenv('SMTP_PASS') ?: ''));
    define('SMTP_SECURE', trim((string) (getenv('SMTP_SECURE') ?: 'tls')));
    define('SMTP_FROM_EMAIL', trim((string) (getenv('SMTP_FROM_EMAIL') ?: '')));
    define('SMTP_FROM_NAME', trim((string) (getenv('SMTP_FROM_NAME') ?: 'CoreAccounting')));
} else {
define('SMTP_HOST', 'smtp.mail.yahoo.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'no-reply@corefluxapp.com');
define('SMTP_PASS', 'rpevtweukxlgnkll');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM_EMAIL', 'no-reply@corefluxapp.com');
define('SMTP_FROM_NAME', 'CoreFlux Notifications');
}

// Application Settings
define('APP_NAME', 'CoreFlux');
define('APP_VERSION', '1.0.0');
$appUrl = 'https://www.corefluxapp.com';
if (COREFLUX_STAGING) {
    $stagingOrigin = rtrim(trim((string) (getenv('COREFLUX_STAGING_PUBLIC_ORIGIN') ?: '')), '/');
    if ($stagingOrigin !== '') {
        $parts = parse_url($stagingOrigin);
        $originHost = strtolower((string) ($parts['host'] ?? ''));
        $validOrigin = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))
            && ((bool) preg_match('/^phpstack-\d+-\d+\.cloudwaysapps\.com$/', $originHost)
                || in_array($originHost, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true));
        if (!$validOrigin) throw new RuntimeException('The staging public origin must be a trusted HTTPS origin.');
        $appUrl = $stagingOrigin;
    } elseif ((bool) preg_match('/^phpstack-\d+-\d+\.cloudwaysapps\.com$/', $requestHost)
        || in_array($requestHost, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true)) {
        $appUrl = 'https://' . $requestHost;
    } else {
        $appUrl = '';
    }
} elseif ($standaloneAccounting) {
    $standaloneOrigin = trim((string) (getenv('COREFLUX_PUBLIC_ORIGIN') ?: ''));
    $parts = parse_url($standaloneOrigin);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host'])
        || array_intersect(['user', 'pass', 'port', 'path', 'query', 'fragment'], array_keys($parts))) {
        throw new RuntimeException('CoreAccounting requires an explicit HTTPS public origin.');
    }
    $appUrl = $standaloneOrigin;
}
define('APP_URL', $appUrl);

// Session Settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Feature Flags
define('USE_DATABASE', true); // Database authentication enabled
