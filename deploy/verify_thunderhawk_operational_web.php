<?php
/** Expiring, authenticated read-only web check for isolated pilot feature gates. */
declare(strict_types=1);
const TH_WEB_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_WEB_HOST='phpstack-1516771-6707602.cloudwaysapps.com';
$financial=($argv[1]??'')==='--verify-financial-reader-web';
$enabled=$financial||($argv[1]??'')==='--verify-pilot-web';
if(PHP_SAPI!=='cli'||!in_array($argv[1]??'',['--verify-pilot-web','--verify-disabled-web','--verify-financial-reader-web'],true)||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_WEB_ROOT||!extension_loaded('curl'))exit(2);
$token=bin2hex(random_bytes(32));
$name='thunderhawk_gate_check_'.bin2hex(random_bytes(16)).'.php';
$path=TH_WEB_ROOT.'/api/admin/'.$name;
if(realpath(dirname($path))!==TH_WEB_ROOT.'/api/admin')exit(2);
$template= <<<'PHP'
<?php
declare(strict_types=1);
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||($_SERVER['HTTP_HOST']??'')!==__HOST__
    ||time()>__EXPIRY__||!hash_equals(__TOKEN__,(string)($_SERVER['HTTP_X_COREFLUX_PILOT_PROBE']??''))){
    http_response_code(404);exit;
}
$root=dirname(__DIR__,2);
if(realpath($root)!==__ROOT__){http_response_code(404);exit;}
try{
    // Refresh only the two private files installed by this pilot configuration change.
    if(function_exists('opcache_invalidate')){
        opcache_invalidate($root.'/core/db.local.php',true);
        $feature=dirname($root).'/private_html/coreflux.thunderhawk-pilot.php';
        if(is_file($feature))opcache_invalidate($feature,true);
    }
    foreach(['quanta/metadata_delivery','quanta/worker_delivery','quanta/onboarding_delivery',
        'quanta/time_review_delivery','integrations/purepay_people_inputs','integrations/purepay_customer_inputs',
        'integrations/purepay_assignment_inputs','integrations/purepay_payroll_authority']as$file)
        require_once $root.'/core/'.$file.'.php';
    if(DB_NAME!=='muzqvdvqbx'||COREFLUX_STAGING!==true
        ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf')throw new RuntimeException('Wrong installation');
    $states=[];
    foreach(['quantaMetadataDeliveryEnabled','quantaWorkerDeliveryEnabled','quantaOnboardingDeliveryEnabled',
        'quantaTimeReviewEnabled','quantaOnboardingDocumentsEnabled','purepayCoreLinkEnabled',
        'purepayPeopleInputEnabled','purepayCustomerInputEnabled','purepayAssignmentInputEnabled',
        'purepayPayrollInputsEnabled','purepayPayrollAuthorityEnabled']as$function){
        foreach([2002,2,999,2003]as$tenant){
            $enabled=$function($tenant);
            if($enabled!==(__ENABLED__&&$tenant===2002))throw new RuntimeException('Wrong company gate');
            $states[$function][(string)$tenant]=$enabled;
        }
    }
    $financial=null;
    if(__FINANCIAL__){
        require_once $root.'/core/purepay_service.php';
        if(purepayApiBase()!=='https://purepay.online:19443/api/v1'||purepayFinancialPreviewTenant()!==2002)
            throw new RuntimeException('Financial preview selection differs');
        $pdo=getDB();$binding=purepayFinancialRequire($pdo,2002);
        $connection=purepayFinancialConnection($pdo,2002);
        if($binding['remote_organization_id']!=='org_0369a63ff2cb'||$binding['provider_key_scope']!=='read'
            ||$connection['key_scope']!=='read_only')throw new RuntimeException('Financial reader binding differs');
        // No provider request may leave this local web configuration check.
        $GLOBALS['__purepay_transport']=static function(){throw new LogicException('Unexpected provider request');};
        foreach([2,999,2003]as$tenant){
            if(purepayGetConnection($tenant)!==null)throw new RuntimeException('Other company connection visible');
            $denied=false;
            try{purepayFinancialReview($pdo,$tenant);}
            catch(PurePayApiException $error){$denied=$error->getMessage()==='The financial preview requires the approved simulation company and a read-only connection.';}
            if(!$denied)throw new RuntimeException('Other company review not denied');
        }
        foreach(['POST','PUT','PATCH','DELETE']as$method){
            $denied=false;
            try{purepayCall('fixture-no-provider-credential',$method,'/bills/nonexistent-financial-reader-proof/pay');}
            catch(PurePayApiException $error){$denied=$error->getMessage()==='PurePay financial preview is read-only.';}
            if(!$denied)throw new RuntimeException('Financial write not denied');
        }
        $financial=['tenant_id'=>2002,'organization_id'=>'org_0369a63ff2cb','scope'=>'read_only',
            'company_confirmed'=>true,'other_company_reviews_denied'=>3,'write_methods_denied'=>4,'provider_contacted'=>false];
    }
    header('Content-Type: application/json');
    echo json_encode(['passed'=>true,'sapi'=>PHP_SAPI,'staging'=>true,'gates'=>$states,'app_url'=>APP_URL,'financial_reader'=>$financial],JSON_THROW_ON_ERROR);
}catch(Throwable $error){
    http_response_code(500);header('Content-Type: application/json');
    echo json_encode(['passed'=>false,'failure_class'=>get_class($error),
        'failure_source'=>basename($error->getFile()),'failure_line'=>$error->getLine()]);
}
PHP;
$source=strtr($template,['__HOST__'=>var_export(TH_WEB_HOST,true),'__ROOT__'=>var_export(TH_WEB_ROOT,true),
    '__EXPIRY__'=>(string)(time()+180),'__TOKEN__'=>var_export($token,true),'__ENABLED__'=>$enabled?'true':'false',
    '__FINANCIAL__'=>$financial?'true':'false']);
$report=['checked_at'=>gmdate('c'),'production_changed'=>false,'business_write_requested'=>false];
$oldMask=umask(0022);
try{$stream=fopen($path,'x');}finally{umask($oldMask);}
if(!$stream)exit(2);
try{
    try{if(fwrite($stream,$source)!==strlen($source)||!fflush($stream))throw new RuntimeException('Probe write failed');}
    finally{fclose($stream);}
    if(!chmod($path,0644))throw new RuntimeException('Probe permissions failed');
    $request=static function(?string $token)use($name):array{
        $curl=curl_init('https://'.TH_WEB_HOST.'/api/admin/'.$name);
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>$token===null?[]:['X-CoreFlux-Pilot-Probe: '.$token]]);
        $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        return [$status,$body];
    };
    [$denied,$empty]=$request(null);
    $report['unauthenticated_http_status']=$denied;
    $report['unauthenticated_body_bytes']=is_string($empty)?strlen($empty):null;
    if($denied!==404||$empty!=='')throw new RuntimeException('Unauthenticated probe access was not denied');
    [$status,$body]=$request($token);
    $result=is_string($body)?json_decode($body,true):null;
    $report['authenticated_http_status']=$status;
    $report['web']=$result;
    if($status!==200||!is_array($result)||($result['passed']??false)!==true||($result['sapi']??'')!=='fpm-fcgi')
        throw new RuntimeException('Web process verification failed');
    $report['web']=$result;$report['unauthenticated_request_denied']=true;$report['passed']=true;
}catch(Throwable $error){
    $report['passed']=false;$report['failure_class']=get_class($error);$report['failure_line']=$error->getLine();
}finally{
    $report['temporary_probe_removed']=unlink($path)&&!file_exists($path);
    if(!$report['temporary_probe_removed'])$report['passed']=false;
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
