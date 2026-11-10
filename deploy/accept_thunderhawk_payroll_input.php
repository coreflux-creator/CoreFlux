<?php
/** Review-only input and source reservation for eight isolated test hours. */
declare(strict_types=1);
const TH_PAY_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const TH_PAY_INSTANCE='9f4b6dfb-e52e-464c-9a9d-af7766928ddf';
const TH_PAY_ASSIGNMENT='asg_core_329879a277eb3fb0398832357ad55998706add368dad8c75386e2058745ba2d6';
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--reserve-isolated-test-hours'||getenv('COREFLUX_ENV')!=='staging'
    ||realpath(getcwd())!==TH_PAY_ROOT)exit(2);
require_once TH_PAY_ROOT.'/core/integrations/purepay_payroll_reservation_review.php';
require_once TH_PAY_ROOT.'/core/integrations/purepay_assignment_inputs.php';
require_once TH_PAY_ROOT.'/core/sub_tenants.php';
require_once TH_PAY_ROOT.'/core/rbac/permissions.php';
$pdo=getDB();
if(!$pdo||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING
    ||integrationInstanceId()!==TH_PAY_INSTANCE||purepayCoreApiBase()!=='https://purepay.online:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1
    ||!RBACResolver::can(4,2002,'payroll','admin')||!RBACResolver::can(4,2002,'integrations','admin'))exit(2);
$report=['checked_at'=>gmdate('c'),'tenant_id'=>2002,'mode'=>'isolated_test_hours',
    'production_changed'=>false,'payroll_execution_requested'=>false,'financial_posting_requested'=>false];
$phase='guard';$flags=[];
try{
    setRequestTenantId(2002);$_SESSION['user']=['id'=>4,'role'=>'tenant_admin','tenant_id'=>2002];
    foreach(['COREFLUX_PUREPAY_CORE_LINK_DELIVERY','COREFLUX_PUREPAY_PAYROLL_INPUT_DELIVERY','COREFLUX_PUREPAY_PAYROLL_AUTHORITY'] as $flag){$flags[$flag]=getenv($flag);putenv($flag.'=1');}
    $owners=[];foreach(['time','people','placements','companies','ap'] as $module){
        $owners[$module]=effectiveTenantIdForModule($module,2002);
        if($owners[$module]!==2002)throw new RuntimeException('Owner differs');
    }
    $audit=static function(string $event='integration.pilot.receipt',array $meta=[])use($pdo):void{
        $meta+=['synthetic'=>true,'payroll_execution_requested'=>false];
        $pdo->prepare('INSERT INTO audit_log(tenant_id,actor_user_id,event,target_id,meta_json,ip_address,created_at) VALUES(2002,4,?,NULL,?,NULL,NOW())')
            ->execute([$event,json_encode($meta,JSON_THROW_ON_ERROR)]);
    };
    $timeBefore=$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC);
    if(count($timeBefore)!==1||(int)$timeBefore[0]['id']!==2||$timeBefore[0]['status']!=='approved'
        ||(int)$timeBefore[0]['approved_by_user_id']!==52||(float)$timeBefore[0]['hours']!==8.0)throw new RuntimeException('Approved test hours differ');
    $phase='assignment_reconcile';
    $delivery=$pdo->query('SELECT review_hash FROM integration_deliveries WHERE tenant_id=2002 AND id=29')->fetch(PDO::FETCH_ASSOC);
    $assignment=purepayAssignmentInputStatus($pdo,2002,TH_PAY_INSTANCE,array_intersect_key($owners,array_flip(['placements','people','companies','ap'])),29,$delivery['review_hash'],$audit);
    if($assignment['provider_review']['state']!=='created'||$assignment['provider_review']['assignment_id']!==TH_PAY_ASSIGNMENT
        ||$assignment['provider_review']['assignment_status']!=='active')throw new RuntimeException('Native assignment differs');
    $report['provider_assignment']=$assignment['provider_review'];
    $link=purepayCoreLinkPreview($pdo,2002,4,TH_PAY_INSTANCE,2002,'placement','PL-864',TH_PAY_ASSIGNMENT);
    if(!$link['existing_provider_link'])throw new RuntimeException('Native assignment link is missing');
    $d=$link['delivery'];
    $linked=in_array($d['state'],['sending','uncertain'],true)
        ?purepayCoreLinkReconcile($pdo,2002,TH_PAY_INSTANCE,2002,(int)$d['id'],$d['review_hash'],$audit)
        :purepayCoreLinkSend($pdo,2002,4,TH_PAY_INSTANCE,2002,(int)$d['id'],$d['review_hash'],$audit);
    if($linked['state']!=='delivered')throw new RuntimeException('Assignment link not recorded');
    $report['assignment_link_delivery_id']=(int)$d['id'];
    $phase='input_review';$payOwners=array_intersect_key($owners,array_flip(['time','people','placements']));
    $p=purepayPayrollInputPreview($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,'P-358','2026-10-19','2026-10-25');
    $c=$p['packet']['components'][0]??[];
    if($p['organization']['id']!=='org_0369a63ff2cb'||count($p['packet']['components'])!==1
        ||$c['source']['time_entry_id']!==2||$c['source']['hours']!=='8.00'||$c['source']['source_workspace_id']!=='4f93d4e7-af74-4765-b812-3464bb6d974b'
        ||$c['source']['source_entry_id']!=='ad44e619-7f34-4f8e-a008-1cd1bf33992e'||$c['employee_id']!=='emp_ea232efec736'
        ||$c['assignment_id']!==TH_PAY_ASSIGNMENT||$c['rate']['pay_rate']!=='65.0000'||$c['rate']['currency']!=='USD')throw new RuntimeException('Payroll packet differs');
    $d=$p['delivery'];$sent=purepayPayrollInputSend($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$audit);
    if($sent['state']!=='delivered'||purepayPayrollInputSend($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$audit)!==$sent)throw new RuntimeException('Input replay differs');
    $report['input_delivery_id']=(int)$d['id'];$report['input_id']=$sent['remote_id'];$report['input_replay_verified']=true;
    $phase='reserve_source';$request=hash('sha256','thunderhawk-2002-test-time-2-reservation-20261030');
    $authority=purepayPayrollReservationReserve($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$request,$audit);
    if($authority['state']!=='held'||$authority['input_id']!==$sent['remote_id'])throw new RuntimeException('Reservation differs');
    if(purepayPayrollReservationReserve($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$request,$audit)!==$authority)throw new RuntimeException('Reservation replay differs');
    $receipt=purepayPayrollReservationAct($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$authority['id'],'send',$audit);
    $confirmed=purepayPayrollReservationAct($pdo,2002,4,TH_PAY_INSTANCE,$payOwners,(int)$d['id'],$d['review_hash'],$authority['id'],'reconcile',$audit);
    if($receipt['authority']['state']!=='held'||$receipt['provider_receipt']!==$confirmed['provider_receipt'])throw new RuntimeException('Reservation reconciliation differs');
    $report['authority_id']=$authority['id'];$report['provider_receipt']=$confirmed['provider_receipt'];
    if($timeBefore!==$pdo->query('SELECT * FROM time_entries WHERE tenant_id=2002')->fetchAll(PDO::FETCH_ASSOC))throw new RuntimeException('Reservation changed source time');
    $report['source_time_unchanged']=true;$report['passed']=true;
}catch(Throwable $error){
    if($pdo->inTransaction())$pdo->rollBack();
    $report['passed']=false;$report['failed_phase']=$phase;$report['failure_class']=get_class($error);
    $report['failure_source']=basename($error->getFile());$report['failure_line']=$error->getLine();
}finally{
    foreach($flags as $name=>$value)putenv($value===false?$name:$name.'='.$value);
    unset($_SESSION['user']);
}
echo json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed']?0:1);
