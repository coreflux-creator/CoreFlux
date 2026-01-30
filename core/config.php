<?php
/**
 * CoreFlux Platform Configuration
 * Central configuration for the platform core
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'grcudkpvcd');
define('DB_USER', 'grcudkpvcd');
define('DB_PASS', '7DgX7F4RPz');

// SMTP Configuration
define('SMTP_HOST', 'smtp.mail.yahoo.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'no-reply@corefluxapp.com');
define('SMTP_PASS', 'rpevtweukxlgnkll');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM_EMAIL', 'no-reply@corefluxapp.com');
define('SMTP_FROM_NAME', 'CoreFlux Notifications');

// Application Settings
define('APP_NAME', 'CoreFlux');
define('APP_VERSION', '1.0.0');
define('APP_URL', 'https://www.corefluxapp.com');

// Session Settings
define('SESSION_LIFETIME', 3600); // 1 hour

// Feature Flags
define('USE_DATABASE', true); // Database authentication enabled
