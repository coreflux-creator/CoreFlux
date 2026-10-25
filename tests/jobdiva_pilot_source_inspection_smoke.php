<?php
declare(strict_types=1);
require_once __DIR__.'/../deploy/jobdiva_pilot_source.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS $label\n";};
$ids=['placement'=>'101','person'=>'201','job'=>'301'];
$start=['id'=>'101','candidate id'=>'201','job id'=>'301','start date'=>'10/01/2026','startStatus'=>'Active'];
$detail=['START_ID'=>'101','EMPLOYEEID'=>'201','JOBID'=>'301',
    'BILLING'=>[['START_ID'=>'101','START_DATE'=>'2026-10-01','END_DATE'=>null,'WORKING_COUNTRY'=>'United States','APPROVED'=>true,'ACTUALSTART'=>true]],
    'SALARY'=>[['EMPLOYMENT_CATEGORY'=>'W2','APPROVED'=>true,'SALARY'=>40]],
    'private_sentinel'=>'PRIVATE-WORKER-DETAIL'];
$run=static function(array $responses,array &$calls)use($ids):array{
    $calls=[];
    return fullCoreJobdivaReadPilot($ids,static function($method,$path,$body,$query)use(&$responses,&$calls):array{
        $calls[]=[$method,$path,$body,$query];
        if (!$responses) throw new RuntimeException('Unexpected extra provider call');
        $next=array_shift($responses);
        if ($next instanceof Throwable) throw $next;
        return $next;
    });
};
$ok=static fn($body):array=>['status'=>200,'body'=>$body];
$calls=[];
$result=$run([$ok([$start]),$ok(['data'=>[$detail]])],$calls);
$check($result['report']['status']==='exact_source_observed'&&$result['report']['source_refreshed'],'fresh exact assignment and detail are verified');
$check($result['contract']['start_id']==='101'&&$result['contract']['worksite_country']==='United States','contract derives only from exact fresh provider detail');
$check($calls[0]===['POST','/apiv2/jobdiva/searchStart',['jobId'=>301,'candidateid'=>201,'maxreturned'=>100,'offset'=>0],null],
    'Start lookup is bounded to the original person and job');
$check($calls[1]===['GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,['startId'=>'101','userFieldsName'=>'']],
    'detail lookup uses only the exact original assignment');
$check(count($calls)===2&&!$result['report']['record_writes'],'successful lookup performs only the two source reads');
$serialized=json_encode($result['report']);
$check(!str_contains($serialized,'PRIVATE-WORKER')&&!str_contains($serialized,'United States')&&!str_contains($serialized,'101'),
    'public report excludes provider record values and source identifiers');
$check($result['report']['provider_calls'][0]['response_shape']==='list'
    &&$result['report']['provider_calls'][1]['recognized_envelope']==='data'
    &&$result['report']['start_diagnostics']['rows_with_expected_person_and_job']===1,
    'diagnostics report fixed shape labels and scoped identity counts');
$incomplete=$start;unset($incomplete['candidate id'],$incomplete['startStatus']);
$result=$run([$ok([$incomplete])],$calls);
$diagnostic=$result['report']['start_diagnostics']['expected_id_checks'][0];
$check($diagnostic['person_echo']==='missing'&&!$diagnostic['assignment_row_recognized']
    &&$diagnostic['lifecycle_reason']==='missing_assignment_state'&&$result['contract']===null&&count($calls)===1,
    'generic ID diagnostics never promote an incomplete row into a verified assignment');
$unknown=$start;$unknown['startStatus']='PRIVATE-STATUS-SENTINEL';$unknown['PRIVATE-FIELD-NAME']='PRIVATE-VALUE';
$result=$run([$ok([$unknown]),$ok([$detail])],$calls);
$check($result['report']['start_diagnostics']['expected_id_checks'][0]['lifecycle_reason']==='unqualified_assignment_state'
    &&!str_contains(json_encode($result['report']),'PRIVATE-')&&!$result['report']['import_authorized'],
    'unrecognized lifecycle and field names are reduced to fixed diagnostics without exposing values');
$sparse=$start;unset($sparse['startStatus']);
$result=$run([$ok([$sparse]),$ok([$detail])],$calls);
$check($result['report']['source_refreshed']&&$result['report']['start_lifecycle']===['qualified'=>false,'reason'=>'missing_assignment_state']
    &&!$result['report']['import_authorized']&&$result['contract']['placement_status']==='active',
    'exact source detail can be inspected despite a missing Start status without authorizing an import');
$rejected=$start;$rejected['startStatus']='Cancelled';
$result=$run([$ok([$rejected]),$ok([$detail])],$calls);
$check($result['report']['start_lifecycle']===['qualified'=>false,'reason'=>'rejected_assignment_state']
    &&!$result['report']['import_authorized'],
    'source lifecycle rejection remains explicit when read-only detail inspection succeeds');
$expired=$detail;$expired['BILLING'][0]['END_DATE']='2001-01-01T23:59:59-0500';
$result=$run([$ok([$sparse]),$ok([$expired])],$calls);
$check($result['contract']['placement_status']==='ended',
    'standalone source inspection honors the assignment end date without loading the sync driver');
$conflicting=$start;$conflicting['employeeId']='999';
$result=$run([$ok([$conflicting])],$calls);
$check($result['contract']===null&&count($calls)===1,'conflicting person aliases stop before detail inspection');
$conflicting=$start;$conflicting['JOB_ID']='999';
$result=$run([$ok([$conflicting])],$calls);
$check($result['contract']===null&&count($calls)===1,'conflicting job aliases stop before detail inspection');
$result=$run([$ok([])],$calls);
$check($result['report']['start_diagnostics']['parsed_rows']===0
    &&$result['report']['start_diagnostics']['expected_id_checks']===[]&&count($calls)===1,
    'an empty source response stays distinct from rejected returned records');
$wrong=$start;$wrong['candidate id']='999';
$result=$run([$ok([$wrong])],$calls);
$check($result['report']['status']==='exact_assignment_unavailable'&&$result['contract']===null&&count($calls)===1,
    'different source person stops before assignment-detail access');
$wrong=$start;unset($wrong['candidate id']);
$check($run([$ok([$wrong])],$calls)['contract']===null,'missing person echo is not inferred from request criteria');
$wrong=$start;$wrong['job id']='999';
$check($run([$ok([$wrong])],$calls)['contract']===null,'different source job is rejected');
$wrong=$start;$wrong['id']='102';
$check($run([$ok([$wrong])],$calls)['contract']===null,'different Start cannot replace the requested assignment');
$check($run([$ok([$start,$start])],$calls)['report']['status']==='assignment_ambiguous','duplicate exact Starts are held for review');
$wrong=$detail;$wrong['START_ID']='102';$wrong['BILLING'][0]['START_ID']='102';
$result=$run([$ok([$start]),$ok([$wrong]),$ok([$detail])],$calls);
$check($result['report']['status']==='exact_source_observed'&&count($calls)===3,'employee fallback still requires exact original assignment detail');
$check($calls[2]===['GET','/apiv2/bi/EmployeeAssignmentRecordsDetail',null,['employeeId'=>'201','userFieldsName'=>'']],
    'fallback is scoped to the original person');
$result=$run([$ok([$start]),$ok([$wrong]),$ok([$wrong])],$calls);
$check($result['report']['status']==='exact_assignment_detail_unavailable'&&$result['contract']===null,
    'unrelated detail cannot produce a fallback contract');
$noEcho=$detail;unset($noEcho['START_ID'],$noEcho['BILLING'][0]['START_ID']);
$check(!fullCoreJobdivaDetailHasExactIdentity($noEcho,$ids),'person/job context alone cannot prove the detail Start identity');
$conflict=$detail;$conflict['BILLING'][]=['START_ID'=>'999'];
$check(!fullCoreJobdivaDetailHasExactIdentity($conflict,$ids),'conflicting nested assignment identities are not hidden by the first value');
$conflict=$detail;$conflict['JOB']=['JOBID'=>'999'];
$check(!fullCoreJobdivaDetailHasExactIdentity($conflict,$ids),'conflicting nested job identities remain ambiguous');
$result=$run([$ok([$start]),$ok([$detail,$detail])],$calls);
$check($result['report']['status']==='assignment_detail_ambiguous'&&count($calls)===2,'duplicate detail is not collapsed into a guessed source');
foreach([401,403,429,500] as $status){
    $result=$run([$ok([$start]),['status'=>$status,'body'=>'SECRET-RESPONSE']],$calls);
    $check(count($calls)===2&&$result['contract']===null&&!str_contains(json_encode($result['report']),'SECRET-RESPONSE'),
        'authorization or service errors do not trigger broader lookup or expose response bodies');
}
$result=$run([new RuntimeException('SECRET-TOKEN-IN-ERROR')],$calls);
$check($result['contract']===null&&!str_contains(json_encode($result),'SECRET-TOKEN'),'transport exception details cannot leak into the public report');
$called=false;$invalid=$ids;$invalid['person']='201 OR 1=1';
try{fullCoreJobdivaReadPilot($invalid,static function()use(&$called){$called=true;return [];});throw new RuntimeException('Invalid identity accepted');}
catch(InvalidArgumentException $e){$check(!$called,'invalid request identities are rejected before transport');}
$invalid=$ids;$invalid['person']='99999999999999999999999999';
try{fullCoreJobdivaReadPilot($invalid,static fn()=>[]);throw new RuntimeException('Overflow accepted');}
catch(InvalidArgumentException $e){$check(true,'provider request integer overflow is rejected');}
$readPerson=static function(array $rows):array{
    return fullCoreJobdivaReadPerson('201',static fn()=>['status'=>200,'body'=>['data'=>$rows]]);
};
$candidate=['id'=>201,'candidateId'=>'201','firstName'=>'PRIVATE-FIRST','lastName'=>'PRIVATE-LAST',
    'email'=>'PRIVATE@example.test','phone'=>'+1 (555) 123-4567','private_custom'=>'DO-NOT-EMIT'];
$personCalls=[];
$person=fullCoreJobdivaReadPerson('201',static function($method,$path,$body,$query)use(&$personCalls,$candidate):array{
    $personCalls[]=[$method,$path,$body,$query];return ['status'=>200,'body'=>[$candidate]];
});
$check($personCalls===[['POST','/apiv2/jobdiva/searchCandidate',['candidateId'=>'201'],null]],'person read uses only the explicitly selected candidate');
$check($person['report']['exact_person_verified']&&$person['report']['source_refreshed']&&!$person['report']['record_writes'],'exact person echo proves the bounded source read only');
$check(!str_contains(json_encode($person['report']),'PRIVATE')&&!str_contains(json_encode($person['report']),'201')
    &&!str_contains(json_encode($person['report']),'DO-NOT-EMIT'),'person diagnostics contain no contact data, provider ID or custom values');
$check($person['person']['fields']['email_primary']['value']==='private@example.test'
    &&$person['person']['fields']['phone_primary']['value']==='+15551234567','contact comparison normalizes case and phone formatting without country inference');
foreach([[],[$candidate,$candidate],[array_replace($candidate,['candidateId'=>'999'])],
    [array_replace($candidate,['id'=>true])],[['firstName'=>'PRIVATE-FIRST']]] as $rows)
    $check($readPerson($rows)['person']===null,'missing, duplicate or conflicting candidate identity remains unavailable');
$alias=$candidate;$alias['EMAIL_ADDRESS']='different@example.test';
$check($readPerson([$alias])['report']['fields']['email_primary']==='ambiguous','conflicting contact aliases are explicit rather than first-match wins');
$alias=$candidate;$alias['phone']=['PRIVATE-PHONE'];
$check($readPerson([$alias])['report']['fields']['phone_primary']==='unrecognized','structured source contact values are not cast or exposed');
$alias=$candidate;$alias['email']='';unset($alias['lastName']);
$missing=$readPerson([$alias]);
$check($missing['report']['fields']['email_primary']==='missing'&&$missing['report']['fields']['last_name']==='missing','blank and absent contact fields stay missing');
foreach([401,403,429,500] as $status){
    $result=fullCoreJobdivaReadPerson('201',static fn()=>['status'=>$status,'body'=>'PRIVATE-TOKEN']);
    $check($result['person']===null&&$result['report']['http_status']===$status&&!str_contains(json_encode($result),'PRIVATE'),'person HTTP failure exposes only status');
}
$result=fullCoreJobdivaReadPerson('201',static function(){throw new RuntimeException('PRIVATE-SECRET');});
$check($result['report']['status']==='transport_failed'&&!str_contains(json_encode($result),'PRIVATE'),'person transport errors do not expose exception text');
$called=false;
try{fullCoreJobdivaReadPerson('201 OR 1=1',static function()use(&$called){$called=true;return [];});throw new RuntimeException('Invalid person accepted');}
catch(InvalidArgumentException){$check(!$called,'invalid candidate criteria never reach the provider');}
$check(fullCorePilotPersonValue('+1 555 123 4567','phone_primary')['value']!==fullCorePilotPersonValue('5551234567','phone_primary')['value'],
    'comparison does not guess a country prefix');
$check(fullCorePilotPersonValue("PRIVATE\nNAME",'first_name')['status']==='unrecognized'
    &&fullCorePilotPersonValue(false,'phone_primary')['status']==='unrecognized','control characters and nonstring contact data are rejected');
echo "$passed passed\n";
