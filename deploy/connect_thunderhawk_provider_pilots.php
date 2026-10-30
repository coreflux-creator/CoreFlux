<?php
/** Configure only isolated staging origins, then use native connection review/binding services. */
declare(strict_types=1);
const TH_CONNECT_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_CONNECT_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TH_CONNECT_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--configure-origins','--connect'],true)||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_CONNECT_ROOT||realpath(TH_CONNECT_PRIVATE)!==TH_CONNECT_PRIVATE)
    throw new RuntimeException('Exact isolated staging configuration required');
require_once TH_CONNECT_ROOT.'/core/db.php';
require_once TH_CONNECT_ROOT.'/core/quanta/workspace.php';
require_once TH_CONNECT_ROOT.'/core/integrations/purepay_core.php';
require_once TH_CONNECT_ROOT.'/core/audit.php';
require_once TH_CONNECT_ROOT.'/core/sub_tenants.php';
require_once TH_CONNECT_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||integrationInstanceId()!==TH_CONNECT_INSTANCE
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||effectiveTenantIdForModule('people',2002)!==2002||effectiveTenantIdForModule('placements',2002)!==2002
    ||!RBACResolver::can(4,2002,'integrations','admin'))throw new RuntimeException('Pilot identity or actor differs');
$keys=json_decode((string)file_get_contents(TH_CONNECT_PRIVATE.'/provider-keys.private.json'),true,512,JSON_THROW_ON_ERROR);
foreach(['purepay_key','quanta_key'] as $n)if(!is_string($keys[$n]??null)||strlen($keys[$n])<24)throw new RuntimeException('Private connection material missing');
$bases=['quanta'=>'https://helloquanta.app:19443/api/v1','purepay'=>'https://purepay.online:19443/api/v1'];
$expected=['quanta'=>'4f93d4e7-af74-4765-b812-3464bb6d974b','purepay'=>'org_0369a63ff2cb'];
$snapshot=static function()use($pdo):string{
    $rows=[];foreach(['quanta_connections','quanta_workspace_bindings','purepay_core_connections'] as $t)
        $rows[$t]=$pdo->query('SELECT * FROM '.$t.' WHERE tenant_id<>2002 ORDER BY tenant_id')->fetchAll(PDO::FETCH_ASSOC);
    return hash('sha256',serialize($rows));
};
$before=$snapshot();$report=['checked_at'=>gmdate('c'),'mode'=>$mode,'tenant_id'=>2002,
    'production_changed'=>false,'business_records_sent'=>false,'payroll_or_payment_execution'=>false];
try{
    // Verify TLS, fresh keys and exact remote company identities before any local mutation.
    foreach($bases as $name=>$base){
        $c=curl_init($base.'/integrations/coreflux/context');
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$keys[$name.'_key']],
            CURLOPT_TIMEOUT=>25,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
        $raw=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);
        if($raw===false||$status!==200)throw new RuntimeException('Pilot context read failed: '.$name);
        $ctx=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        $ctx=$name==='quanta'?quantaContractContextValidate($ctx):purepayCoreContextValidate($ctx);
        $identity=$ctx[$name==='quanta'?'workspace':'organization'];
        if($identity['id']!==$expected[$name]||!str_contains($identity['name'],'isolated pilot'))
            throw new RuntimeException('Provider company differs from the held pilot');
    }
    if($mode==='--configure-origins'){
        $path=TH_CONNECT_ROOT.'/core/db.local.php';
        if(realpath($path)!==$path||is_link($path)||fileowner($path)!==posix_geteuid())throw new RuntimeException('Configuration ownership differs');
        $old=(string)file_get_contents($path);$new=$old;
        $replacements=[
            'COREFLUX_QUANTA_PREVIEW_API_BASE'=>['https://clockin-hub-16.preview.emergentagent.com/api/v1',$bases['quanta']],
            'COREFLUX_PUREPAY_PREVIEW_API_BASE'=>['https://pure-pay-support.preview.emergentagent.com/api/v1',$bases['purepay']],
        ];
        foreach($replacements as $name=>[$previous,$next]){
            if(!defined($name)||constant($name)!==$previous)throw new RuntimeException('Previous origin differs; do not overwrite it');
            $pattern='~(define\(\s*[\x27"]'.preg_quote($name,'~').'[\x27"]\s*,\s*[\x27"])'.preg_quote($previous,'~').'([\x27"]\s*\))~';
            $new=preg_replace_callback($pattern,static fn($m)=>$m[1].$next.$m[2],$new,-1,$count);
            if($count!==1)throw new RuntimeException('Expected one exact preview definition');
        }
        $backup=TH_CONNECT_PRIVATE.'/db.local.before-provider-origins.private.php';
        $mask=umask(0077);
        try{
            $f=fopen($backup,'x');if(!$f)throw new RuntimeException('Private configuration backup already exists');
            if(fwrite($f,$old)!==strlen($old))throw new RuntimeException('Private backup failed');fclose($f);
            $tmp=TH_CONNECT_PRIVATE.'/db.local.next.private.php';
            $f=fopen($tmp,'x');if(!$f)throw new RuntimeException('Private staged configuration already exists');
            if(fwrite($f,$new)!==strlen($new))throw new RuntimeException('Private staged write failed');fclose($f);
            if(!chgrp($tmp,filegroup($path))||!chmod($tmp,fileperms($path)&0777)
                ||hash_file('sha256',$path)!==hash('sha256',$old)||!rename($tmp,$path))throw new RuntimeException('Atomic configuration replacement failed');
            if(hash_file('sha256',$path)!==hash('sha256',$new))throw new RuntimeException('Installed configuration differs');
        }finally{umask($mask);}
        $report+=['configured_origins'=>$bases,'prior_configuration_backed_up'=>true,
            'old_config_sha256'=>hash('sha256',$old),'new_config_sha256'=>hash('sha256',$new),
            'existing_demo_bindings_retained'=>true,'old_demo_origins_now_require_explicit_migration'=>true,
            'financial_payment_rail_configuration_changed'=>false];
    }else{
        if(quantaApiBase()!==$bases['quanta']||purepayCoreApiBase()!==$bases['purepay'])throw new RuntimeException('Configure exact isolated destinations first');
        $audit=static fn(string $event)=>platformAuditLogWrite(2002,4,$event,null,['pilot'=>'thunderhawk-20261030'],['source'=>'integration_pilot']);
        $q=quantaConnection(2002);
        if($q&&!hash_equals((string)decryptField($q['api_key_ct']),$keys['quanta_key']))throw new RuntimeException('Existing pilot Quanta credential differs');
        if(!$q){
            quantaProbeConnection($keys['quanta_key']);$cipher=encryptField($keys['quanta_key']);
            quantaWorkspaceReconfigure($pdo,2002,static function()use($pdo,$cipher,$keys):void{
                $pdo->prepare('INSERT INTO quanta_connections(tenant_id,api_key_ct,api_key_last4,status,last_probe_at,connected_by_user_id)
                    VALUES(2002,?,?,"active",NOW(),4)')->execute([$cipher,substr($keys['quanta_key'],-4)]);
            });
            $audit('quanta.connection.connected');
        }
        $q=quantaConnection(2002);$ctx=quantaContractContext($keys['quanta_key']);
        if($ctx['workspace']['id']!==$expected['quanta'])throw new RuntimeException('Quanta company changed during review');
        $review=quantaWorkspaceReview(2002);
        quantaWorkspaceBind($pdo,2002,4,$q,$keys['quanta_key'],TH_CONNECT_INSTANCE,$ctx,$review['review_hash']);
        $audit('quanta.workspace.verified');
        $p=purepayCoreConnection($pdo,2002);
        if($p&&!hash_equals((string)decryptField($p['api_key_ct']),$keys['purepay_key']))throw new RuntimeException('Existing pilot PurePay credential differs');
        if(!$p)purepayCoreConnect($pdo,2002,$keys['purepay_key'],TH_CONNECT_INSTANCE,static fn()=>$audit('purepay.core.connected'));
        $review=purepayCoreReview($pdo,2002,TH_CONNECT_INSTANCE);
        purepayCoreConfirm($pdo,2002,4,TH_CONNECT_INSTANCE,$review['review_hash'],$expected['purepay'],static fn()=>$audit('purepay.core.organization_confirmed'));
        $qr=quantaWorkspaceReview(2002);$pr=purepayCoreReview($pdo,2002,TH_CONNECT_INSTANCE);
        if(!$qr['verified']||!$pr['verified'])throw new RuntimeException('Final native company verification failed');
        $report+=['quanta'=>['workspace'=>$qr['workspace'],'verified'=>$qr['verified'],'api_base'=>quantaApiBase()],
            'purepay'=>['organization'=>$pr['organization'],'verified'=>$pr['verified'],'api_base'=>purepayCoreApiBase(),'capabilities'=>$pr['capabilities']]];
    }
    if(!hash_equals($before,$snapshot()))throw new RuntimeException('Existing company connection records changed');
    $report['other_company_connections_unchanged']=true;$report['passed']=true;
}catch(Throwable $e){$report['passed']=false;$report['failure_class']=get_class($e);$report['failure_line']=$e->getLine();}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
exit($report['passed']?0:1);
