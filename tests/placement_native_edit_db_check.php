<?php
/** Real native edit paths against a disposable database; all business rows roll back. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
require_once __DIR__.'/../core/RBAC.php';
require_once __DIR__.'/../core/rbac/legacy_map.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$user=['id'=>(int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn(),'role'=>'admin'];
$oldMode=getenv('CF_RBAC_BRIDGE_MODE');putenv('CF_RBAC_BRIDGE_MODE=legacy');
$checks=0;$failures=[];
$check=static function(bool $ok,string $message)use(&$checks,&$failures):void{
    if($ok){$checks++;echo "PASS $message\n";}else{$failures[]=$message;echo "FAIL $message\n";}
};
$refused=static function(callable $call,int $code):bool{try{$call();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static fn(string $table):array=>getDB()->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Native','last_name'=>'Owner','email_primary'=>'ci-native-owner@example.invalid','classification'=>'w2']);
    $placement=scopedInsert('placements',['person_id'=>$person,'status'=>'on_hold','start_date'=>'2026-11-01','end_date'=>'2026-12-01','engagement_type'=>'w2','title'=>'CI native edit','external_id'=>'jd:99130001','coreflux_overridden_fields'=>'["notes"]','notes'=>'Core-owned note']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-11-01 12:00:00']);
    $rates=$read('placement_rates');$people=$read('people');
    $before=placementAuditRow($placement);
    $result=placementApplyNativeEdit($placement,['title'=>'Core reviewed title'],$user,'patch_status');$row=placementAuditRow($placement);
    $check($result['changed']&&$row['title']==='Core reviewed title'&&json_decode($row['coreflux_overridden_fields'],true)===['notes','title'],'PATCH edits the value and merges ownership without losing prior flags');
    $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.updated' ORDER BY id DESC LIMIT 1");$q->execute([$tenant,$placement]);$meta=json_decode($q->fetchColumn(),true);
    $check(($meta['before']??null)===$before&&($meta['after']??null)===$row&&$meta['via']==='patch_status'&&isset($meta['ownership_change_id']),'Native audit records exact before and after values and operation');
    $audit=$read('audit_log');$graph=$read('placement_economic_parties');
    $result=placementApplyNativeEdit($placement,['title'=>'Core reviewed title'],$user,'patch_status');
    $check(!$result['changed']&&placementAuditRow($placement)===$row&&$read('audit_log')===$audit&&$read('placement_economic_parties')===$graph,'Repeated native field edit changes no record, audit or commercial party');
    $result=placementApplyNativeEdit($placement,['status'=>'on_hold'],$user,'bulk_status');$row=placementAuditRow($placement);
    $check($result['changed']&&in_array('status',json_decode($row['coreflux_overridden_fields'],true),true),'Selecting the existing status still establishes explicit Core ownership');
    $result=placementApplyNativeEdit($placement,['worksite_city'=>'Hamilton'],$user,'bulk_update');$row=placementAuditRow($placement);
    $check($result['changed']&&$row['worksite_city']==='Hamilton'&&in_array('status',json_decode($row['coreflux_overridden_fields'],true),true)&&in_array('worksite_city',json_decode($row['coreflux_overridden_fields'],true),true),'Bulk field editing retains status ownership and claims only its field');
    $result=jobdivaSyncApplyTerminalAssignmentLifecycle($tenant,[['startId'=>'99130001','__cf_jobdiva_canonical_status'=>'ended','endDate'=>'2026-11-10']]);
    $check(($result['skipped_reasons']['core_owned_lifecycle']??0)===1&&placementAuditRow($placement)===$row,'A later terminal source update preserves the native bulk status decision');
    foreach(['external_id'=>'jd:99139999','person_id'=>$person+1000000,'jobdiva_job_id'=>'99139998'] as $field=>$value){
        $check($refused(fn()=>placementApplyNativeEdit($placement,[$field=>$value],$user,'patch_status'),409)&&placementAuditRow($placement)===$row,'Native edit cannot silently rebind source identity '.$field);
    }
    foreach(['coreflux_overridden_fields'=>'[]','tenant_id'=>$tenant+1,'deleted_at'=>'2026-11-11 00:00:00','unknown_column'=>'x'] as $field=>$value){
        $check($refused(fn()=>placementApplyNativeEdit($placement,[$field=>$value],$user,'patch_status'),422)&&placementAuditRow($placement)===$row,'Native edit rejects protected or unknown field '.$field);
    }
    foreach(['patch_status','bulk_status','bulk_update','activate_action'] as $via){
        scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);$before=placementAuditRow($placement);$audit=$read('audit_log');
        $check($refused(fn()=>placementApplyNativeEdit($placement,['status'=>'active'],$user,$via),422)&&placementAuditRow($placement)===$before&&$read('audit_log')===$audit&&$read('placement_rates')===$rates,'Malformed ownership rolls back before side effects for '.$via);
    }
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'["notes"]']);
    $company=companiesUpsertByName($tenant,'CI Native retired company',[],['client']);
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$tenant,$company]);
    scopedUpdate('placements',$placement,['end_client_company_id'=>$company]);$before=placementAuditRow($placement);$audit=$read('audit_log');
    $check($refused(fn()=>placementApplyNativeEdit($placement,['notes'=>'Would break graph'],$user,'patch_status'),422)&&placementAuditRow($placement)===$before&&$read('audit_log')===$audit&&$pdo->inTransaction(),'Graph failure rolls back the value, ownership and audit while preserving the caller transaction');
    scopedUpdate('placements',$placement,['end_client_company_id'=>null]);$before=placementAuditRow($placement);$companies=$read('companies');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>placementApplyNativeEdit($placement,['end_client_name'=>'CI Native audit rollback company'],$user,'bulk_update'),503)&&placementAuditRow($placement)===$before&&$read('companies')===$companies&&$pdo->inTransaction(),'Audit failure rolls back the company bridge, placement edit and ownership together');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    setRequestTenantId($tenant+1000000);$_SESSION['tenant_id']=$tenant+1000000;
    $check($refused(fn()=>placementApplyNativeEdit($placement,['notes'=>'Wrong tenant'],$user,'patch_status'),404),'Native edit cannot reach another tenant placement');
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    // The dedicated approval setup path must use the same ownership and audit
    // guarantees as the overview, without changing economics or time approvals.
    $setupBefore=placementAuditRow($placement);$graph=$read('placement_economic_parties');$time=$read('time_entries');
    $result=placementApplyNativeEdit($placement,['client_approver_name'=>' Core Reviewer ',
        'client_approver_email'=>'reviewer@example.invalid','tokenized_email_approval_enabled'=>false,
        'bulk_uploads_can_be_pre_approved'=>'0'],$user,'approval_contact');
    $setup=placementAuditRow($placement);$owned=json_decode($setup['coreflux_overridden_fields'],true);
    $check($result['changed']&&$setup['client_approver_name']==='Core Reviewer'
        &&count(array_intersect(['client_approver_name','client_approver_email'],$owned))===2,
        'Focused approval setup normalizes contacts and owns the complete contact choice');
    $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.approval_contact.updated' ORDER BY id DESC LIMIT 1");
    $q->execute([$tenant,$placement]);$meta=json_decode($q->fetchColumn(),true);
    $check($meta['before']===$setupBefore&&$meta['after']===$setup&&isset($meta['ownership_change_id']),
        'Focused setup retains its original audit event with atomic before and after evidence');
    $check($read('placement_economic_parties')===$graph&&$read('time_entries')===$time&&$read('placement_rates')===$rates,
        'Contact setup does not reconcile commercial parties, change rates, or approve time');
    $audit=$read('audit_log');
    $repeat=placementApplyNativeEdit($placement,['client_approver_name'=>'Core Reviewer','client_approver_email'=>'reviewer@example.invalid',
        'tokenized_email_approval_enabled'=>0,'bulk_uploads_can_be_pre_approved'=>false],$user,'approval_contact');
    $check(!$repeat['changed']&&placementAuditRow($placement)===$setup&&$read('audit_log')===$audit,
        'An exact repeated approval setup succeeds without duplicate audit or writes');
    foreach([['title'=>'Not contact setup'],['status'=>'active'],['coreflux_overridden_fields'=>'[]'],
        ['tokenized_email_approval_enabled'=>'false'],['bulk_uploads_can_be_pre_approved'=>null],
        ['client_approver_name'=>[]],['client_approver_email'=>"a@example.invalid\r\nb@example.invalid"]]as$invalid){
        $check($refused(fn()=>placementApplyNativeEdit($placement,$invalid,$user,'approval_contact'),422)
            &&placementAuditRow($placement)===$setup&&$read('audit_log')===$audit,
            'Focused setup rejects invalid or unrelated input without writes: '.array_key_first($invalid));
    }
    $check($refused(fn()=>placementClearOverrides($placement,['client_approver_email']),422)
        &&placementAuditRow($placement)===$setup,'A contact choice cannot be partially handed back to JobDiva');
    $source=['startId'=>'99130001','candidateId'=>'99130002','jobId'=>'99130003','startDate'=>'2026-11-01',
        'status'=>'on_hold','approverName'=>'Source Reviewer','approverEmail'=>'source-reviewer@example.invalid',
        '_jd_contract'=>['start_id'=>'99130001','engagement_type'=>'w2','start_date'=>'2026-11-01','placement_status'=>'on_hold']];
    jobdivaSyncUpsertPlacement($tenant,$person,0,$source,'99130001');$afterSource=placementAuditRow($placement);
    $check($afterSource['client_approver_name']==='Core Reviewer'&&$afterSource['client_approver_email']==='reviewer@example.invalid',
        'The actual JobDiva placement writer preserves the native approval contact');
    placementClearOverrides($placement,['client_approver_name','client_approver_email']);
    jobdivaSyncUpsertPlacement($tenant,$person,0,$source,'99130001');$afterRelease=placementAuditRow($placement);
    $check($afterRelease['client_approver_name']==='Source Reviewer'&&$afterRelease['client_approver_email']==='source-reviewer@example.invalid',
        'An explicit paired handback lets the actual JobDiva writer refresh the contact');
    placementApplyNativeEdit($placement,['client_approver_email'=>null],$user,'approval_contact');$cleared=placementAuditRow($placement);
    jobdivaSyncUpsertPlacement($tenant,$person,0,$source,'99130001');$afterClear=placementAuditRow($placement);
    $check($afterClear['client_approver_email']===null&&$afterClear['client_approver_name']===$cleared['client_approver_name']
        &&count(array_intersect(['client_approver_name','client_approver_email'],json_decode($afterClear['coreflux_overridden_fields'],true)))===2,
        'Explicit contact clearing remains Core-owned and cannot be filled by a later pull');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>'{}']);$broken=placementAuditRow($placement);$audit=$read('audit_log');
    $check($refused(fn()=>placementApplyNativeEdit($placement,['client_approver_name'=>'Lost'],$user,'approval_contact'),422)
        &&placementAuditRow($placement)===$broken&&$read('audit_log')===$audit,'Malformed ownership refuses focused setup atomically');
    scopedUpdate('placements',$placement,['coreflux_overridden_fields'=>$afterClear['coreflux_overridden_fields']]);
    $auditBefore=placementAuditRow($placement);
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try {
        $check($refused(fn()=>placementApplyNativeEdit($placement,['client_approver_name'=>'Unrecorded reviewer'],$user,'approval_contact'),503)
            &&placementAuditRow($placement)===$auditBefore&&$pdo->inTransaction(),
            'Unavailable audit storage rolls back contact values and ownership together');
    } finally { $pdo->exec('DROP TEMPORARY TABLE audit_log'); }
    setRequestTenantId($tenant+1000000);$_SESSION['tenant_id']=$tenant+1000000;
    $check($refused(fn()=>placementApplyNativeEdit($placement,['client_approver_name'=>'Foreign'],$user,'approval_contact'),404),
        'Focused setup cannot write a placement in another directory');
    setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
    $manual=scopedInsert('placements',['person_id'=>$person,'status'=>'draft','start_date'=>'2026-11-01','engagement_type'=>'w2','title'=>'CI manual placement']);
    placementApplyNativeEdit($manual,['client_approver_email'=>'manual@example.invalid'],$user,'approval_contact');
    $check(placementAuditRow($manual)['coreflux_overridden_fields']===null,'Native-only approval setup does not invent a JobDiva relationship');
    $result=placementApplyNativeEdit($manual,['notes'=>'Native-only note'],$user,'bulk_update');$manualRow=placementAuditRow($manual);
    $check($result['changed']&&$manualRow['coreflux_overridden_fields']===null,'Native-only placement does not acquire JobDiva ownership flags');
    $draft=scopedInsert('placement_rates',['placement_id'=>$manual,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50]);
    $before=placementAuditRow($manual);$rateBefore=$read('placement_rates');$audit=$read('audit_log');
    $check($refused(fn()=>placementApplyNativeEdit($manual,['status'=>'active'],$user,'activate_action'),422)&&placementAuditRow($manual)===$before&&$read('placement_rates')===$rateBefore&&$read('audit_log')===$audit,'Failed activation rolls back rate approval, status, ownership and audit');
    $result=placementApplyNativeEdit($manual,['status'=>'pending_start'],$user,'bulk_status');
    $check($result['rates_auto_approved']===1&&placementAuditRow($manual)['status']==='pending_start','Successful draft promotion still approves its rate within the same edit');
    // A competing connection must wait rather than read and overwrite stale ownership.
    $before=placementAuditRow($placement);$main=$pdo;
    $second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{placementApplyNativeEdit($placement,['notes'=>'Competing native edit'],$user,'bulk_update');$check(false,'Native edit serializes on the placement lock');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'Native edit waits for the placement lock and rolls back its transaction on timeout');}
    finally{$pdo=$main;$second=null;}
    $check(placementAuditRow($placement)===$before&&$pdo->inTransaction(),'Contention preserves the first transaction and its field ownership');
    $check($read('people')===$people&&(int)placementAuditRow($placement)['person_id']===$person&&placementAuditRow($placement)['external_id']==='jd:99130001','Native editing preserves people and existing provider identities');
    $check([$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')]===$financial,'Native edits and rate promotion produce no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);putenv($oldMode===false?'CF_RBAC_BRIDGE_MODE':'CF_RBAC_BRIDGE_MODE='.$oldMode);}
if($failures)throw new RuntimeException(implode('; ',$failures));
echo "$checks placement native edit checks passed.\n";
