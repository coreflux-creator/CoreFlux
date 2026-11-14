<?php
/** Requisition meanings and the actual ownership-controlled JobDiva projection. */
declare(strict_types=1);

function staffingJobFieldDefinitions(): array
{
    return [
        'client_id'=>['Optional existing active client role for the exactly mapped requisition company. Ambiguous client profiles require review. This is not the assignment bill-to choice and creates no client.','nullable-reference:client'],
        'company_id'=>['Exactly mapped requisition company in the authorized company directory, following reviewed company aliases. A job company does not establish the work recipient, billing debtor or legal employer.','nullable-reference:company'],
        'title'=>['Requisition title, independent of placement, worker employment and provider assignment titles. New source jobs without a title have an explicitly synthetic JobDiva Job label.','required-text-max-255'],
        'status'=>['Requisition lifecycle. A job refresh does not reopen or close its placements, approve time or activate provider assignments. Missing source status preserves current state.','open|active|on_hold|filled|closed|cancelled'],
        'description'=>['Requisition description retained as text, not instructions or worker-visible onboarding content.','nullable-text-max-65535-bytes'],
        'department'=>['Requisition department label; separate from placement department and payroll/reporting dimensions.','nullable-text-max-120'],
        'location_city'=>['Requisition city; not worker residence or the accepted placement worksite.','nullable-text-max-120'],
        'location_state'=>['Requisition state/province text; does not select payroll tax jurisdiction.','nullable-text-max-60'],
        'location_country'=>['Requisition country normalized from recognized names/codes to alpha-2. Missing source country does not imply US.','nullable-recognized-alpha-2'],
        'remote_policy'=>['Requisition work arrangement, independent of the reviewed assignment location, worker access and payroll setup.','nullable-onsite|hybrid|remote'],
        'opened_at'=>['Recorded requisition opening date, normalized from a supported source date or timestamp. It is not the employee or assignment start date.','nullable-calendar-date'],
        'closed_at'=>['Recorded requisition closing date. Missing or blank source values preserve the existing date; reopening does not implicitly clear it.','nullable-calendar-date'],
    ];
}

function staffingJobFieldRules(string $table,string $field,array $rules): array
{
    if($table!=='staffing_jobs'||!isset(staffingJobFieldDefinitions()[$field]))return$rules;
    [$definition,$unit]=staffingJobFieldDefinitions()[$field];
    foreach($rules['remote_contracts']as$provider=>&$remote){
        if(!empty($remote['mappings']))continue;
        $remote=['path'=>null,'direction'=>null,'status'=>'unsupported_with_gap','sync_enabled_by_dictionary'=>false,
            'reason'=>'No requisition-field receiver is implemented for this provider. Assignment publication carries separately reviewed placement fields; PurePayroll work_context.staffing_job_id is retained context, not a job mirror or permission to overwrite assignment values.'];
    }unset($remote);
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_job_directory','owner_directory_module'=>'staffing','ownership_status'=>'source_backed_native_and_inbound_workflow',
        'ownership_evidence'=>[['source'=>'modules/staffing/lib/jobs.php'],['source'=>'modules/staffing/lib/job_source.php'],
            ['source'=>'modules/staffing/lib/job_write.php','route'=>'/modules/staffing/api/jobs.php']],
        'disposition'=>'specialized_workflow',
        'write_policy'=>'revision_checked_native_edit_claims_ownership; observed_source_updates_only_unowned_fields; reviewed_use_once_follow_source_or_keep_core; required_atomic_audit',
        'missing_value_policy'=>'source_missing_null_or_blank_preserves_existing; new_job_title_and_status_defaults_are_not_source_evidence',
        'null_policy'=>'explicit_native_clear_claims_ownership; nullable_references_dates_country_remote_use_null; optional_text_can_retain_empty_string; title_and_status_cannot_clear',
        'conflict_policy'=>'retain_source_proposal; legacy_rows_default_Core_owned; stale_review_refused; no_last_writer_wins',
        'effective_date_policy'=>'current_requisition_context_only; no_rewrite_of_placement_approved_time_rates_or_financial_history',
        'exchange_boundary'=>'Job fields remain separate from placement, employment and provider assignment fields. Provider requisition publication is still an explicit implementation gap.',
    ]);
}
