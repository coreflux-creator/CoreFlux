<?php
/**
 * core/config.secrets.example.php
 *
 * Template for the gitignored core/config.secrets.php.  Copy to
 * `config.secrets.php` and fill in real values.  Both files live next
 * to config.local.php; config.local.php @includes whichever exists.
 *
 * NEVER fill real values into this example file.  This file is
 * tracked in git as a deployment template.
 */

// CoreFlux data-encryption key — base64, 32 bytes after decode.
//   php -r "echo base64_encode(random_bytes(32)) . PHP_EOL;"
if (!defined('COREFLUX_DATA_KEY')) {
    define('COREFLUX_DATA_KEY', 'REPLACE_WITH_BASE64_32_BYTE_KEY');
}

// OpenAI (https://platform.openai.com/api-keys)
if (!defined('OPENAI_API_KEY')) {
    define('OPENAI_API_KEY', 'sk-REPLACE_ME');
}

// Plaid (https://dashboard.plaid.com/team/keys)
if (!defined('PLAID_CLIENT_ID')) {
    define('PLAID_CLIENT_ID',         'REPLACE_ME');
}
if (!defined('PLAID_SECRET_SANDBOX')) {
    define('PLAID_SECRET_SANDBOX',    'REPLACE_ME');
}
if (!defined('PLAID_SECRET_PRODUCTION')) {
    define('PLAID_SECRET_PRODUCTION', 'REPLACE_ME');
}

// Resend (https://resend.com/api-keys)
if (!defined('RESEND_API_KEY')) {
    define('RESEND_API_KEY', 're_REPLACE_ME');
}

// QuickBooks Online (https://developer.intuit.com/app/developer/dashboard)
if (!defined('QBO_CLIENT_ID')) {
    define('QBO_CLIENT_ID',     'REPLACE_ME');
}
if (!defined('QBO_CLIENT_SECRET')) {
    define('QBO_CLIENT_SECRET', 'REPLACE_ME');
}
