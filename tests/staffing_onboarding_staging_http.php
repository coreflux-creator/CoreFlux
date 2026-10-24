<?php
/** Native HTTP acceptance on the named isolated staging app, using synthetic actors and records only. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--execute')throw new RuntimeException('Explicit isolated staging execution required');
define('QA_LIFECYCLE_LIBRARY_MODE',true);
require_once __DIR__.'/accounting_staging_lifecycle.php';
require_once __DIR__.'/../core/mail_bootstrap.php';
require_once __DIR__.'/../modules/people/lib/onboarding.php';
require_once __DIR__.'/../core/sub_tenants.php';
$mail=cf_mail_bootstrap();
if(!defined('COREFLUX_STAGING')||!COREFLUX_STAGING||$mail->default_driver_name()!=='log'||$mail->driver('resend')!==null
    ||effectiveTenantIdForModule('people',QA_TENANT)!==QA_TENANT||effectiveTenantIdForModule('placements',QA_TENANT)!==QA_TENANT)throw new RuntimeException('Synthetic owned directories and log-only delivery required');
$actors=[];$cookies=[];$count=0;
$check=static function(bool $ok,string $label)use(&$count){qaExpect($ok,$label);$count++;};
try{
    foreach(['onboarding-maker','onboarding-reviewer']as$label){
        $actor=qaEnsureActor($pdo,$label);$actors[]=$actor;$cookie=tempnam(sys_get_temp_dir(),'cf-onboard-');
        if(!$cookie)throw new RuntimeException('Could not create test session');$cookies[]=$cookie;qaLogin($actor,$cookie);
    }
    $run=gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
    $person=qaRequest('/modules/people/api/people.php','POST',['first_name'=>'Synthetic','last_name'=>'Onboarding '.$run,'email_primary'=>'onboarding-'.$run.'@example.test','classification'=>'1099','status'=>'inactive'],$cookies[0]);
    $personId=(int)($person['id']??$person['person']['id']??0);$check($personId>0,'native People API creates only the synthetic onboarding person');
    $placement=qaRequest('/modules/placements/api/placements.php','POST',['person_id'=>$personId,'title'=>'Synthetic onboarding '.$run,'engagement_type'=>'1099','status'=>'draft','start_date'=>gmdate('Y-m-d'),'worksite_country'=>'US','worksite_state'=>'NJ'],$cookies[0]);
    $placementId=(int)($placement['id']??$placement['placement']['id']??0);$check($placementId>0,'native Placement API creates a draft synthetic assignment');
    $baselinePerson=qaOne($pdo,'SELECT status FROM people WHERE tenant_id=:t AND id=:id',['t'=>QA_TENANT,'id'=>$personId]);
    $baselinePlacement=qaOne($pdo,'SELECT status FROM placements WHERE tenant_id=:t AND id=:id',['t'=>QA_TENANT,'id'=>$placementId]);
    $path='/modules/people/api/onboarding.php';
    $catalog=qaRequest($path.'?person_id='.$personId,'GET',null,$cookies[0]);
    $check(($catalog['permissions']['manage']??null)===true&&($catalog['permissions']['review']??null)===true,'native tenant administrator receives resolved onboarding capabilities');
    $create=['action'=>'create','request_id'=>onboardingUuid(),'person_id'=>$personId,'placement_id'=>$placementId];
    $result=qaRequest($path,'POST',$create,$cookies[0]);$case=$result['case'];$id=$case['id'];
    $check($case['readiness']==='requirements_unconfirmed'&&$case['required_count']===0,'HTTP creation cannot mark an empty case complete');
    $retained=qaRequest('/modules/people/api/onboarding_quanta.php?case_id='.$id.'&view=document_transfers','GET',null,$cookies[0]);
    $check($retained['items']===[]&&$retained['has_more']===false,'native HTTP retained-file history works without a provider connection or storage access');
    $documentRequest=['action'=>'import_document','case_id'=>$id,'receipt_id'=>'cfs_'.str_repeat('a',64),'delivery_id'=>1,'expected_version'=>$case['version']];
    try{qaRequest('/modules/people/api/onboarding_quanta.php','POST',$documentRequest+['storage_key'=>'people/1/document/1/untrusted.pdf'],$cookies[0]);throw new LogicException('Browser storage identity accepted');}
    catch(RuntimeException $e){$check(str_contains($e->getMessage(),'HTTP 422'),'document HTTP intake rejects browser-provided storage identities');}
    // This staging acceptance never enables the document feature or contacts a live provider.
    if(getenv('COREFLUX_QUANTA_ONBOARDING_DOCUMENTS')!=='1'){
        try{qaRequest('/modules/people/api/onboarding_quanta.php','POST',$documentRequest,$cookies[0]);throw new LogicException('Disabled document intake accepted');}
        catch(RuntimeException $e){$check(str_contains($e->getMessage(),'HTTP 409'),'document HTTP intake remains disabled before provider or storage IO');}
    }
    $retry=qaRequest($path,'POST',$create,$cookies[0]);$check($retry['receipt']['replayed']===true&&$retry['receipt']['case_id']===$id,'HTTP request replay preserves one case and receipt');
    $command=static function(string $action,array $fields=[],int $actor=0)use(&$case,$path,$cookies){$response=qaRequest($path,'POST',['action'=>$action,'request_id'=>onboardingUuid(),'case_id'=>$case['id'],'expected_version'=>$case['version']]+$fields,$cookies[$actor]);$case=$response['case'];return $response;};
    $added=$command('add_task',['title'=>'Synthetic orientation review','instructions'=>'Synthetic acceptance only: record orientation and route to employer review.','assignee'=>'staff','evidence_kind'=>'attestation','required'=>true]);$task=$added['receipt']['task_id'];
    $check($case['tasks'][0]['state']==='requested','HTTP requirement creation requests evidence');
    $command('confirm_plan',[],1);$check($case['readiness']==='incomplete','reviewer confirms list without approving evidence');
    $command('submit',['task_id'=>$task,'acknowledged'=>true]);
    $check($case['tasks'][0]['state']==='submitted'&&$case['tasks'][0]['evidence']['actor_user_id']===(int)$actors[0]['id'],'HTTP submission records the authenticated maker');
    $stale=['action'=>'review','request_id'=>onboardingUuid(),'case_id'=>$id,'expected_version'=>$case['version']-1,'task_id'=>$task,'decision'=>'approve','reason'=>'Synthetic stale attempt'];
    try{qaRequest($path,'POST',$stale,$cookies[1]);throw new LogicException('Stale HTTP request accepted');}catch(RuntimeException $e){$check(str_contains($e->getMessage(),'HTTP 409'),'HTTP stale review returns a conflict');}
    $command('review',['task_id'=>$task,'decision'=>'approve','reason'=>'Synthetic evidence reviewed'],1);
    $check($case['readiness']==='complete'&&$case['tasks'][0]['review']['actor_user_id']===(int)$actors[1]['id'],'HTTP reviewer decision completes only the confirmed requirement list');
    $check($case['payroll_authorization']==='not_evaluated'&&$case['portal_delivery']==='not_checked','HTTP completion remains separate from payroll and portal publication');
    $fetched=qaRequest($path.'?case_id='.$id,'GET',null,$cookies[0]);
    $check($fetched['case']['version']===$case['version']&&count($fetched['case']['history'])===$case['version'],'HTTP read retains one event for every committed revision');
    $command('revise_task',['task_id'=>$task,'title'=>'Synthetic revised orientation','instructions'=>'Synthetic change requires fresh evidence.','assignee'=>'staff','evidence_kind'=>'attestation','required'=>true]);
    $check($case['readiness']==='requirements_unconfirmed'&&$case['tasks'][0]['review']===null,'HTTP requirement revision invalidates prior completion');
    $check(qaOne($pdo,'SELECT status FROM people WHERE tenant_id=:t AND id=:id',['t'=>QA_TENANT,'id'=>$personId])===$baselinePerson
        &&qaOne($pdo,'SELECT status FROM placements WHERE tenant_id=:t AND id=:id',['t'=>QA_TENANT,'id'=>$placementId])===$baselinePlacement,'onboarding HTTP workflow leaves person and placement activation unchanged');
    echo json_encode(['status'=>'passed','checks'=>$count,'synthetic_tenant'=>QA_TENANT,'case_id'=>$id,'person_id'=>$personId,'placement_id'=>$placementId,'portal_delivery'=>'not_checked','payroll_authorization'=>'not_evaluated'],JSON_PRETTY_PRINT),"\n";
}finally{
    foreach($actors as$actor)$pdo->prepare('UPDATE users SET is_active=0,password_hash=NULL WHERE tenant_id=:t AND id=:id')->execute(['t'=>QA_TENANT,'id'=>$actor['id']]);
    foreach($cookies as$cookie)if(is_file($cookie))unlink($cookie);
}
