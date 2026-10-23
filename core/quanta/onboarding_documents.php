<?php
/** Private provider file reads. No vault registration, case mutation or approval. */
declare(strict_types=1);
require_once __DIR__.'/onboarding_contract.php';

const QUANTA_ONBOARDING_DOCUMENT_VERSION='2026-10-23.1';
const QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES=25*1024*1024;

function quantaOnboardingDocumentsEnabled(int $tenant): bool {
    $tenants=array_map('trim',explode(',',(string)getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS')));
    return $tenant>0&&getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS')==='1'&&in_array((string)$tenant,$tenants,true);
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
