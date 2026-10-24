<?php
declare(strict_types=1);

/** Host environment wins; private standalone PHP config can define constants. */
if (!function_exists('coreAccountingMailSetting')) {
    function coreAccountingMailSetting(string $name): string
    {
        if (!str_starts_with($name, 'COREFLUX_ACCOUNTING_')) {
            throw new InvalidArgumentException('Not a CoreAccounting setting');
        }
        $environment = getenv($name);
        if ($environment !== false && (string) $environment !== '') return (string) $environment;
        return defined($name) ? (string) constant($name) : '';
    }
}
