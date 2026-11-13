<?php
declare(strict_types=1);
putenv('COREFLUX_DISABLE_DATABASE=1');
require_once __DIR__.'/../core/integrations/staffing_field_contract.php';
$passed=0;$check=static function(bool$ok,string$label)use(&$passed):void{if(!$ok)throw new RuntimeException($label);$passed++;};
foreach(staffingPartyFieldDefinitions()as$table=>$fields){
    $module=$table==='staffing_clients'?'placements':($table==='ap_vendors_index'?'ap':'companies');
    foreach($fields as$field=>[$meaning,$unit]){
        $rule=staffingFieldContractRules($table,$field);
        $check($rule['definition_status']==='reviewed_core_definition'&&$rule['owner_directory_module']===$module,'Each party field follows its actual catalog owner: '.$table.'.'.$field);
        foreach($rule['remote_contracts']as$remote)$check($remote['sync_enabled_by_dictionary']===false&&$remote['status']!=='unverified','Explicit destinations never enable exchange: '.$table.'.'.$field);
        if(str_starts_with($unit,'restricted-'))$check($rule['disposition']==='restricted','Sensitive party evidence remains restricted: '.$table.'.'.$field);
        if(str_starts_with($unit,'system-'))$check($rule['disposition']==='system_owned'&&$rule['write_policy']==='system_derived_only','Derived values cannot become source-editable profile fields');
    }
    $check(staffingFieldContractRules($table,'future_unreviewed_field')['disposition']==='review_required','Future fields never inherit a blanket reviewed disposition');
}
foreach(['name','legal_name','primary_contact_email','primary_contact_phone']as$field){
    $rule=staffingFieldContractRules('staffing_clients',$field);
    $check(!empty($rule['remote_contracts']['purepay']['mappings']),'Existing customer transport evidence survives: '.$field);
}
foreach([['companies','legal_name'],['companies','country'],['company_contacts','email'],['company_contacts','is_active']]as[$table,$field]){
    $r=staffingFieldContractRules($table,$field);
    $check($r['remote_contracts']['jobdiva']['status']==='legacy_inbound_conflict_review_required'&&$r['implementation_gap']==='legacy_inbound_ownership_and_contact_identity_review','Observed source overwrite behavior remains an explicit open gap');
}
foreach(['payment_account_ct','payment_routing_ct','tax_id_full_ct','kms_key_version','default_remit_to_json']as$field){
    $r=staffingFieldContractRules('ap_vendors_index',$field);
    foreach($r['remote_contracts']as$remote)$check($remote['status']==='core_internal_only'&&$remote['path']===null&&empty($remote['mappings']),'Encrypted/opaque vendor state is never generic transport');
}
foreach(['msa_storage_object_id','w9_storage_object_id','coi_storage_object_id']as$field)$check(staffingFieldContractRules('companies',$field)['implementation_gap']==='native_document_pointer_validation_and_browser_upload_acceptance','Unaccepted document-reference validation is not claimed complete');
$check(staffingFieldContractRules('staffing_clients','external_id')['disposition']==='source_reference','Legacy provider reference keeps its original meaning');
$check(staffingFieldContractRules('companies','id')['disposition']==='system_owned','Canonical database identity remains system-owned');
$check(staffingFieldContractRules('billing_invoices','future_unreviewed_field')['disposition']==='review_required','Unrelated financial fields remain unreviewed');
echo "Staffing party fields: {$passed} checks passed\n";
