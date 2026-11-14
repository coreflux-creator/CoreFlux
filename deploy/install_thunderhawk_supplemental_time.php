<?php
/** Bound, owner-preserving installation or explicit recovery of the isolated pilot only. */
declare(strict_types=1);
const TP_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TP_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TP_FEATURE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
const TP_HOST='phpstack-1516771-6707602.cloudwaysapps.com';
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==TP_ROOT||posix_geteuid()!==1003
    ||realpath(__DIR__)!==__DIR__||!str_starts_with(__DIR__,TP_PRIVATE.'/supplemental-time-')
    ||!in_array($argv[1]??'', ['inspect','install','recover'],true)
    ||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??'')||!preg_match('/^[a-f0-9]{64}$/D',$argv[3]??''))exit(2);

function tpRead(string $path): string {
    clearstatcache(true,$path);$s=@lstat($path);
    if(!$s||is_link($path)||realpath($path)!==$path||!is_file($path)||$s['nlink']!==1
        ||$s['uid']!==1003||($s['mode']&0777)!==0600)throw new RuntimeException('Private material differs');
    return (string)file_get_contents($path);
}
function tpPrivate(string $path,string $body,bool $replace=false): void {
    if(realpath(dirname($path))!==dirname($path)||is_link($path)||(!$replace&&file_exists($path)))throw new RuntimeException('Private target already exists or is redirected');
    $temporary=dirname($path).'/.write-'.bin2hex(random_bytes(12));$old=umask(0077);
    try{$stream=fopen($temporary,'x+b');}finally{umask($old);}
    try{if(!$stream||fwrite($stream,$body)!==strlen($body)||!fflush($stream)||!fsync($stream))throw new RuntimeException('Private write failed');}
    finally{if(is_resource($stream))fclose($stream);}
    if(!rename($temporary,$path))throw new RuntimeException('Private receipt replacement failed');
}
function tpPath(string $relative): string {
    if(!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$relative)||str_contains($relative,'..'))throw new RuntimeException('Invalid application path');
    $path=TP_ROOT.'/'.$relative;
    if(is_link($path)||realpath(dirname($path))!==dirname($path))throw new RuntimeException('Redirected application path');
    if(file_exists($path)){
        $s=stat($path);if(!is_file($path)||$s['nlink']!==1||$s['uid']!==1003||$s['gid']!==33||($s['mode']&0777)!==0644)throw new RuntimeException('Application ownership differs: '.$relative);
    }
    return $path;
}
function tpWrite(string $relative,string $body,?string $expected): void {
    $path=tpPath($relative);
    if((is_file($path)?hash_file('sha256',$path):null)!==$expected)throw new RuntimeException('Application preimage changed: '.$relative);
    $temporary=dirname($path).'/.time-payroll-'.bin2hex(random_bytes(12));$old=umask(0077);
    try{$f=fopen($temporary,'x+b');}finally{umask($old);}
    try{if(!$f||fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))throw new RuntimeException('Application write failed');}
    finally{if(is_resource($f))fclose($f);}
    if(!chgrp($temporary,33)||!chmod($temporary,0644))throw new RuntimeException('Application metadata preservation failed');
    if((is_file($path)?hash_file('sha256',$path):null)!==$expected||!rename($temporary,$path))throw new RuntimeException('Concurrent application replacement');
    if(hash_file('sha256',tpPath($relative))!==hash('sha256',$body))throw new RuntimeException('Application readback differs');
}
function tpHttp(string $path,string $method='GET',?string $token=null,?array $payload=null): array {
    $curl=curl_init('https://'.TP_HOST.'/'.$path);$headers=['Cache-Control: no-cache'];
    if($token!==null)$headers[]='X-Coreflux-Maintenance: '.$token;
    if($payload!==null)$headers[]='Content-Type: application/json';
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
    if($payload!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,json_encode($payload,JSON_THROW_ON_ERROR));
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_errno($curl);curl_close($curl);
    if($body===false||$error!==0)throw new RuntimeException('Pilot HTTP transport unavailable');
    return ['status'=>$status,'body'=>$body];
}
function tpChannel(array $state,array $request): array {
    $deadline=microtime(true)+15;
    do{
        $r=tpHttp($state['channel']['path'],'POST',$state['channel_token'],$request);
        $value=json_decode($r['body'],true);
        $observation=['http_status'=>$r['status'],'response'=>$value,'body_sha256'=>hash('sha256',$r['body'])];
        tpPrivate(__DIR__.'/last-channel-observation.json',json_encode($observation,JSON_THROW_ON_ERROR),file_exists(__DIR__.'/last-channel-observation.json'));
        if($r['status']!==200||!is_array($value)||($value['operation']??null)!==$state['operation']
            ||!($value['pool']['pool_matches']??false))throw new RuntimeException('Pilot request pool is not verifiably identified');
        $pid=$value['self_pid']??null;
        if(!is_int($pid)||$pid<1)throw new RuntimeException('Native web process identity unavailable');
        $status=@file_get_contents('/proc/'.$pid.'/status');$cmd=@file_get_contents('/proc/'.$pid.'/cmdline');
        if(!is_string($status)||!preg_match('/^Uid:\s+1009\s+1009\s+1009\s+1009\s*$/m',$status)
            ||!is_string($cmd)||!str_starts_with($cmd,'php-fpm: pool muzqvdvqbx-82'))throw new RuntimeException('Native web process owner or pool differs');
        if($value['pool']['request_pool_quiet']??false)return $value;
        // Re-observe a known pool while its current requests finish. Never replay a cache action.
        if($request!==['action'=>'status']||microtime(true)>=$deadline)break;
        usleep(200000);
    }while(true);
    throw new RuntimeException('Pilot request pool did not drain within the bounded observation');
}
function tpCache(array $state,array $files,string $phase): array {
    $plan=['version'=>1,'operation'=>$state['operation'],'candidate_commit'=>$state['candidate'],
        'root'=>TP_ROOT,'held_web_sha256'=>$state['channel']['held_web_sha256'],'phase'=>$phase,'files'=>$files];
    $bytes=json_encode($plan,JSON_THROW_ON_ERROR);$path=__DIR__.'/cache-plan.json';
    tpPrivate($path,$bytes,file_exists($path));
    return tpChannel($state,['action'=>'invalidate','plan_sha256'=>hash('sha256',$bytes)]);
}
function tpFinishProbe(array $state): void {
    $path=tpPath($state['channel']['path']);
    if(!is_file($path)||hash_file('sha256',$path)!==$state['channel_file_sha256']||!unlink($path))throw new RuntimeException('Diagnostic cleanup requires review');
}
function tpProcessIdentity(): array {
    $stat=file_get_contents('/proc/'.getmypid().'/stat');
    $fields=preg_split('/\s+/',trim(substr($stat,strrpos($stat,') ')+2)));
    if(!ctype_digit($fields[19]??''))throw new RuntimeException('Native process identity unavailable');
    return ['pid'=>getmypid(),'pid_start_ticks'=>$fields[19]];
}
$packageBytes=tpRead(__DIR__.'/quanta-supplement-application-package.json');
$toolsBytes=tpRead(__DIR__.'/trusted-tools.json');
if(hash('sha256',$packageBytes)!==$argv[2]||hash('sha256',$toolsBytes)!==$argv[3])exit(2);
$package=json_decode($packageBytes,true,512,JSON_THROW_ON_ERROR);$tools=json_decode($toolsBytes,true,512,JSON_THROW_ON_ERROR);
foreach($tools['files']as$file=>$hash)if(hash('sha256',tpRead(__DIR__.'/'.$file))!==$hash)throw new RuntimeException('Installation tool changed');
if(($package['root']??null)!==TP_ROOT||($package['application_source_commit']??null)!=='329d9f8a97e0f28657a0ba27d1c29844b99a35fa'
    ||count($package['files']??[])!==23||count($package['dependencies']??[])!==448)exit(2);
$lock=fopen(TP_PRIVATE.'/contact-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
require_once TP_ROOT.'/core/db.php';require_once TP_ROOT.'/core/integrations/identity.php';require_once TP_ROOT.'/core/migrate.php';
require_once __DIR__.'/full_core_maintenance_host.php';require_once __DIR__.'/full_core_maintenance_channel.php';
require_once __DIR__.'/thunderhawk_pilot_channel.php';
require_once __DIR__.'/thunderhawk_supplemental_time_schema.php';require_once __DIR__.'/thunderhawk_pilot_backup.php';
// The application bootstrap uses global variables, including $host for PDO.
// Load operation inputs afterward and keep their names separate from that scope.
$tpHostPreparation=json_decode(tpRead(__DIR__.'/host-preparation.json'),true,512,JSON_THROW_ON_ERROR);
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$maintenance=new FullCoreMaintenanceHost(TP_ROOT,TP_PRIVATE);
$plan=thunderhawkSupplementPlan(__DIR__.'/candidate');
$stateFile=__DIR__.'/installation-state.json';$locked=false;$state=null;$journal=null;
$save=static function()use(&$state,$stateFile):void{tpPrivate($stateFile,json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),file_exists($stateFile));};
$record=static function(array $event)use(&$journal):void{
    $bytes=json_encode(['at'=>gmdate('c')]+$event,JSON_THROW_ON_ERROR)."\n";
    if(!$journal||fwrite($journal,$bytes)!==strlen($bytes)||!fflush($journal)||!fsync($journal))throw new RuntimeException('Durable installation journal unavailable');
};
try{
    if(hash_file('sha256',TP_FEATURE)!==$package['feature_sha256'])throw new RuntimeException('Private pilot configuration changed');
    foreach($package['dependencies']as$f=>$h)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(tpPath($f))))!==$h)throw new RuntimeException('Installed dependency changed: '.$f);
    if($argv[1]==='recover'){
        $state=json_decode(tpRead($stateFile),true,512,JSON_THROW_ON_ERROR);
        if(($state['package_sha256']??null)!==$argv[2]||in_array($state['phase']??'',['completed','recovered','aborted_before_schema'],true))throw new RuntimeException('Recovery identity or phase differs');
        $state=array_replace($state,tpProcessIdentity());$state['interrupted_phase']=$state['phase'];$state['phase']='recovering';$save();
        $maintenance->assertHeld($state['operation']);tpChannel($state,['action'=>'status']);
        if(!isset($state['baseline']))throw new RuntimeException('Inspect pre-backup maintenance recovery separately');
        thunderhawkSupplementLock($pdo);$locked=true;
        thunderhawkSupplementPreserved($pdo,$state['baseline'],$plan,true);
        foreach($package['files']as$f=>$entry){$p=tpPath($f);$hash=is_file($p)?hash_file('sha256',$p):null;if(!in_array($hash,[$entry['before_sha256'],$entry['sha256']],true))throw new RuntimeException('Concurrent file change prevents recovery');}
        $state['phase']='recovering';$save();
        foreach($package['files']as$f=>$entry){
            $p=tpPath($f);$hash=is_file($p)?hash_file('sha256',$p):null;
            if($hash===$entry['before_sha256'])continue;
            if($entry['before_sha256']===null){if(!unlink($p))throw new RuntimeException('Cannot remove owned new file');}
            else{$body=tpRead(__DIR__.'/backup/files/'.$f);if(hash('sha256',$body)!==$entry['before_sha256'])throw new RuntimeException('File backup changed');tpWrite($f,$body,$hash);}
        }
        thunderhawkSupplementRecover($pdo,$state['baseline'],$plan);
        $cache=[];foreach($package['files']as$f=>$e)if(str_ends_with($f,'.php')&&$e['before_sha256']!==null)$cache[]=['path'=>$f,'sha256'=>$e['before_sha256']];
        $state['recovery_cache']=tpCache($state,$cache,'recovery');
        thunderhawkTimePayrollUnlock($pdo);$locked=false;
        $maintenance->restore($state['operation']);tpFinishProbe($state);
        $state['phase']='recovered';$state['recovered_at']=gmdate('c');$save();
        echo json_encode(['recovered'=>true,'operation'=>$state['operation'],'original_values_restored'=>true,'production_changed'=>false])."\n";exit;
    }
    if(file_exists($stateFile))throw new RuntimeException('Installation already has a receipt; inspect it rather than replaying');
    foreach($package['files']as$f=>$e){
        $p=tpPath($f);if((is_file($p)?hash_file('sha256',$p):null)!==$e['before_sha256'])throw new RuntimeException('Application preimage differs: '.$f);
        $body=base64_decode($e['body'],true);if($body===false||hash('sha256',$body)!==$e['sha256'])throw new RuntimeException('Invalid package body');
        if(str_ends_with($f,'.php'))token_get_all($body,TOKEN_PARSE);
    }
    if($pdo->query('SELECT * FROM _migrations ORDER BY filename')->fetchAll(PDO::FETCH_ASSOC)!==$package['migration_ledger'])throw new RuntimeException('Migration ledger changed');
    thunderhawkSupplementBaseline($pdo,$plan);
    $web=$maintenance->webSnapshot();$expected=$tpHostPreparation['web_preimages']['.htaccess'];$expected['mode']=octdec($expected['mode']);
    if($web!==$expected||hash('sha256',$maintenance->crontab())!==$tpHostPreparation['scheduler_sha256'])throw new RuntimeException('Hosting state changed');
    $review=[];foreach($tpHostPreparation['scheduler_jobs']as$j){if($j['pilot_reference']||!$j['production_reference'])throw new RuntimeException('Scheduler needs explicit disposition');$review[$j['line']]=['sha256'=>$j['sha256'],'action'=>'keep'];}
    if((int)$pdo->query("SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()")->fetchColumn()!==0)throw new RuntimeException('Database events require separate hold');
    if($argv[1]==='inspect'){echo json_encode(['ready'=>true,'files'=>23,'dependencies'=>448,'migrations'=>1,'application_changed'=>false,'private_tools_staged'=>true])."\n";exit;}
    $operation=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$path='core-maintenance-'.$operation.'-'.bin2hex(random_bytes(16)).'.php';
    $spec=['operation'=>$operation,'candidate_commit'=>$package['application_source_commit'],'token_sha256'=>hash('sha256',$token),
        'held_web_sha256'=>hash('sha256',fullCoreMaintenanceWebRule($operation,$path)),
        'library_sha256'=>hash_file('sha256',__DIR__.'/full_core_maintenance_channel.php'),'path'=>$path,
        'root'=>TP_ROOT,'private_directory'=>__DIR__,'host'=>TP_HOST,'pool'=>'muzqvdvqbx-82','installer_uid'=>1003,'issued_at'=>time(),'expires'=>time()+14400];
    $candidateCache=[];$recoveryCache=[];
    foreach($package['files']as$f=>$e)if(str_ends_with($f,'.php')){
        $candidateCache[]=['path'=>$f,'sha256'=>$e['sha256']];
        if($e['before_sha256']!==null)$recoveryCache[]=['path'=>$f,'sha256'=>$e['before_sha256']];
    }
    $probe=thunderhawkPilotChannelRender($spec,$candidateCache,$recoveryCache);
    $state=['operation'=>$operation,'candidate'=>$package['application_source_commit'],'package_sha256'=>$argv[2],
        'tools_sha256'=>$argv[3],'phase'=>'prepared','started_at'=>gmdate('c'),
        'channel'=>$spec,'channel_token'=>$token,'channel_file_sha256'=>hash('sha256',$probe),'production_changed'=>false]+tpProcessIdentity();$save();
    $journal=thunderhawkPilotPrivateStream(__DIR__.'/installation-journal.jsonl');
    tpWrite($path,$probe,null);
    $state['phase']='holding';$save();
    $maintenance->pause($operation,$web,$tpHostPreparation['scheduler_sha256'],$review,$path);
    foreach(['api/workflow.php','modules/time/api/entries.php']as$url)if(tpHttp($url,'POST')['status']!==503)throw new RuntimeException('Pilot ingress is not held');
    $state['drain']=tpChannel($state,['action'=>'status']);$state['phase']='backing_up';$save();
    if(!mkdir(__DIR__.'/backup',0700)||!mkdir(__DIR__.'/backup/files',0700))throw new RuntimeException('Private backup directory unavailable');
    foreach($package['files']as$f=>$e)if($e['before_sha256']!==null){
        $dest=__DIR__.'/backup/files/'.$f;if(!is_dir(dirname($dest))&&!mkdir(dirname($dest),0700,true))throw new RuntimeException('File backup directory unavailable');
        $body=file_get_contents(tpPath($f));if(hash('sha256',$body)!==$e['before_sha256'])throw new RuntimeException('File changed before backup');tpPrivate($dest,$body);
    }
    $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as$t)if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))throw new RuntimeException('Unexpected table identifier');
    $pdo->exec('SET SESSION lock_wait_timeout=10');$pdo->exec('SET autocommit=0');
    $pdo->exec('LOCK TABLES '.implode(',',array_map(static fn($t)=>'`'.$t.'` READ',$tables)));$locked=true;
    $state['baseline']=thunderhawkSupplementBaseline($pdo,$plan);$save();
    $state['database_backup']=thunderhawkPilotDatabaseCapture($pdo,__DIR__.'/backup/database.sql');
    if(thunderhawkTimePayrollTableState($pdo)!==$state['baseline']['tables'])throw new RuntimeException('Backup changed database values');$save();
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $maintenance->assertHeld($operation);tpChannel($state,['action'=>'status']);
    thunderhawkSupplementLock($pdo);$locked=true;
    $state['phase']='migrating';$save();
    $state['schema']=thunderhawkSupplementApply($pdo,$plan,$state['baseline'],$record);
    $state['phase']='installing_files';$save();
    foreach($package['files']as$f=>$e){$record(['phase'=>'file_start','path'=>$f,'sha256'=>$e['sha256']]);tpWrite($f,base64_decode($e['body'],true),$e['before_sha256']);$record(['phase'=>'file_complete','path'=>$f]);}
    $maintenance->assertHeld($operation);$cache=[];
    foreach($package['files']as$f=>$e){if(hash_file('sha256',tpPath($f))!==$e['sha256'])throw new RuntimeException('Installed file differs');if(str_ends_with($f,'.php'))$cache[]=['path'=>$f,'sha256'=>$e['sha256']];}
    $state['cache']=tpCache($state,$cache,'candidate');
    $state['schema']=thunderhawkSupplementPreserved($pdo,$state['baseline'],$plan);
    if(hash_file('sha256',TP_FEATURE)!==$package['feature_sha256'])throw new RuntimeException('Private configuration changed');
    thunderhawkTimePayrollUnlock($pdo);$locked=false;
    $original=tpRead(TP_PRIVATE.'/maintenance-'.$operation.'/original.htaccess');
    $maintenance->prepareActivation($operation,$original,$web['sha256'],$state['candidate'],$argv[2]);
    $state['phase']='activating';$save();$maintenance->activate($operation,$state['candidate'],$argv[2]);
    foreach($package['files']as$f=>$e)if($f==='index.html'||preg_match('~^spa-assets/index-[A-Za-z0-9_-]+\.(js|css)$~D',$f)){
        $r=tpHttp($f.'?release='.substr($argv[2],0,16));if($r['status']!==200||hash('sha256',$r['body'])!==$e['sha256'])throw new RuntimeException('Served application asset differs');
    }
    tpFinishProbe($state);$state['phase']='completed';$state['completed_at']=gmdate('c');$save();
    echo json_encode(['passed'=>true,'operation'=>$operation,'source_commit'=>$state['candidate'],'schema'=>$state['schema'],
        'installed_files'=>23,'retained_dependency_pins'=>448,'configuration_preserved'=>true,'diagnostic_removed'=>true,
        'maintenance_restored'=>true,'production_changed'=>false,'provider_requests'=>0])."\n";
}catch(Throwable $error){
    // Before schema work, restore ingress only after proving every application preimage.
    // Later failures retain the hold and require the explicit paired recovery action.
    if($state!==null&&in_array($state['phase'],['prepared','holding','backing_up'],true)){
        try{
            if($locked){thunderhawkTimePayrollUnlock($pdo);$locked=false;}
            foreach($package['files']as$f=>$e){$p=tpPath($f);if((is_file($p)?hash_file('sha256',$p):null)!==$e['before_sha256'])throw new RuntimeException('Pre-schema application changed');}
            if(hash_file('sha256',TP_FEATURE)!==$package['feature_sha256'])throw new RuntimeException('Pre-schema configuration changed');
            $receipt=TP_PRIVATE.'/maintenance-'.$state['operation'].'/receipt.json';
            if(is_file($receipt))$maintenance->restore($state['operation']);
            elseif($maintenance->webSnapshot()!==$web||hash('sha256',$maintenance->crontab())!==$tpHostPreparation['scheduler_sha256'])throw new RuntimeException('Unrecorded maintenance state requires review');
            if(is_file(tpPath($state['channel']['path'])))tpFinishProbe($state);
            $state['phase']='aborted_before_schema';$state['maintenance_restored']=true;
        }catch(Throwable $recovery){$state['pre_schema_recovery_failure']=$recovery->getMessage();}
    }
    if($state!==null){$state['failure']=$error->getMessage();$state['failed_at']=gmdate('c');$save();}
    fwrite(STDERR,json_encode(['passed'=>false,'phase'=>$state['phase']??'preflight','failure'=>$error->getMessage(),
        'operation'=>$state['operation']??null,'failure_file'=>basename($error->getFile()),'failure_line'=>$error->getLine(),
        'retry_automatically'=>false])."\n");$exitCode=1;
}finally{
    if($locked)thunderhawkTimePayrollUnlock($pdo);
    if(is_resource($journal))fclose($journal);
}
exit($exitCode??0);
