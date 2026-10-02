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
$requiresExplicitDb = getenv('COREFLUX_ENV') === 'staging'
    || (bool) preg_match('/(^|\.)cloudwaysapps\.com$/', $requestHost)
    || in_array($requestHost, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true);
if (!defined('COREFLUX_STAGING')) {
    define('COREFLUX_STAGING', $requiresExplicitDb);
}
if ($requiresExplicitDb) {
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
        if (!defined($setting) || trim((string) constant($setting)) === '') {
            throw new RuntimeException('Staging database configuration is incomplete.');
        }
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
define('SMTP_HOST', 'smtp.mail.yahoo.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'no-reply@corefluxapp.com');
define('SMTP_PASS', 'rpevtweukxlgnkll');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM_EMAIL', 'no-reply@corefluxapp.com');
define('SMTP_FROM_NAME', 'CoreFlux Notifications');

// Application Settings
define('APP_NAME', 'CoreFlux');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://www.corefluxapp.com');

// Session Settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Feature Flags
define('USE_DATABASE', true); // Database authentication enabled
