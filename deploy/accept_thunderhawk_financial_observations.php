<?php
/** Capture the previously reviewed historical AP/AR projection through the native product. */
declare(strict_types=1);
const TH_FIN_CAPTURE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--capture-reviewed-financial-observations'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_FIN_CAPTURE_ROOT)exit(2);
foreach([
    'core/integrations/purepay_financial_observations.php'=>'cb63dbc88210c7887cec75bad9dd83fb08ccd2fe3dbd31097db8c04f6c943ba9',
    'core/integrations/purepay_financial_observation_contract.php'=>'d820afc10ab724c2520f18c5a6bf421370eeaf3c4ff77c355e3dc074e8c7cc6b',
    'core/integrations/contracts/purepay_financial_observations.json'=>'53305c2fefe008da8f6f42e2ca7e2f4c9d7314a63f2fabaf552ce0276a397087',
    'core/migrations/183_purepay_financial_observations.sql'=>'fba9c9b3cd5dd1639af06ace53421513ea3ae6462935a398a6f0e1fc9a06b52f',
] as $file=>$hash)if(!is_file(TH_FIN_CAPTURE_ROOT.'/'.$file) || hash('sha256',str_replace("\r\n","\n",file_get_contents(TH_FIN_CAPTURE_ROOT.'/'.$file)))!==$hash)exit(2);
require_once TH_FIN_CAPTURE_ROOT.'/core/integrations/purepay_financial_observations.php';
require_once TH_FIN_CAPTURE_ROOT.'/core/integrations/identity.php';
require_once TH_FIN_CAPTURE_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();$instance=integrationInstanceId();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||COREFLUX_STAGING!==true
    ||$instance!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
    ||purepayApiBase()!=='https://purepay.online:19443/api/v1'||purepayFinancialPreviewTenant()!==2002
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'accounting','admin')||!RBACResolver::can(4,2002,'ap','read')
    ||!RBACResolver::can(4,2002,'billing','read'))exit(2);
$report=['checked_at'=>gmdate('c'),'production_changed'=>false,'financial_postings'=>false,
    'payments_or_payroll_executed'=>false];$phase='guard';
try {
    $expected=[['bill','pay_041311ec10','b40b11b2fc5a9994fc9a446af58b265938b53e1d4e2047e08bef625016a39a76',2,2],
        ['invoice','inv_05611deda7','e1d8f1cfa03c03efb7ea2f98c8f6b4bfecad157a563f0818dbde33fdae6c8964',42,0],
        ['invoice','inv_13c40a9026','4939f0aa1d1d0f129a21e4c0346e3f54699adffd70b317ff30098f5572dc9455',1,0]];
    $protected=static function()use($pdo):string {
        $all=[];
        foreach(['people','placements','placement_rates','staffing_clients','time_entries','staffing_timesheets',
            'payroll_runs','payroll_line_items','payroll_provider_results','payroll_native_observations',
            'ap_bills','ap_payments','accounting_journal_entries','accounting_journal_entry_lines',
            'billing_invoices','billing_payments','purepay_connections','purepay_financial_bindings',
            'purepay_financial_organizations','purepay_payment_links','purepay_webhook_events']as$table){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC));
            sort($rows,SORT_STRING);$all[$table]=hash('sha256',serialize($rows));
        }
        $q=$pdo->prepare('SELECT * FROM purepay_financial_observations WHERE NOT(tenant_id=2002 AND document_id IN (?,?,?)) ORDER BY id');
        $q->execute(['pay_041311ec10','inv_05611deda7','inv_13c40a9026']);
        $all['other_financial_captures']=hash('sha256',serialize($q->fetchAll(PDO::FETCH_ASSOC)));
        return hash('sha256',serialize($all));
    };
    $before=$protected();$results=[];$phase='read_and_capture';
    $audit=static function(array $meta)use($pdo):void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(2002,4,"purepay.financial_observation.saved",?,?,NOW())')
            ->execute([$meta['capture_id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    foreach($expected as[$kind,$id,$hash,$lines,$payments]) {
        $read=purepayFinancialObservationRead($pdo,2002,$instance,$kind,$id);
        if($read['observation']['observation_hash']!==$hash||$read['observation']['document']['currency']!==null
            ||count($read['observation']['document']['line_items'])!==$lines||count($read['observation']['outgoing_payments'])!==$payments
            ||$read['comparison']['core_record']!==null||$read['comparison']['ledger_posted']!==false)throw new RuntimeException('Reviewed source differs');
        $saved=purepayFinancialObservationSave($pdo,2002,4,$instance,$kind,$id,null,$read['review_hash'],$audit);
        $fresh=purepayFinancialObservationRead($pdo,2002,$instance,$kind,$id);
        $replay=purepayFinancialObservationSave($pdo,2002,4,$instance,$kind,$id,null,$fresh['review_hash'],$audit);
        $history=purepayFinancialObservationHistory($pdo,2002,$instance,$kind,$id);
        $q=$pdo->prepare('SELECT payload_ct FROM purepay_financial_observations WHERE tenant_id=2002 AND id=?');$q->execute([$saved['id']]);$ct=$q->fetchColumn();
        if(!$replay['replayed']||$replay['id']!==$saved['id']||count($history['items'])!==1||$history['next_before']!==null
            ||$history['items'][0]['id']!==$saved['id']||str_contains($ct,$id)
            ||json_decode(decryptField($ct),true,64,JSON_THROW_ON_ERROR)['observation']['observation_hash']!==$hash)throw new RuntimeException('Native encrypted history differs');
        $results[]=['kind'=>$kind,'document_id'=>$id,'capture_id'=>$saved['id'],'existing_capture_reused'=>$saved['replayed'],
            'replay_reused'=>true,'observation_hash'=>$hash,'line_count'=>$lines,'outgoing_payment_count'=>$payments,
            'currency_missing'=>true,'core_identity_unlinked'=>true,'encrypted_readback_verified'=>true,'history_verified'=>true];
    }
    $phase='isolation';$denied=0;
    foreach([2,999,2003] as $other) {
        try{purepayFinancialObservationRead($pdo,$other,$instance,'bill','pay_041311ec10');}
        catch(PurePayApiException $e){$denied++;}
    }
    if($denied!==3||$protected()!==$before)throw new RuntimeException('Protected business state differs');
    $report+=['passed'=>true,'tenant_id'=>2002,'captures'=>$results,'other_company_reads_denied'=>$denied,
        'all_protected_business_records_unchanged'=>true,'cash_delivery_proven'=>false,'staff_browser_acceptance'=>false];
}catch(Throwable $e){$report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($e);
    $report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
