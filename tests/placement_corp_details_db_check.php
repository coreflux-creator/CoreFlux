<?php
/** Corporate editor: synthetic records, real writers, rollback-only disposable DB. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('COREFLUX_ENV')!=='coreaccounting'
    ||getenv('COREFLUX_STANDALONE_DATABASE')!=='coreaccounting_ci'
    ||!in_array('--confirm-disposable-fixture',$argv,true))exit(2);
require_once __DIR__.'/../modules/placements/lib/corp_details.php';
require_once __DIR__.'/../core/jobdiva/sync.php';
$pdo=getDB();if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='coreaccounting_ci')exit(2);
$tenant=(int)$pdo->query('SELECT MIN(id) FROM tenants')->fetchColumn();if($tenant<=0)exit(2);
setRequestTenantId($tenant);$_SESSION['tenant_id']=$tenant;
// Only this disposable CI process may provide an ephemeral encryption key.
if(getenv('COREFLUX_ENV')==='coreaccounting'&&!defined('COREFLUX_DATA_KEY')&&!getenv('COREFLUX_DATA_KEY'))putenv('COREFLUX_DATA_KEY='.base64_encode(random_bytes(32)));
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS $label\n";};
$refused=static function(callable $fn,int $code):bool{try{$fn();return false;}catch(RuntimeException|InvalidArgumentException $e){return $e->getCode()===$code;}};
$read=static function(string $table):array{$rows=getDB()->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);usort($rows,static fn($a,$b)=>strcmp(serialize($a),serialize($b)));return $rows;};
$tables=['people','placements','placement_corp_details','placement_economic_parties','companies','company_roles','ap_vendors_index','staffing_operating_cycles','external_entity_mappings','storage_objects','placement_documents'];
$snapshot=static fn():array=>array_map($read,$tables);
$financial=[$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')];
$pdo->beginTransaction();
try{
    $person=scopedInsert('people',['first_name'=>'CI Corporate','last_name'=>'Editor','classification'=>'c2c','email_primary'=>'corp-editor@example.invalid']);
    $company=companiesUpsertByName($tenant,'CI Original Editor Corporation',[],['vendor']);
    $placement=scopedInsert('placements',['person_id'=>$person,'title'=>'CI corporate editor','status'=>'on_hold','engagement_type'=>'c2c','start_date'=>'2026-10-01','end_date'=>'2026-12-31','external_id'=>'jd:99184001','vendor_payment_terms_override'=>'NET15','vendor_pwp_enabled'=>0,'vendor_pay_cycle'=>'weekly']);
    $cycle=placementEconomicsEnsureCycle($tenant,'ap','weekly','2026-10-05','coreflux','ci-corp-editor','CI custom corporate schedule');
    scopedUpdate('placements',$placement,['ap_operating_cycle_id'=>$cycle,'vendor_pay_cycle_anchor'=>'2026-10-05']);
    $pdo->prepare('INSERT INTO placement_corp_details(tenant_id,placement_id,company_id,corp_legal_name,payment_terms_override,pwp_enabled,corp_address_line1,corp_contact_phone) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$tenant,$placement,$company,'CI Original Editor Corporation','NET15',0,'Original corporate address','555-0100']);
    mappingUpsert($tenant,'jobdiva','company','99184101',$company);
    placementEconomicsReconcile($tenant,$placement);
    $review=static fn():array=>placementCorpReview($tenant,$placement);
    $save=static fn(array $body):array=>placementCorpSave($tenant,$placement,$body,[]);
    $fresh=static function(array $body)use($save,$review):array{$r=$review();return $save($body+['corp_legal_name'=>$r['corp']['corp_legal_name'],'review_token'=>$r['review_token']]);};
    $corp=static function()use($pdo,$tenant,$placement):array{$q=$pdo->prepare('SELECT * FROM placement_corp_details WHERE tenant_id=? AND placement_id=?');$q->execute([$tenant,$placement]);return $q->fetch(PDO::FETCH_ASSOC);};
    $party=static function()use($pdo,$tenant,$placement):array{$q=$pdo->prepare('SELECT * FROM placement_economic_parties WHERE tenant_id=? AND placement_id=? AND source_ref=?');$q->execute([$tenant,$placement,'corp:'.$placement]);return $q->fetch(PDO::FETCH_ASSOC);};
    $originalParty=$party();$stable=$snapshot();$r=$review();
    $check($snapshot()===$stable&&strlen($r['review_token'])===64&&!array_key_exists('corp_ein_ct',$r['corp']),'Review is read-only and exposes metadata without tax ciphertext');
    $check($refused(fn()=>placementCorpReview($tenant+1000000,$placement),404)&&$refused(fn()=>placementCorpSave($tenant+1000000,$placement,['corp_legal_name'=>'CI Foreign Attempt']),404)&&$snapshot()===$stable,'Another tenant cannot read or change corporate records');
    $check($refused(fn()=>$save(['corp_legal_name'=>'CI Original Editor Corporation']),409)&&$snapshot()===$stable,'Existing corporate edits require a current review token');
    $result=$fresh(['corp_contact_email'=>'corp-editor@example.invalid']);
    $after=$corp();$p=$party();$row=placementAuditRow($placement);
    $check($result['changed']&&$after['corp_contact_email']==='corp-editor@example.invalid'&&$after['corp_address_line1']==='Original corporate address'&&$after['corp_contact_phone']==='555-0100','A partial save preserves unsubmitted corporate details');
    $check((int)$p['id']===(int)$originalParty['id']&&(int)$p['company_id']===$company&&(int)$p['ap_vendor_id']===(int)$after['ap_vendor_id']&&!(int)$p['source_managed'],'Corporate edits align the stable participant and payee identity under Core ownership');
    $check((int)$p['operating_cycle_id']===$cycle&&(int)$row['ap_operating_cycle_id']===$cycle&&$row['vendor_pay_cycle_anchor']==='2026-10-05'&&(int)$p['cycle_overridden']===1,'Saving metadata preserves and owns the existing custom payment schedule');
    $check($p['payment_terms']==='NET15'&&$row['vendor_payment_terms_override']==='NET15'&&(int)$p['payment_terms_overridden']===1&&in_array('corp_identity',placementOwnershipFields($row['coreflux_overridden_fields']),true),'Payment setup and corporate identity are owned consistently');
    $stable=$snapshot();$audits=$read('audit_log');$same=$fresh(['corp_contact_email'=>'corp-editor@example.invalid']);
    $check(!$same['changed']&&$snapshot()===$stable&&$read('audit_log')===$audits,'Fresh unchanged save creates no directory usage, graph or audit changes');
    $check($refused(fn()=>$save(['corp_legal_name'=>'CI Original Editor Corporation','review_token'=>$r['review_token'],'corp_city'=>'Stale city']),409)&&$snapshot()===$stable,'An older review cannot overwrite a newer native edit');
    foreach([['corp_contact_email'=>'invalid'],['corp_country'=>'USA'],['corp_state'=>str_repeat('x',61)],['coi_expiry'=>'2026-02-30'],['corp_ein'=>'12-bad'],['pwp_enabled'=>'yes'],['corp_contact_phone'=>['bad']],['corp_ein_ct'=>'forbidden']] as $bad)
        $check($refused(fn()=>$fresh($bad),422)&&$snapshot()===$stable,'Malformed corporate edit is rejected before linked records change');
    $next=$fresh(['corp_legal_name'=>'CI Replacement Editor Corporation']);
    $replacement=(int)$next['corp']['company_id'];
    $check($replacement!==$company&&(int)$party()['company_id']===$replacement&&(int)$party()['id']===(int)$originalParty['id'],'Changing the legal-name identity rebinds the company and reuses the participant');
    $check($corp()['corp_address_line1']===null&&$corp()['corp_contact_email']===null&&(int)mappingFindInternal($tenant,'jobdiva','company','99184101')['internal_entity_id']===$company,'Replacement clears old company details without changing its provider mapping');
    $fresh(['corp_ein'=>'12-3456789']);$encrypted=$corp();$public=$review();
    $check($encrypted['corp_ein_ct']!=='123456789'&&decryptField($encrypted['corp_ein_ct'])==='123456789'&&$public['corp']['corp_ein_last4']==='6789'&&!array_key_exists('corp_ein_ct',$public['corp']),'EIN is encrypted at rest and only its last four digits are returned');
    $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.corp.updated' ORDER BY id DESC LIMIT 1");$q->execute([$tenant,$placement]);$audit=(string)$q->fetchColumn();
    $check(!str_contains($audit,'123456789')&&!str_contains($audit,'12-3456789')&&!str_contains($audit,'corp_ein_ct'),'The required audit contains no plaintext EIN or encrypted tax blob');
    $stable=$snapshot();$audits=$read('audit_log');$same=$fresh(['corp_ein'=>'123456789']);
    $check(!$same['changed']&&$snapshot()===$stable&&$read('audit_log')===$audits,'Equivalent formatted EIN replay preserves its ciphertext and creates no duplicate audit');
    $fresh(['corp_contact_phone'=>'555-0111','corp_ein'=>'']);$check($corp()['corp_ein_ct']===$encrypted['corp_ein_ct'],'Blank EIN input preserves the existing encrypted value');
    $stable=$snapshot();$check($refused(fn()=>$fresh(['corp_legal_name'=>'CI Private Rebind Attempt']),409)&&$snapshot()===$stable,'An identity change cannot move private tax data to another company');
    $other=scopedInsert('placements',['person_id'=>$person,'title'=>'CI other document owner','status'=>'on_hold','engagement_type'=>'c2c','start_date'=>'2026-10-01']);
    $storage=static function(int $pid,string $suffix)use($pdo,$tenant):int{
        $pdo->prepare('INSERT INTO storage_objects(tenant_id,module,entity_type,entity_id,s3_key,filename,created_at) VALUES(?,"placements","document",?,?,?,NOW())')
            ->execute([$tenant,(string)$pid,'ci-corp-editor/'.$pid.'/'.$suffix,$suffix]);$id=(int)$pdo->lastInsertId();
        scopedInsert('placement_documents',['placement_id'=>$pid,'doc_type'=>'other','storage_object_id'=>$id,'file_name'=>$suffix]);return $id;
    };
    $ownedDoc=$storage($placement,'owned.pdf');$otherDoc=$storage($other,'other.pdf');$stable=$snapshot();
    $check($refused(fn()=>$fresh(['w9_storage_object_id'=>$otherDoc]),422)&&$snapshot()===$stable,'A document attached to another placement cannot become this corporate tax document');
    $fresh(['w9_storage_object_id'=>$ownedDoc]);$fresh(['corp_city'=>'Hamilton']);
    $check((int)$corp()['w9_storage_object_id']===$ownedDoc&&$corp()['corp_ein_ct']===$encrypted['corp_ein_ct'],'Metadata-only saves retain existing document and tax bindings');
    $stable=$snapshot();$pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>$fresh(['corp_city'=>'Rollback city','corp_ein'=>'98-7654321']),503)&&$snapshot()===$stable&&$pdo->inTransaction(),'A failed required audit rolls back encrypted data, metadata, graph and directory changes');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $payload=['startId'=>'99184001','candidateId'=>'99184002','jobId'=>'99184003','startDate'=>'2026-10-01','status'=>'on_hold','title'=>'Fresh unrelated title','__cf_force_source_contract'=>true,
        '_jd_contract'=>['start_id'=>'99184001','engagement_type'=>'c2c','placement_status'=>'on_hold','start_date'=>'2026-10-01','end_date'=>'2026-12-31','corporation_id'=>'99184101','corporation_name'=>'CI Original Editor Corporation',
            'vendor_payment_terms'=>'NET60','vendor_pay_cycle'=>'monthly','paid_when_paid'=>true]];
    $before=$corp();jobdivaSyncUpsertPlacement($tenant,$person,null,$payload,'99184001');
    $check($corp()===$before&&placementAuditRow($placement)['title']==='Fresh unrelated title'&&$party()['payment_terms']==='NET15'&&(int)$party()['operating_cycle_id']===$cycle,'A later JobDiva refresh retains the reviewed corporate identity, private data, terms and custom cycle');
    placementEconomicsApplyNativePartyEdit($tenant,(int)$party()['id'],['payment_terms'=>'NET20']);
    $check($corp()['corp_ein_ct']===$encrypted['corp_ein_ct']&&$corp()['payment_terms_override']==='NET20'&&$party()['payment_terms']==='NET20','Participant term editing remains atomic when the corporation holds encrypted tax data');
    $q=$pdo->prepare("SELECT meta_json FROM audit_log WHERE tenant_id=? AND target_id=? AND event='placement.economic_party.updated' ORDER BY id DESC LIMIT 1");$q->execute([$tenant,$placement]);$audit=(string)$q->fetchColumn();
    $check($audit!==''&&!str_contains($audit,'corp_ein_ct')&&!str_contains($audit,'123456789'),'Term editing audits only the corporate commercial fields needed for its change');
    $stable=$snapshot();$pdo->exec('CREATE TEMPORARY TABLE audit_log (id BIGINT,tenant_id BIGINT,target_id BIGINT,event VARCHAR(100),meta_json JSON)');
    try{$check($refused(fn()=>placementCorpSave($tenant,$other,['corp_legal_name'=>'CI Failed New Corporation','corp_country'=>'us']),503)&&$snapshot()===$stable,'Failed initial-save audit leaves no new company, vendor, cycle or corporate record');}
    finally{$pdo->exec('DROP TEMPORARY TABLE audit_log');}
    $otherResult=placementCorpSave($tenant,$other,['corp_legal_name'=>'CI Newly Entered Corporation','corp_country'=>'us']);
    $check($otherResult['changed']&&$otherResult['corp']['corp_country']==='US'&&(int)$otherResult['corp']['company_id']>0,'A new C2C corporate entry creates its company and canonical payee atomically');
    $r=$review();$main=$pdo;$second=new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);$second->exec('SET SESSION innodb_lock_wait_timeout=1');$pdo=$second;
    try{$save(['corp_legal_name'=>$r['corp']['corp_legal_name'],'review_token'=>$r['review_token'],'corp_city'=>'Competing city']);$check(false,'Competing corporate writer must wait');}
    catch(PDOException $e){$check((int)($e->errorInfo[1]??0)===1205&&!$second->inTransaction(),'Competing corporate edits wait on the placement lock and roll back on timeout');}
    finally{$pdo=$main;$second=null;}
    scopedUpdate('placements',$other,['engagement_type'=>'w2']);$stable=$snapshot();
    $check($refused(fn()=>placementCorpSave($tenant,$other,['corp_legal_name'=>'CI Invalid Model']),422)&&$snapshot()===$stable,'A non-C2C placement cannot acquire corporate payee details');
    scopedUpdate('placements',$other,['deleted_at'=>date('Y-m-d H:i:s')]);$stable=$snapshot();
    $check($refused(fn()=>placementCorpReview($tenant,$other),404)&&$refused(fn()=>placementCorpSave($tenant,$other,['corp_legal_name'=>'CI Deleted Attempt']),404)&&$snapshot()===$stable,'Deleted placements cannot read or save corporate details');
    $check([$read('accounting_events'),$read('accounting_journal_entries'),$read('accounting_journal_entry_lines')]===$financial,'Corporate editing and source refresh create no financial postings');
}finally{if($pdo->inTransaction())$pdo->rollBack();setRequestTenantId(null);}
echo "$checks corporate details editor checks passed.\n";
