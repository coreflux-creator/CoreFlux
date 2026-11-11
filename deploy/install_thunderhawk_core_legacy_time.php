<?php
/** Exact file-only historical-time update with all existing records preserved for the existing isolated Core pilot. */
declare(strict_types=1);
const TIME_PILOT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TIME_PILOT_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TIME_PILOT_FEATURE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
const TIME_PILOT_HOST='phpstack-1516771-6707602.cloudwaysapps.com';
if(PHP_SAPI!=='cli'||!in_array(($argv[1]??''),['--inspect-isolated-core-legacy-time','--install-isolated-core-legacy-time'],true)||getenv('COREFLUX_ENV')!=='staging'
 ||realpath(getcwd())!==TIME_PILOT_ROOT||realpath(TIME_PILOT_PRIVATE)!==TIME_PILOT_PRIVATE||posix_geteuid()!==1003
 ||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??''))exit(2);
$packagePath=__DIR__.'/core-legacy-time-package.json';
if(is_link($packagePath)||!hash_equals($argv[2],hash_file('sha256',$packagePath)))exit(2);
$package=json_decode(file_get_contents($packagePath),true,64,JSON_THROW_ON_ERROR);
if(($package['root']??null)!==TIME_PILOT_ROOT||($package['source_commit']??null)!=='6c83324bea0d7c4dcf8733316759a9ef0c784b1e'
 ||count($package['files']??[])!==13)exit(2);
$lock=fopen(TIME_PILOT_PRIVATE.'/contact-install.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
require_once TIME_PILOT_ROOT.'/core/db.php';
require_once TIME_PILOT_ROOT.'/core/integrations/identity.php';
require_once TIME_PILOT_ROOT.'/core/migrate.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
 ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
 ||!defined('COREFLUX_INTEGRATION_DELIVERY_TENANTS')||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
 ||!defined('COREFLUX_QUANTA_PROFILE_RETURN')||!COREFLUX_QUANTA_PROFILE_RETURN
 ||(defined('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY')&&COREFLUX_QUANTA_TIME_ACCESS_DELIVERY))exit(2);
$binding=$pdo->query('SELECT core_instance_id,remote_workspace_id,state FROM quanta_workspace_bindings WHERE tenant_id=2002')->fetch(PDO::FETCH_ASSOC);
if($binding!==['core_instance_id'=>'9f4b6dfb-e52e-464c-9a9d-af7766928ddf',
 'remote_workspace_id'=>'4f93d4e7-af74-4765-b812-3464bb6d974b','state'=>'verified'])exit(2);
$schemaHelper=__DIR__.'/quanta_assignment_route_upgrade.php';
if(is_link($schemaHelper)||!hash_equals($package['schema_helper_sha256']??'',hash_file('sha256',$schemaHelper)))exit(2);
require_once $schemaHelper;
$sqlFiles=[];
$locksHeld=false;$routeBefore=null;
$report=['checked_at'=>gmdate('c'),'source_commit'=>$package['source_commit'],'production_changed'=>false,
 'payroll_payments_or_financial_postings'=>false,'passed'=>false];
$backup=TIME_PILOT_PRIVATE.'/legacy-time-before-'.bin2hex(random_bytes(12));
$previous=[];$changed=[];$featureChanged=false;$phase='preflight';$probe=null;

function timePilotSnapshot(PDO $pdo):array {
 $out=[];
 foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
  if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected table name');
  if($table==='_migrations')continue;
  $rows=[];$s=$pdo->query('SELECT * FROM `'.$table.'`');
  while($row=$s->fetch(PDO::FETCH_ASSOC)){$rows[]=hash('sha256',serialize($row));}
  sort($rows,SORT_STRING);$out[$table]=['count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
 }
 ksort($out);return $out;
}
function timePilotWrite(string $path,string $bytes,int $mode):void {
 $temporary=dirname($path).'/.contact-next-'.bin2hex(random_bytes(10));
 $f=fopen($temporary,'x');
 if(!$f)throw new RuntimeException('Exclusive file creation failed');
 try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Incomplete file write');}
 finally{fclose($f);}
 if(!chgrp($temporary,33)||!chmod($temporary,$mode)||!rename($temporary,$path))throw new RuntimeException('Atomic install failed');
 clearstatcache(true,$path);
 if(fileowner($path)!==1003||filegroup($path)!==33||(fileperms($path)&0777)!==$mode)throw new RuntimeException('Installed file ownership differs');
}
function timePilotWeb(array $paths,bool $verify):array {
 $token=bin2hex(random_bytes(32));$name='contact_release_'.bin2hex(random_bytes(12)).'.php';
 $path=TIME_PILOT_ROOT.'/api/admin/'.$name;
 $template= <<<'WEB'
<?php
declare(strict_types=1);
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!==__HOST__
 ||time()>__EXPIRES__||!hash_equals(__TOKEN__,(string)($_SERVER['HTTP_X_COREFLUX_PILOT_PROBE']??''))){http_response_code(404);exit;}
$root=dirname(__DIR__,2);
if(realpath($root)!==__ROOT__){http_response_code(404);exit;}
try{
 foreach(__PATHS__ as $path)if(function_exists('opcache_invalidate'))opcache_invalidate($path,true);
 $states=[];
 if(__VERIFY__){
  require_once $root.'/core/quanta/profile_return_delivery.php';
  require_once $root.'/core/quanta/time_control_delivery.php';
  require_once $root.'/core/quanta/legacy_time_delivery.php';
  if(DB_NAME!=='muzqvdvqbx'||COREFLUX_STAGING!==true||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')throw new RuntimeException();
  foreach([2002,2,999,2003] as $tenant){
   $states[$tenant]=quantaProfileReturnEnabled($tenant);
   if($states[$tenant]!==($tenant===2002)||quantaTimeControlEnabled($tenant))throw new RuntimeException();
  }
 }
 header('Content-Type: application/json');echo json_encode(['passed'=>true,'sapi'=>PHP_SAPI,'gates'=>$states]);
}catch(Throwable $e){http_response_code(500);echo json_encode(['passed'=>false,'failure_class'=>get_class($e),'line'=>$e->getLine()]);}
WEB;
 $body=strtr($template,['__HOST__'=>var_export(TIME_PILOT_HOST,true),'__EXPIRES__'=>(string)(time()+180),
  '__TOKEN__'=>var_export($token,true),'__ROOT__'=>var_export(TIME_PILOT_ROOT,true),'__PATHS__'=>var_export($paths,true),
  '__VERIFY__'=>$verify?'true':'false']);
 timePilotWrite($path,$body,0644);
 $request=static function(?string $key)use($name):array{
  $c=curl_init('https://'.TIME_PILOT_HOST.'/api/admin/'.$name);
  curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
   CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
   CURLOPT_HTTPHEADER=>$key===null?[]:['X-CoreFlux-Pilot-Probe: '.$key]]);
  $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);return [$status,$raw];
 };
 try{
  [$denied,$body]=$request(null);if($denied!==404||$body!=='')throw new RuntimeException('Private probe accepted anonymous access');
  [$status,$body]=$request($token);$result=is_string($body)?json_decode($body,true):null;
  if($status!==200||($result['passed']??false)!==true||($result['sapi']??null)!=='fpm-fcgi')throw new RuntimeException('FPM verification failed');
  return $result;
 }finally{if(!unlink($path)||file_exists($path))throw new RuntimeException('Temporary web probe was not removed');}
}
try{
 if(hash_file('sha256',TIME_PILOT_FEATURE)!==$package['feature_sha256']||fileowner(TIME_PILOT_FEATURE)!==1003
  ||filegroup(TIME_PILOT_FEATURE)!==33||(fileperms(TIME_PILOT_FEATURE)&0777)!==0640)throw new RuntimeException('Private feature configuration changed');
 $featureBefore=file_get_contents(TIME_PILOT_FEATURE);
 foreach(token_get_all($featureBefore) as $token)if(is_array($token)&&$token[0]===T_CLOSE_TAG)throw new RuntimeException('Private configuration must remain in PHP');
 foreach($package['dependencies'] as $relative=>$expected){
  $path=TIME_PILOT_ROOT.'/'.$relative;
  if(is_link($path)||realpath($path)!==$path||hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))!==$expected)
   throw new RuntimeException('A reviewed dependency changed');
 }
 $bodyMap=[];
 foreach($package['files'] as $relative=>$file){
  if(!preg_match('~^(?:api/quanta\.php|core/(?:quanta|integrations|migrations)/[A-Za-z0-9_.-]+|(?:dashboard/dist/)?index\.html|spa-assets/index-[A-Za-z0-9_-]+\.(?:js|css))$~D',$relative))throw new RuntimeException('Unexpected package path');
  $path=TIME_PILOT_ROOT.'/'.$relative;$parent=dirname($path);
  if(realpath($parent)!==$parent||!is_writable($parent)||is_link($path))throw new RuntimeException('Unwritable or redirected destination');
  $bytes=base64_decode($file['body'],true);
  if($bytes===false||hash('sha256',$bytes)!==$file['sha256'])throw new RuntimeException('Package body differs');
  $prior=is_file($path)?file_get_contents($path):null;$stat=$prior===null?null:stat($path);
  if(($prior===null?null:hash('sha256',str_replace("\r\n","\n",$prior)))!==$file['before_sha256'])throw new RuntimeException('Reviewed file preimage changed');
  if($stat!==null&&($stat['uid']!==1003||$stat['gid']!==33))throw new RuntimeException('Existing file owner differs');
  if(str_ends_with($relative,'.php'))token_get_all($bytes,TOKEN_PARSE);
  $previous[$relative]=['body'=>$prior,'stat'=>$stat];$bodyMap[$relative]=$bytes;
 }
 if(($argv[1]??'')==='--inspect-isolated-core-legacy-time'){
  $inspectedLedger=$pdo->query('SELECT filename,sha256,last_error FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
  if($inspectedLedger!==$package['migration_ledger'])throw new RuntimeException('Migration ledger changed after inspection');
  $inspection=quantaPilotRouteState($pdo);if($inspection!==$package['route_state'])throw new RuntimeException('Route state changed since inspection');
  echo json_encode(['ready'=>true,'source_commit'=>$package['source_commit'],'files'=>count($bodyMap),'legacy_routes'=>count($inspection['rows']),
   'schema_helper_verified'=>true,'file_preimages_verified'=>true,'configuration_unchanged'=>true,'hosted_changes'=>false],JSON_THROW_ON_ERROR)."\n";exit(0);
 }
 $lockedTables=quantaPilotRouteLock($pdo);$locksHeld=true;
 $ledgerChecked=$pdo->query('SELECT filename,sha256,last_error FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
 if($ledgerChecked!==$package['migration_ledger'])throw new RuntimeException('Migration ledger changed after inspection');
 $routeBefore=quantaPilotRouteState($pdo);if($routeBefore!==$package['route_state'])throw new RuntimeException('Route state changed since inspection');
 $ledgerBefore=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
 $known=array_column($ledgerBefore,null,'filename');
 foreach(array_merge(glob(TIME_PILOT_ROOT.'/core/migrations/*.sql'),glob(TIME_PILOT_ROOT.'/modules/*/migrations/*.sql')) as $path){
  if(str_contains($path,'/modules/_'))continue;
  $name=str_contains($path,'/modules/')?substr($path,strlen(TIME_PILOT_ROOT)+1):basename($path);
  if(!corefluxMigrationHashMatches($known[$name]['sha256']??null,file_get_contents($path))||!empty($known[$name]['last_error']))throw new RuntimeException('An unrelated migration is pending');
 }
 $before=timePilotSnapshot($pdo);
 if(!mkdir($backup,0700))throw new RuntimeException('Private backup directory unavailable');
 file_put_contents($backup.'/before.json',json_encode(['tables'=>$before,'ledger'=>$ledgerBefore,'routes'=>$routeBefore,'files'=>$previous],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE));
 file_put_contents($backup.'/feature.private.php',$featureBefore);chmod($backup.'/feature.private.php',0600);
 // Exact preimage bytes are kept separately; the summary never substitutes for a backup.
 foreach($previous as $relative=>$entry)if($entry['body']!==null){
  $saved=$backup.'/files/'.$relative;if(!is_dir(dirname($saved)))mkdir(dirname($saved),0700,true);
  file_put_contents($saved,$entry['body']);
 }
 $report['private_backup']=$backup;
 $phase='source_install';
 // Supporting code/assets first, public API and HTML entry points last.
 $order=array_keys($bodyMap);usort($order,static function($a,$b)use($previous){
  $last=['api/quanta.php'=>1,'dashboard/dist/index.html'=>2,'index.html'=>3];
  return ($last[$a]??($previous[$a]['body']===null?-1:0))<=>($last[$b]??($previous[$b]['body']===null?-1:0));
 });
 foreach($order as $relative){
  $changed[]=$relative;$mode=$previous[$relative]['stat']===null?0644:($previous[$relative]['stat']['mode']&0777);
  timePilotWrite(TIME_PILOT_ROOT.'/'.$relative,$bodyMap[$relative],$mode);
 }
 $after=timePilotSnapshot($pdo);
 foreach($before as $table=>$value)if(($after[$table]??null)!==$value)throw new RuntimeException('Existing business or integration records changed');
 if(quantaPilotRouteState($pdo)!==$routeBefore)throw new RuntimeException('Route schema or records changed');
 $ledgerAfter=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC);
 $existingAfter=array_values(array_filter($ledgerAfter,static fn($row)=>!isset($sqlFiles[$row['filename']])));
 if($existingAfter!==$ledgerBefore)throw new RuntimeException('An existing migration record changed');
 $phase='verify_held_runtime';
 if(hash_file('sha256',TIME_PILOT_FEATURE)!==$package['feature_sha256'])throw new RuntimeException('Operational configuration changed');
 $cachePaths=array_map(static fn($r)=>TIME_PILOT_ROOT.'/'.$r,array_filter(array_keys($bodyMap),static fn($r)=>str_ends_with($r,'.php')));
 $cachePaths[]=TIME_PILOT_FEATURE;
 $report['held_cache_refresh']=timePilotWeb($cachePaths,false);
 quantaPilotRouteUnlock($pdo);$locksHeld=false;
 $phase='native_web_verification';$report['web']=timePilotWeb($cachePaths,true);
 $curl=curl_init('https://'.TIME_PILOT_HOST.'/index.html?contact-release='.bin2hex(random_bytes(8)));
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
 $html=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if($http!==200||$html!==$bodyMap['index.html'])throw new RuntimeException('Served dashboard differs');
 foreach($bodyMap as $relative=>$bytes)if(hash_file('sha256',TIME_PILOT_ROOT.'/'.$relative)!==hash('sha256',$bytes))throw new RuntimeException('Installed file verification failed');
 if(timePilotSnapshot($pdo)!==$after)throw new RuntimeException('Web verification changed database records');
 $report+=['passed_tables'=>count($before),'existing_records_unchanged'=>true,'migration_tables_added'=>[],'legacy_routes_preserved'=>count($routeBefore['rows']),'operational_policy_enabled'=>false,'native_write_fence_used'=>true,
  'migrations_applied'=>array_keys($sqlFiles),'files_verified'=>count($bodyMap),'existing_contact_enablement_preserved'=>true,
  'temporary_web_probe_removed'=>true,'served_dashboard_verified'=>true,'provider_write_requested'=>false];
 $report['passed']=true;
}catch(Throwable $e){
 $report+=['failed_phase'=>$phase,'failure_class'=>get_class($e),'failure_message'=>$e->getMessage(),'failure_line'=>$e->getLine()];
 try{
  if($featureChanged)timePilotWrite(TIME_PILOT_FEATURE,$featureBefore,0640);
  foreach(array_reverse($changed) as $relative){
   $old=$previous[$relative];$path=TIME_PILOT_ROOT.'/'.$relative;
   if($old['body']===null){if(is_file($path))unlink($path);}
   else{timePilotWrite($path,$old['body'],$old['stat']['mode']&0777);touch($path,$old['stat']['mtime'],$old['stat']['atime']);}
  }
  if($changed||$featureChanged){
   timePilotWeb(array_merge([TIME_PILOT_FEATURE],array_map(static fn($r)=>TIME_PILOT_ROOT.'/'.$r,$changed)),false);
   $report['previous_runtime_restored']=true;
  }
  if($locksHeld){quantaPilotRouteUnlock($pdo);$locksHeld=false;}
  $report['original_migration_ledger_restored']=$pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)===($ledgerBefore??[]);
 }catch(Throwable $recovery){$report['recovery_failed']=true;$report['recovery_failure_class']=get_class($recovery);}
}
if($locksHeld){quantaPilotRouteUnlock($pdo);$locksHeld=false;}
file_put_contents(TIME_PILOT_PRIVATE.'/legacy-time-core-install-result.json',json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
