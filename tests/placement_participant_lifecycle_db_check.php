<?php
/** Native participant creation/removal: invented records, outer rollback only. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('COREFLUX_ENV') !== 'coreaccounting'
    || getenv('COREFLUX_STANDALONE_DATABASE') !== 'coreaccounting_ci'
    || !in_array('--confirm-disposable-fixture', $argv, true)) exit(2);
require_once __DIR__ . '/../modules/placements/lib/economics_participants.php';
require_once __DIR__ . '/../core/memberships.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static function(string $table):array{
    $rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
    usort($rows,static fn($a,$b)=>strcmp(json_encode($a,JSON_THROW_ON_ERROR),json_encode($b,JSON_THROW_ON_ERROR)));
    return $rows;
};
$tables=['placements','placement_corp_details','placement_economic_parties','companies','company_roles','ap_vendors_index','staffing_operating_cycles'];
$snapshot=static fn():array=>array_map($read,$tables);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
$pdo->beginTransaction();
try {
    $person=scopedInsert('people',['first_name'=>'CI Participant','last_name'=>'Lifecycle','classification'=>'c2c','email_primary'=>'participant-lifecycle@example.invalid']);
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI participant lifecycle','status'=>'on_hold','engagement_type'=>'c2c','start_date'=>'2026-10-01','external_id'=>'jd:99182001']);
    $create=static fn(array $body):array=>placementEconomicsCreateNativeParty($tenant,$placement,$body,[]);
    $remove=static fn(int $id):array=>placementEconomicsRemoveNativeParty($tenant,$id);
    $body=['role'=>'c2c_vendor','display_name'=>'CI Atomic Participant Vendor','settlement_channel'=>'ap','payment_terms'=>'NET30','cadence'=>'weekly'];
    $before=$snapshot();
    $check($refused(fn()=>placementEconomicsCreateNativeParty($tenant+1000000,$placement,$body,[]),404)&&$snapshot()===$before,'A foreign placement cannot create directory, vendor, cycle or participant records');
    $check($refused(fn()=>$create($body+['company_id'=>['bad']]),422)&&$snapshot()===$before,'Structured participant fields are rejected without writes');
    $check($refused(fn()=>$create(array_replace($body,['cadence'=>'invalid'])),422)&&$snapshot()===$before,'Late cadence validation rolls back the new company and role');
    $company=companiesUpsertByName($tenant,'CI Role Rollback Company',[],[]);
    $before=$snapshot();
    $invalidAr=['role'=>'client','company_id'=>$company,'display_name'=>'Ignored submitted name','settlement_channel'=>'ar','payment_terms'=>'PWP_NET30'];
    $check($refused(fn()=>$create($invalidAr),422)&&$snapshot()===$before,'Invalid client terms roll back a role added to an existing company');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try {
        $check($refused(fn()=>$create($body),503)&&$snapshot()===$before&&$pdo->inTransaction(),'Failed required audit rolls back all company, vendor, cycle, party and corporate writes');
    } finally {$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $result=$create($body);$partyId=(int)$result['id'];
    $q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND id=?');
    $q->execute([$tenant,$partyId]);$party=$q->fetch(PDO::FETCH_ASSOC);
    $check($partyId>0&&$party['source_ref']==='corp:'.$placement&&$party['source_type']==='corp'&&!(int)$party['source_managed'],'Creation keeps the canonical C2C participant identity');
    $corp=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=?');
    $corp->execute([$tenant,$placement]);$corpRow=$corp->fetch(PDO::FETCH_ASSOC);
    $check((int)$corpRow['company_id']===(int)$party['company_id']&&(int)$corpRow['ap_vendor_id']===(int)$party['ap_vendor_id']&&$corpRow['payment_terms_override']==='NET30','Corporate source, payee and terms are aligned at commit');
    $summary=placementAuditRow($placement);
    $check($summary['vendor_pay_cycle']==='weekly'&&(int)$summary['ap_operating_cycle_id']===(int)$party['operating_cycle_id'],'Placement and participant share the chosen payment schedule');
    $check((int)$summary['person_id']===$person&&$summary['external_id']==='jd:99182001','Creation preserves the worker and source assignment identities');
    $audit=$pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE tenant_id=? AND target_id=? AND event=?');
    $audit->execute([$tenant,$placement,'placement.economic_party.added']);
    $check((int)$audit->fetchColumn()===1,'Successful creation includes one required audit receipt');
    $check(!empty($result['context']['available'])&&$pdo->inTransaction(),'Creation returns its contract context inside the caller transaction');
    $stable=$snapshot();$auditRows=$read('audit_log');
    $check($refused(fn()=>placementEconomicsRemoveNativeParty($tenant+1000000,$partyId),404)&&$snapshot()===$stable&&$read('audit_log')===$auditRows,'Another tenant cannot remove the participant');
    $pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try {
        $check($refused(fn()=>$remove($partyId),503)&&$snapshot()===$stable&&$pdo->inTransaction(),'Failed removal audit restores both the corporate source and active participant');
    } finally {$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $pdo->prepare('UPDATE placement_economic_parties SET source_managed=1 WHERE tenant_id=? AND id=?')->execute([$tenant,$partyId]);
    $stable=$snapshot();
    $check($refused(fn()=>$remove($partyId),409)&&$snapshot()===$stable,'A source-managed participant must be removed through its source');
    $pdo->prepare('UPDATE placement_economic_parties SET source_managed=0 WHERE tenant_id=? AND id=?')->execute([$tenant,$partyId]);
    $removed=$remove($partyId);$q->execute([$tenant,$partyId]);$after=$q->fetch(PDO::FETCH_ASSOC);
    $corp->execute([$tenant,$placement]);
    $check($removed['changed']&&!(int)$after['active']&&!$corp->fetchColumn(),'Removal clears the corporate source and deactivates its participant together');
    $check((int)$after['company_id']===(int)$party['company_id']&&(int)$after['ap_vendor_id']===(int)$party['ap_vendor_id'],'Removal retains historical party, company and vendor identities');
    $audit->execute([$tenant,$placement,'placement.economic_party.removed']);
    $check((int)$audit->fetchColumn()===1,'Successful removal includes one required audit receipt');
    $stable=$snapshot();$auditRows=$read('audit_log');$replay=$remove($partyId);
    $check(!$replay['changed']&&$snapshot()===$stable&&$read('audit_log')===$auditRows,'Removal replay leaves records and audit receipts unchanged');
    $pdo->prepare('UPDATE placements SET deleted_at=NOW() WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
    $stable=$snapshot();
    $check($refused(fn()=>$create($body),404)&&$refused(fn()=>$remove($partyId),404)&&$snapshot()===$stable,'Deleted placements cannot create or remove participants');
    $pdo->prepare('UPDATE placements SET deleted_at=NULL WHERE tenant_id=? AND id=?')->execute([$tenant,$placement]);
    $main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try {$create($body);$check(false,'A competing participant create must wait');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'Competing participant creation waits on the parent lock and rolls back on timeout');}
    finally {$pdo=$main;$second=null;}
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Participant lifecycle creates no financial postings');
} finally {if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks native participant lifecycle checks passed.\n";
