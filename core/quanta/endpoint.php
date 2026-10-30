<?php
/** Deployment-owned endpoint selection; invalid preview configuration fails before IO. */
declare(strict_types=1);

const QUANTA_API_BASE = 'https://helloquanta.app/api/v1';

function quantaApiBase(): string
{
    // A private PHP constant also works on hosts that disable putenv() in FPM.
    $configured = defined('COREFLUX_QUANTA_PREVIEW_API_BASE')
        ? constant('COREFLUX_QUANTA_PREVIEW_API_BASE')
        : getenv('COREFLUX_QUANTA_PREVIEW_API_BASE');
    if ($configured === false || $configured === '') return QUANTA_API_BASE;
    if (!is_string($configured) || !defined('COREFLUX_STAGING') || COREFLUX_STAGING !== true
        || !in_array($configured, [
            'https://clockin-hub-16.preview.emergentagent.com/api/v1',
            'https://helloquanta.app:19443/api/v1',
        ], true)) {
        throw new RuntimeException('Quanta preview access requires the reviewed isolated staging destination.');
    }
    return $configured;
}
