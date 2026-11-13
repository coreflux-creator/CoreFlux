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
if (!in_array($path, ['/login.php', '/logout.php', '/api/jobdiva.php', '/api/admin/integrations/reindex_jobdiva_subpayloads.php', '/api/admin/integrations/jobdiva_mapping_alignment.php', '/modules/placements/api/economics.php', '/modules/placements/api/corp.php', '/modules/placements/api/cycles.php', '/modules/payroll/api/cycles.php', '/modules/payroll/api/pay_schedules.php',
    '/modules/placements/api/jobdiva_reconciliation.php', '/modules/placements/api/source_rules.php', '/modules/placements/api/chain.php', '/modules/placements/api/referrals.php', '/modules/placements/api/documents.php', '/modules/people/api/documents.php', '/modules/people/api/merge.php', '/modules/placements/api/merge.php', '/modules/placements/api/rates.php', '/modules/placements/api/placements.php'], true)) { http_response_code(404); exit; }
if (in_array($path,['/modules/placements/api/documents.php','/modules/people/api/documents.php'],true)) {
    require_once dirname(__DIR__,2).'/core/StorageService.php';
    $storageRoot=getenv('COREFLUX_CONTRACT_HTTP_STORAGE');
    if (!is_string($storageRoot) || !str_starts_with($storageRoot,sys_get_temp_dir().'/coreflux-contract-http-')
        || realpath($storageRoot)!==$storageRoot || is_link($storageRoot) || basename($storageRoot)!=='objects') { http_response_code(404); exit; }
    // Actual bytes use LocalDriver. Only the signed-form transport is synthetic; never contact S3 in CI.
    $driver=new class($storageRoot,'synthetic-document-http') extends \Core\Storage\LocalDriver {
        public function driver_name():string{return 's3';}
        public function get_presigned_post(string $key,array $constraints=[]):array {
            $post=parent::get_presigned_post($key);
            $post['fields']['fixture_policy']=json_encode($constraints,JSON_THROW_ON_ERROR);
            return $post;
        }
    };
    \Core\StorageService::reset($driver);
}
if ($path === '/api/jobdiva.php') {
    if (!in_array($_GET['action'] ?? '', ['assignment_contracts_batch','review_assignment_contracts_batch'], true)) { http_response_code(404); exit; }
    require __DIR__.'/live_contract_http_transport.php';
}
$script = dirname(__DIR__, 2) . $path;
$_SERVER['SCRIPT_FILENAME'] = $script;
require $script;
