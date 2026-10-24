<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/quanta/onboarding_contract.php';
$passed=0;$check=static function(bool $ok,string $label)use(&$passed){if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";$passed++;};
$fails=static function(callable $f,string $label)use($check){try{$f();}catch(InvalidArgumentException|OnboardingConflict $e){$check(true,$label);return;}throw new RuntimeException('Accepted '.$label);};
$instance='381e452a-5d11-4e2e-9daa-e9427501898a';
$person=integrationRecordReference($instance,10,'person','P-1');$placement=integrationRecordReference($instance,11,'placement','PL-1');
$assignment=['id'=>quantaCoreRemoteId('a',$placement),'tenant_id'=>'a','person'=>$person,'placement'=>$placement,
    'worker_id'=>'worker-1','person_link_id'=>quantaCoreRemoteId('a',$person),'version'=>1,'status'=>'draft'];
$case=['id'=>onboardingUuid(),'tenant_id'=>1,'people_tenant_id'=>10,'placements_tenant_id'=>11,'person_number'=>'P-1','placement_number'=>'PL-1',
    'version'=>4,'plan_version'=>2,'confirmed_plan_version'=>2,'source_changed'=>false,'source_revision'=>str_repeat('a',64),'required_count'=>1,
    'tasks'=>[['id'=>onboardingUuid(),'version'=>1,'title'=>'Orientation / café','instructions'=>'Review the instructions.','assignee'=>'worker',
        'evidence_kind'=>'attestation','required'=>true,'effective_state'=>'requested','state'=>'requested','evidence'=>['secret'=>'PRIVATE'],
        'review'=>['reason'=>'PRIVATE']]],'history'=>[['private'=>'PRIVATE']]];
$plan=quantaOnboardingPlan($case,$instance,'a',$assignment,null);$record=$plan['expected'];
$check(!str_contains(json_encode($plan),'PRIVATE'),'projection excludes Core document evidence, reviewer reasons and history');
$check($record['publication']['person']['directory_tenant_id']===10&&$record['publication']['placement']['directory_tenant_id']===11,'separate directory ownership survives projection');
$check($record['version']===1&&$plan['body']['expected_version']===null,'initial publication uses conditional creation');
foreach([['source_changed'=>true],['confirmed_plan_version'=>null],['required_count'=>0]]as$change)
    $fails(fn()=>quantaOnboardingPlan(array_replace($case,$change),$instance,'a',$assignment,null),'unconfirmed requirements cannot publish');
$fails(fn()=>quantaOnboardingPlan($case,$instance,'b',$assignment,null),'another provider workspace rejected');
$fails(fn()=>quantaOnboardingPlan($case,$instance,'a',array_replace($assignment,['status'=>'ended']),null),'closed assignment cannot publish');
$withdrawn=quantaOnboardingPlan(array_replace($case,['source_changed'=>true]),$instance,'a',$assignment,$record,'withdrawn');
$check($withdrawn['body']['tasks']===[]&&$withdrawn['body']['expected_version']===1,'withdrawal omits details and fences current provider version');
$request=onboardingUuid();$receipt=['id'=>'cfs_'.onboardingHash([$record['id'],'worker-1',$request]),'request_id'=>$request,'request_hash'=>str_repeat('b',64),
    'worker_id'=>'worker-1','identity_id'=>'identity-1','case_id'=>$record['id'],'core_case_id'=>$case['id'],'core_version'=>4,
    'source_revision'=>$case['source_revision'],'projection_hash'=>$record['projection_hash'],'task_id'=>$case['tasks'][0]['id'],
    'task_version'=>1,'kind'=>'attestation','acknowledged'=>true,'submitted_at'=>'2026-10-22T23:00:00.123456+00:00','status'=>'awaiting_core_review'];
$validate=static fn($c,$r,$p=null)=>quantaOnboardingSubmissionValidate($c,$p??$record,$r,'worker-1',$instance,'a');
$check($validate($case,$receipt)===$receipt,'authenticated matching receipt validates without granting approval');
foreach([['worker_id'=>'worker-2'],['identity_id'=>''],['task_version'=>2],['kind'=>'document'],['acknowledged'=>1],['status'=>'approved'],
    ['submitted_at'=>'2026-02-30T00:00:00+00:00'],['decision'=>'approve']]as$change)
    $fails(fn()=>$validate($case,array_replace($receipt,$change)),'spoofed, stale or unsupported evidence rejected');
$fails(fn()=>$validate(array_replace($case,['source_changed'=>true]),$receipt),'changed Core setup invalidates source evidence');
$fails(fn()=>$validate($case,$receipt,array_replace($record,['tenant_id'=>'b'])),'foreign publication cannot validate an evidence receipt');
$invalid=$record;$invalid['publication']['operating_tenant_id']=2;
$fails(fn()=>$validate($case,$receipt,$invalid),'same directory does not imply same operating workspace');
$invalid=$record;$invalid['publication']['tasks'][0]['instructions']='Changed';
$fails(fn()=>$validate($case,$receipt,$invalid),'mismatched projection fingerprint fails closed');
$GLOBALS['__quanta_core_write_transport']=static fn()=>['status'=>201,'body'=>json_encode($record+['delivery_id'=>'cfd_'.str_repeat('c',64)])];
$check(quantaWriteContract('fixture','a',$plan['operation'],$plan['body'],$plan['expected'],str_repeat('d',64))['id']===$record['id'],'writer validates exact provider acknowledgement');
require_once __DIR__.'/fixtures/staffing_dictionary_checks.php';
staffingDictionaryAssertPayload('quanta_onboarding_publication','quanta',$plan['body']);
$check(true,'dictionary covers every generated publication field without exposing private evidence');
staffingDictionaryAssertPayload('quanta_onboarding_submission','quanta',$validate($case,$receipt),'submissions[]');
$check(true,'dictionary covers every validated worker receipt field at its actual response path');
echo "PASS $passed Quanta onboarding contract checks\n";
