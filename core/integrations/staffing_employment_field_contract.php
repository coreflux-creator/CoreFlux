<?php
/** Native employment identity is distinct from person and provider references. */
declare(strict_types=1);
function staffingEmploymentFieldRules(string $table,string $field,array $rules): array
{
    $setupFields=[
        'status'=>['Current employment activation state, distinct from person availability and assignment status. Pending activation requires explicit employment facts.','pending|active|on_leave|terminated'],
        'employment_type'=>['Reviewed employment arrangement. Person intake and its linked employment share the agreed value; W-2 activation cannot use contractor.','nullable-enum'],
        'flsa_class'=>['Reviewed overtime classification; W-2 status does not establish exemption. Unknown remains null until reviewed.','exempt|non_exempt|null'],
        'hire_date'=>['Reviewed hire date, saved consistently to the linked person profile and employment record.','nullable-date'],
        'start_date'=>['Reviewed employment start, on or after hire date; distinct from each placement start.','nullable-date'],
        'job_title'=>['Current employment job title; separate from a placement title.','nullable-text-max-150'],
        'department'=>['Current employment department; separate from placement routing dimensions.','nullable-text-max-120'],
        'location'=>['Current employment location label; not a tax jurisdiction or a structured placement worksite.','nullable-text-max-120'],
    ];
    $reviewFields=[
        'id'=>['Immutable local setup receipt row.','reference:employment_review'],
        'tenant_id'=>['Actual owning People directory; request company is also bound inside the encrypted review.','reference:people_directory'],
        'person_id'=>['Canonical person joined through the saved employment identity.','reference:person'],
        'employee_id'=>['Existing linked employment record; provider identities and legacy numbers are preserved.','reference:employment'],
        'review_id'=>['Original operation ID retained for exact retries without reapplying later state.','opaque-review-id'],
        'actor_user_id'=>['Authenticated Core operator who reviewed and saved these employment facts.','reference:user'],
        'kind'=>['Initial activation or an update to current setup; not payroll execution.','activation|setup_update'],
        'reason'=>['Operator reason recorded with the exact before and after facts.','private-text'],
        'review_ct'=>['Encrypted original instance, company, directory, identity and employment snapshot plus confirmed changes.','encrypted-private-snapshot'],
        'history_id'=>['Atomic hire-history event for pending-to-active employment; null for setup-only updates.','nullable-reference:employment_history'],
        'created_at'=>['Time Core committed the review; not the employment effective date.','timestamp'],
    ];
    $setup=($table==='people_employees'&&isset($setupFields[$field]))||($table==='people'&&in_array($field,['employment_type','hire_date'],true));
    if($setup||($table==='people_employment_reviews'&&isset($reviewFields[$field]))){
        [$definition,$unit]=$setup?$setupFields[$field]:$reviewFields[$field];
        foreach($rules['remote_contracts']as&$remote)$remote=['path'=>null,'direction'=>null,'status'=>'core_internal_only','sync_enabled_by_dictionary'=>false];unset($remote);
        return array_replace($rules,[
            'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
            'owner'=>'core_people_employment_setup','owner_directory_module'=>'people','ownership_status'=>'source_backed_native_workflow',
            'ownership_evidence'=>[['source'=>'modules/people/lib/employment_setup.php','route'=>'/modules/people/api/employment_setup.php']],
            'disposition'=>in_array($field,['reason','review_ct'],true)?'restricted':(in_array($rules['disposition'],['system_owned','source_reference'],true)?$rules['disposition']:'specialized_workflow'),
            'write_policy'=>'reviewed_current_employment_setup; atomic_profile_alignment_and_audit; activation_is_not_payroll_or_assignment_approval',
            'effective_date_policy'=>'hire_and_start_are_explicit; current_setup_is_not_retroactive_payroll_or_contract_correction',
        ]);
    }
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
