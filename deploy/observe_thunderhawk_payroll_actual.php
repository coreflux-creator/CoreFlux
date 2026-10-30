<?php
/** Existing provider history -> encrypted Core observation; never execute or post. */
declare(strict_types=1);
const TH_ACTUAL_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_ACTUAL_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const TH_ACTUAL_RUN='run_54dde5d17c05';
const TH_ACTUAL_HASH='504056f9de9b1e06dbf41a63875a3126cada7aa4425bf59fe06c819ed82c023a';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--capture-existing-payroll-observation'
    ||getenv('COREFLUX_ENV')!=='staging'||realpath(getcwd())!==TH_ACTUAL_ROOT)exit(2);
require_once TH_ACTUAL_ROOT.'/core/integrations/purepay_observations.php';
require_once TH_ACTUAL_ROOT.'/core/sub_tenants.php';
require_once TH_ACTUAL_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!==TH_ACTUAL_INSTANCE||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'payroll','read')||!RBACResolver::can(4,2002,'payroll','admin')
    ||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'run_id'=>TH_ACTUAL_RUN,
    'production_changed'=>false,'payroll_executed'=>false,'financial_postings'=>false];$phase='guard';
try{
    setRequestTenantId(2002);$owners=[];
    foreach(['people','placements','time'] as $module){
        $owners[$module]=effectiveTenantIdForModule($module,2002);
        if($owners[$module]!==2002)throw new RuntimeException('Owner changed');
    }
    $protected=static function()use($pdo):array{
        $result=[];
        foreach(['people','placements','placement_rates','staffing_clients','time_entries','staffing_timesheets',
                 'payroll_runs','payroll_line_items','payroll_provider_results','ap_bills','ap_payments',
                 'accounting_journal_entries','accounting_journal_entry_lines','billing_invoices','billing_payments']as$table){
            $rows=$pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $values=array_map('serialize',$rows);sort($values,SORT_STRING);
            $result[$table]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($values))];
        }
        $q=$pdo->prepare('SELECT * FROM payroll_native_observations WHERE NOT(tenant_id=2002 AND run_id=?) ORDER BY id');
        $q->execute([TH_ACTUAL_RUN]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $result['other_native_observations']=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        return$result;
    };
    $before=$protected();$phase='native_review';
    $read=purepayObservationRead($pdo,2002,TH_ACTUAL_INSTANCE,$owners,TH_ACTUAL_RUN);
    $data=$read['observation'];
    if($data['organization_id']!=='org_0369a63ff2cb'||$data['observation_hash']!==TH_ACTUAL_HASH
        ||$data['status']!=='paid'||$data['source']!=='provider_history'||$data['pay_date']!=='2026-10-23'
        ||count($data['lines'])!==18||$read['comparison']['ledger_posted']!==false
        ||$read['comparison']['cash_delivery']!=='not_evaluated')throw new RuntimeException('Source observation differs');
    $people=$read['comparison']['people'];$linked=array_values(array_filter($people,static fn($p)=>$p['link_status']==='linked'));
    if(count($people)!==18||count($linked)!==1||$linked[0]['employee_id']!=='emp_ea232efec736'
        ||$linked[0]['person']['number']!=='P-358'||$linked[0]['person']['directory_tenant_id']!==2002
        ||count(array_filter($people,static fn($p)=>$p['link_status']==='unlinked'))!==17)
        throw new RuntimeException('Existing person match or explicit unmatched population differs');
    $audit=static function()use($pdo):void{
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,created_at) VALUES(2002,4,"purepay.core.payroll_observation_save",NULL,?,NOW())')
            ->execute([json_encode(['run_id'=>TH_ACTUAL_RUN,'existing_provider_history'=>true,'observation_only'=>true],JSON_THROW_ON_ERROR)]);
    };
    $phase='encrypted_capture';
    $saved=purepayObservationSave($pdo,2002,4,TH_ACTUAL_INSTANCE,$owners,TH_ACTUAL_RUN,$read['review_hash'],$audit);
    $again=purepayObservationSave($pdo,2002,4,TH_ACTUAL_INSTANCE,$owners,TH_ACTUAL_RUN,$read['review_hash'],$audit);
    if(!$again['replayed']||$again['id']!==$saved['id'])throw new RuntimeException('Observation replay created another record');
    $q=$pdo->prepare('SELECT payload_ct FROM payroll_native_observations WHERE tenant_id=2002 AND id=?');
    $q->execute([$saved['id']]);$cipher=$q->fetchColumn();
    if(!is_string($cipher)||str_contains($cipher,'"gross"')
        ||(json_decode((string)decryptField($cipher),true)['observation']['observation_hash']??null)!==TH_ACTUAL_HASH)
        throw new RuntimeException('Encrypted observation differs');
    $phase='protected_state';
    if($protected()!==$before)throw new RuntimeException('Observation modified protected business records');
    $totals=[];
    foreach($read['comparison']['totals']as$field=>$value){
        $totals[$field]=['status'=>$value['status'],'missing_lines'=>$value['missing_lines']];
    }
    $lineage=[];
    foreach($data['lines']as$line)$lineage[$line['source_time_lineage']['status']]=($lineage[$line['source_time_lineage']['status']]??0)+1;
    $report+=['passed'=>true,'observation_id'=>$saved['id'],'observation_hash'=>TH_ACTUAL_HASH,
        'source_status'=>'paid','source'=>'provider_history','period_start'=>$data['period_start'],'period_end'=>$data['period_end'],
        'pay_date'=>$data['pay_date'],'lines_preserved'=>18,'people_linked'=>1,'people_unlinked'=>17,
        'linked_person_number'=>'P-358','totals_comparison'=>$totals,'source_lineage_status_counts'=>$lineage,
        'unavailable_metadata'=>$data['unavailable_fields'],'cash_delivery_proven'=>false,'source_time_matched'=>false,
        'replay_reuses_observation'=>true,'encrypted_observation_verified'=>true,
        'all_tenant_protected_business_sets_unchanged'=>true,'protected'=>$before];
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
