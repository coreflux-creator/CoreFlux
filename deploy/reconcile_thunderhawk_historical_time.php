<?php
/** Read-only native payroll packet versus retained paid history; no delivery or new run. */
declare(strict_types=1);
const TH_REC_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_REC_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--compare-historical-pilot-time'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_REC_ROOT)exit(2);
require_once TH_REC_ROOT.'/core/integrations/purepay_observations.php';
require_once TH_REC_ROOT.'/core/quanta/client.php';
require_once TH_REC_ROOT.'/core/sub_tenants.php';
require_once TH_REC_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!==TH_REC_INSTANCE||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND parent_id IS NULL AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'payroll','read')||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'person_number'=>'P-358','placement_number'=>'PL-864',
    'mode'=>'read_only_historical_pilot_reconciliation','production_changed'=>false,'new_payroll_run'=>false,
    'payroll_input_delivered'=>false,'source_reserved'=>false,'financial_postings'=>false,
    'cash_delivery_proven'=>false,'independent_human_review'=>false];$phase='preflight';
try{
    setRequestTenantId(2002);$owners=[];
    foreach(['people','placements','time']as$m){$owners[$m]=effectiveTenantIdForModule($m,2002);if($owners[$m]!==2002)throw new RuntimeException('Owner differs');}
    $protected=static function()use($pdo):array{
        $out=[];
        foreach(['people','placements','placement_rates','time_entries','staffing_timesheets','quanta_time_imports',
            'payroll_source_authorities','payroll_provider_results','payroll_runs','payroll_line_items','integration_deliveries',
            'ap_bills','ap_payments','billing_invoices','billing_payments','accounting_journal_entries','accounting_journal_entry_lines']as$t){
            $rows=array_map('serialize',$pdo->query('SELECT * FROM '.$t)->fetchAll(PDO::FETCH_ASSOC));sort($rows,SORT_STRING);
            $out[$t]=['count'=>count($rows),'sha256'=>hash('sha256',serialize($rows))];
        }return$out;
    };
    $before=$protected();$phase='native_input_snapshot';
    $snapshot=purepayPayrollInputSnapshot($pdo,2002,TH_REC_INSTANCE,$owners,'P-358','2026-10-05','2026-10-18');
    $packet=$snapshot['body'];
    if($snapshot['access']['review']['organization']['id']!=='org_0369a63ff2cb'||count($packet['components'])!==10)throw new RuntimeException('Native packet identity differs');
    $ids=[];$dates=[];
    foreach($packet['components']as$c){
        if($c['person']['number']!=='P-358'||$c['placement']['number']!=='PL-864'||$c['employee_id']!=='emp_ea232efec736'
            ||$c['assignment_id']!=='asg_core_329879a277eb3fb0398832357ad55998706add368dad8c75386e2058745ba2d6'
            ||$c['source']['hours']!=='8.00'||$c['source']['category']!=='regular_billable'||$c['source']['source_system']!=='quanta'
            ||$c['source']['source_workspace_id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
            ||$c['rate']['pay_rate']!=='65.0000'||$c['rate']['currency']!=='USD'||$c['rate']['multiplier']!=='1.00')throw new RuntimeException('Frozen pay or original source differs');
        $id=$c['source']['time_entry_id'];$ids[]=$id;$dates[]=$c['source']['work_date'];
        $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=2002 AND actor_user_id=4 AND event='time.pilot.historical_source' AND target_id=?");
        $q->execute([$id]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);$a=count($rows)===1?json_decode($rows[0],true):[];
        if(($a['source_sha256']??null)!=='38a576b558d80bc63a65ec5472680c45ae5ba65744fda98109e4092881ee5ac8'
            ||$a['source_entry_id']!==$c['source']['source_entry_id']||$a['already_paid_source_period']!==true)throw new RuntimeException('Workbook source evidence differs');
    }
    if($ids!==range(4,13)||$dates!==array_map(static fn($d)=>'2026-10-'.$d,['05','06','07','08','09','12','13','14','15','16']))throw new RuntimeException('Daily entry set differs');
    $phase='existing_provider_history';
    $read=purepayObservationRead($pdo,2002,TH_REC_INSTANCE,$owners,'run_54dde5d17c05');$data=$read['observation'];
    if($data['status']!=='paid'||$data['source']!=='provider_history'||$data['pay_date']!=='2026-10-23'
        ||$data['period_start']!=='2026-10-05'||$data['period_end']!=='2026-10-18')throw new RuntimeException('Existing paid period differs');
    $lines=array_values(array_filter($data['lines'],static fn($l)=>($l['employee_id']??null)==='emp_ea232efec736'));
    if(count($lines)!==1)throw new RuntimeException('Existing employee line is ambiguous');$line=$lines[0];
    $report['provider_line']=['hours'=>$line['hours'],'ot_hours'=>$line['ot_hours'],'gross'=>$line['amounts']['gross']??null,
        'net'=>$line['amounts']['net']??null,'source_time_lineage'=>$line['source_time_lineage']['status']];
    if((float)$line['hours']!==80.0||($line['ot_hours']!==null&&(float)$line['ot_hours']!==0.0)
        ||(float)($line['amounts']['gross']??-1)!==5200.0)throw new RuntimeException('Hours or gross do not reconcile');
    if($protected()!==$before)throw new RuntimeException('Read-only comparison changed protected records');
    $report+=['passed'=>true,'core_entry_ids'=>$ids,'hours'=>80,'hour_difference'=>0,'core_pay_rate'=>'65.0000',
        'core_currency'=>'USD','core_expected_gross'=>'5200.00','gross_numeric_difference'=>'0.00',
        'provider_currency_explicit'=>isset($data['currency'])&&$data['currency']!==null,
        'provider_overtime_hours_explicit'=>$line['ot_hours']!==null,
        'provider_original_source_time_ids_proven'=>false,'original_client_approval_stamp_present'=>false,
        'original_paid_run_id'=>'run_54dde5d17c05','pay_date'=>'2026-10-23',
        'native_packet_sha256'=>integrationDeliveryHash($packet),'native_packet_components'=>10,
        'packet_built_in_memory_only'=>true,'provider_observation_hash'=>$data['observation_hash'],
        'provider_observation_environment'=>'isolated pilot retained history','protected_records_unchanged'=>true];
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();$report['passed']=false;$report['failed_phase']=$phase;
    $report['failure_class']=get_class($e);$report['failure_source']=basename($e->getFile());$report['failure_line']=$e->getLine();
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";exit($report['passed']?0:1);
