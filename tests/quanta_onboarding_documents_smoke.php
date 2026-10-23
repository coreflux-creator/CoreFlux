<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/onboarding_documents.php';
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$passed++;};
$fails=static function(callable $f,string $label)use($check){try{$f();}catch(OnboardingConflict|QuantaApiException|InvalidArgumentException $e){$check(true,$label);return;}throw new RuntimeException('Accepted '.$label);};
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';$worker='worker-1';$workspace='a';
$person=integrationRecordReference($instance,10,'person','P-1');$placement=integrationRecordReference($instance,11,'placement','PL-1');
$assignment=['id'=>quantaCoreRemoteId($workspace,$placement),'tenant_id'=>$workspace,'person'=>$person,'placement'=>$placement,
    'worker_id'=>$worker,'person_link_id'=>quantaCoreRemoteId($workspace,$person),'version'=>1,'status'=>'draft'];
$case=['id'=>onboardingUuid(),'tenant_id'=>1,'people_tenant_id'=>10,'placements_tenant_id'=>11,'person_number'=>'P-1','placement_number'=>'PL-1',
    'version'=>4,'plan_version'=>2,'confirmed_plan_version'=>2,'source_changed'=>false,'source_revision'=>str_repeat('a',64),'required_count'=>1,
    'tasks'=>[['id'=>onboardingUuid(),'version'=>1,'title'=>'Placement file','instructions'=>'Provide the requested file.','assignee'=>'worker',
        'evidence_kind'=>'document','required'=>true,'effective_state'=>'requested']]];
$record=quantaOnboardingPlan($case,$instance,$workspace,$assignment,null)['expected'];$request=onboardingUuid();
$receipt=['id'=>'cfs_'.onboardingHash([$record['id'],$worker,$request]),'request_id'=>$request,'request_hash'=>str_repeat('b',64),
    'worker_id'=>$worker,'identity_id'=>'identity-1','case_id'=>$record['id'],'core_case_id'=>$case['id'],'core_version'=>4,
    'source_revision'=>$case['source_revision'],'projection_hash'=>$record['projection_hash'],'task_id'=>$case['tasks'][0]['id'],
    'task_version'=>1,'kind'=>'document','acknowledged'=>true,'submitted_at'=>'2026-10-23T00:00:00+00:00','status'=>'awaiting_core_review'];
$content="%PDF-1.4\nSynthetic document\n%%EOF";
$doc=['id'=>'cfof_'.onboardingHash([$record['id'],$worker,$request]),'contract_version'=>QUANTA_ONBOARDING_DOCUMENT_VERSION,
    'filename'=>'placement.pdf','mime'=>'application/pdf','size_bytes'=>strlen($content),'sha256'=>hash('sha256',$content)];
$receipt['document']=$doc;$calls=0;$mode='ok';
$GLOBALS['__quanta_transport']=static function()use(&$calls,$workspace){$calls++;return['status'=>200,'body'=>json_encode([
    'workspace_id'=>$workspace,'contract_version'=>QUANTA_ONBOARDING_DOCUMENT_VERSION,'document_transfer'=>true,
    'approval_authority'=>false,'max_bytes'=>QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES])];};
$GLOBALS['__quanta_document_transport']=static function($method,$url,$headers,$buffer)use(&$calls,&$mode,$content,$doc,$check,$record,$receipt){
    $calls++;$check($url===QUANTA_API_BASE.QUANTA_CORE_CONTRACT_PATH.'/onboarding/'.$record['id'].'/documents/'.$receipt['id'],'document path comes only from verified receipt IDs');
    if($mode==='redirect')return 302;
    foreach(['content-type'=>'application/octet-stream','x-core-document-id'=>$doc['id'],'x-core-document-sha256'=>$doc['sha256'],'content-length'=>(string)$doc['size_bytes']]as$n=>$v)$buffer->header($n.': '.$v."\r\n");
    if($mode==='duplicate')$buffer->header('x-core-document-id: '.$doc['id']."\r\n");
    if($mode==='encoding')$buffer->header("Content-Encoding: gzip\r\n");
    $buffer->body($mode==='tampered'?str_replace('Synthetic','Different',$content):$content);return 200;
};
$fetch=static fn($r=null)=>quantaOnboardingDocumentFetch('fixture-key',$workspace,$case,$record,$r??$receipt,$worker,$instance);
foreach([['','1'],['1',''],['1','2'],['1','*']]as[$flag,$tenants]){
    putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS='.$flag);putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS='.$tenants);
    $fails(fn()=>$fetch(),'disabled or different tenant cannot fetch private bytes');
}
$check($calls===0,'disabled preparation makes no provider request');
putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS=1');putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS=1');
$fetched=$fetch();$check($fetched['bytes']===$content&&$fetched['receipt']===$receipt,'receipt-bound file bytes arrive intact');
$fails(fn()=>quantaOnboardingSubmissionValidate($case,$record,$receipt,$worker,$instance,$workspace),'existing attestation import still rejects documents');
foreach(['redirect','duplicate','encoding','tampered']as$mode)$fails(fn()=>$fetch(),'redirect or incompatible file response rejected: '.$mode);$mode='ok';
foreach([['id'=>'cfof_'.str_repeat('0',64)],['filename'=>'../private.pdf'],['filename'=>'bad\\name.pdf'],['filename'=>"bad\nname.pdf"],['filename'=>str_repeat('a',201).'.pdf'],
    ['filename'=>'name.exe'],['mime'=>'text/html'],['size_bytes'=>0],['size_bytes'=>QUANTA_ONBOARDING_DOCUMENT_MAX_BYTES+1],['size_bytes'=>'1'],
    ['sha256'=>str_repeat('G',64)],['url'=>'https://other.invalid/private'],['contract_version'=>'unknown']]as$change){
    $r=$receipt;$r['document']=array_replace($doc,$change);$before=$calls;$fails(fn()=>$fetch($r),'invalid descriptor cannot start file retrieval');$check($calls===$before,'descriptor failure occurs before network IO');
}
$buffer=new QuantaDocumentBuffer(10);$check($buffer->body('123456')===6&&$buffer->body('78901')===0&&$buffer->bytes==='123456','streaming body stops before exceeding receipt byte limit');
$check($buffer->header(str_repeat('x',16385))===0,'oversized response headers stop the transfer');
foreach([['worker_id'=>'another-worker'],['task_version'=>99],['status'=>'approved'],['source_revision'=>str_repeat('c',64)]]as$change){
    $before=$calls;$fails(fn()=>$fetch(array_replace($receipt,$change)),'stale or foreign worker evidence cannot fetch a file');
    $check($before===$calls,'invalid worker evidence is rejected before provider IO');
}
$wrongFormat=$receipt;$wrongFormat['document']['mime']='image/png';$wrongFormat['document']['filename']='evidence.png';
$fails(fn()=>quantaOnboardingDocumentBytes('fixture-key',$wrongFormat,$wrongFormat['document']),'self-consistent descriptor still requires matching format bytes');
$GLOBALS['__quanta_transport']=static fn()=>['status'=>200,'body'=>json_encode(['workspace_id'=>'other'])];
$fails(fn()=>$fetch(),'fresh capability must match the exact workspace');
unset($GLOBALS['__quanta_transport'],$GLOBALS['__quanta_document_transport']);
putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS');putenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENT_TENANTS');
echo "PASS $passed Quanta private document checks\n";
