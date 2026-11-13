<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../modules/people/lib/profile_input.php';
require_once __DIR__.'/../modules/people/lib/people.php';
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$passed=0;
$check=static function(bool $ok,string $label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
$reject=static function(array $body,string $label)use($check):void{try{peopleProfileInput($body);}catch(InvalidArgumentException $e){$check(true,$label);return;}$check(false,$label);};
$private=array_map('trim',explode(',',peoplePIIFields()));sort($private);$writes=peopleProfilePrivateFields();sort($writes);
$check($private===$writes,'Every read-restricted person field has the same private-write boundary');
foreach(['user_id','tenant_id','deleted_at','updated_at','created_by_user_id','worker_class','worker_class_meta_json','resume_storage_object_id','person_number','ssn_ct','future_column'] as $key){
    $reject(['preferred_name'=>'Valid accompanying edit',$key=>1],'Profile input refuses protected or unknown field without partial acceptance: '.$key);
}
$input=['preferred_name'=>null,'requires_sponsorship'=>false,'phone_secondary'=>'0','middle_name'=>'','gender'=>null];
$check(peopleProfileInput($input)===$input,'Explicit clears, false and zero-like contact text survive validation exactly');
$reject(['first_name'=>['nested'=>'value']],'Nested values cannot reach dynamic SQL');
foreach(peopleProfileEditableFields() as $field){
    $r=staffingFieldContractRules('people',$field);
    $check($r['definition_status']==='reviewed_core_definition'&&$r['owner']!=='review_required','Actual native profile field has an owner and meaning: '.$field);
    foreach($r['remote_contracts'] as $remote)$check($remote['sync_enabled_by_dictionary']===false&&$remote['status']!=='unverified','Native profile field has an explicit remote boundary: '.$field);
}
foreach(peopleProfilePrivateFields() as $field){
    $r=staffingFieldContractRules('people',$field);
    $check($r['disposition']==='restricted','Private person data cannot use generic field mapping: '.$field);
    foreach($r['remote_contracts'] as $remote)$check(empty($remote['mappings'])&&$remote['status']==='core_internal_only','No blanket private-profile copy: '.$field);
}
foreach(['first_name','last_name','email_primary','phone_primary'] as $field){
    $r=staffingFieldContractRules('people',$field);
    $check(!empty($r['remote_contracts']['quanta']['mappings'])&&!empty($r['remote_contracts']['purepayroll']['mappings']),'Existing worker/payroll contact adapters remain present: '.$field);
}
foreach(['middle_name','preferred_name','email_secondary','phone_secondary'] as $field)$check(staffingFieldContractRules('people',$field)['remote_contracts']['purepayroll']['status']==='unsupported_with_gap','Unimplemented receiving field stays visible: '.$field);
foreach(['employment_type','hire_date'] as $field)$check(staffingFieldContractRules('people',$field)['owner']==='core_people_employment_setup','Mirrored employment facts retain their reviewed workflow');
$check(staffingFieldContractRules('people','future_unreviewed_field')['disposition']==='review_required','Unknown future fields remain unresolved');
$check(staffingFieldContractRules('companies','future_unreviewed_field')['disposition']==='review_required','Unreviewed company fields are not relabeled');
echo "Staffing person fields: {$passed} checks passed\n";
