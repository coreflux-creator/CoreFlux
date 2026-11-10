<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/metadata_delivery.php';
require_once __DIR__.'/../deploy/accept_synthetic_assignment_refresh.php';
$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$fails=static function(callable $fn):bool{try{$fn();return false;}catch(Throwable $e){return true;}};
$binary=[['id'=>1,'api_key_ct'=>"\xff\x00\xfe\x80"]];
$check(assignmentPilotRowsHash($binary)===assignmentPilotRowsHash($binary),'Binary encrypted database values remain comparable without decoding');
$check(assignmentPilotRowsHash($binary)!==assignmentPilotRowsHash([['id'=>1,'api_key_ct'=>"\xff\x00\xfe\x81"]]),'A ciphertext byte change is detected');
$location=['city'=>null,'state'=>'NJ','country'=>'US'];
$snapshot=['verified'=>['context'=>['workspace'=>['id'=>ASSIGNMENT_PILOT_QUANTA]]],
    'source'=>['reference'=>assignmentPilotReference('placement','PL-13'),'person'=>assignmentPilotReference('person','P-15'),
        'worker_id'=>ASSIGNMENT_PILOT_WORKER,'assignment'=>['work_location'=>$location]],
    'plan'=>['remote_id'=>ASSIGNMENT_PILOT_REMOTE,'operation'=>'assignment.update','status'=>'preview','body'=>['expected_version'=>2,'work_location'=>$location]],
    'remote'=>['assignment'=>['id'=>ASSIGNMENT_PILOT_REMOTE,'worker_id'=>ASSIGNMENT_PILOT_WORKER,'version'=>2]]];
assignmentPilotQuantaChange($snapshot);$check(true,'Exact location-only conditional update accepted');
$unchanged=$snapshot;$unchanged['plan']['status']='unchanged';$unchanged['plan']['body']=['expected_version'=>2];
assignmentPilotQuantaChange($unchanged);$check(true,'Exact unchanged replay accepted');
foreach(['title'=>'Unreviewed title','status'=>'ended','end_date'=>'2026-10-25','pay_rate'=>'99'] as $field=>$value){
    $bad=$snapshot;$bad['plan']['body'][$field]=$value;
    $check($fails(fn()=>assignmentPilotQuantaChange($bad)),'Unrelated Quanta update rejected: '.$field);
}
foreach(['workspace','worker','placement','person','location','version','create'] as $case){
    $bad=$snapshot;
    if($case==='workspace')$bad['verified']['context']['workspace']['id']='other';
    if($case==='worker')$bad['source']['worker_id']='other';
    if($case==='placement')$bad['source']['reference']=assignmentPilotReference('placement','PL-864');
    if($case==='person')$bad['source']['person']=assignmentPilotReference('person','P-358');
    if($case==='location')$bad['source']['assignment']['work_location']['city']='Invented city';
    if($case==='version')$bad['plan']['body']['expected_version']=1;
    if($case==='create')$bad['plan']['operation']='assignment.create';
    $check($fails(fn()=>assignmentPilotQuantaChange($bad)),'Quanta guard rejects changed '.$case);
}
$row=['tenant_id'=>999,'provider'=>'quanta','operation'=>'assignment.update','entity_type'=>'placement','entity_tenant_id'=>999,'entity_id'=>13];
$payload=['body'=>$snapshot['plan']['body'],'expected'=>['placement'=>$snapshot['source']['reference'],'person'=>$snapshot['source']['person']]];
assignmentPilotOriginalLocation($row,$payload);$check(true,'Original location attempt accepted for recovery');
foreach(['tenant_id'=>2,'entity_tenant_id'=>2,'entity_id'=>864,'provider'=>'purepay','operation'=>'assignment.create'] as $field=>$value){
    $check($fails(fn()=>assignmentPilotOriginalLocation(array_replace($row,[$field=>$value]),$payload)),'Foreign original delivery rejected: '.$field);
}
$bad=$payload;$bad['body']['title']='Not a location-only attempt';
$check($fails(fn()=>assignmentPilotOriginalLocation($row,$bad)),'Unrelated original attempt cannot be recovered by this runner');
$old=['contract_version'=>'2026-10-22.2','placement'=>assignmentPilotReference('placement','PL-13'),
    'person'=>assignmentPilotReference('person','P-15'),'client'=>assignmentPilotReference('client','CL-1'),
    'terms'=>[['pay_rates'=>['regular'=>'30'],'bill_rates'=>['regular'=>'60']]],
    'work_context'=>['worksite_state'=>'NJ','worksite_country'=>'US'],'source_revision'=>str_repeat('a',64),'previous_revision'=>null];
$fresh=$old;$fresh['contract_version']='2026-10-25.1';$fresh['work_context']['worksite_city']=null;
unset($fresh['source_revision'],$fresh['previous_revision']);$fresh['source_revision']=integrationDeliveryHash($fresh);$fresh['previous_revision']=$old['source_revision'];
assignmentPilotCommercialChange($old,$fresh);$check(true,'Exact commercial location extension accepted');
foreach(['rate','client','city','previous','hash','scope','version'] as $case){
    $bad=$fresh;
    if($case==='rate')$bad['terms'][0]['pay_rates']['regular']='99';
    if($case==='client')$bad['client']=assignmentPilotReference('client','CL-2');
    if($case==='city')$bad['work_context']['worksite_city']='Invented city';
    if($case==='previous')$bad['previous_revision']=str_repeat('b',64);
    if($case==='hash')$bad['source_revision']=str_repeat('b',64);
    if($case==='scope')$bad['placement']['directory_tenant_id']=2;
    if($case==='version')$bad['contract_version']='2026-10-22.2';
    $check($fails(fn()=>assignmentPilotCommercialChange($old,$bad)),'Commercial guard rejects changed '.$case);
}
$bad=$old;$bad['contract_version']='2026-10-22.1';
$check($fails(fn()=>assignmentPilotCommercialChange($bad,$fresh)),'Incomplete legacy commercial graph cannot be silently upgraded');
foreach(['--publish-demo-location','--publish-demo-commercial','--production',''] as $mode){
    $pipes=[];$proc=proc_open([PHP_BINARY,__DIR__.'/../deploy/accept_synthetic_assignment_refresh.php',$mode],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,['COREFLUX_ENV'=>'staging','COREFLUX_DISABLE_DATABASE'=>'1']);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    $check(proc_close($proc)===2 && $out==='' && str_contains($error,'Exact isolated staging'),'Wrong host cannot reach application or credentials');
}
echo "Synthetic assignment refresh guards: {$checks} passed\n";
