<?php
/** Private provider file reads and employer-reviewed vault intake. Never grants approval. */
declare(strict_types=1);
require_once __DIR__.'/onboarding_contract.php';
require_once __DIR__.'/../StorageService.php';
require_once __DIR__.'/onboarding_transfers.php';

const QUANTA_ONBOARDING_DOCUMENT_VERSION='2026-10-23.1';
const QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES=25*1024*1024;

function quantaOnboardingDocumentsEnabled(int $tenant): bool {
    $tenants=array_map('trim',explode(',',(string)getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS')));
    return $tenant>0&&getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS')==='1'&&in_array((string)$tenant,$tenants,true);
}

function quantaOnboardingDocumentAuthority(array $scope): void {
    onboardingScope($scope,'manage');
    if(($scope['permissions']['documents_manage']??false)!==true)throw new OnboardingConflict('Document management permission is required');
    if(!quantaOnboardingDocumentsEnabled($scope['tenant_id']))throw new OnboardingConflict('Document intake has not been enabled for this Core workspace');
}

/** Full-Core HTTP intake has no development/local-storage fallback. */
function quantaOnboardingDocumentStorageReady(): bool {
    return strtolower((string)getenv('STORAGE_DRIVER'))==='s3'
        &&trim((string)getenv('STORAGE_S3_BUCKET'))!==''&&trim((string)getenv('STORAGE_S3_KMS_KEY_ID'))!=='';
}

function quantaOnboardingDocumentStamp(array $head): array {
    return array_intersect_key($head,array_flip(['etag','version_id','size_bytes','last_modified','mime']));
}

/** The durable reservation precedes physical IO; retries verify/reuse exactly that object. */
function quantaOnboardingStoreDocument(array $scope,array $case,array $fetched,\Core\StorageService $storage,array $reservation): array {
    quantaOnboardingDocumentAuthority($scope);$doc=$fetched['document'];$bytes=$fetched['bytes'];
    $pdo=getDB();if($pdo->inTransaction())throw new LogicException('Document IO cannot run inside a transaction');
    $saved=quantaOnboardingTransferGet($pdo,$scope['tenant_id'],$reservation['import_id']);
    foreach(['case_id','people_tenant_id','person_id','storage_key','receipt_hash','descriptor_hash','binding_hash','storage_identity']as$k)
        if(!$saved||(string)$saved[$k]!== (string)$reservation[$k])throw new OnboardingConflict('Document transfer reservation changed');
    if($saved['storage_identity']!==$storage->location_fingerprint()||$saved['case_id']!==$case['id']||(int)$saved['people_tenant_id']!==$case['people_tenant_id']||(int)$saved['person_id']!==$case['person_id']
        ||$saved['descriptor_hash']!==onboardingHash($doc)||$saved['receipt_hash']!==onboardingHash($fetched['receipt']))throw new OnboardingConflict('Document transfer source changed');
    if(strlen($bytes)!==$doc['size_bytes']||!hash_equals($doc['sha256'],hash('sha256',$bytes)))throw new OnboardingConflict('Document bytes changed before storage');
    $key=peopleDocumentKey($case['people_tenant_id'],$case['person_id'],$saved['storage_key']);
    if($storage->head($key)===null){
        if($saved['state']!=='reserved')throw new OnboardingConflict('The previously verified document is unavailable. Reconcile it before retrying.');
        try{$storage->put_reserved('people',$case['people_tenant_id'],'document',$case['person_id'],$key,$bytes,
            ['mime'=>$doc['mime'],'metadata'=>['core_import'=>'quanta_onboarding','provider_document_id'=>$doc['id'],'sha256'=>$doc['sha256']]]);}
        catch(Throwable $e){
            // A lost PUT response or overlapping writer may already have stored the exact object.
            // Never overwrite it; the bounded verification below determines whether it is usable.
            if($storage->head($key)===null)throw $e;
        }
    }
    $head=peopleDocumentHead(static fn($k)=>$storage->head($k),$key);
    $stored=$storage->read($key,QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES);
    $after=peopleDocumentHead(static fn($k)=>$storage->head($k),$key);
    if(!is_string($stored)||strlen($stored)!==$doc['size_bytes']||!hash_equals($doc['sha256'],hash('sha256',$stored))
        ||$head['size_bytes']!==$doc['size_bytes']||($head['mime']??null)!==$doc['mime']
        ||quantaOnboardingDocumentStamp($head)!==quantaOnboardingDocumentStamp($after))throw new OnboardingConflict('The stored document could not be verified');
    quantaOnboardingDocumentAuthority($scope);
    $prepared=['transfer_id'=>$saved['import_id'],'binding_hash'=>$saved['binding_hash'],'storage_identity'=>$saved['storage_identity'],
        'storage_key'=>$key,'stamp'=>quantaOnboardingDocumentStamp($after),'descriptor_hash'=>onboardingHash($doc)];
    quantaOnboardingTransferStored($pdo,$scope,$saved,$prepared);
    return $prepared;
}

/** Registration, onboarding evidence and history share the caller's locked SQL transaction. */
function quantaOnboardingAttachDocument(PDO $pdo,array $scope,array $case,array $receipt,array $document,callable $head): array {
    if(!$pdo->inTransaction())throw new LogicException('Document attachment requires the onboarding transaction');
    quantaOnboardingDocumentAuthority($scope);$doc=quantaOnboardingDocumentMetadata($receipt);
    if(($receipt['kind']??null)!=='document'||($document['descriptor_hash']??null)!==onboardingHash($doc))throw new OnboardingConflict('Prepared document differs from the verified receipt');
    $transfer=quantaOnboardingTransferLock($pdo,$scope,$case,$receipt,$document);
    $key=peopleDocumentKey($case['people_tenant_id'],$case['person_id'],$document['storage_key']??null);
    $verifiedHead=static function(string $actualKey)use($head,$key,$document,$doc):array{
        if($actualKey!==$key)throw new OnboardingConflict('Prepared storage identity changed');
        $blob=peopleDocumentHead($head,$key);
        if(quantaOnboardingDocumentStamp($blob)!==$document['stamp']||$blob['size_bytes']!==$doc['size_bytes']||($blob['mime']??null)!==$doc['mime'])throw new OnboardingConflict('Stored evidence changed before registration');
        return $blob;
    };
    $registered=peopleDocumentRegisterInTransaction($pdo,$scope,$case['person_id'],
        ['doc_type'=>'other','storage_key'=>$key,'filename'=>$doc['filename'],'signed'=>false],$verifiedHead);
    $pdo->prepare('UPDATE quanta_onboarding_document_transfers SET state="registered",document_id=:d,updated_at=UTC_TIMESTAMP() WHERE tenant_id=:t AND import_id=:i')
        ->execute(['d'=>$registered['id'],'t'=>$scope['tenant_id'],'i'=>$transfer['import_id']]);
    return onboardingDocument($pdo,$case,$registered['id'],$verifiedHead,true)+[
        'provider_document_id'=>$doc['id'],'sha256'=>$doc['sha256'],'filename'=>$doc['filename']];
}

/** No browser-supplied file/key/author fields. Fresh provider receipts are the only intake source. */
function quantaOnboardingImportDocument(array $scope,string $caseId,string $receiptId,int $deliveryId,int $expectedVersion,\Core\StorageService $storage): array {
    quantaOnboardingDocumentAuthority($scope);
    if(!preg_match('/^cfs_[a-f0-9]{64}$/D',$receiptId))throw new InvalidArgumentException('Choose a verified worker document');
    $pdo=getDB();$head=static fn($key)=>$storage->head($key);
    $snapshot=quantaOnboardingSnapshot($scope,$caseId,$head);
    $found=array_values(array_filter($snapshot['remote']['submissions']??[],static fn($r)=>($r['id']??null)===$receiptId));
    if(count($found)!==1||($found[0]['kind']??null)!=='document')throw new OnboardingConflict('Worker document is unavailable');
    $receipt=$found[0];$workspace=$snapshot['access']['context']['workspace']['id'];
    $rid=quantaOnboardingImportId($workspace,$receiptId);
    $hash=onboardingHash(['workspace'=>$workspace,'case_id'=>$caseId,'receipt'=>$receipt]);
    if($pdo->inTransaction())throw new LogicException('Document intake owns its transaction');
    $pdo->beginTransaction();
    try{
        $case=quantaOnboardingLock($pdo,$scope,$snapshot,$head,false);
        if($prior=onboardingReceipt($pdo,$scope,$rid,$hash,true)){$pdo->commit();return $prior;}
        quantaOnboardingImportProof($pdo,$scope,$snapshot,$case,$receipt,$deliveryId,$expectedVersion,true);
        $reservation=quantaOnboardingTransferReserve($pdo,$scope,$case,$receipt,$snapshot['access'],$storage);
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    $fetched=quantaOnboardingDocumentFetch($snapshot['access']['api_key'],$workspace,$case,$snapshot['remote'],$receipt,
        $snapshot['worker'],$snapshot['access']['instance_id']);
    $document=quantaOnboardingStoreDocument($scope,$case,$fetched,$storage,$reservation);
    // Recheck the provider after storage IO; the final SQL command rechecks all local authority under lock.
    $latest=quantaOnboardingSnapshot($scope,$caseId,$head);
    $current=array_values(array_filter($latest['remote']['submissions']??[],static fn($r)=>($r['id']??null)===$receiptId));
    if($latest['access']['binding_hash']!==$snapshot['access']['binding_hash']||$latest['worker']!==$snapshot['worker']
        ||($latest['remote']['assignment_current']??null)!==true||count($current)!==1||onboardingHash($current[0])!==onboardingHash($receipt))throw new OnboardingConflict('Provider document or authority changed during intake');
    quantaOnboardingDocumentContext($latest['access']['api_key'],$workspace);
    return quantaOnboardingImportVerified($pdo,$scope,$latest,$receipt,$deliveryId,$expectedVersion,$head,$document);
}

/** Metadata is bound to the authenticated worker receipt, never a caller-supplied URL. */
function quantaOnboardingDocumentMetadata(array $receipt): array {
    $doc=$receipt['document']??null;$keys=['id','contract_version','filename','mime','size_bytes','sha256'];
    if(!is_array($doc)||array_diff(array_keys($doc),$keys)||array_diff($keys,array_keys($doc)))throw new OnboardingConflict('Incomplete or unsupported document descriptor');
    if($doc['contract_version']!==QUANTA_ONBOARDING_DOCUMENT_VERSION
        ||$doc['id']!=='cfof_'.onboardingHash([$receipt['case_id'],$receipt['worker_id'],$receipt['request_id']])
        ||!is_int($doc['size_bytes'])||$doc['size_bytes']<1||$doc['size_bytes']>QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES
        ||!is_string($doc['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$doc['sha256'])
        ||!is_string($doc['filename'])||trim($doc['filename'])===''||preg_match('/[\x00-\x1f\x7f\/\\\\]/',$doc['filename'])
        ||($length=preg_match_all('/./us',$doc['filename']))===false||$length>200
        ||!is_string($doc['mime']))throw new OnboardingConflict('Invalid document descriptor');
    $extension=strtolower(pathinfo($doc['filename'],PATHINFO_EXTENSION));
    if(!in_array($extension,['pdf','png','jpg','jpeg'],true)
        ||$doc['mime']!==['pdf'=>'application/pdf','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'][$extension])
        throw new OnboardingConflict('Unsupported document format');
    return $doc;
}

function quantaOnboardingDocumentContext(string $key,string $workspace): array {
    $context=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/onboarding-document-context');
    if(($context['workspace_id']??null)!==$workspace||($context['contract_version']??null)!==QUANTA_ONBOARDING_DOCUMENT_VERSION
        ||($context['approval_authority']??null)!==false||($context['document_transfer']??null)!==true
        ||($context['max_bytes']??null)!==QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES)
        throw new OnboardingConflict('Quanta document access is unavailable for this workspace');
    return $context;
}

/** The same bounded callbacks are used by cURL and the isolated HTTP fixture. */
final class QuantaDocumentBuffer {
    public string $bytes='';
    public array $headers=[];
    private int $headerBytes=0;
    public function __construct(private int $maxBytes) {
        if($maxBytes<1||$maxBytes>QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES)throw new InvalidArgumentException('Invalid document limit');
    }
    public function header(string $line): int {
        $this->headerBytes+=strlen($line);if($this->headerBytes>16384)return 0;
        if(preg_match('~^HTTP/\S+ \d{3}~',$line))$this->headers=[];
        elseif(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$this->headers[strtolower(trim($name))][]=trim($value);}
        return strlen($line);
    }
    public function body(string $chunk): int {
        if(strlen($this->bytes)+strlen($chunk)>$this->maxBytes)return 0;
        $this->bytes.=$chunk;return strlen($chunk);
    }
}

function quantaOnboardingDocumentBytes(string $key,array $receipt,array $doc): string {
    if($key===''||preg_match('/[\r\n]/',$key)||!preg_match('/^cfo_[a-f0-9]{64}$/D',(string)($receipt['case_id']??''))
        ||!preg_match('/^cfs_[a-f0-9]{64}$/D',(string)($receipt['id']??'')))throw new InvalidArgumentException('Verified document receipt required');
    $url=QUANTA_API_BASE.QUANTA_CORE_CONTRACT_PATH.'/onboarding/'.$receipt['case_id'].'/documents/'.$receipt['id'];
    $headers=['Authorization: Bearer '.$key,'Accept: application/octet-stream','Accept-Encoding: identity'];
    $buffer=new QuantaDocumentBuffer($doc['size_bytes']);
    if(isset($GLOBALS['__quanta_document_transport'])&&is_callable($GLOBALS['__quanta_document_transport'])) {
        $status=($GLOBALS['__quanta_document_transport'])('GET',$url,$headers,$buffer);
    } else {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>40,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static fn($handle,$line)=>$buffer->header($line),
            CURLOPT_WRITEFUNCTION=>static fn($handle,$chunk)=>$buffer->body($chunk)]);
        $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($ok===false)throw new QuantaApiException('Quanta document transfer could not be verified',$status);
    }
    if($status!==200)throw new QuantaApiException('Quanta document transfer could not be verified',(int)$status);
    foreach(['content-type'=>'application/octet-stream','x-core-document-sha256'=>$doc['sha256'],
        'x-core-document-id'=>$doc['id'],'content-length'=>(string)$doc['size_bytes']]as$name=>$value)
        if(($buffer->headers[$name]??null)!==[$value])throw new OnboardingConflict('Document response does not match its receipt');
    if(isset($buffer->headers['content-encoding'])&&$buffer->headers['content-encoding']!==['identity'])throw new OnboardingConflict('Unexpected document encoding');
    $bytes=$buffer->bytes;
    if(strlen($bytes)!==$doc['size_bytes']||!hash_equals($doc['sha256'],hash('sha256',$bytes)))throw new OnboardingConflict('Document contents do not match the worker receipt');
    $signature=match($doc['mime']){'application/pdf'=>'%PDF-','image/png'=>"\x89PNG\r\n\x1a\n",'image/jpeg'=>"\xff\xd8\xff",default=>null};
    if($signature===null||!str_starts_with($bytes,$signature))throw new OnboardingConflict('Document format does not match the worker receipt');
    return $bytes;
}

/** Read-only preparation. The later vault import must recheck Core authority under its own transaction. */
function quantaOnboardingDocumentFetch(string $key,string $workspace,array $case,array $publication,array $receipt,string $worker,string $instance): array {
    if(!quantaOnboardingDocumentsEnabled((int)($case['tenant_id']??0)))throw new OnboardingConflict('Document intake has not been enabled for this Core workspace');
    quantaOnboardingSubmissionValidate($case,$publication,$receipt,$worker,$instance,$workspace,true);
    if($receipt['kind']!=='document')throw new OnboardingConflict('Choose a document submission');
    $document=quantaOnboardingDocumentMetadata($receipt);
    quantaOnboardingDocumentContext($key,$workspace);
    $bytes=quantaOnboardingDocumentBytes($key,$receipt,$document);
    if(!quantaOnboardingDocumentsEnabled((int)$case['tenant_id']))throw new OnboardingConflict('Core document access changed during retrieval');
    return ['receipt'=>$receipt,'document'=>$document,'bytes'=>$bytes];
}
