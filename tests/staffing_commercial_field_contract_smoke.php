<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
require_once __DIR__.'/../modules/placements/lib/source_rule_input.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
$reject=static function(callable $call,string $label)use($check):void{try{$call();}catch(InvalidArgumentException $e){$check(true,$label);return;}$check(false,$label);};
$tables=['chain'=>'placement_client_chain','referral'=>'placement_referrals','commission'=>'placement_commissions'];
foreach($tables as $kind=>$table)foreach(placementSourceRuleFields($kind) as $field){
    $rules=staffingFieldContractRules($table,$field);
    $check($rules['owner']==='core_placement_contract'&&$rules['definition_status']==='reviewed_core_definition'&&$rules['unit']!==null,'Actual native editor field has an explicit owner and definition: '.$table.'.'.$field);
    foreach($rules['remote_contracts'] as $remote)$check($remote['sync_enabled_by_dictionary']===false&&$remote['status']!=='unverified','Editor coverage never activates an unverified receiver');
}
foreach(staffingCommercialFieldDefinitions() as $table=>$fields){
    foreach($fields as $field=>$_){
        $rules=staffingFieldContractRules($table,$field);
        foreach($rules['ownership_evidence'] as $evidence){
            $check(is_file(__DIR__.'/../'.$evidence['source']),'Ownership names an existing native source');
            if(isset($evidence['route']))$check(is_file(__DIR__.'/..'.$evidence['route']),'Ownership names an existing native route');
        }
    }
    $check(staffingFieldContractRules($table,'future_unreviewed_field')['disposition']==='review_required','Unknown future fields remain unreviewed');
}
foreach(['portal_credentials_ct','kms_key_version'] as $field){
    $r=staffingFieldContractRules('placement_client_chain',$field);
    $check($r['disposition']==='restricted'&&str_contains($r['write_policy'],'dedicated_authorized'),'Portal secrets retain their separate workflow');
    foreach($r['remote_contracts'] as $remote)$check($remote['status']==='core_internal_only'&&$remote['path']===null,'Portal secrets have no remote destination');
    $reject(static fn()=>placementSourceRuleInput('chain',[$field=>'caller-value']),'Generic chain input cannot write private credential metadata');
}
foreach(['corp_ein_ct','corp_ein_last4','kms_key_version','msa_storage_object_id','coi_storage_object_id','w9_storage_object_id'] as $field){
    $r=staffingFieldContractRules('placement_corp_details',$field);
    $check($r['disposition']==='restricted','Corporate private fields remain restricted');
    foreach($r['remote_contracts'] as $remote)$check($remote['status']==='core_internal_only','Corporate private fields are not blanket replicated');
}
$r=staffingFieldContractRules('placement_corp_details','ap_vendor_id');
$check(!empty($r['remote_contracts']['jobdiva']['mappings'])&&!empty($r['remote_contracts']['quanta']['mappings']),'Implemented JobDiva and Quanta vendor identity adapters are retained');
foreach(['currency','payment_frequency','due_month'] as $field){
    foreach(['placement_commissions','placement_economic_parties'] as $table){
        $r=staffingFieldContractRules($table,$field);
        if($table==='placement_commissions')$check($r['remote_contracts']['purepayroll']['status']==='unsupported_with_gap','Raw commission rules do not masquerade as provider payroll support');
        else {
            $remote=$r['remote_contracts']['purepayroll'];
            $check($remote['status']==='source_verified_hosted_acceptance_pending'&&!empty($remote['mappings']),'Economic party projection has a real versioned receiver');
            $check($remote['mappings'][0]['path']==='current_commercial_context.parties[].'.$field&&$remote['sync_enabled_by_dictionary']===false,'Commission terms map without enabling settlement');
        }
    }
}
$input=placementSourceRuleInput('commission',['basis'=>'flat','flat_amount'=>'7500','currency'=>'INR','payment_frequency'=>'one_time','due_month'=>'2026-08']);
$check($input['currency']==='INR'&&$input['payment_frequency']==='one_time'&&$input['due_month']==='2026-08'&&(float)$input['flat_amount']===7500.0,'Native input preserves independent currency, one-time frequency and month precision');
$reject(static fn()=>placementSourceRuleInput('commission',['currency'=>'XYZ']),'Unknown commission currency is rejected');
$reject(static fn()=>placementSourceRuleInput('commission',['due_month'=>'2026-08-01']),'Exact date cannot silently replace source month precision');
$check(str_contains(staffingFieldContractRules('placement_commissions','plan_id')['write_policy'],'new_nonempty_plan_binding_refused'),'Unconfigured plans remain an explicit limitation');
$check(str_contains(staffingFieldContractRules('placement_corp_details','corp_name')['write_policy'],'preserve_legacy_alias'),'Legacy name is not a second authoritative payee');
$check(staffingFieldContractRules('ap_bills','total')['disposition']==='review_required','Unrelated unresolved financial fields are not relabeled');
echo "Staffing commercial fields: {$passed} checks passed\n";
