<?php
/** Native employment identity is distinct from person and provider references. */
declare(strict_types=1);
function staffingEmploymentFieldRules(string $table,string $field,array $rules): array
{
    $definitions=[
        'id'=>['Immutable local receipt row; never a provider or person number.','reference:employment_link'],
        'tenant_id'=>['Owning People directory; a shared child company reuses this identity but keeps its own payroll profile.','reference:people_directory'],
        'person_id'=>['Reviewed canonical Core person. Names and contact values never establish this link.','reference:person'],
        'employee_id'=>['One employment record in the same People directory. Existing legacy numbers are preserved.','reference:employment'],
        'review_id'=>['Unique original reviewed operation, reused on a lost-response retry.','opaque-review-id'],
        'actor_user_id'=>['Core operator who created or explicitly reviewed the employment identity.','reference:user'],
        'origin'=>['Explicit new-person creation or reviewed legacy identity. No name/email backfill.','new_person|reviewed'],
        'reason'=>['Operator explanation retained with the reviewed link.','private-text'],
        'review_ct'=>['Encrypted original company, operator, person and employment snapshot for exact replay and audit.','encrypted-private-snapshot'],
        'created_at'=>['Recorded link creation time; never a hire date or payroll activation.','timestamp'],
    ];
    if($table!=='people_employment_links'||!isset($definitions[$field]))return $rules;
    [$definition,$unit]=$definitions[$field];
    foreach($rules['remote_contracts']as&$remote)$remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false];unset($remote);
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_people_employment_identity','owner_directory_module'=>'people',
        'ownership_status'=>'source_backed_native_workflow',
        'ownership_evidence'=>[['source'=>'modules/people/lib/employment_links.php','route'=>'/modules/people/api/employment_link.php']],
        'disposition'=>in_array($field,['reason','review_ct'],true)?'restricted':(in_array($rules['disposition'],['system_owned','source_reference'],true)?$rules['disposition']:'specialized_workflow'),
        'write_policy'=>'explicit_review_or_exact_new_person; immutable_link; no_provider_identity_overwrite',
    ]);
}
