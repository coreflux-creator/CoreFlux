<?php
/** Portal-safe onboarding projection. Provider submissions are evidence, never employer approval. */
declare(strict_types=1);
require_once __DIR__.'/core_contract.php';
require_once __DIR__.'/../../modules/people/lib/onboarding.php';

const QUANTA_ONBOARDING_CONTRACT_VERSION='2026-10-22.1';

function quantaOnboardingRemoteId(string $workspace,array $publication): string {
    if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$workspace))throw new InvalidArgumentException('Invalid Quanta workspace');
    $ref=quantaCoreReferenceValidate($publication['placement'],'placement');
    onboardingId($publication['core_case_id']);
    if(!is_int($publication['operating_tenant_id'])||$publication['operating_tenant_id']<=0)throw new InvalidArgumentException('Operating workspace required');
    return 'cfo_'.onboardingHash([$workspace,$ref['instance_id'],$publication['operating_tenant_id'],$publication['core_case_id']]);
}

/** Source must be a fresh authorized onboardingView; caller also verifies connection and worker mapping. */
function quantaOnboardingPlan(array $case,string $instance,string $workspace,array $assignment,?array $current,string $state='published'): array {
    if(!in_array($state,['published','withdrawn'],true))throw new InvalidArgumentException('Choose publish or withdraw');
    $person=integrationRecordReference($instance,$case['people_tenant_id'],'person',$case['person_number']);
    $placement=integrationRecordReference($instance,$case['placements_tenant_id'],'placement',$case['placement_number']);
    foreach(['person'=>$person,'placement'=>$placement]as$key=>$ref)
        if(!isset($assignment[$key])||onboardingHash($assignment[$key])!==onboardingHash($ref))throw new OnboardingConflict('Quanta assignment identity requires review');
    if(($assignment['id']??null)!==quantaCoreRemoteId($workspace,$placement)||($assignment['tenant_id']??null)!==$workspace
        ||($assignment['person_link_id']??null)!==quantaCoreRemoteId($workspace,$person)
        ||!is_string($assignment['worker_id']??null)||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$assignment['worker_id'])
        ||!is_int($assignment['version']??null)||$assignment['version']<=0)throw new OnboardingConflict('Verified Quanta assignment required');
    if($state==='published'&&(!in_array($assignment['status']??null,['draft','active'],true)
        ||$case['confirmed_plan_version']!==$case['plan_version']||$case['source_changed']||$case['required_count']<1))
        throw new OnboardingConflict('Confirm the current employer requirement list before publishing it');
    $tasks=[];
    if($state==='published')foreach($case['tasks']as$task){
        $tasks[]=['id'=>onboardingId($task['id']),'version'=>$task['version'],
            'title'=>$task['title'],'instructions'=>$task['instructions'],'assignee'=>$task['assignee'],
            'evidence_kind'=>$task['evidence_kind'],'required'=>$task['required'],'effective_state'=>$task['effective_state']];
    }
    $body=['contract_version'=>QUANTA_ONBOARDING_CONTRACT_VERSION,'core_case_id'=>onboardingId($case['id']),
        'operating_tenant_id'=>$case['tenant_id'],'core_version'=>$case['version'],'source_revision'=>$case['source_revision'],
        'person'=>$person,'placement'=>$placement,'assignment_version'=>$assignment['version'],
        'expected_version'=>$current['version']??null,'state'=>$state,'tasks'=>$tasks];
    $id=quantaOnboardingRemoteId($workspace,$body);
    if($current!==null&&(($current['id']??null)!==$id||($current['tenant_id']??null)!==$workspace
        ||($current['worker_id']??null)!==$assignment['worker_id']||($current['person_link_id']??null)!==$assignment['person_link_id']
        ||!is_int($current['version']??null)||$current['version']<1))throw new OnboardingConflict('Existing onboarding publication needs identity reconciliation');
    $publication=$body;unset($publication['expected_version']);
    return ['operation'=>'onboarding.publish','body'=>$body,'expected'=>['id'=>$id,'tenant_id'=>$workspace,
        'publication'=>$publication,'projection_hash'=>onboardingHash($publication),'version'=>($body['expected_version']??0)+1,
        'assignment_id'=>$assignment['id'],'worker_id'=>$assignment['worker_id'],'person_link_id'=>$assignment['person_link_id']]];
}

/** The binding is separate from metadata permissions; a fresh call must establish this exact capability. */
function quantaOnboardingContext(string $key,string $workspace): array {
    $context=quantaGet($key,QUANTA_CORE_CONTRACT_PATH.'/onboarding-context');
    if(($context['workspace_id']??null)!==$workspace||($context['contract_version']??null)!==QUANTA_ONBOARDING_CONTRACT_VERSION
        ||($context['approval_authority']??null)!==false||($context['document_transfer']??null)!==false
        ||!is_array($context['capabilities']??null)
        ||array_diff(['conditional_publication','worker_attestation_receipts','durable_delivery_receipts'],$context['capabilities']))
        throw new OnboardingConflict('Quanta onboarding capability or workspace needs review');
    return $context;
}

/** Pure validator for the next Core import step. It cannot change a case or approve evidence. */
function quantaOnboardingSubmissionValidate(array $case,array $publication,array $receipt,string $workerId,string $instance,string $workspace,bool $allowDocument=false): array {
    $keys=['id','request_id','request_hash','worker_id','identity_id','case_id','core_case_id','core_version','source_revision',
        'projection_hash','task_id','task_version','kind','acknowledged','submitted_at','status'];
    $kind=$allowDocument&&($receipt['kind']??null)==='document'?'document':'attestation';
    if($kind==='document')$keys[]='document';
    if(array_diff(array_keys($receipt),$keys))throw new OnboardingConflict('Unsupported Quanta evidence receipt fields');
    foreach($keys as$key)
        if(!array_key_exists($key,$receipt))throw new OnboardingConflict('Incomplete Quanta evidence receipt');
    $p=$publication['publication']??[];
    $person=integrationRecordReference($instance,$case['people_tenant_id'],'person',$case['person_number']);
    $placement=integrationRecordReference($instance,$case['placements_tenant_id'],'placement',$case['placement_number']);
    if(($p['contract_version']??null)!==QUANTA_ONBOARDING_CONTRACT_VERSION||($p['core_case_id']??null)!==$case['id']
        ||($p['operating_tenant_id']??null)!==$case['tenant_id']||($p['source_revision']??null)!==$case['source_revision']
        ||($p['state']??null)!=='published'||onboardingHash($p['person']??[])!==onboardingHash($person)
        ||onboardingHash($p['placement']??[])!==onboardingHash($placement)||($publication['tenant_id']??null)!==$workspace
        ||($publication['id']??null)!==quantaOnboardingRemoteId($workspace,$p)
        ||($publication['projection_hash']??null)!==onboardingHash($p))throw new OnboardingConflict('Quanta publication identity or revision differs from Core');
    $task=null;foreach($case['tasks']as$t)if($t['id']===$receipt['task_id'])$task=$t;
    if($receipt['worker_id']!==$workerId||($publication['worker_id']??null)!==$workerId
        ||!is_string($receipt['identity_id'])||!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$receipt['identity_id'])
        ||$receipt['case_id']!==$publication['id']||$receipt['core_case_id']!==$case['id']
        ||$receipt['source_revision']!==$case['source_revision']||$receipt['projection_hash']!==$publication['projection_hash']
        ||$receipt['core_version']!==$publication['publication']['core_version']||$receipt['core_version']>$case['version']
        ||$receipt['kind']!==$kind||$receipt['acknowledged']!==true||$receipt['status']!=='awaiting_core_review'
        ||$case['source_changed']||$case['confirmed_plan_version']!==$case['plan_version']
        ||!$task||$task['version']!==$receipt['task_version']||$task['assignee']!=='worker'||$task['evidence_kind']!==$kind
        ||!in_array($task['effective_state'],['requested','rejected','needs_review','expired','evidence_unavailable'],true))throw new OnboardingConflict('Quanta evidence is stale or belongs to a different worker or requirement');
    // This is Quanta's canonical UUID idempotency key, not a Core case/task ID.
    // Its UUID version carries no authority; the receipt hash below binds it to
    // the authenticated worker and publication. Quanta also accepts v5/v7 keys.
    if(!is_string($receipt['request_id'])
        ||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$receipt['request_id']))
        throw new OnboardingConflict('Invalid provider submission request identifier');
    onboardingId($receipt['task_id']);
    foreach(['request_hash','projection_hash','source_revision']as$key)if(!is_string($receipt[$key])||!preg_match('/^[a-f0-9]{64}$/D',$receipt[$key]))throw new OnboardingConflict('Invalid evidence revision');
    $expectedId='cfs_'.onboardingHash([$publication['id'],$workerId,$receipt['request_id']]);
    if($receipt['id']!==$expectedId||!is_string($receipt['submitted_at'])
        ||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D',$receipt['submitted_at']))throw new OnboardingConflict('Invalid evidence receipt identity or timestamp');
    try{new DateTimeImmutable($receipt['submitted_at']);$errors=DateTimeImmutable::getLastErrors();}
    catch(Throwable $e){throw new OnboardingConflict('Invalid evidence timestamp');}
    if($errors&&($errors['warning_count']||$errors['error_count']))throw new OnboardingConflict('Invalid evidence timestamp');
    return $receipt;
}
