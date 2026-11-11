<?php
/** Canonical disposable database only; all synthetic changes are rolled back. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/ownership.php';
require_once __DIR__.'/../modules/placements/lib/end.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$failures=[];
$check=static function(bool $ok,string $message)use(&$checks,&$failures):void{
    if($ok){$checks++;echo "PASS $message\n";}else{$failures[]=$message;echo "FAIL $message\n";}
};
$refused=static function(callable $call,int $code):bool{try{$call();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Lifecycle','last_name'=>'Release','email_primary'=>'ci-lifecycle-release@example.invalid','classification'=>'w2']);
    $placement=scopedInsert('placements',['person_id'=>$person,'status'=>'active','start_date'=>'2026-11-01','end_date'=>'2026-12-01','engagement_type'=>'w2','title'=>'CI census lifecycle','external_id'=>'jd:99120001','notes'=>'Core-owned note']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-11-01 12:00:00']);
    $rates=$read('placement_rates');$people=$read('people');
    $terminal=['startId'=>'99120001','__cf_jobdiva_canonical_status'=>'ended','endDate'=>'2026-11-10'];
    foreach(['status','end_date','actual_end_date'] as $field){
        scopedUpdate('placements',$placement,['status'=>'active','end_date'=>'2026-12-01','coreflux_overridden_fields'=>json_encode([$field,'notes'])]);
        $before=placementAuditRow($placement);$audits=$read('jobdiva_sync_audit');$graph=$read('placement_economic_parties');
        $result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[$terminal]);
        $check($result['updated']===0&&($result['skipped_reasons']['core_owned_lifecycle']??0)===1&&$result['failed']===0
            &&placementAuditRow($placement)===$before&&$read('jobdiva_sync_audit')===$audits&&$read('placement_economic_parties')===$graph,
            'Terminal census preserves Core ownership of '.$field.' and reports the conflict');
    }
    scopedUpdate('placements',$placement,['status'=>'active','coreflux_overridden_fields'=>'{}']);
    $before=placementAuditRow($placement);$result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[$terminal]);
    $check($result['failed']===1&&$result['updated']===0&&placementAuditRow($placement)===$before,'Malformed ownership blocks terminal census without writes');
    scopedUpdate('placements',$placement,['status'=>'active','end_date'=>'2026-12-01','coreflux_overridden_fields'=>'["notes"]']);
    $result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[$terminal]);$row=placementAuditRow($placement);
    $check($result['updated']===1&&$result['ended']===1&&$row['status']==='ended'&&$row['end_date']==='2026-11-10'
        &&$row['notes']==='Core-owned note'&&$row['external_id']==='jd:99120001','Source-owned terminal lifecycle updates the exact existing placement');
    $worker=$pdo->prepare("SELECT effective_to FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND role='worker'");$worker->execute([$tenant,$placement]);
    $check($worker->fetchColumn()==='2026-11-10','Terminal census reconciles the commercial period with the source date');
    $audits=$read('jobdiva_sync_audit');$graph=$read('placement_economic_parties');
    $result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[$terminal]);
    $check($result['updated']===0&&placementAuditRow($placement)===$row&&$read('jobdiva_sync_audit')===$audits&&$read('placement_economic_parties')===$graph,'Terminal census replay preserves lifecycle, graph and audit');
    scopedUpdate('placements',$placement,['status'=>'active']);
    foreach(['not-a-date','2026-10-31'] as $date){
        $before=placementAuditRow($placement);$result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[array_replace($terminal,['endDate'=>$date])]);
        $check($result['failed']===1&&placementAuditRow($placement)===$before,'Invalid terminal date is refused: '.$date);
    }
    $before=placementAuditRow($placement);$result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant+1000000,[$terminal]);
    $check($result['updated']===0&&placementAuditRow($placement)===$before,'Terminal census cannot resolve a placement in another tenant');
    $company=companiesUpsertByName($tenant,'CI census retired company',[],['client']);
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$tenant,$company]);
    scopedUpdate('placements',$placement,['end_client_company_id'=>$company]);
    $before=placementAuditRow($placement);$audits=$read('jobdiva_sync_audit');$graph=$read('placement_economic_parties');
    $result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[$terminal]);
    $check($result['failed']===1&&$result['updated']===0&&$pdo->inTransaction()&&placementAuditRow($placement)===$before
        &&$read('jobdiva_sync_audit')===$audits&&$read('placement_economic_parties')===$graph,'Commercial graph failure rolls back the terminal source update and retains the caller transaction');
    scopedUpdate('placements',$placement,['end_client_company_id'=>null,'status'=>'active','coreflux_overridden_fields'=>'["notes"]']);
    placementEnd($placement,['actual_end_date'=>'2026-11-10']);
    $closed=placementAuditRow($placement);$audit=$read('audit_log');
    foreach(['status','actual_end_date'] as $field){
        $check($refused(fn()=>placementClearOverrides($placement,[$field]),422)&&placementAuditRow($placement)===$closed&&$read('audit_log')===$audit,
            'A partial closure ownership release is refused for '.$field);
    }
    $result=placementClearOverrides($placement,['notes']);
    $check($result['changed']&&$result['released_fields']===['notes']&&json_decode(placementAuditRow($placement)['coreflux_overridden_fields'],true)===['status','actual_end_date'],
        'Unrelated field ownership can be released without releasing the closure');
    $result=placementClearOverrides($placement,['status','actual_end_date']);$released=placementAuditRow($placement);
    $check($result['changed']&&$released['coreflux_overridden_fields']===null&&$released['status']==='ended'&&$released['actual_end_date']==='2026-11-10',
        'Explicit paired release changes ownership without changing lifecycle values');
    $last=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.override_cleared' ORDER BY id DESC LIMIT 1");$last->execute([$tenant,$placement]);$meta=json_decode($last->fetchColumn(),true);
    $check(($meta['before']['coreflux_overridden_fields']??[])===['status','actual_end_date']&&($meta['after']['coreflux_overridden_fields']??null)===[]&&isset($meta['ownership_change_id']),
        'The release audit retains exact before and after ownership');
    $audit=$read('audit_log');$result=placementClearOverrides($placement,['status','actual_end_date']);
    $check(!$result['changed']&&placementAuditRow($placement)===$released&&$read('audit_log')===$audit,'Repeated ownership release is a no-op');
    foreach([[],['status',null],['status'=>true]] as $fields)$check($refused(fn()=>placementClearOverrides($placement,$fields),422),'Malformed ownership release input is refused');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);$before=placementAuditRow($placement);
    $check($refused(fn()=>placementClearOverrides($placement,['notes']),422)&&placementAuditRow($placement)===$before,'Malformed stored ownership cannot be silently cleared');
    setRequestTenantId($tenant+1000000);$_SESSION['tenant_id']=$tenant+1000000;
    $check($refused(fn()=>placementClearOverrides($placement,['notes']),404),'Cross-tenant ownership release is refused');
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'["notes"]']);$before=placementAuditRow($placement);
    // Temporary DDL does not commit the fixture transaction. This deliberately
    // incomplete table simulates unavailable audit storage without altering it.
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>placementClearOverrides($placement,['notes']),503)&&placementAuditRow($placement)===$before&&$pdo->inTransaction(),
        'Audit failure rolls back ownership release');}finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    // The second connection must wait on the same row, not read stale ownership.
    $main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{placementClearOverrides($placement,['notes']);$check(false,'A competing ownership write waits for the row lock');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'A competing ownership write waits for the row lock and rolls back on timeout');}
    finally{$pdo=$main;$second=null;}
    $check(placementAuditRow($placement)===$before&&$pdo->inTransaction(),'The first transaction retains its placement ownership after contention');
    $check($read('placement_rates')===$rates&&$read('people')===$people,'Lifecycle and ownership changes preserve people and approved rates');
    $check([$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')]===$financial,'Lifecycle and ownership changes create no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
if($failures)throw new RuntimeException(implode('; ',$failures));
echo "$checks placement lifecycle ownership checks passed.\n";
