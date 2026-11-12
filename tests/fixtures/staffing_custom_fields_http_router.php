<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||getenv('GITHUB_ACTIONS')!=='true'||getenv('CI')!=='true'
    ||getenv('COREFLUX_ENV')!=='staging'||getenv('DB_NAME')!=='coreaccounting_ci'
    ||getenv('COREFLUX_CUSTOM_FIELDS_HTTP_FIXTURE')!=='1'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){
    http_response_code(404);exit;
}
if(($_SERVER['HTTP_HOST']??'')!=='127.0.0.1:'.getenv('COREFLUX_CUSTOM_FIELDS_HTTP_PORT')){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
if($path==='/fixture-ready'){header('Content-Type: application/json');echo '{"ready":true}';exit;}
$root=dirname(__DIR__,2);
if(in_array($path,['/login.php','/logout.php','/api/custom_field_definitions.php','/api/custom_field_values.php',
    '/modules/people/api/custom_fields.php','/modules/people/api/custom_field_values.php'],true))$script=$root.$path;
elseif(preg_match('#^/api/v1/(people|placements)/custom-field-(definitions|values)(/\d+)?$#',$path))$script=$root.'/api/index.php';
else{http_response_code(404);exit;}
$_SERVER['SCRIPT_FILENAME']=$script;
require $script;
