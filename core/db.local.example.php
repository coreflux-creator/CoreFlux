<?php
// For standalone CoreAccounting, copy outside public_html and set
// COREFLUX_ACCOUNTING_DB_CONFIG_PATH to its absolute path. Existing ERP
// staging hosts may still use core/db.local.php. Never commit the real file.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'REPLACE_WITH_APP_DATABASE_NAME');
define('DB_USER', 'REPLACE_WITH_APP_DATABASE_USER');
define('DB_PASS', 'REPLACE_WITH_APP_DATABASE_PASSWORD');
