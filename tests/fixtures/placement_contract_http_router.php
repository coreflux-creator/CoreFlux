<?php
/** Loopback-only dispatcher; authentication, tenant scope, RBAC and writers are native. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
    || getenv('COREFLUX_ENV') !== 'staging' || getenv('DB_NAME') !== 'coreaccounting_ci'
    || getenv('COREFLUX_CONTRACT_HTTP_FIXTURE') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . getenv('COREFLUX_CONTRACT_HTTP_PORT')) {
    http_response_code(404); exit;
}
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path === '/fixture-ready') { header('Content-Type: application/json'); echo '{"ready":true}'; exit; }
if (!in_array($path, ['/login.php', '/logout.php', '/modules/placements/api/economics.php', '/modules/placements/api/corp.php',
    '/modules/placements/api/jobdiva_reconciliation.php'], true)) { http_response_code(404); exit; }
$script = dirname(__DIR__, 2) . $path;
$_SERVER['SCRIPT_FILENAME'] = $script;
require $script;
