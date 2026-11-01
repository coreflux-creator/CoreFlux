<?php
/** Verify source observations through Core's reviewed reader; retain an encrypted private packet. */
declare(strict_types=1);
const TH_FIN_READ_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_FIN_READ_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--read-existing-financial-observations'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_FIN_READ_ROOT
    ||realpath(TH_FIN_READ_PRIVATE)!==TH_FIN_READ_PRIVATE)exit(2);
require_once TH_FIN_READ_ROOT.'/core/purepay_service.php';
require_once TH_FIN_READ_ROOT.'/core/integrations/purepay_core.php';
require_once TH_FIN_READ_ROOT.'/core/sub_tenants.php';
require_once TH_FIN_READ_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||purepayApiBase()!=='https://purepay.online:19443/api/v1'||purepayFinancialPreviewTenant()!==2002
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'accounting','admin')||!RBACResolver::can(4,2002,'ap','read')
    ||!RBACResolver::can(4,2002,'billing','read'))exit(2);
$report=['checked_at'=>gmdate('c'),'production_changed'=>false,'financial_postings'=>false,
    'payments_or_payroll_executed'=>false,'product_observation_saved'=>false];$phase='guard';
try{
    $protected=static function()use($pdo):string{
        $all=[];
        foreach(['people','placements','placement_rates','staffing_clients','time_entries','staffing_timesheets',
            'payroll_runs','payroll_line_items','payroll_provider_results','payroll_native_observations',
            'ap_bills','ap_payments','accounting_journal_entries','accounting_journal_entry_lines',
            'billing_invoices','billing_payments','purepay_connections','purepay_financial_bindings',
            'purepay_financial_organizations','purepay_payment_links','purepay_webhook_events']as$table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$all[$table]=hash('sha256',serialize($rows));
        }
        return hash('sha256',serialize($all));
    };
    $before=$protected();$binding=purepayFinancialRequire($pdo,2002,null,true);
    if($binding['remote_organization_id']!=='org_0369a63ff2cb'||$binding['provider_key_scope']!=='read')
        throw new RuntimeException('Provider binding differs');
    $connection=purepayFinancialConnection($pdo,2002);$key=purepayFinancialKey($connection);
    $expected=[
        ['bill','pay_041311ec10','b40b11b2fc5a9994fc9a446af58b265938b53e1d4e2047e08bef625016a39a76',2,2],
        ['invoice','inv_05611deda7','e1d8f1cfa03c03efb7ea2f98c8f6b4bfecad157a563f0818dbde33fdae6c8964',42,0],
        ['invoice','inv_13c40a9026','4939f0aa1d1d0f129a21e4c0346e3f54699adffd70b317ff30098f5572dc9455',1,0],
    ];
    $canonical=static function(mixed $value)use(&$canonical):mixed{
        if(!is_array($value))return$value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as$key=>$item)$value[$key]=$canonical($item);
        return$value;
    };
    $encode=static fn(mixed $value):string=>json_encode($canonical($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_LINE_TERMINATORS);
    $observations=[];$results=[];$phase='native_read';
    foreach($expected as[$kind,$identity,$hash,$lines,$payments]){
        $data=purepayCall($key,'GET','/financial-observations/'.($kind==='bill'?'bills':'invoices').'/'.rawurlencode($identity));
        if(($data['contract_version']??null)!=='2026-10-30.1'||($data['organization_id']??null)!=='org_0369a63ff2cb'
            ||($data['document_id']??null)!==$identity||($data['kind']??null)!==$kind
            ||($data['observation_hash']??null)!==$hash||($data['cash_delivery_proven']??null)!==false
            ||($data['execution_authority']??null)!==false||($data['external_sources_refreshed']??null)!==false
            ||($data['stored_record_enumeration_complete']??null)!==true
            ||($data['consistency']??null)!=='rechecked_read_not_transactional_snapshot'
            ||!array_key_exists('currency',$data['document'])||$data['document']['currency']!==null
            ||!in_array('/document/currency',$data['unavailable_fields'],true)
            ||count($data['document']['line_items'])!==$lines||count($data['outgoing_payments'])!==$payments)
            throw new RuntimeException('Source observation differs');
        $content=$data;unset($content['observed_at'],$content['observation_hash']);
        if(!hash_equals($hash,hash('sha256',$encode($content))))throw new RuntimeException('Cross-language observation hash differs');
        unset($data['observed_at']);$observations[]=$data;
        $results[]=['kind'=>$kind,'document_id'=>$identity,'observation_hash'=>$hash,'line_count'=>$lines,
            'payment_count'=>$payments,'currency_missing'=>true,'cross_language_hash_verified'=>true];
    }
    if(purepayFinancialRequire($pdo,2002)['binding_hash']!==$binding['binding_hash'])throw new RuntimeException('Connection changed while reading');
    $phase='private_encrypted_packet';
    $payload=$encode(['tenant_id'=>2002,'organization_id'=>'org_0369a63ff2cb','api_base'=>purepayApiBase(),
        'connection_id'=>(int)$connection['id'],'binding_hash'=>$binding['binding_hash'],'observations'=>$observations]);
    $path=TH_FIN_READ_PRIVATE.'/financial-observations.encrypted.private.json';
    if(is_link($path))throw new RuntimeException('Linked private packet');
    $existing=is_file($path);
    if(!$existing){
        // encryptField returns binary bytes; JSON must carry an explicit base64 envelope.
        $packet=json_encode(['captured_at'=>gmdate('c'),'encoding'=>'base64',
            'payload_ct'=>base64_encode(encryptField($payload))],JSON_THROW_ON_ERROR);
        $mask=umask(0077);
        try{$stream=fopen($path,'x');if(!$stream)throw new RuntimeException('Private packet already exists');
            try{if(fwrite($stream,$packet)!==strlen($packet)||!fflush($stream))throw new RuntimeException('Private packet write incomplete');}
            finally{fclose($stream);}
        }finally{umask($mask);}
    }
    $saved=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $cipher=($saved['encoding']??null)==='base64'&&is_string($saved['payload_ct']??null)
        ?base64_decode($saved['payload_ct'],true):false;
    if((fileperms($path)&0777)!==0600||$cipher===false||strlen($cipher)<28
        ||!hash_equals($payload,(string)decryptField($cipher)))throw new RuntimeException('Private encrypted evidence differs');
    if($protected()!==$before)throw new RuntimeException('Core business records changed');
    $report+=['passed'=>true,'tenant_id'=>2002,'observations'=>$results,'private_encrypted_packet_verified'=>true,
        'existing_packet_reused'=>$existing,'all_core_business_records_unchanged'=>true,
        'currency_reconciliation'=>'missing_source_currency','cash_delivery_proven'=>false];
}catch(Throwable $error){
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
