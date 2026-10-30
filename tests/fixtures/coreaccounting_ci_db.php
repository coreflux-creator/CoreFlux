<?php
/** Private-copy database config for the disposable CoreAccounting CI database. */
declare(strict_types=1);

if (!in_array(getenv('COREFLUX_ENV'), ['staging', 'coreaccounting'], true)
    || getenv('DB_NAME') !== 'coreaccounting_ci') {
    throw new RuntimeException('The CI database fixture is restricted to its disposable database.');
}

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $setting) {
    $value = getenv($setting);
    if ($value === false || $value === '') {
        throw new RuntimeException("Missing CI database setting: {$setting}");
    }
    define($setting, $value);
}
