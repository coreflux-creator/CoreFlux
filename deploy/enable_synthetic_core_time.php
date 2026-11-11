<?php
/** Persist one time-setup flag on isolated Core; verify the actual web runtime. */
declare(strict_types=1);
const CT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const CT_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const CT_FEATURE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/coreflux.thunderhawk-pilot.php';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--enable-synthetic-time-setup'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==CT_ROOT||posix_geteuid()!==1003||realpath(CT_PRIVATE)!==CT_PRIVATE
    ||(fileperms(CT_PRIVATE)&0777)!==0700||time()>=strtotime('2026-11-11T19:25:00Z'))exit(2);
require CT_ROOT.'/core/quanta/time_control_delivery.php';
$pdo=getDB();
if(!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||defined('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY')||getenv('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY')!==false
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$plan=json_decode(file_get_contents(__DIR__.'/synthetic-time-configuration-preflight.json'),true,64,JSON_THROW_ON_ERROR)['core'];
foreach($plan['runtime'] as $file=>$hash)if(hash('sha256',str_replace("\r\n","\n",file_get_contents(CT_ROOT.'/'.$file)))!==$hash)exit(2);
if(is_link(CT_FEATURE)||realpath(CT_FEATURE)!==CT_FEATURE||fileowner(CT_FEATURE)!==1003||filegroup(CT_FEATURE)!==33
    ||(fileperms(CT_FEATURE)&0777)!==0640||hash_file('sha256',CT_FEATURE)!==$plan['feature_sha256'])exit(2);
$state=CT_PRIVATE.'/synthetic-core-time-enablement-20261111.json';if(file_exists($state))exit(3);
$snapshot=static function()use($pdo):array{
    $out=[];
    foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('Invalid table');
        $rows=[];foreach($pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC) as $row)$rows[]=hash('sha256',serialize($row));
        sort($rows,SORT_STRING);$out[$table]=hash('sha256',serialize($rows));
    }ksort($out);return $out;
};
$before=$snapshot();$old=file_get_contents(CT_FEATURE);
foreach(token_get_all($old) as $token)if(is_array($token)&&$token[0]===T_CLOSE_TAG)throw new RuntimeException('Unexpected PHP closing tag');
if(str_contains($old,'COREFLUX_QUANTA_TIME_ACCESS_DELIVERY'))exit(2);
$new=$old."\n// Reviewed assignment time setup for the existing isolated tenant allowlist.\ndefine('COREFLUX_QUANTA_TIME_ACCESS_DELIVERY',true);\n";
$mask=umask(0077);$probe=null;$report=['passed'=>false,'started_at'=>gmdate('c'),'tenant_id'=>2002,'production_changed'=>false,'real_payroll_payments_postings'=>false];
$write=static function(string $path,string $body,int $mode):void{
    $f=fopen($path,'x');if(!$f)throw new RuntimeException('Exclusive write failed');
    try{if(fwrite($f,$body)!==strlen($body)||!fflush($f)||!fsync($f))throw new RuntimeException('Private write failed');}finally{fclose($f);}
    if(!chgrp($path,33)||!chmod($path,$mode))throw new RuntimeException('Ownership preservation failed');
};
$persist=static function()use(&$report,$state):void{file_put_contents($state,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);};
try{
    $write($state,'{}',0600);$write(CT_PRIVATE.'/feature-before-synthetic-time.private.php',$old,0600);
    $next=CT_PRIVATE.'/feature-next-synthetic-time.private.php';$write($next,$new,0640);
    $report['phase']='configuration';$persist();
    if(hash_file('sha256',CT_FEATURE)!==hash('sha256',$old)||!rename($next,CT_FEATURE))throw new RuntimeException('Configuration changed before activation');
    $token=bin2hex(random_bytes(32));$probe=CT_ROOT.'/api/admin/synthetic_time_check_'.bin2hex(random_bytes(12)).'.php';
    $body= <<<'PHP'
<?php
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!=='phpstack-1516771-6707602.cloudwaysapps.com'
 ||time()>EXPIRY||!hash_equals(TOKEN,(string)($_SERVER['HTTP_X_COREFLUX_PILOT_PROBE']??''))){http_response_code(404);exit;}
$root=dirname(__DIR__,2);if(realpath($root)!==ROOT){http_response_code(404);exit;}
if(function_exists('opcache_invalidate'))opcache_invalidate(FEATURE,true);
require $root.'/core/quanta/time_control_delivery.php';
if(DB_NAME!=='muzqvdvqbx'||!COREFLUX_STAGING||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'){http_response_code(404);exit;}
$states=[];foreach([2002,2,999,2003] as $tenant)$states[(string)$tenant]=quantaTimeControlEnabled($tenant);
header('Content-Type: application/json');echo json_encode(['states'=>$states,'feature_sha256'=>hash_file('sha256',FEATURE)]);
PHP;
    $body=str_replace(['EXPIRY','TOKEN','ROOT','FEATURE'],[(string)(time()+120),var_export($token,true),var_export(CT_ROOT,true),var_export(CT_FEATURE,true)],$body);
    $write($probe,$body,0640);
    $request=static function(?string $secret)use($probe):array{
        $ch=curl_init('https://phpstack-1516771-6707602.cloudwaysapps.com/api/admin/'.basename($probe));
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$secret===null?[]:['X-CoreFlux-Pilot-Probe: '.$secret]]);
        $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$status,json_decode((string)$raw,true)];
    };
    [$status]=$request(null);if($status!==404)throw new RuntimeException('Anonymous probe was not refused');
    [$status,$result]=$request($token);
    if($status!==200||($result['states']??null)!==['2002'=>true,'2'=>false,'999'=>false,'2003'=>false]
        ||($result['feature_sha256']??null)!==hash('sha256',$new))throw new RuntimeException('Web feature state differs');
    $report+=['web_runtime_verified'=>true,'states'=>$result['states'],'feature_sha256'=>hash('sha256',$new)];
    if($snapshot()!==$before)throw new RuntimeException('Configuration changed database records');
    $report+=['database_tables_preserved'=>count($before)];$report['phase']='complete';$report['passed']=true;
}catch(Throwable $e){$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
finally{
    if($probe!==null&&is_file($probe)){$report['probe_removed']=unlink($probe);if(!$report['probe_removed'])$report['passed']=false;}
    $report['finished_at']=gmdate('c');$persist();umask($mask);
}
echo json_encode($report,JSON_THROW_ON_ERROR)."\n";exit($report['passed']?0:1);
