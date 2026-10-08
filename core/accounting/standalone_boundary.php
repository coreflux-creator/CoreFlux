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

/** Keep incomplete standalone configuration out of public responses. */
function coreAccountingConfigurationFailure(string $reason): never
{
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException($reason);
    }
    error_log($reason);
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(503);
    echo '{"error":"CoreAccounting is not configured","status":503}';
    exit;
}
