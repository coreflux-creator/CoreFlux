<?php
// Logs errors to storage/logs/app_debug.log when APP_DEBUG=true
if (getenv('APP_DEBUG') === 'true') {
    @mkdir(__DIR__.'/storage/logs', 0777, true);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', __DIR__.'/storage/logs/app_debug.log');
    error_reporting(E_ALL);
}
