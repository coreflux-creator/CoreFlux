<?php
/** Actual Core PHP client -> isolated loopback Quanta HTTP app only. */
declare(strict_types=1);
$origin=(string)getenv('COREFLUX_QUANTA_FIXTURE_ORIGIN');
if(getenv('COREFLUX_QUANTA_CONTRACT_FIXTURE')!=='1' || !preg_match('~^http://127\.0\.0\.1:[1-9][0-9]{0,4}$~D',$origin)) throw new RuntimeException('Explicit loopback fixture required');
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/worker_profile_contract.php';
$transport=static function($method,$url,$headers,$body='')use($origin){
    if(!str_starts_with($url,QUANTA_API_BASE.'/'))throw new RuntimeException('Unexpected route');
    $options=['http'=>['method'=>$method,'header'=>implode("\r\n",$headers),'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>10]];
    $raw=file_get_contents($origin.'/api/v1'.substr($url,strlen(QUANTA_API_BASE)),false,stream_context_create($options));
    if($raw===false)throw new RuntimeException('Fixture transport interrupted');
    preg_match('/^HTTP\/\S+ ([0-9]{3})/',$http_response_header[0]??'',$match);
    return ['status'=>(int)($match[1]??0),'body'=>$raw];
};
$GLOBALS['__quanta_transport']=$transport;$GLOBALS['__quanta_core_write_transport']=$transport;
$passed=0;$check=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$key='ck_fixture_profile-a';
$context=quantaWorkerProfileContext($key);
$check($context['workspace']['id']==='a','Verified worker-profile capability and tenant');
$person=integrationRecordReference('381e452a-5d11-4e2e-9daa-e9427501898a',1,'person','P-777');
$body=['person'=>$person,'email'=>'new.worker@example.com','full_name'=>'Zoë / 合作','phone'=>'+15555550111','worker_id'=>null,'expected_worker'=>null];
$created=quantaWriteWorkerProfile($key,'a',$body,str_repeat('5',64));
$check($created['worker']['status']==='disabled' && !$created['invitation_sent'],'Prepared disabled worker with no invitation');
$replay=quantaWriteWorkerProfile($key,'a',$body,str_repeat('5',64));
$check($replay===$created,'Exact receipt replay');
$recovered=quantaWorkerProfileReceipt($key,'a',$body,str_repeat('5',64));
$check($recovered['response']===$created,'Original request key recovers a receipt with a Unicode and slash request hash');
$check(quantaWorkerProfileReceipt($key,'a',$body,str_repeat('9',64))===null,'Absent receipt remains unconfirmed');
$read=quantaWorkerProfileRead($key,'a',$person);
$check($read['worker_id']===$created['worker_id'],'Read back shared person and native worker');
$update=array_replace($body,['worker_id'=>$read['worker_id'],'full_name'=>'Reviewed Worker','phone'=>null,'expected_worker'=>$read['worker']]);
$updated=quantaWriteWorkerProfile($key,'a',$update,str_repeat('6',64));
$check($updated['worker']['full_name']==='Reviewed Worker' && $updated['worker']['phone']==='+15555550111','Reviewed update preserves omitted phone');
$again=quantaWriteWorkerProfile($key,'a',$update,str_repeat('6',64));
$check($again===$updated,'Contact update replay is exact');
try{quantaWriteWorkerProfile($key,'a',$update,str_repeat('7',64));$conflict=false;}
catch(QuantaContractWriteException $e){$conflict=$e->uncertain;}
$check($conflict,'Stale observation refuses another write');
$receipt=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/deliveries/'.$updated['delivery_id']);
$check($receipt['state']==='complete' && $receipt['response']['worker_id']===$updated['worker_id'],'Read durable provider receipt');
$check(quantaWorkerProfileRead('ck_fixture_profile-b','b',$person)===null,'Other tenant cannot read profile');
try{quantaWriteWorkerProfile('ck_fixture_write-a','a',$body,str_repeat('8',64));$denied=false;}
catch(QuantaContractWriteException $e){$denied=!$e->uncertain;}
$check($denied,'Metadata-only authority cannot prepare workers');
echo json_encode(['passed'=>$passed,'scope'=>'Core PHP -> isolated Quanta worker-profile HTTP']),"\n";
