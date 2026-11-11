<?php
/** Canonical disposable database only; all synthetic changes roll back. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../core/db.php';
require_once __DIR__.'/../modules/placements/lib/placements.php';
require_once __DIR__.'/../modules/placements/lib/end.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();
if($tenant<=0)exit(2);
setRequestTenantId($tenant);
$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);$checks++;echo "PASS $message\n";
};
$refuse=static function(callable $call,int $status)use($check):void{
    try{$call();}catch(RuntimeException|InvalidArgumentException $e){$check($e->getCode()===$status,'Operation refused with expected status '.$status);return;}
    throw new RuntimeException('Expected refusal did not occur');
};
$read=static fn(string $table):array=>$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$financial=static fn():array=>[$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('accounting_events')];
$financialBefore=$financial();
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI End','last_name'=>'Worker','email_primary'=>'ci-placement-end@example.invalid','classification'=>'w2']);
    $placement=scopedInsert('placements',['person_id'=>$person,'status'=>'active','start_date'=>'2026-11-01','engagement_type'=>'w2','title'=>'CI end action','external_id'=>'CI-END-ORIGINAL']);
    $rate=scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-11-01','bill_rate'=>100,'pay_rate'=>50,'approved_at'=>'2026-11-01 12:00:00']);
    $sourceBefore=$read('placement_rates');$peopleBefore=$read('people');
    $result=placementEnd($placement,['actual_end_date'=>'2026-11-11']);
    $row=placementAuditRow($placement);
    $check($result['changed']&&$row['status']==='ended'&&$row['actual_end_date']==='2026-11-11','Native ending accepts omitted status and preserves the explicit date');
    $check($row['external_id']==='CI-END-ORIGINAL'&&(int)$row['person_id']===$person,'Original placement and person identities are retained');
    $audit=$read('audit_log');$parties=$read('placement_economic_parties');$items=$read('placement_economic_items');
    $again=placementEnd($placement,['actual_end_date'=>'2026-11-11']);
    $check(!$again['changed']&&placementAuditRow($placement)===$row&&$read('audit_log')===$audit
        &&$read('placement_economic_parties')===$parties&&$read('placement_economic_items')===$items,'Exact replay changes no placement, graph or audit rows');
    $again=placementEnd($placement,[]);
    $check(!$again['changed']&&placementAuditRow($placement)===$row,'Retry without a date keeps the saved terminal date');
    $refuse(fn()=>placementEnd($placement,['status'=>'active']),422);
    $refuse(fn()=>placementEnd($placement,['actual_end_date'=>'2026-10-31']),422);
    $check(placementAuditRow($placement)===$row&&$read('audit_log')===$audit,'Rejected inputs preserve the terminal placement and audit');
    setRequestTenantId($tenant+1000000);
    $refuse(fn()=>placementEnd($placement,[]),404);
    setRequestTenantId($tenant);
    $future=scopedInsert('placements',['person_id'=>$person,'status'=>'draft','start_date'=>'2026-12-01','engagement_type'=>'w2','title'=>'CI cancelled before start']);
    placementEnd($future,['status'=>'cancelled','actual_end_date'=>'2026-11-11']);
    $check(placementAuditRow($future)['status']==='cancelled','An unstarted placement is cancellable');
    // A stale commercial party must roll back the terminal state inside the caller's transaction.
    $retiredCompany=companiesUpsertByName($tenant,'CI retired placement customer',[],['client']);
    $pdo->prepare('UPDATE companies SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$tenant,$retiredCompany]);
    $broken=scopedInsert('placements',['person_id'=>$person,'status'=>'active','start_date'=>'2026-11-01','engagement_type'=>'w2','title'=>'CI invalid company link','end_client_company_id'=>$retiredCompany]);
    $beforeBroken=placementAuditRow($broken);$beforeAudit=$read('audit_log');
    $refuse(fn()=>placementEnd($broken,['actual_end_date'=>'2026-11-11']),422);
    $check($pdo->inTransaction()&&placementAuditRow($broken)===$beforeBroken&&$read('audit_log')===$beforeAudit,'Commercial reconciliation failure rolls back only ending and preserves the caller transaction');
    $check($read('placement_rates')===$sourceBefore&&$read('people')===$peopleBefore,'Ending preserves approved rates and the worker record');
    $check($financial()===$financialBefore,'Ending creates no journals or accounting events');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks placement end database checks passed.\n";
