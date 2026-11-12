<?php
/** One-off isolated recovery: exact old source proves delivery 96 never reached provider IO. */
declare(strict_types=1);
const LR_ROOT='/home/1516771.cloudwaysapps.com/muzqvdvqbx/public_html';
const LR_PRIVATE='/home/1516771.cloudwaysapps.com/muzqvdvqbx/private_html/thunderhawk-pilot-20261030';
const LR_STATE=LR_PRIVATE.'/assignment-context-local-rejection-recovery-20261112-r2.json';
const LR_OLD_SHA='eff1c66637a6cf65a61af52f320999c1d67bed2401640db34788f08614ebf17b';
const LR_CIPHERTEXT_SHA='a58f51f9be6d90dd19225ecab6e123c0aa868f7bcb4b5e82b2e1970c713cb8a6';
const LR_PAYLOAD_SHA='4daed4dbd55f168172793c66e9935d8af6c17fdca1cced191fbb7bf854458630';
if(PHP_SAPI!=='cli'||posix_geteuid()!==1003||getenv('COREFLUX_ENV')!=='staging'||getcwd()!==LR_ROOT
    ||!in_array($argv[1]??'',['--inspect-local-rejection','--recover-local-rejection'],true)
    ||realpath(LR_PRIVATE)!==LR_PRIVATE||(fileperms(LR_PRIVATE)&0777)!==0700)exit(2);
$mutate=$argv[1]==='--recover-local-rejection';
if($mutate&&file_exists(LR_STATE))throw new RuntimeException('Inspect the existing recovery receipt; do not repeat');
$lock=fopen(LR_PRIVATE.'/contact-install.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(2);
require_once LR_ROOT.'/core/quanta/metadata_delivery.php';
require_once LR_ROOT.'/core/audit.php';
$pdo=getDB();
if(!$pdo||!COREFLUX_STAGING||$pdo->query('SELECT DATABASE()')->fetchColumn()!=='muzqvdvqbx'
    ||integrationInstanceId()!=='9f4b6dfb-e52e-464c-9a9d-af7766928ddf'||COREFLUX_INTEGRATION_DELIVERY_TENANTS!=='2002'
    ||quantaApiBase()!=='https://helloquanta.app:19443/api/v1'
    ||(int)$pdo->query('SELECT is_simulation FROM tenants WHERE id=2002 AND is_active=1')->fetchColumn()!==1)exit(2);
$install=json_decode(file_get_contents(LR_PRIVATE.'/assignment-context-fix-install-result.json'),true,64,JSON_THROW_ON_ERROR);
if(($install['passed']??false)!==true||($install['source_commit']??'')!=='4902c07f0788e9e30754e852e7375c847b0d8675'
    ||!function_exists('quantaContractWriteRequest'))throw new RuntimeException('Tested fix must be installed first');
$beforePath=$install['backup'].'/core_quanta_core_contract.php';
if(is_link($beforePath)||realpath(dirname($beforePath))!==dirname($beforePath)
    ||!str_starts_with($beforePath,LR_PRIVATE.'/assignment-context-fix-before-')
    ||hash_file('sha256',$beforePath)!==LR_OLD_SHA)throw new RuntimeException('Original runtime proof differs');
$row=integrationDeliveryGet($pdo,2002,96);
if($row['provider']!=='quanta'||$row['operation']!=='assignment.update'||(int)$row['entity_id']!==864
    ||(int)$row['entity_tenant_id']!==2002||$row['state']!=='uncertain'||$row['error_code']!=='delivery_interrupted'
    ||$row['remote_id']!==null||$row['remote_receipt_id']!==null||$row['claim_hash']!==null)throw new RuntimeException('Held envelope differs');
$json=(string)decryptField($row['payload_ct']);$payload=json_decode($json,true,64,JSON_THROW_ON_ERROR);
if(hash('sha256',$row['payload_ct'])!==LR_CIPHERTEXT_SHA||integrationDeliveryHash($payload)!==LR_PAYLOAD_SHA)throw new RuntimeException('Original encrypted request differs');
$old=file_get_contents($beforePath);$marker='/** Validate the complete request again at the IO boundary; only the explicit operations below can write. */';
$offset=strpos($old,$marker);if($offset===false)throw new RuntimeException('Original validator not found');
$function=substr($old,$offset);
if(substr_count($function,'function quantaWriteContract(')!==1)throw new RuntimeException('Original function ambiguous');
$proof='<?php' . "\n" . str_replace('function quantaWriteContract(', 'function quantaWriteContractBeforeContextFix(', $function);
$proofPath=LR_PRIVATE.'/context-rejected-validator-'.hash('sha256',$proof).'.php';
if(is_link($proofPath))throw new RuntimeException('Redirected proof');
if(!file_exists($proofPath)){$u=umask(0077);$f=fopen($proofPath,'x');umask($u);if(!$f||fwrite($f,$proof)!==strlen($proof)||!fflush($f)||!fsync($f))throw new RuntimeException('Proof write failed');fclose($f);}
if(hash_file('sha256',$proofPath)!==hash('sha256',$proof))throw new RuntimeException('Proof changed');
require $proofPath;
$transportCalls=0;$failure=null;
$GLOBALS['__quanta_core_write_transport']=static function()use(&$transportCalls){$transportCalls++;throw new RuntimeException('Proof reached transport');};
try{quantaWriteContractBeforeContextFix('fixture-key','4f93d4e7-af74-4765-b812-3464bb6d974b',$row['operation'],$payload['body'],$payload['expected'],$row['idempotency_key']);}
catch(Throwable $e){$failure=['class'=>get_class($e),'message'=>$e->getMessage()];}
finally{unset($GLOBALS['__quanta_core_write_transport']);}
if($transportCalls!==0||$failure!==['class'=>'InvalidArgumentException','message'=>'Assignment confirmation differs from the request'])throw new RuntimeException('No-IO rejection was not proven');
$receipt=quantaMetadataReceipt(2002,4,96,$row['review_hash']);
if($receipt['receipt_state']!=='not_found')throw new RuntimeException('Provider receipt requires normal reconciliation');
$snapshot=quantaMetadataSnapshot(2002,'placement',864,$payload['worksite_id']);
if(($snapshot['remote']['assignment']['version']??null)!==2||array_key_exists('assignment_context',$snapshot['remote']['assignment'])
    ||integrationDeliveryHash($snapshot['payload'])!==LR_PAYLOAD_SHA)throw new RuntimeException('Reviewed source or provider changed');
foreach(['binding_hash','source_hash','remote_hash']as$key)if($row[$key]!==$snapshot['context'][$key])throw new RuntimeException('Original review is stale');
quantaContractWriteRequest('fixture-key',$snapshot['verified']['context']['workspace']['id'],$row['operation'],$payload['body'],$payload['expected'],$row['idempotency_key']);
$report=['checked_at'=>gmdate('c'),'passed'=>false,'delivery_id'=>96,'transport_calls'=>0,'original_source_sha256'=>LR_OLD_SHA,
    'payload_sha256'=>LR_PAYLOAD_SHA,'provider_receipt'=>'not_found','assignment_version'=>2,'provider_writes'=>false,'financial_changes'=>false];
if(!$mutate){$report['ready']=true;echo json_encode($report,JSON_THROW_ON_ERROR);exit;}
$backupRow=$row;$backupRow['payload_ct']=base64_encode($row['payload_ct']);
$bytes=json_encode(['payload_encoding'=>'base64','row'=>$backupRow],JSON_THROW_ON_ERROR);
$u=umask(0077);$f=fopen(LR_STATE,'x');umask($u);if(!$f)throw new RuntimeException('Receipt exists');fclose($f);
$beforeFile=LR_PRIVATE.'/assignment-context-delivery-96-before-recovery-r2.json';
$u=umask(0077);$f=fopen($beforeFile,'x');umask($u);if(!$f)throw new RuntimeException('Original backup exists');
if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||!fsync($f))throw new RuntimeException('Original backup incomplete');fclose($f);
file_put_contents(LR_STATE,json_encode($report,JSON_THROW_ON_ERROR));
try{
    $pdo->beginTransaction();quantaWorkspaceAssertCurrent($pdo,2002,$snapshot['verified'],true);
    $s=$pdo->prepare('SELECT * FROM integration_deliveries WHERE tenant_id=2002 AND id=96 FOR UPDATE');$s->execute();
    if($s->fetch(PDO::FETCH_ASSOC)!==$row)throw new RuntimeException('Original envelope changed');
    $s=$pdo->prepare('SELECT subject_hash FROM integration_delivery_subjects WHERE tenant_id=2002 AND subject_hash=? FOR UPDATE');$s->execute([$row['subject_hash']]);
    if(!$s->fetchColumn())throw new RuntimeException('Subject lock unavailable');
    $s=$pdo->prepare('SELECT id FROM integration_deliveries WHERE tenant_id=2002 AND subject_hash=? AND state IN ("sending","uncertain")');$s->execute([$row['subject_hash']]);
    if(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN))!==[96])throw new RuntimeException('Another held attempt exists');
    // Retain the original request, review and idempotency identity. Archive only its dedupe slot,
    // so an explicit new preview can create a fresh envelope rather than reviving the old attempt.
    $retired=hash('sha256','verified-local-rejection:96:'.$row['dedupe_hash']);
    $s=$pdo->prepare('UPDATE integration_deliveries SET state="failed",error_code="local_validation_rejected",finished_at=NOW(),dedupe_hash=? WHERE tenant_id=2002 AND id=96 AND state="uncertain"');
    $s->execute([$retired]);if($s->rowCount()!==1)throw new RuntimeException('Recovery did not match');
    $after=integrationDeliveryGet($pdo,2002,96);
    foreach($row as$key=>$value)if(!in_array($key,['state','error_code','finished_at','dedupe_hash'],true)&&$after[$key]!==$value)throw new RuntimeException('Original lineage changed');
    $auditBefore=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM audit_log')->fetchColumn();
    platformAuditLogWrite(2002,4,'quanta.metadata.local_rejection_reconciled',null,
        ['delivery_id'=>96,'old_source_sha256'=>LR_OLD_SHA,'payload_sha256'=>LR_PAYLOAD_SHA,'transport_calls'=>0,
         'original_dedupe_hash'=>$row['dedupe_hash'],'outcome'=>'failed_requires_new_review'],['source'=>'quanta']);
    $s=$pdo->prepare('SELECT meta_json FROM audit_log WHERE id>? AND tenant_id=2002 AND event="quanta.metadata.local_rejection_reconciled"');$s->execute([$auditBefore]);
    $audits=$s->fetchAll(PDO::FETCH_COLUMN);
    if(count($audits)!==1||json_decode($audits[0],true,64,JSON_THROW_ON_ERROR)['payload_sha256']!==LR_PAYLOAD_SHA)throw new RuntimeException('Recovery audit missing');
    $pdo->commit();$report+=['state'=>'failed','original_request_and_identity_preserved'=>true,'new_review_required'=>true];$report['passed']=true;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$report['failure']=$e->getMessage();}
file_put_contents(LR_STATE,json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX);
echo json_encode($report,JSON_THROW_ON_ERROR);exit($report['passed']?0:1);
