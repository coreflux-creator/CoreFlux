<?php
/** Optional cross-repository fixture: only a loopback bridge to Quanta's isolated ASGI test app. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_QUANTA_FIXTURE_ORIGIN');
if(getenv('COREFLUX_QUANTA_CONTRACT_FIXTURE')!=='1' || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin)) throw new RuntimeException('Explicit loopback contract fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/core_contract.php';
$transport=static function($method,$url,$headers,$body='')use($origin){
    if(!str_starts_with($url,QUANTA_API_BASE.'/'))throw new RuntimeException('Unexpected provider route');
    $fixtureUrl=$origin.substr($url,strlen('https://helloquanta.app'));
    $options=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]];
    $raw=file_get_contents($fixtureUrl,false,stream_context_create($options));
    if($raw===false)throw new RuntimeException('Fixture transport failed');
    preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$raw];
};
$GLOBALS['__quanta_transport']=$transport;
$GLOBALS['__quanta_core_write_transport']=$transport;
$passed=0;$check=static function($ok,$message)use(&$passed){if(!$ok)throw new RuntimeException($message);$passed++;};
$key='ck_fixture_write-a';
$context=quantaContractContext($key);
$check($context['workspace']['id']==='a','Authenticated provider workspace');
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$ref=static fn($kind,$number)=>integrationRecordReference($instance,1,$kind,$number);
$person=$ref('person','P-333');$placement=$ref('placement','PL-810');
$body=['person'=>$person,'worker_id'=>'worker-1'];
$linked=quantaWriteContract($key,'a','person.link',$body,$body,str_repeat('1',64));
$check(quantaContractRecord($key,'a',$person)['id']===$linked['id'],'Person link readback');
$desired=['placement'=>$placement,'person'=>$person,'client'=>$ref('client','CL-11'),'vendor'=>$ref('vendor','V-12'),
    'end_client'=>$ref('company','CO-13'),'worksite_id'=>'site-1','title'=>'Synthetic assignment','engagement_type'=>'c2c',
    'start_date'=>'2026-10-01','end_date'=>null,'status'=>'draft',
    'work_location'=>['city'=>'Montréal','state'=>'QC','country'=>'CA']];
quantaAssignmentLocationRequire($key,'a');
$created=quantaWriteContract($key,'a','assignment.create',$desired,$desired,str_repeat('2',64));
$check($created['version']===1&&$created['work_location']===$desired['work_location'],'Assignment created at version one with exact location');
$check(quantaContractRecord($key,'a',$placement)['id']===$created['id'],'Assignment readback');
$replay=quantaWriteContract($key,'a','assignment.create',$desired,$desired,str_repeat('2',64));
$check($replay['delivery_id']===$created['delivery_id'],'Create replay uses the durable receipt');
$changed=array_replace($desired,['title'=>'Reviewed title','status'=>'active']);
try{quantaWriteContract($key,'a','assignment.create',$changed,$changed,str_repeat('2',64));$conflict=false;}
catch(QuantaContractWriteException $e){$conflict=$e->uncertain;}
$check($conflict,'Changed body cannot reuse a delivery key');
$plan=quantaAssignmentPlan('a',$changed,$created);
$updated=quantaWriteContract($key,'a',$plan['operation'],$plan['body'],$plan['expected'],str_repeat('3',64));
$check($updated['version']===2 && $updated['title']==='Reviewed title','Conditional assignment update');
$again=quantaWriteContract($key,'a',$plan['operation'],$plan['body'],$plan['expected'],str_repeat('3',64));
$check($again['version']===2 && $again['delivery_id']===$updated['delivery_id'],'Update replay cannot increment twice');
try{quantaWriteContract($key,'a',$plan['operation'],$plan['body'],$plan['expected'],str_repeat('4',64));$stale=false;}
catch(QuantaContractWriteException $e){$stale=$e->uncertain;}
$check($stale,'Stale conditional edit rejected');
$receipt=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/deliveries/'.$updated['delivery_id']);
$check($receipt['state']==='complete' && $receipt['response']['id']===$updated['id'],'Provider receipt confirms the result');
try{quantaGet('ck_fixture_read-b',QUANTA_CORE_CONTRACT_PATH.'/assignments/'.$updated['id']);$isolated=false;}
catch(QuantaApiException $e){$isolated=$e->httpStatus===404;}
$check($isolated,'Other provider tenant cannot read assignment');
try{quantaContractContext('ck_fixture_worker-a');$scoped=false;}
catch(QuantaApiException $e){$scoped=$e->httpStatus===403;}
$check($scoped,'Worker permission does not imply Core integration permission');
echo json_encode(['passed'=>$passed,'scope'=>'synthetic Core PHP to Quanta ASGI HTTP contract']),"\n";
