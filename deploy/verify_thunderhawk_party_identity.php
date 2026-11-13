<?php
/** Installed-library acceptance using only connection-private temporary copies of actual schemas. */
declare(strict_types=1);
const PI_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const PI_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
if(PHP_SAPI!=='cli'||getcwd()!==PI_ROOT||getenv('COREFLUX_ENV')!=='staging'||posix_geteuid()!==1003
 ||($argv[1]??'')!=='--verify-installed-party-identity'||!preg_match('/^[a-f0-9]{64}$/D',$argv[2]??''))exit(2);
$packagePath=__DIR__.'/party-identity-package.json';
if(is_link($packagePath)||hash_file('sha256',$packagePath)!==$argv[2])exit(2);
$package=json_decode(file_get_contents($packagePath),true,64,JSON_THROW_ON_ERROR);
$receipt=json_decode(file_get_contents(PI_PRIVATE.'/party-identity-install-result.json'),true,64,JSON_THROW_ON_ERROR);
if(!($receipt['passed']??false)||$receipt['source_commit']!==$package['source_commit'])exit(2);
$pins=$package['dependencies'];foreach($package['files']as$f=>$entry)$pins[$f]=$entry['sha256'];
foreach($pins as$f=>$hash){$path=PI_ROOT.'/'.$f;if(is_link($path)||hash('sha256',str_replace("\r\n","\n",file_get_contents($path)))!==$hash)throw new RuntimeException('Installed runtime differs');}
require_once PI_ROOT.'/core/jobdiva/party_review.php';require_once PI_ROOT.'/core/jobdiva/projector.php';
$pdo=getDB();
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'||!COREFLUX_STAGING||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'
 ||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002')->fetchColumn()!==1)exit(2);
$snapshot=static function()use($pdo):array{$result=[];foreach($pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN)as$t){if(!preg_match('/^[A-Za-z0-9_]+$/D',$t))exit(2);$hashes=[];foreach($pdo->query('SELECT * FROM `'.$t.'`')as$r)$hashes[]=hash('sha256',serialize($r));sort($hashes);$result[$t]=['rows'=>count($hashes),'hash'=>hash('sha256',implode("\n",$hashes))];}ksort($result);return$result;};
$before=$snapshot();$temporary=[];$checks=[];
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
$fails=static function(callable $call,string $message):bool{try{$call();return false;}catch(Throwable $e){return str_contains($e->getMessage(),$message);}};
$read=static function(string $table,int $id)use($pdo):array{$q=$pdo->prepare('SELECT * FROM '.$table.' WHERE id=?');$q->execute([$id]);return$q->fetch(PDO::FETCH_ASSOC);};
$business=static function(array $r):array{unset($r['coreflux_source_state'],$r['updated_at']);return$r;};
try{
    foreach(['tenants','tenant_module_scope','jobdiva_connections','companies','company_contacts','company_roles','core_record_numbers','external_entity_mappings','entity_sync_history','integration_payload_field_index','audit_log']as$t){
        $pdo->exec('CREATE TEMPORARY TABLE `'.$t.'` LIKE `muzqvdvqbx`.`'.$t.'`');$temporary[]=$t;
    }
    $pdo->exec('INSERT INTO tenants(id,name,is_active,is_simulation,tenant_type) VALUES(2002,"Synthetic installed identity fixture",1,1,"master")');
    $pdo->exec('INSERT INTO jobdiva_connections(tenant_id,client_id,username,password_enc,status) VALUES(2002,"synthetic","synthetic","opaque-synthetic","connected")');
    $pdo->exec('INSERT INTO companies(id,tenant_id,name,phone,country) VALUES(900001,2002,"Synthetic Core company","Core phone","US"),(900002,999999,"Synthetic foreign company","Foreign phone","US")');
    $pdo->exec('INSERT INTO core_record_numbers(tenant_id,entity_type,record_id,record_number,canonical_record_id) VALUES(2002,"company",900001,"CO-900001",900001)');
    $pdo->exec('INSERT INTO company_contacts(id,tenant_id,company_id,name,email,phone) VALUES(900003,2002,900001,"Synthetic Core contact","core@example.invalid","Core contact phone")');
    $pdo->beginTransaction();
    $source=['COMPANYID'=>'900000001','COMPANYNAME'=>'Synthetic source company','PHONE'=>'Source phone'];$reads=0;$offline=false;
    $reader=static function()use(&$source,&$reads,&$offline):array{$reads++;if($offline)throw new RuntimeException('Synthetic offline');return[$source];};
    $request=['record_type'=>'companies','record_id'=>900001,'source_id'=>'900000001'];
    $original=$read('companies',900001);$preview=jobdivaPartyReviewPreview(2002,4,$request,$reader);
    $check($read('companies',900001)===$original&&mappingFindInternal(2002,'jobdiva','company','900000001')===null,'Installed preview leaves existing values and links unchanged');
    $check($fails(fn()=>jobdivaPartyReviewPreview(2002,4,array_replace($request,['record_id'=>900002]),$reader),'directory'),'Installed preview isolates foreign company records');
    $check($fails(fn()=>jobdivaProjectorResolvePartyCompany(2002,[],['external_id'=>'900000001','name'=>'Synthetic Core company'],4),'review the JobDiva identity link'),'Installed assignment projection refuses name-only adoption');
    $result=jobdivaPartyReviewApply(2002,4,$preview['review_token'],$reader);
    $check(!$result['replayed']&&$result['company_number']==='CO-900001'&&$business($read('companies',900001))===$business($original),'Installed link retains Core values, internal ID and permanent number');
    $state=companySourceState($read('companies',900001),'companies');
    $check($state['owned']===companySourceFields('companies')&&$state['proposed']['phone']==='Source phone','Installed adoption protects native fields and retains source proposals');
    $check(jobdivaProjectorResolvePartyCompany(2002,[],['external_id'=>'900000001','name'=>'Synthetic source company'],4)===900001,'Installed assignment projection follows the reviewed identity with an independently retained Core name');
    $offline=true;$readsBefore=$reads;$link=mappingFindInternal(2002,'jobdiva','company','900000001');
    $retry=jobdivaPartyReviewApply(2002,4,$preview['review_token'],$reader);
    $check($retry['replayed']&&$reads===$readsBefore&&mappingFindInternal(2002,'jobdiva','company','900000001')===$link,'Installed retry uses its receipt without provider access or mapping rewrite');$offline=false;
    companyNativeFields($pdo,2002,2002,'companies',900001,['phone'=>'Later Core phone'],4);
    $after=$read('companies',900001);jobdivaPartyReviewApply(2002,4,$preview['review_token'],$reader);
    $check($read('companies',900001)===$after,'Installed receipt replay preserves later native edits');
    $source=['CONTACTID'=>'900000002','COMPANYID'=>'900000001','FIRSTNAME'=>'Source','LASTNAME'=>'Contact','EMAIL'=>'source@example.invalid','PHONE'=>'Source contact phone'];
    $contactRequest=['record_type'=>'company_contacts','record_id'=>900003,'source_id'=>'900000002'];
    $contactBefore=$read('company_contacts',900003);$cp=jobdivaPartyReviewPreview(2002,4,$contactRequest,$reader);
    jobdivaPartyReviewApply(2002,4,$cp['review_token'],$reader);
    $check($business($read('company_contacts',900003))===$business($contactBefore)&&(int)mappingFindInternal(2002,'jobdiva','contact','900000002')['internal_entity_id']===900003,'Installed contact link retains identity, parent, name, email and phone');
    $source['COMPANYID']='900000099';
    $check($fails(fn()=>jobdivaPartyReviewPreview(2002,4,$contactRequest,$reader),'parent company'),'Installed contact review cannot change company implicitly');
    $check((int)$pdo->query('SELECT COUNT(*) FROM audit_log WHERE event="company.source.identity_linked"')->fetchColumn()===2,'Only two exact identity receipts are written despite replay');
    $check($pdo->inTransaction(),'Installed workflow preserves its caller transaction');
}finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach(array_reverse($temporary)as$t)$pdo->exec('DROP TEMPORARY TABLE `'.$t.'`');
}
$after=$snapshot();$check($after===$before,'Every permanent database row is unchanged after fixture cleanup');
echo json_encode(['checked_at'=>gmdate('c'),'passed'=>true,'checks_passed'=>count($checks),'checks'=>$checks,'installed_runtime_pins'=>count($pins),
 'tables_verified'=>count($after),'rows_preserved'=>array_sum(array_column($after,'rows')),'temporary_tables_removed'=>count($temporary),'provider_calls'=>0,'permanent_fixture_records'=>0],JSON_THROW_ON_ERROR)."\n";
