<?php
/** Private-copy database config for the disposable CoreAccounting CI database. */
declare(strict_types=1);

if (getenv('COREFLUX_ENV') !== 'staging') {
    throw new RuntimeException('The CI database fixture is staging-only.');
}

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
    $value = getenv($setting);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing CI database setting: {$setting}");
    }
    define($setting, $value);
}
