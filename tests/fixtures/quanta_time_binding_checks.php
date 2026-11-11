<?php
declare(strict_types=1);
require_once __DIR__.'/../../core/quanta/sync.php';

function quantaTimeBindingChecks(array $packet): array
{
    $checks=[];
    $check=static function(bool $ok,string $name)use(&$checks):void {
        if (!$ok) throw new RuntimeException($name);
        $checks[]=$name;
    };
    $fails=static function(callable $f):bool {try{$f();return false;}catch(InvalidArgumentException|RuntimeException $e){return true;}};
    $instance='381e452a-5d11-4e2e-9daa-e9427501898a';
    $person=integrationRecordReference($instance,1,'person','P-333');
    $placement=integrationRecordReference($instance,1,'placement','PL-810');
    $access=['instance_id'=>$instance,'context'=>['workspace'=>['id'=>'a']]];
    $raw=$packet['approved'];
    $entry=quantaNormalizeEntry($raw,[],'approved');
    $bound=$entry['core_assignment'];
    $check($packet['workspace_id']==='a' && $bound['person']===$person && $bound['placement']===$placement,'Independent Core person and placement references match the native wire');
    $check(integrationDeliveryHash($bound)===integrationDeliveryHash($packet['state']['core_assignment']),'Approved export and fresh source-state recheck carry the same assignment');
    $route=['id'=>7,'worker_id'=>'worker-1','worksite_id'=>'site-1','placement_id'=>42,'person_id'=>19,
        'person_name'=>'Synthetic worker','dimension_key'=>quantaDimensionKey([]),'effective_from'=>'2026-10-01','effective_to'=>null,
        'placement_status'=>'active','start_date'=>'2026-10-01','end_date'=>null,'actual_end_date'=>null,'deleted_at'=>null,
        'engagement_type'=>'c2c','person_directory_tenant_id'=>1,'placement_directory_tenant_id'=>1,
        'person_number'=>'P-333','placement_number'=>'PL-810'];
    $other=array_replace($route,['id'=>8,'placement_id'=>43,'placement_number'=>'PL-811']);
    $workers=['worker-1'=>19];
    $check(quantaRouteFor([$other,$route],$entry,$workers,$access)['placement_id']===42,'Captured assignment disambiguates overlapping worker/site/dimension routes');
    $check($fails(fn()=>quantaRouteFor([$other],$entry,$workers,$access)),'Captured time cannot be silently routed to another placement');
    $check($fails(fn()=>quantaRouteFor([$route,$route],$entry,$workers,$access)),'Duplicate routes to the captured placement still fail');
    $check($fails(fn()=>quantaRouteFor([$route],$entry,$workers)),'Captured time requires verified workspace context');
    $foreign=$access;$foreign['context']['workspace']['id']='b';
    $check($fails(fn()=>quantaRouteFor([$route],$entry,$workers,$foreign)),'Another Quanta workspace cannot supply the same IDs');
    $foreign=$access;$foreign['instance_id']='481e452a-5d11-4e2e-9daa-e9427501898a';
    $check($fails(fn()=>quantaRouteFor([$route],$entry,$workers,$foreign)),'Another Core installation cannot supply the same record numbers');
    foreach (['person_directory_tenant_id','placement_directory_tenant_id'] as $field) {
        $check($fails(fn()=>quantaRouteFor([array_replace($route,[$field=>2])],$entry,$workers,$access)),"Wrong {$field} is rejected");
    }
    $check($fails(fn()=>quantaRouteFor([$route],$entry,['worker-1'=>20],$access)),'Worker link cannot redirect captured time to another person');
    $check($fails(fn()=>quantaRouteFor([array_replace($route,['actual_end_date'=>'2026-10-06'])],$entry,$workers,$access)),'Current Core placement dates still apply');
    foreach (['contract_version'=>'unsupported','policy_version'=>true,'assignment_version'=>'2','work_date'=>'2026-10-08',
        'worker_id'=>'worker-2','worksite_id'=>'site-2','assignment_id'=>'not-an-id','private_bank'=>'forbidden'] as $field=>$value) {
        $bad=$raw;$bad['core_assignment'][$field]=$value;
        $check($fails(fn()=>quantaNormalizeEntry($bad,[],'approved')),"Malformed or mismatched {$field} is rejected");
    }
    foreach (['policy_id','assignment_id'] as $field) {
        $bad=$raw;$bad['core_assignment'][$field]=($field==='policy_id'?'cfta_':'cfa_').str_repeat('f',64);
        $check($fails(fn()=>quantaRouteFor([$route],quantaNormalizeEntry($bad,[],'approved'),$workers,$access)),"Foreign {$field} cannot pass workspace identity verification");
    }
    $bad=$raw;$bad['core_assignment']=null;
    $check($fails(fn()=>quantaNormalizeEntry($bad,[],'approved')),'An explicitly null binding is not an unmanaged legacy record');
    $evidence=quantaTimeApprovalFields($entry);
    $check(json_decode($evidence['source_approval_json'],true)['core_assignment']===$bound,'Durable import evidence retains the complete original assignment');
    $prior=[$entry['id']=>['regular'=>['source_hash'=>quantaComponentHash($entry,$route,'regular',1.0),...$evidence,
        'source_worksite_id'=>'site-1','entry_status'=>'pending_review','placement_id'=>42,'person_id'=>19,'work_date'=>'2026-10-07',
        'bill_extracted_at'=>null,'ap_extracted_at'=>null,'payroll_extracted_at'=>null]]];
    $check(quantaEntryDecision($entry,$route,$prior)[0]==='imported','Exact captured source replay remains idempotent');
    $legacyRaw=$raw;unset($legacyRaw['core_assignment']);$legacy=quantaNormalizeEntry($legacyRaw,[],'approved');
    $check(quantaEntryDecision($legacy,$route,$prior)[0]==='conflict','A refresh cannot remove a previously captured assignment');
    $changed=$raw;$changed['core_assignment']['assignment_version']++;
    $check(quantaEntryDecision(quantaNormalizeEntry($changed,[],'approved'),$route,$prior)[0]==='conflict','A refresh cannot replace the original assignment revision');
    $check(quantaComponentHash($legacy,$route,'regular',1.0)!==quantaComponentHash($entry,$route,'regular',1.0),'Assignment binding contributes to source integrity');
    $reordered=$raw;$reordered['core_assignment']=array_reverse($reordered['core_assignment'],true);
    $check(quantaComponentHash(quantaNormalizeEntry($reordered,[],'approved'),$route,'regular',1.0)===quantaComponentHash($entry,$route,'regular',1.0),'Object-key ordering does not change source integrity');
    $check(!array_key_exists('core_assignment',$legacy) && quantaRouteFor([$route],$legacy,$workers)['placement_id']===42,'Unmanaged legacy entries retain their existing explicit routing');
    $check($fails(fn()=>quantaRouteFor([$route,$other],$legacy,$workers)),'Legacy ambiguity is still rejected');
    $legacyRoute=$route;unset($legacyRoute['person_directory_tenant_id'],$legacyRoute['placement_directory_tenant_id']);
    $check(quantaTimeRouteEvidence($legacy,$route)===$legacyRoute && quantaTimeRouteEvidence($entry,$route)===$route,'Legacy evidence stays stable while captured time includes directory ownership');
    $access['binding_hash']=str_repeat('a',64);
    $check(quantaTimeSourceReviewHash($access,$legacy,$route,[])===quantaTimeSourceReviewHash($access,$legacy,$legacyRoute,[]),'New route directory columns do not invalidate existing legacy source reviews');
    return ['passed'=>count($checks),'checks'=>$checks,'assignment_id'=>$bound['assignment_id'],
        'person'=>$bound['person'],'placement'=>$bound['placement'],'approval_evidence'=>$evidence,
        'normalized_entry'=>$entry,'database_used'=>false,'network_used'=>false];
}
