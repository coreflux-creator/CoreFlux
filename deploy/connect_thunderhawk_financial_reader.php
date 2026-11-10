<?php
/** Select the isolated financial reader, preserving prior demo and all business records. */
declare(strict_types=1);
const TH_FIN_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_FIN_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const TH_FIN_BASE='https://purepay.online:19443/api/v1';
const TH_FIN_OLD_BASE='https://pure-pay-support.preview.emergentagent.com/api/v1';
const TH_FIN_ORG='org_0369a63ff2cb';
$mode=$argv[1]??'';
if(PHP_SAPI!=='cli'||!in_array($mode,['--inspect','--configure','--connect','--verify'],true)
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_FIN_ROOT
    ||realpath(TH_FIN_PRIVATE)!==TH_FIN_PRIVATE)exit(2);
foreach([
    'purepay_adapter.php'=>'aa0659f03f8f3e2d52c98b7335325f03374b55e534423039fd6a7f3d8cc6f0d1',
    'purepay_service.php'=>'04ed6c97b97cdc1253d0ea5937df136299abdb194ce53a2fe12104466ea4422b',
    'purepay_financial_binding.php'=>'1b1d0246de295bbff3073fd693d83cf0a31a00dbde2a501048d427e412393b54',
]as$file=>$hash)if(!hash_equals($hash,(string)hash_file('sha256',TH_FIN_ROOT.'/core/'.$file)))exit(2);
require_once TH_FIN_ROOT.'/core/purepay_service.php';
require_once TH_FIN_ROOT.'/core/integrations/purepay_core.php';
require_once TH_FIN_ROOT.'/core/sub_tenants.php';
require_once TH_FIN_ROOT.'/core/rbac/permissions.php';
require_once TH_FIN_ROOT.'/core/audit.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'integrations','admin')
    ||!RBACResolver::can(4,2002,'accounting','admin')
    ||!function_exists('purepayFinancialPreviewTenant')||purepayCoreApiBase()!==TH_FIN_BASE)exit(2);
$report=['checked_at'=>gmdate('c'),'mode'=>$mode,'tenant_id'=>2002,
    'production_changed'=>false,'payroll_or_payment_execution'=>false,'financial_postings'=>false];
$phase='guard';
try{
    $protected=static function()use($pdo):array{
        $result=[];
        foreach(['people','placements','placement_rates','staffing_clients','time_entries','staffing_timesheets',
            'payroll_runs','payroll_line_items','payroll_provider_results','payroll_native_observations',
            'ap_bills','ap_payments','accounting_journal_entries','accounting_journal_entry_lines',
            'billing_invoices','billing_payments','purepay_core_connections','quanta_connections',
            'purepay_payment_links','purepay_webhook_events','purepay_connections',
            'purepay_financial_bindings','purepay_financial_organizations']as$table){
            $where=in_array($table,['purepay_connections','purepay_financial_bindings','purepay_financial_organizations'],true)
                ?' WHERE tenant_id<>2002':'';
            $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`'.$where)->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        }
        return$result;
    };
    $before=$protected();$base=purepayApiBase();
    if(!in_array($base,[TH_FIN_OLD_BASE,TH_FIN_BASE],true))throw new RuntimeException('Unreviewed current financial origin');
    $report['financial_origin']=$base;$report['selected_company']=purepayFinancialPreviewTenant();
    if($mode!=='--inspect'){
        $packetPath=TH_FIN_PRIVATE.'/financial-reader.private.json';
        if(realpath($packetPath)!==$packetPath||(fileperms($packetPath)&0777)!==0600)throw new RuntimeException('Private reader material unavailable');
        $packet=json_decode((string)file_get_contents($packetPath),true,512,JSON_THROW_ON_ERROR);
        if(($packet['purpose']??'')!=='isolated_thunderhawk_financial_read_only'||($packet['tenant_id']??null)!==2002
            ||($packet['organization_id']??'')!==TH_FIN_ORG||($packet['scope']??'')!=='read')throw new RuntimeException('Reader identity differs');
        $key=$packet['api_key'];
    }
    if($mode==='--configure'){
        $phase='destination_preflight';
        $context=purepayFinancialContext(purepayCall($key,'GET','/context',[],25,null,TH_FIN_BASE));
        if($context['organization']['id']!==TH_FIN_ORG||$context['key_scope']!=='read'
            ||!str_contains($context['organization']['name'],'isolated pilot'))throw new RuntimeException('Destination identity differs');
        $report['destination_tls_company_scope_verified']=true;
        $phase='private_configuration';
        $path=TH_FIN_ROOT.'/core/db.local.php';
        $backup=TH_FIN_PRIVATE.'/db.local.before-financial-reader.private.php';
        $next=TH_FIN_PRIVATE.'/db.local.financial-reader-next.private.php';
        foreach([$path,$backup,$next]as$file)if(is_link($file))throw new RuntimeException('Linked configuration');
        if(realpath($path)!==$path||fileowner($path)!==posix_geteuid()
            ||filegroup($path)!==33||(fileperms($path)&0777)!==0640)throw new RuntimeException('Configuration ownership differs');
        $old=(string)file_get_contents($path);
        if($base===TH_FIN_BASE){
            if(purepayFinancialPreviewTenant()!==2002||!is_file($backup))throw new RuntimeException('Unreviewed prior setup');
            $report['already_configured']=true;
        }else{
            if(purepayFinancialPreviewTenant()!==999||str_contains($old,'COREFLUX_PUREPAY_FINANCIAL_PREVIEW_TENANT_ID'))
                throw new RuntimeException('Previous selection differs');
            foreach(token_get_all($old)as$token)if(is_array($token)&&$token[0]===T_CLOSE_TAG)throw new RuntimeException('Ambiguous PHP configuration');
            $pattern='~(define\(\s*[\x27"]COREFLUX_PUREPAY_FINANCIAL_PREVIEW_API_BASE[\x27"]\s*,\s*[\x27"])'
                .preg_quote(TH_FIN_OLD_BASE,'~').'([\x27"]\s*\))~';
            $new=preg_replace_callback($pattern,static fn($m)=>$m[1].TH_FIN_BASE.$m[2],$old,-1,$count);
            if($count!==1)throw new RuntimeException('Expected one financial preview definition');
            $new.="\n// Reviewed isolated Thunderhawk financial observation; no payment writes.\n"
                ."define('COREFLUX_PUREPAY_FINANCIAL_PREVIEW_TENANT_ID',2002);\n";
            $write=static function(string $file,string $body,int $mode):void{
                $stream=fopen($file,'x');if(!$stream)throw new RuntimeException('Prior private preparation needs review');
                try{if(fwrite($stream,$body)!==strlen($body)||!fflush($stream))throw new RuntimeException('Incomplete private write');}
                finally{fclose($stream);}
                if(!chgrp($file,33)||!chmod($file,$mode))throw new RuntimeException('Private permissions failed');
            };
            $mask=umask(0077);
            try{
                $write($backup,$old,0600);$write($next,$new,0640);
                if(hash_file('sha256',$path)!==hash('sha256',$old)||!rename($next,$path)
                    ||hash_file('sha256',$path)!==hash('sha256',$new))throw new RuntimeException('Atomic configuration replacement failed');
            }finally{umask($mask);}
            $report['new_config_sha256']=hash('sha256',$new);$report['old_config_sha256']=hash('sha256',$old);
            $report['fresh_process_verification_required']=true;
        }
    }elseif(in_array($mode,['--connect','--verify'],true)){
        $phase='native_company_review';
        if($base!==TH_FIN_BASE||purepayFinancialPreviewTenant()!==2002)throw new RuntimeException('Exact financial preview selection required');
        $context=purepayFinancialReadContext($key);
        if($context['organization']['id']!==TH_FIN_ORG||$context['key_scope']!=='read'
            ||!str_contains($context['organization']['name'],'isolated pilot'))throw new RuntimeException('Provider company or permission differs');
        $connection=purepayFinancialConnection($pdo,2002);
        if($connection&&(!hash_equals(purepayFinancialKey($connection),$key)||$connection['key_scope']!=='read_only'))
            throw new RuntimeException('Existing pilot financial connection differs');
        $audit=static fn(string $event)=>platformAuditLogWrite(2002,4,$event,null,
            ['pilot'=>'thunderhawk-20261030','financial_access'=>'read_only'],['source'=>'integration_pilot']);
        if($mode==='--connect'){
            if(!$connection){purepayStoreConnection(2002,$key,'Thunderhawk isolated financial reader',4,'read_only');$audit('purepay.connected');}
            $review=purepayFinancialReview($pdo,2002);
            if(!$review['verified'])purepayFinancialConfirm($pdo,2002,4,$review['review_hash'],TH_FIN_ORG,
                static fn()=>$audit('purepay.company.confirmed'));
        }
        $binding=purepayFinancialRequire($pdo,2002,null,true);
        if($binding['remote_organization_id']!==TH_FIN_ORG||$binding['provider_key_scope']!=='read')throw new RuntimeException('Final binding differs');
        $current=purepayFinancialConnection($pdo,2002);
        if(!hash_equals($key,purepayFinancialKey($current))||str_contains($current['api_key_ct'],$key))
            throw new RuntimeException('Encrypted connection verification failed');
        $report['company_confirmed']=true;$report['organization_id']=TH_FIN_ORG;$report['key_scope']='read_only';
        $report['connection_id']=(int)$current['id'];$report['binding_revision']=(int)$binding['revision'];
        $report['existing_connection_reused']=$connection!==null;$report['credential_encrypted_at_rest']=true;
        foreach([2,999,2003]as$tenant)if(purepayGetConnection($tenant)!==null)throw new RuntimeException('Another company can use the preview');
        $report['other_companies_denied']=true;
        foreach(['POST','PUT','PATCH','DELETE']as$method){
            $denied=false;
            try{purepayCall('fixture-no-credential',$method,'/bills/nonexistent-financial-reader-proof/pay');}
            catch(PurePayApiException $e){$denied=$e->getMessage()==='PurePay financial preview is read-only.';}
            if(!$denied)throw new RuntimeException('A financial write escaped the local guard');
        }
        $report['all_financial_write_methods_denied']=true;
    }
    if($protected()!==$before)throw new RuntimeException('Protected business or other-company connection records changed');
    $report['protected_business_and_other_company_records_unchanged']=true;
    $report['passed']=true;
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    $report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
