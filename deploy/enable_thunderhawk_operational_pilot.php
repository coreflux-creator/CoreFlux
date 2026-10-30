<?php
/** Persist operational delivery only for the existing isolated Thunderhawk company. */
declare(strict_types=1);

const TH_ENABLE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_ENABLE_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html';
const TH_ENABLE_BACKUPS=TH_ENABLE_PRIVATE.'/thunderhawk-pilot-20261030';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--inspect','--enable','--verify'],true)
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_ENABLE_ROOT
    ||realpath(TH_ENABLE_PRIVATE)!==TH_ENABLE_PRIVATE||realpath(TH_ENABLE_BACKUPS)!==TH_ENABLE_BACKUPS)exit(2);

$report=['checked_at'=>gmdate('c'),'mode'=>$mode,'tenant_id'=>2002,'production_changed'=>false,
    'provider_permissions_changed'=>false,'payroll_or_payment_execution'=>false];
$phase='preflight';
try{
    $gate=TH_ENABLE_ROOT.'/core/integrations/delivery_enablement.php';
    if(!is_file($gate)||hash_file('sha256',$gate)!=='a021cd62c08a9dc81d42d9a6b4c2dc280917002cea71192ed14e243eac723133')
        throw new RuntimeException('Verified company gate runtime is required');
    foreach(['quanta/metadata_delivery','quanta/worker_delivery','quanta/onboarding_delivery',
        'quanta/time_review_delivery','integrations/purepay_people_inputs','integrations/purepay_customer_inputs',
        'integrations/purepay_assignment_inputs','integrations/purepay_payroll_authority']as$file)
        require_once TH_ENABLE_ROOT.'/core/'.$file.'.php';
    $pdo=getDB();
    if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
        ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
        ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
        ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
        ||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1')throw new RuntimeException('Isolated installation differs');
    $gates=[
        'quantaMetadataDeliveryEnabled'=>'COREFLUX_QUANTA_METADATA_DELIVERY',
        'quantaWorkerDeliveryEnabled'=>'COREFLUX_QUANTA_WORKER_DELIVERY',
        'quantaOnboardingDeliveryEnabled'=>'COREFLUX_QUANTA_ONBOARDING_DELIVERY',
        'quantaTimeReviewEnabled'=>'COREFLUX_QUANTA_TIME_REVIEW_DELIVERY',
        'purepayCoreLinkEnabled'=>'COREFLUX_PUREPAY_CORE_LINK_DELIVERY',
        'purepayPeopleInputEnabled'=>'COREFLUX_PUREPAY_PEOPLE_DELIVERY',
        'purepayCustomerInputEnabled'=>'COREFLUX_PUREPAY_CUSTOMER_DELIVERY',
        'purepayAssignmentInputEnabled'=>'COREFLUX_PUREPAY_ASSIGNMENT_DELIVERY',
        'purepayPayrollInputsEnabled'=>'COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY',
        'purepayPayrollAuthorityEnabled'=>'COREFLUX_PUREPAY_PAYROLL_AUTHORITY',
        'quantaOnboardingDocumentsEnabled'=>'COREFLUX_QUANTA_ONBOARDING_DOCUMENTS',
    ];
    $flags=array_fill_keys(array_values($gates),true);
    $flags=['COREFLUX_INTEGRATION_DELIVERY_TENANTS'=>'2002','COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS'=>'2002']+$flags;
    $config=TH_ENABLE_ROOT.'/core/db.local.php';
    $feature=TH_ENABLE_PRIVATE.'/coreflux.thunderhawk-pilot.php';
    $backup=TH_ENABLE_BACKUPS.'/db.local.before-operational-enablement.private.php';
    if(realpath($config)!==$config||is_link($config)||fileowner($config)!==posix_geteuid()
        ||filegroup($config)!==33||(fileperms($config)&0777)!==0640||is_link($feature))
        throw new RuntimeException('Private configuration ownership differs');
    $old=(string)file_get_contents($config);
    foreach(token_get_all($old)as$token)if(is_array($token)&&$token[0]===T_CLOSE_TAG)
        throw new RuntimeException('Configuration must remain in PHP');
    $featureBody="<?php\n// Isolated Thunderhawk pilot; no payroll execution or payment capability.\n";
    $featureBody.="if (realpath(__DIR__) !== ".var_export(TH_ENABLE_PRIVATE,true)
        ." || !defined('DB_NAME') || DB_NAME !== 'muzqvdvqbx'"
        ." || (PHP_SAPI === 'cli' && getenv('COREFLUX_ENV') !== 'staging')) {\n"
        ."    throw new RuntimeException('Thunderhawk pilot configuration belongs to isolated staging.');\n}\n";
    foreach($flags as$name=>$value)$featureBody.='define('.var_export($name,true).','.var_export($value,true).");\n";
    $include="\n// Reviewed isolated operational pilot settings.\nrequire_once ".var_export($feature,true).";\n";
    $installed=str_contains($old,$include);
    if($installed&&(!is_file($feature)||file_get_contents($feature)!==$featureBody))
        throw new RuntimeException('Installed pilot configuration differs');
    if(!$installed&&is_file($feature))throw new RuntimeException('Unreferenced pilot configuration needs review');
    $state=static function()use($gates):array{
        $state=[];
        foreach($gates as$function=>$flag){
            $state[$function]=[];
            foreach([2002,2,999,2003]as$tenant)$state[$function][(string)$tenant]=$function($tenant);
        }
        return $state;
    };
    $snapshot=static function()use($pdo):string{
        $all=[];
        foreach(['quanta_connections','quanta_workspace_bindings','purepay_core_connections','people','placements',
            'time_entries','staffing_timesheets','payroll_source_authorities','payroll_provider_results']as$table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$all[$table]=hash('sha256',serialize($rows));
        }
        return hash('sha256',serialize($all));
    };
    $before=$snapshot();
    $report['already_installed']=$installed;
    $report['before']=$state();
    if($mode==='--enable'&&!$installed){
        foreach($flags as$name=>$value)if(getenv($name)!==false||defined($name))
            throw new RuntimeException('Existing operational flag requires separate review');
        if(is_file($backup))throw new RuntimeException('Prior enablement backup requires review');
        $phase='private_configuration_install';
        $mask=umask(0077);
        $write=static function(string $path,string $body,int $permissions):void{
            $stream=fopen($path,'x');
            if(!$stream)throw new RuntimeException('Exclusive private write failed');
            try{if(fwrite($stream,$body)!==strlen($body)||!fflush($stream))throw new RuntimeException('Private write incomplete');}
            finally{fclose($stream);}
            if(!chgrp($path,33)||!chmod($path,$permissions))throw new RuntimeException('Private file permissions failed');
        };
        try{
            $write($backup,$old,0600);
            $write($feature,$featureBody,0640);
            $next=TH_ENABLE_BACKUPS.'/db.local.operational-next.private.php';
            $write($next,$old.$include,0640);
            if(hash_file('sha256',$config)!==hash('sha256',$old)||!rename($next,$config))
                throw new RuntimeException('Atomic configuration install failed');
            if(fileowner($config)!==posix_geteuid()||filegroup($config)!==33||(fileperms($config)&0777)!==0640)
                throw new RuntimeException('Installed configuration permissions differ');
            require_once $feature;
            $installed=true;
        }finally{umask($mask);}
    }
    $report['after']=$state();
    if($mode!=='--inspect'){
        $phase='company_boundary_verification';
        if(!$installed)throw new RuntimeException('Persistent pilot configuration is not installed');
        foreach($report['after']as$states)foreach($states as$tenant=>$enabled)
            if($enabled!==((int)$tenant===2002))throw new RuntimeException('Company delivery boundary differs');
        if(!defined('COREFLUX_INTEGRATION_DELIVERY_TENANTS')||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
            ||!defined('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS')||COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS!=='2002')
            throw new RuntimeException('Explicit lists differ');
        $report['unlisted_company_send_checks']=0;
        foreach([2,999]as$tenant){
            // Nonexistent references cannot target any real delivery if a gate regresses.
            $scope=['tenant_id'=>$tenant,'people_tenant_id'=>$tenant,'placements_tenant_id'=>$tenant,
                'actor_user_id'=>4,'permissions'=>['manage'=>true]];
            $attempts=[
                fn()=>quantaMetadataSend($tenant,4,0,'denied-pilot-check'),
                fn()=>quantaWorkerProfilesSend($tenant,4,0,'denied-pilot-check'),
                fn()=>quantaTimeReviewSend($tenant,4,0,'denied-pilot-check'),
                fn()=>quantaOnboardingSend($scope,'nonexistent-case',0,'denied-pilot-check',static fn()=>null),
                fn()=>purepayCoreLinkSend($pdo,$tenant,4,'nonexistent-instance',$tenant,0,'denied-pilot-check'),
                fn()=>purepayPeopleInputSend($pdo,$tenant,4,'nonexistent-instance',[],0,'denied-pilot-check'),
                fn()=>purepayCustomerInputSend($pdo,$tenant,4,'nonexistent-instance',$tenant,0,'denied-pilot-check'),
                fn()=>purepayAssignmentInputSend($pdo,$tenant,4,'nonexistent-instance',[],0,'denied-pilot-check'),
                fn()=>purepayPayrollInputSend($pdo,$tenant,4,'nonexistent-instance',[],0,'denied-pilot-check'),
                fn()=>purepayPayrollAuthorityReserve($pdo,$tenant,4,'nonexistent-instance',[],0,'denied-pilot-check','nonexistent-request'),
                fn()=>purepayPayrollAuthoritySend($pdo,$tenant,'nonexistent-instance','nonexistent-reservation'),
                fn()=>purepayPayrollAuthorityRelease($pdo,$tenant,4,'nonexistent-instance','nonexistent-reservation'),
            ];
            foreach($attempts as$attempt){
                $denied=false;
                try{$attempt();}catch(RuntimeException $error){
                    $denied=str_contains($error->getMessage(),'not been enabled')||str_contains($error->getMessage(),'not enabled');
                }
                if(!$denied)throw new RuntimeException('Unlisted company did not stop at feature boundary');
                $report['unlisted_company_send_checks']++;
            }
        }
    }
    if($before!==$snapshot())throw new RuntimeException('Connection or business records changed');
    $report['persistent_configuration_installed']=$installed;
    $report['connection_and_business_records_unchanged']=true;
    $report['passed']=true;
}catch(Throwable $error){
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_line']=$error->getLine();$report['failure_source']=basename($error->getFile());
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
