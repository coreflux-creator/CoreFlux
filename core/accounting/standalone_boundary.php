<?php
declare(strict_types=1);

/** Prefer the server-resolved endpoint path over a client-controlled URL. */
function coreAccountingRequestModule(?string $scriptFilename, ?string $scopedModule): ?string
{
    if ($scriptFilename !== null
        && preg_match('~[\\\\/]modules[\\\\/]([a-z][a-z0-9_-]*)[\\\\/]api[\\\\/]~i', $scriptFilename, $match)) {
        return strtolower($match[1]);
    }

    return $scopedModule;
}

/** Keep standalone API access on the shared finance modules. */
function coreAccountingAllowsModule(?string $moduleKey, string $environment): bool
{
    if ($environment !== 'coreaccounting' || $moduleKey === null) {
        return true;
    }

    return in_array($moduleKey, ['accounting', 'billing', 'ap', 'treasury'], true);
}

/** Billing and AP share the company directory without exposing the People module. */
function coreAccountingAllowsModuleRequest(?string $scriptFilename, ?string $moduleKey, string $environment): bool
{
    if (coreAccountingAllowsModule($moduleKey, $environment)) return true;
    return $environment === 'coreaccounting'
        && $moduleKey === 'people'
        && $scriptFilename !== null
        && str_ends_with(str_replace('\\', '/', $scriptFilename), '/modules/people/api/companies.php');
}

/** Keep incomplete standalone configuration out of public responses. */
function coreAccountingConfigurationFailure(string $reason): never
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($reason);
    }
    error_log($reason);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    http_response_code(503);
    echo '{"error":"CoreAccounting is not configured","status":503}';
    exit;
}
