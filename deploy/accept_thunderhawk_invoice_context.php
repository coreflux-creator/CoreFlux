<?php
/** Capture the reviewed invoice projections with their original source labels; no staffing identity inference. */
declare(strict_types=1);
const TH_FIN_CAPTURE_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--capture-reviewed-invoice-context'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_FIN_CAPTURE_ROOT)exit(2);
foreach([
    'core/integrations/contracts/purepay_financial_observations_v2.json'=>'a624b41b01e4d7ac69a306e530735ec17655ff629a8fffbfe4468932f97686c6',
    'core/integrations/purepay_financial_observations.php'=>'cb63dbc88210c7887cec75bad9dd83fb08ccd2fe3dbd31097db8c04f6c943ba9',
    'core/integrations/purepay_financial_observation_contract.php'=>'4e03825870d755d5626fcb5fccaff8f1878f2e207ec0d3d200da7cf8e8e5f4d4',
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
    $expected=json_decode('[["invoice","inv_82de265509","446fa0484259ee3223482e57484c02fd66b23415ac25815db429a651b9f9ac86",22,0,"il_qbo_6019_21","itm_eb535696e3"],["invoice","inv_ea597af5df","e0d14abfb26cfc9d46d32d21e2346c16e29eec3aec0f7e23a05861baa5a13feb",24,0,"il_qbo_4155_21","itm_eb535696e3"],["invoice","inv_dcb25061c4","f6f5b0bc5a25a1e4ec196c8fbec4cbe8907927c0841707fd01921a65f17c7ebe",24,0,"il_qbo_3474_24","itm_eb535696e3"],["invoice","inv_191eb3675c","8107b861f558d6064248174737a46f3857b20ad73cee052142da56f04418ef2c",21,0,"il_qbo_6472_18","itm_eb535696e3"],["invoice","inv_0f1382bcb5","554f045e13a35d7001d8ec92c3b6e256ac57f4552124f90f6759d3de0f610a4f",21,0,"il_qbo_7101_16","itm_eb535696e3"]]',true,64,JSON_THROW_ON_ERROR);
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
        $q=$pdo->prepare('SELECT * FROM purepay_financial_observations WHERE NOT(tenant_id=2002 AND document_id IN (?,?,?,?,?)) ORDER BY id');
        $q->execute(['inv_82de265509','inv_ea597af5df','inv_dcb25061c4','inv_191eb3675c','inv_0f1382bcb5']);
        $all['other_financial_captures']=hash('sha256',serialize($q->fetchAll(PDO::FETCH_ASSOC)));
        return hash('sha256',serialize($all));
    };
    $before=$protected();$results=[];$phase='read_and_capture';
    $audit=static function(array $meta)use($pdo):void {
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(2002,4,"purepay.financial_observation.saved",?,?,NOW())')
            ->execute([$meta['capture_id'],json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    foreach($expected as[$kind,$id,$hash,$lines,$payments,$selectedLine,$item]) {
        $read=purepayFinancialObservationRead($pdo,2002,$instance,$kind,$id);
        if($read['observation']['observation_hash']!==$hash||$read['observation']['document']['currency']!==null
            ||count($read['observation']['document']['line_items'])!==$lines||count($read['observation']['outgoing_payments'])!==$payments
            ||$read['comparison']['core_record']!==null||$read['comparison']['ledger_posted']!==false)throw new RuntimeException('Reviewed source differs');
        $selected=array_values(array_filter($read['observation']['document']['line_items'],static fn($v)=>$v['item_id']===$item));
        if($read['observation']['contract_version']!=='2026-10-30.2'||count($selected)!==1||$selected[0]['line_id']!==$selectedLine
            ||!is_string($selected[0]['item_name'])||!is_string($selected[0]['description'])
            ||$selected[0]['assignment_id']!==null||$selected[0]['employee_id']!==null
            ||$selected[0]['service_start']!==null||$selected[0]['service_end']!==null)throw new RuntimeException('Selected source context differs');
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
            'source_labels_retained'=>true,'selected_line_id'=>$selectedLine,'selected_item_id'=>$item,'service_dates_unknown'=>true,
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
