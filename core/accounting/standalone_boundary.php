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
