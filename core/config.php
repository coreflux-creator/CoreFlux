<?php
/**
 * CoreFlux Platform Configuration
 * Central configuration for the platform core
 */

// A dedicated CoreAccounting host rejects unknown flat APIs before loading
// secrets, opening a session, or running startup migrations.
if (getenv('COREFLUX_ENV') === 'coreaccounting') {
    require_once __DIR__ . '/accounting/standalone_boundary.php';
    require_once __DIR__ . '/accounting/standalone_api_boundary.php';
    if (PHP_SAPI !== 'cli') {
        coreAccountingEnforcePublicApiScript($_SERVER['SCRIPT_FILENAME'] ?? null, dirname(__DIR__));
    }
}

// A standalone service must load its credentials from outside the public
// checkout. Staging CLI commands may use the same private file.
$standaloneAccounting = getenv('COREFLUX_ENV') === 'coreaccounting';
$privateDbConfigPath = ($standaloneAccounting || getenv('COREFLUX_ENV') === 'staging')
    ? trim((string) (getenv('COREFLUX_ACCOUNTING_DB_CONFIG_PATH') ?: '')) : '';
if ($privateDbConfigPath !== '') {
    $resolvedDbConfig = realpath($privateDbConfigPath);
    $publicRoot = realpath(dirname(__DIR__));
    $requestedPath = str_replace('\\', '/', $privateDbConfigPath);
    $publicPath = str_replace('\\', '/', (string) $publicRoot);
    if (PHP_OS_FAMILY === 'Windows') {
        $requestedPath = strtolower($requestedPath);
        $publicPath = strtolower($publicPath);
    }
    $requestedInsideWebroot = $publicRoot !== false
        && ($requestedPath === $publicPath || str_starts_with($requestedPath, $publicPath . '/'));
    $invalidDbConfig = !preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $privateDbConfigPath)
        || $resolvedDbConfig === false || $publicRoot === false || $requestedInsideWebroot
        || !is_file($resolvedDbConfig) || !is_readable($resolvedDbConfig)
        || pathinfo($resolvedDbConfig, PATHINFO_EXTENSION) !== 'php'
        || $resolvedDbConfig === $publicRoot
        || str_starts_with($resolvedDbConfig, $publicRoot . DIRECTORY_SEPARATOR);
    if ($invalidDbConfig) {
        if ($standaloneAccounting) {
            coreAccountingConfigurationFailure('CoreAccounting private database configuration is unavailable.');
        }
        throw new RuntimeException('Private staging database configuration is unavailable.');
    }
    require_once $resolvedDbConfig;
} elseif ($standaloneAccounting) {
    coreAccountingConfigurationFailure('CoreAccounting private database configuration is unavailable.');
} else {
    $dbLocalConfig = __DIR__ . '/db.local.php';
    if (is_file($dbLocalConfig)) {
        require_once $dbLocalConfig;
    }
}
$requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$requestHost = preg_replace('/:\d+$/', '', $requestHost);
$requiresExplicitDb = getenv('COREFLUX_ENV') === 'staging'
    || (!$standaloneAccounting && ((bool) preg_match('/(^|\.)cloudwaysapps\.com$/', $requestHost)
        || in_array($requestHost, ['stage.corefluxapp.com', 'staging.corefluxapp.com'], true)));
if (!defined('COREFLUX_STAGING')) {
    define('COREFLUX_STAGING', $requiresExplicitDb);
}
if ($requiresExplicitDb || $standaloneAccounting) {
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
        if (!defined($setting) || trim((string) constant($setting)) === '') {
            if ($standaloneAccounting) {
                coreAccountingConfigurationFailure('CoreAccounting database configuration is incomplete.');
            }
            throw new RuntimeException('Staging database configuration is incomplete.');
        }
    }
}
if ($standaloneAccounting) {
    require_once __DIR__ . '/accounting/mail_settings.php';
    $expectedDatabase = trim((string) (getenv('COREFLUX_STANDALONE_DATABASE') ?: ''));
    if ($expectedDatabase === '' || !hash_equals($expectedDatabase, (string) DB_NAME)) {
        coreAccountingConfigurationFailure('CoreAccounting database identity is missing or does not match.');
    }

    $mailConfigPath = trim((string) (getenv('COREFLUX_ACCOUNTING_MAIL_CONFIG_PATH') ?: ''));
    if ($mailConfigPath !== '') {
        $resolvedMailConfig = realpath($mailConfigPath);
        $publicRoot = realpath(dirname(__DIR__));
        $requestedPath = str_replace('\\', '/', $mailConfigPath);
        $publicPath = str_replace('\\', '/', (string) $publicRoot);
        if (PHP_OS_FAMILY === 'Windows') {
            $requestedPath = strtolower($requestedPath);
            $publicPath = strtolower($publicPath);
        }
        $requestedInsideWebroot = $publicRoot !== false
            && ($requestedPath === $publicPath || str_starts_with($requestedPath, $publicPath . '/'));
        if (!preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $mailConfigPath)
            || $resolvedMailConfig === false || $publicRoot === false || $requestedInsideWebroot
            || !is_file($resolvedMailConfig) || !is_readable($resolvedMailConfig)
            || is_link($mailConfigPath) || pathinfo($resolvedMailConfig, PATHINFO_EXTENSION) !== 'php'
            || $resolvedMailConfig === $publicRoot
            || str_starts_with($resolvedMailConfig, $publicRoot . DIRECTORY_SEPARATOR)) {
            coreAccountingConfigurationFailure('CoreAccounting private mail configuration is unavailable.');
        }
        require_once $resolvedMailConfig;
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
    foreach (['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'SMTP_SECURE', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME'] as $setting) {
        if (defined($setting)) {
            coreAccountingConfigurationFailure('CoreAccounting SMTP settings must use dedicated environment variables.');
        }
    }
    define('SMTP_HOST', trim(coreAccountingMailSetting('COREFLUX_ACCOUNTING_SMTP_HOST')));
    define('SMTP_PORT', (int) (coreAccountingMailSetting('COREFLUX_ACCOUNTING_SMTP_PORT') ?: 587));
    define('SMTP_USER', trim(coreAccountingMailSetting('COREFLUX_ACCOUNTING_SMTP_USER')));
    define('SMTP_PASS', coreAccountingMailSetting('COREFLUX_ACCOUNTING_SMTP_PASS'));
    define('SMTP_SECURE', trim(coreAccountingMailSetting('COREFLUX_ACCOUNTING_SMTP_SECURE') ?: 'tls'));
    define('SMTP_FROM_EMAIL', trim(coreAccountingMailSetting('COREFLUX_ACCOUNTING_FROM_EMAIL')));
    define('SMTP_FROM_NAME', trim(coreAccountingMailSetting('COREFLUX_ACCOUNTING_FROM_NAME') ?: 'CoreAccounting'));
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
        coreAccountingConfigurationFailure('CoreAccounting requires an explicit HTTPS public origin.');
    }
    $appUrl = $standaloneOrigin;
}
define('APP_URL', $appUrl);

// Session Settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Feature Flags
define('USE_DATABASE', true); // Database authentication enabled
