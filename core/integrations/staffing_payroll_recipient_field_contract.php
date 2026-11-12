<?php
declare(strict_types=1);
function staffingPayrollRecipientFieldRules(string $table,string $field,array $rules): array
{
    $definitions=[
        'id'=>['Immutable local payroll recipient receipt; never a provider number.','reference:payroll_recipient_receipt'],
        'tenant_id'=>['Company that owns the native payroll earning and its payroll profile.','reference:tenant'],
        'obligation_id'=>['Original earning recorded atomically with this identity evidence.','reference:economic_obligation'],
        'employee_tenant_id'=>['People directory that owns the original employment record, including a shared parent directory.','reference:people_directory'],
        'employee_id'=>['Exact original employment identity; current person/account matches never replace it.','reference:employment'],
        'payroll_profile_id'=>['Exact company-owned payroll profile selected when the earning was recorded.','reference:payroll_profile'],
        'receipt_json'=>['Original source, amount, currency, run and recipient identifiers. Retained after later participant edits; no historical backfill from current identity.','restricted-json-evidence'],
        'receipt_sha256'=>['Fingerprint of the original serialized receipt; not payment or payroll execution evidence.','sha256'],
        'created_at'=>['Time the earning and recipient evidence were committed; not a payment or work date.','timestamp'],
    ];
    if($table!=='placement_payroll_recipient_receipts'||!isset($definitions[$field]))return $rules;
    foreach($rules['remote_contracts']as&$remote)$remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false];unset($remote);
    return array_replace($rules,[
        'definition'=>$definitions[$field][0],'unit'=>$definitions[$field][1],'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_payroll_source_identity','owner_directory_module'=>'payroll','ownership_status'=>'source_backed_native_workflow',
        'ownership_evidence'=>[['source'=>'modules/placements/lib/payroll_recipients.php','route'=>'/modules/time/api/settlement.php']],
        'disposition'=>$field==='receipt_json'?'restricted':'system_owned',
        'write_policy'=>'atomic_with_new_native_payroll_earning; exact_retry_preserves_original; no_automatic_legacy_adoption_or_identity_replacement',
        'effective_date_policy'=>'original_source_and_recipient_retained; later_current_record_changes_do_not_rewrite_history',
    ]);
}
