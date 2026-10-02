<?php
// Copy to core/db.local.php on each non-production host and supply that
// application's own database credentials. Never commit the real file.
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'REPLACE_WITH_APP_DATABASE_NAME');
define('DB_USER', 'REPLACE_WITH_APP_DATABASE_USER');
define('DB_PASS', 'REPLACE_WITH_APP_DATABASE_PASSWORD');
