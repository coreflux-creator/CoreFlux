<?php
/** Real item writers and claims on invented rows, always enclosed in an outer rollback. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/economic_items_edit.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static function(string $table):array{$rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);usort($rows,static fn($a,$b)=>strcmp(serialize($a),serialize($b)));return $rows;};
$tables=['people','placements','placement_rates','placement_economic_parties','placement_economic_items','placement_economic_obligations','companies','company_roles','ap_vendors_index','staffing_operating_cycles','audit_log'];
$snapshot=static fn():array=>array_map($read,$tables);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('payroll_runs'),$read('ap_payments'),$read('billing_payments')];
$key=static fn():string=>substr(bin2hex(random_bytes(4)),0,8).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(2)).'-'.bin2hex(random_bytes(6));
$pdo->beginTransaction();
try{
    require_once __DIR__.'/payroll_recipient_fixture.php';
    $recipientUser=(int)$pdo->query('SELECT MIN(id) FROM users WHERE is_active=1')->fetchColumn();
    payrollRecipientFixture($pdo,$tenant,$recipientUser,[99185004,99185006]);
    $financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('payroll_runs'),$read('ap_payments'),$read('billing_payments')];
    $person=scopedInsert('people',['first_name'=>'CI Item','last_name'=>'Recipient','classification'=>'1099','email_primary'=>'native-item@example.invalid']);
    $company=companiesUpsertByName($tenant,'CI Item Billing Company',[],['client']);
    $client=staffingClientEnsureForCompany($tenant,$company,'CI Item Billing Company')['client_id'];
    $placement=scopedInsert('placements',['person_id'=>$person,'client_id'=>$client,'title'=>'CI native items','status'=>'on_hold','engagement_type'=>'1099','start_date'=>'2026-10-01','external_id'=>'jd:99185001']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-10-01','bill_rate'=>100,'pay_rate'=>50,'currency'=>'USD','approved_at'=>'2026-10-01 12:00:00']);
    scopedInsert('placement_rates',['placement_id'=>$placement,'effective_from'=>'2026-12-01','bill_rate'=>100,'pay_rate'=>50,'currency'=>'CAD','approved_at'=>'2026-10-01 12:00:00']);
    placementEconomicsReconcile($tenant,$placement);
    $q=$pdo->prepare('SELECT id FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND settlement_channel=? AND active=1 ORDER BY id LIMIT 1');
    $q->execute([$tenant,$placement,'ar']);$ar=(int)$q->fetchColumn();$q->execute([$tenant,$placement,'ap']);$ap=(int)$q->fetchColumn();
    $payroll=placementEconomicsUpsertParty($tenant,$placement,['source_ref'=>'manual:ci-item-payroll','source_type'=>'manual','role'=>'commission_recipient','display_name'=>'CI Payroll Recipient','user_id'=>$recipientUser,'settlement_channel'=>'payroll','money_flow'=>'payable','fee_basis'=>'none','source_managed'=>0]);
    $body=['description'=>'CI one-time charge','settlement_channel'=>'ar','economic_party_id'=>$ar,'direction'=>'charge','amount'=>'25.00','currency'=>'USD','apply_on'=>'2026-10-15','taxable'=>false,'request_id'=>$key()];
    $create=static fn(array $input):array=>placementEconomicsCreateNativeItem($tenant,$placement,$input);
    $fresh=static fn(array $changes=[]):array=>$create(array_replace($body,['request_id'=>$key()],$changes));
    $stable=$snapshot();
    $check($refused(fn()=>placementEconomicsCreateNativeItem($tenant+1000000,$placement,$body),404)&&$snapshot()===$stable,'A foreign workspace cannot create an item for this placement');
    foreach([['request_id'=>''],['description'=>''],['description'=>str_repeat('x',256)],['amount'=>'1.001'],['amount'=>'1e3'],['amount'=>'-1'],['amount'=>'10000000000'],['amount'=>true],['currency'=>'EUR'],['apply_on'=>'2026-02-30'],['apply_on'=>'2026-09-01'],['source_managed'=>1],['settlement_channel'=>'payroll','direction'=>'credit'],['settlement_channel'=>'ap','direction'=>'credit'],['settlement_channel'=>'none'],['economic_party_id'=>$ap],['economic_party_id'=>null],['taxable'=>'yes'],['description'=>['invalid']]] as $bad){
        $check($refused(fn()=>$fresh($bad),422)&&$snapshot()===$stable,'Invalid item input is rejected without graph, item or audit changes');
    }
    $saved=$create($body);$id=$saved['id'];
    $check($saved['changed']&&$saved['item']['amount']==='25.00'&&$saved['item']['currency']==='USD'&&!$saved['item']['source_managed'],'A dated charge uses its applicable contract currency and stays Core-owned');
    $stable=$snapshot();$again=$create(array_replace($body,['amount'=>25]));
    $check(!$again['changed']&&$again['id']===$id&&$snapshot()===$stable,'Equivalent same-request retry returns one item and one audit receipt');
    $check($refused(fn()=>$create(array_replace($body,['amount'=>'26.00'])),409)&&$snapshot()===$stable,'A reused request key cannot change the saved item');
    $second=$fresh();$check($second['id']!==$id,'A separately requested identical charge remains a distinct intentional item');
    $stable=$snapshot();$pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$before=count($read('placement_economic_items'));$check($refused(fn()=>$fresh(),503)&&count($read('placement_economic_items'))===$before&&$pdo->inTransaction(),'An unavailable audit rolls back the item within its caller transaction');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $check($snapshot()===$stable,'Failed audit leaves all original graph and audit rows unchanged');
    $margin=$fresh(['settlement_channel'=>'none','economic_party_id'=>null,'direction'=>'credit']);
    $check($margin['item']['economic_party_id']===null&&$margin['item']['direction']==='credit','Margin-only credits retain their separate non-settlement effect');
    $apItem=$fresh(['settlement_channel'=>'ap','economic_party_id'=>$ap]);
    $payItem=$fresh(['settlement_channel'=>'payroll','economic_party_id'=>$payroll]);
    $check($apItem['item']['settlement_channel']==='ap'&&$payItem['item']['settlement_channel']==='payroll','AP and payroll additions retain their explicitly selected recipients');
    $values=['amount'=>25,'currency'=>'USD','quantity'=>1,'status'=>'projected','ar_invoice_id'=>99185001];
    $claim=static fn(array $v):int=>placementEconomicsRecordObligation($tenant,$placement,$ar,'economic_item',$id,$v);
    $stable=$snapshot();
    $check($refused(fn()=>$claim(array_replace($values,['amount'=>26])),409)&&$refused(fn()=>$claim(array_replace($values,['currency'=>'CAD'])),409)&&$snapshot()===$stable,'A stale amount or currency cannot be claimed by settlement');
    $check($refused(fn()=>$claim(array_replace($values,['ap_bill_id'=>99185002])),409)&&$snapshot()===$stable,'A one-time charge cannot cross settlement destinations');
    $apClaim=placementEconomicsRecordItemObligation($tenant,$apItem['id'],'billed',null,99185003);
    $payClaim=placementEconomicsRecordItemObligation($tenant,$payItem['id'],'payroll',null,null,99185004);
    $stable=$snapshot();
    $check($apClaim>0&&$payClaim>0&&placementEconomicsRecordItemObligation($tenant,$apItem['id'],'billed',null,99185003)===$apClaim
        &&placementEconomicsRecordItemObligation($tenant,$payItem['id'],'payroll',null,null,99185004)===$payClaim&&$snapshot()===$stable,'AP and payroll item claims replay against their own original settlement references');
    $check($refused(fn()=>placementEconomicsRecordItemObligation($tenant,$apItem['id'],'billed',null,99185005),409)
        &&$refused(fn()=>placementEconomicsRecordItemObligation($tenant,$payItem['id'],'payroll',null,null,99185006),409)&&$snapshot()===$stable,'Another bill or payroll run cannot consume an already-claimed item');
    $obligation=$claim($values);$stable=$snapshot();
    $check($obligation>0&&$claim($values)===$obligation&&$snapshot()===$stable,'Repeating the same claim preserves its obligation ID and all recorded values');
    $check($refused(fn()=>$claim(array_replace($values,['ar_invoice_id'=>99185002])),409)&&$snapshot()===$stable,'A second invoice cannot steal an existing one-time claim');
    $check($refused(fn()=>placementEconomicsRemoveNativeItem($tenant,$id),409)&&$snapshot()===$stable,'An applied item cannot be removed');
    $pdo->prepare('UPDATE placement_economic_obligations SET status="paid" WHERE id=?')->execute([$obligation]);$stable=$snapshot();
    $check($claim($values)===$obligation&&$snapshot()===$stable,'An old claim retry cannot regress a paid obligation');
    $pdo->prepare('UPDATE placement_economic_obligations SET status="void" WHERE id=?')->execute([$obligation]);
    $removed=placementEconomicsRemoveNativeItem($tenant,$id);$stable=$snapshot();
    $check($removed['changed']&&$removed['item']['status']==='void','A released claim allows removal without deleting item history');
    $check(!placementEconomicsRemoveNativeItem($tenant,$id)['changed']&&$snapshot()===$stable,'Repeated removal is an unchanged success with no duplicate audit');
    $check(!$create($body)['changed']&&$create($body)['item']['status']==='void'&&$snapshot()===$stable,'Replaying the original create cannot resurrect a removed item');
    $check($refused(fn()=>$claim($values),409)&&$snapshot()===$stable,'A stale settlement preview cannot claim a removed item');
    $check($refused(fn()=>placementEconomicsRemoveNativeItem($tenant+1000000,$second['id']),404)&&$snapshot()===$stable,'Another workspace cannot remove an item by ID');
    $pdo->prepare('UPDATE placement_economic_items SET source_managed=1,source_system="jobdiva" WHERE id=?')->execute([$second['id']]);$stable=$snapshot();
    $check($refused(fn()=>placementEconomicsRemoveNativeItem($tenant,$second['id']),409)&&$snapshot()===$stable,'Integration-managed items must be changed through their source');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>placementEconomicsRemoveNativeItem($tenant,$margin['id']),503)&&placementEconomicItemRead($pdo,$tenant,$placement,$margin['id'])['status']==='active','Removal audit failure restores the active item');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $beforeItems=$read('placement_economic_items');placementEconomicsReconcile($tenant,$placement);
    $check($read('placement_economic_items')===$beforeItems,'Graph refresh preserves Core items and source item identifiers');
    jobdivaSyncUpsertPlacement($tenant,$person,null,['startId'=>'99185001','candidateId'=>'99185002','jobId'=>'99185003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Source refresh after native charges','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99185001','engagement_type'=>'1099','placement_status'=>'on_hold','start_date'=>'2026-10-01','vendor_pay_cycle'=>'monthly']],'99185001');
    $check($read('placement_economic_items')===$beforeItems&&placementAuditRow($placement)['title']==='Source refresh after native charges','A JobDiva assignment refresh preserves native item identities and values');
    $main=$pdo;$secondDb=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$secondDb->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$secondDb;
    try{
        try{$fresh();$check(false,'Competing item writer must wait');}catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$secondDb->inTransaction(),'Competing native item creation waits on the parent lock and rolls back on timeout');}
        try{$claim($values);$check(false,'Competing settlement claim must wait');}catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$secondDb->inTransaction(),'Settlement claims share the native item parent lock and roll back on timeout');}
    }finally{$pdo=$main;$secondDb=null;}
    scopedUpdate('placements',$placement,['deleted_at'=>date('Y-m-d H:i:s')]);$stable=$snapshot();
    $check($refused(fn()=>$fresh(),404)&&$refused(fn()=>placementEconomicsRemoveNativeItem($tenant,$margin['id']),404)&&$snapshot()===$stable,'Deleted placements refuse both new items and removal');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines'),$read('payroll_runs'),$read('ap_payments'),$read('billing_payments')]===$financial,'Item mutations preserve fixture drafts and create no financial postings or payments');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks native one-time item checks passed.\n";
