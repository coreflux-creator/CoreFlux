<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;echo "PASS {$label}\n";};
foreach([
    ['people','first_name','core_people_directory','people'],
    ['placements','branch','core_placement_directory','placements'],
    ['placement_rates','pay_rate','core_placement_contract','placements'],
    ['time_entries','hours','core_time_review','time'],
    ['quanta_time_imports','source_workspace_id','quanta_source_evidence','time'],
]as[$table,$field,$owner,$module]){
    $r=staffingFieldContractRules($table,$field);
    $check($r['owner']===$owner&&$r['owner_directory_module']===$module,'adapter-backed owner and directory: '.$table.'.'.$field);
    $check($r['disposition']==='specialized_workflow'&&count($r['ownership_evidence'])>0,'ownership retains concrete adapter evidence');
    foreach($r['remote_contracts']as$c)$check($c['sync_enabled_by_dictionary']===false,'ownership never grants provider writes');
}
$contact=staffingFieldContractRules('people','first_name');
$check(in_array('quanta_to_core',array_column($contact['ownership_evidence'],'direction'),true)
    &&in_array('core_to_quanta',array_column($contact['ownership_evidence'],'direction'),true),
    'Core accepted contact ownership retains both reviewed exchange directions');
$check($contact['remote_contracts']['quanta']['status']==='source_verified_hosted_acceptance_pending',
    'source ownership does not claim acceptance for another deployment or tenant');
$check(staffingFieldContractRules('placements','new_unreviewed_field')['disposition']==='review_required',
    'new fields do not inherit a reviewed disposition from their table');
$check(staffingFieldContractRules('placements','id')['disposition']==='system_owned',
    'adapter ownership does not replace system identity ownership');
$check(staffingFieldContractRules('placements','external_id')['disposition']==='source_reference',
    'provider references remain connector-owned');
$check(staffingFieldContractRules('people','ssn_ct')['disposition']==='restricted',
    'private identity fields remain restricted');
$check(staffingFieldContractRules('payroll_runs','gross_total_cents')['disposition']==='review_required',
    'unmapped financial fields are not declared exchanged merely because their table is familiar');
$check(staffingFieldExchangeOwnership('people',['quanta'=>['path'=>'workers.full_name','status'=>'schema_only']])===null,
    'a schema hint without an implemented adapter cannot establish ownership');
$check(staffingFieldContractRules('placement_rates','approved_at')['write_policy']==='specialized_workflow_only',
    'record ownership does not loosen the approval workflow');
$incomplete=$contact['remote_contracts'];
foreach($incomplete as &$contract)foreach($contract['mappings']??[]as$index=>$mapping)unset($contract['mappings'][$index]['direction']);
unset($contract);
$check(staffingFieldExchangeOwnership('people',$incomplete)===null,
    'missing exchange direction leaves ownership unverified');
foreach(['id','tenant_id','person_id','employee_id','review_id','actor_user_id','origin','reason','review_ct','created_at']as$field){
    $rule=staffingFieldContractRules('people_employment_links',$field);
    $check($rule['owner']==='core_people_employment_identity'&&$rule['remote_contracts']['quanta']['status']==='core_internal_only','Employment identity field stays in Core: '.$field);
}
$check(staffingFieldContractRules('people_employment_links','review_ct')['disposition']==='restricted','Employment snapshot remains restricted');
$check(staffingFieldContractRules('people_employment_links','future_field')['disposition']==='review_required','Unknown employment fields still need review');
foreach(['id','tenant_id','person_id','employee_id','review_id','actor_user_id','kind','reason','review_ct','history_id','created_at']as$field){
    $rule=staffingFieldContractRules('people_employment_reviews',$field);
    $check($rule['owner']==='core_people_employment_setup'&&$rule['remote_contracts']['purepayroll']['status']==='core_internal_only','Employment setup receipt remains local: '.$field);
}
foreach(['status','employment_type','flsa_class','hire_date','start_date','job_title','department','location']as$field){
    $check(staffingFieldContractRules('people_employees',$field)['owner']==='core_people_employment_setup','Current employment field uses explicit setup workflow: '.$field);
}
foreach(['employment_type','hire_date']as$field)$check(staffingFieldContractRules('people',$field)['owner']==='core_people_employment_setup','Mirrored person employment fact has the same owner: '.$field);
$check(staffingFieldContractRules('people_employment_reviews','review_ct')['disposition']==='restricted','Setup review envelope remains private');
$check(staffingFieldContractRules('people_employment_reviews','future_field')['disposition']==='review_required','Future setup fields are not implicitly approved');
echo "Staffing field ownership: {$passed} passed\n";
