<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/placements/lib/approval_contact.php';
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
$refuses=static function(array $patch,bool $focused=true):bool{try{placementApprovalContactInput($patch,$focused);return false;}catch(InvalidArgumentException $e){return $e->getCode()===422;}};
$check(placementApprovalContactInput(['client_approver_name'=>'  Reviewer  ','client_approver_email'=>'  reviewer@example.invalid  '],true)
    ===['client_approver_name'=>'Reviewer','client_approver_email'=>'reviewer@example.invalid'],'Contact text is trimmed');
$check(placementApprovalContactInput(['client_approver_name'=>' ','client_approver_email'=>null],true)
    ===['client_approver_name'=>null,'client_approver_email'=>null],'Explicit clear remains distinct from absent');
$check(placementApprovalContactInput(['title'=>'Unchanged'],false)===['title'=>'Unchanged'],'General native patches retain unrelated fields');
foreach(['tokenized_email_approval_enabled','bulk_uploads_can_be_pre_approved']as$field){
    foreach([true,false,1,0,'1','0']as$value)$check(placementApprovalContactInput([$field=>$value],true)===[$field=>(int)$value],'Strict boolean accepted');
    foreach(['false','true','yes','',2,-1,null,[],1.0]as$value)$check($refuses([$field=>$value]),'Malformed boolean refused');
}
foreach(['client_approver_name','client_approver_email']as$field){
    foreach([true,0,[],"A\nB","A\0B",str_repeat('x',256),"\xFF"]as$value)$check($refuses([$field=>$value]),'Malformed contact refused');
}
$check($refuses(['client_approver_email'=>'invalid']),'Invalid email refused');
$check($refuses(['client_approver_name'=>str_repeat('x',201)]),'Name respects the native 200 character column');
$check(placementApprovalContactInput(['client_approver_name'=>str_repeat('é',200)],true)['client_approver_name']===str_repeat('é',200),'Native name length counts Unicode characters');
$check($refuses(['tokenized_email_approval_enabled'=>'false'],false),'Overview writes use the same strict boolean validation');
foreach(['tenant_id','person_id','status','coreflux_overridden_fields']as$field)$check($refuses([$field=>'1']),'Focused setup rejects unrelated fields');
foreach(array_merge(placementApprovalContactFields(),['coreflux_overridden_fields'])as$field){
    $r=staffingFieldContractRules('placements',$field);
    $check($r['owner']==='core_placement_approval_setup'&&$r['definition_status']==='reviewed_core_definition','Explicit approval setup ownership');
    foreach(['quanta','purepay','purepayroll']as$provider)$check($r['remote_contracts'][$provider]['status']==='core_internal_only'
        &&!$r['remote_contracts'][$provider]['sync_enabled_by_dictionary'],'Approval setup does not grant provider access');
}
foreach(['client_approver_name','client_approver_email']as$field){
    $r=staffingFieldContractRules('placements',$field)['remote_contracts']['jobdiva'];
    $check($r['direction']==='jobdiva_to_core'&&$r['status']==='adapter_source_verified_live_cohort_pending','Source suggestion is not claimed as live delivery');
}
$check(staffingFieldContractRules('placements','new_approval_field')['disposition']==='review_required','Unknown fields stay unreviewed');
echo "Placement approval contact: {$passed} passed\n";
