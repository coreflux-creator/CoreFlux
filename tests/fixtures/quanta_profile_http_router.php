<?php
/** Loopback-only native session fixture. Authentication/RBAC are the actual application code. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli-server' || getenv('GITHUB_ACTIONS') !== 'true' || getenv('CI') !== 'true'
    || getenv('COREFLUX_ENV') !== 'staging' || getenv('DB_NAME') !== 'coreaccounting_ci'
    || getenv('COREFLUX_QUANTA_AUTH_HTTP_FIXTURE') !== '1'
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404); exit;
}
$fixturePath = (string)getenv('COREFLUX_QUANTA_AUTH_HTTP_CONFIG');
$directory = dirname($fixturePath);
if (!str_starts_with($directory, sys_get_temp_dir() . '/coreflux-quanta-http-') || realpath($directory) !== $directory
    || realpath($fixturePath) !== $fixturePath || is_link($fixturePath)) { http_response_code(404); exit; }
$fixture = json_decode((string)file_get_contents($fixturePath), true, 32, JSON_THROW_ON_ERROR);
if (($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:' . $fixture['port']) { http_response_code(404); exit; }
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($path === '/fixture-ready') { header('Content-Type: application/json'); echo '{"ready":true}'; exit; }
if (!in_array($path, ['/login.php','/logout.php','/api/quanta.php','/modules/people/api/people.php','/modules/people/api/companies.php','/modules/people/api/audit_pii.php','/modules/people/api/employment_link.php','/modules/people/api/employment_setup.php','/modules/people/api/employees.php','/modules/ap/api/vendors.php','/api/staffing_reconciliation.php','/api/staffing_financial_evidence.php','/api/purepay_connection.php','/api/admin/integrations/jobdiva_party_review.php','/api/integrations/mappings.php','/modules/staffing/api/jobs.php','/modules/staffing/api/clients.php','/modules/staffing/api/csv_import.php'], true)) { http_response_code(404); exit; }
// Only provider transport is synthetic. Login, PHP session cookies, tenant
// selection, dual RBAC, API validation and database reads are unmodified.
$GLOBALS['__quanta_transport'] = static function ($method, $url) use ($fixture, $directory): array {
    $route = parse_url($url, PHP_URL_PATH);
    file_put_contents($directory . '/provider-read.log', $method . ' ' . $route . "\n", FILE_APPEND | LOCK_EX);
    if ($method !== 'GET') throw new LogicException('Unexpected fixture provider method');
    if (str_ends_with($route, '/profile-change-context')) $body = [
        'workspace_id'=>$fixture['context']['workspace']['id'], 'contract_version'=>'2026-11-10.1',
        'baseline_publication'=>true, 'worker_proposals'=>true, 'decision_delivery'=>true, 'login_identity_changes'=>false];
    elseif (str_ends_with($route, '/context')) $body = $fixture['context'];
    elseif (str_ends_with($route, '/profile-changes')) $body = ['items'=>[], 'has_more'=>false, 'next_cursor'=>null];
    else throw new LogicException('Unexpected fixture provider route');
    return ['status'=>200,'body'=>json_encode($body, JSON_THROW_ON_ERROR)];
};
$GLOBALS['__quanta_core_write_transport'] = static function () use ($directory): never {
    file_put_contents($directory . '/provider-write.log', "UNEXPECTED WRITE\n", FILE_APPEND | LOCK_EX);
    throw new LogicException('This permission fixture cannot write to a provider');
};
$script = dirname(__DIR__, 2) . $path;
$_SERVER['SCRIPT_FILENAME'] = $script;
require $script;
