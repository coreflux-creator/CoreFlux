<?php
/** Native employment compensation has its own dated authority, distinct from placement rates. */
declare(strict_types=1);
function staffingCompensationFieldDefinitions(): array
{
    return [
        'employee_id'=>['Existing employment record in the current People directory. This is not the person number or a provider worker ID.','reference:people_employee'],
        'pay_type'=>['Interpretation of the employment rate: annual salary or amount per hour. Does not replace rates frozen when placement time is approved.','salary|hourly'],
        'pay_rate_cents'=>['Nonnegative whole minor units: annualized salary or per-hour compensation, in the explicitly recorded currency. Native payroll may use separately approved placement earnings for hourly work.','integer-minor-units'],
        'pay_frequency'=>['Native employment pay frequency used to interpret compensation. Does not choose a provider pay schedule or change placement billing cycles.','weekly|biweekly|semimonthly|monthly'],
        'currency'=>['Explicit three-letter employment compensation currency. No missing-value USD assumption and no conversion or provider-currency authorization.','explicit-three-letter-code'],
        'bonus_target_cents'=>['Optional target bonus in the compensation currency. A target is not earned payroll, a commission obligation or a payment instruction.','nullable-nonnegative-integer-minor-units'],
        'effective_from'=>['First inclusive calendar day of this compensation version. New versions append after existing history; earlier corrections need a separate reviewed workflow.','inclusive-calendar-date'],
        'effective_to'=>['Last inclusive day of this compensation version. Null is open-ended. Appending a version closes its open predecessor on the preceding day, in the same audited transaction.','nullable-inclusive-calendar-date'],
        'reason'=>['Optional native compensation-change reason, retained with this version. It does not approve payroll or authorize provider rate changes.','nullable-text-max-80'],
        'notes'=>['Private employment compensation notes. They are not sent to worker portals or payroll providers by this workflow.','nullable-private-text-max-65535-bytes'],
        'created_by'=>['Authenticated Core actor who appended the version; never taken from a client-supplied field or used as independent payroll approval.','reference:core_user'],
    ];
}
function staffingCompensationFieldRules(string $table,string $field,array $rules): array
{
    if($table!=='people_compensation'||!isset(staffingCompensationFieldDefinitions()[$field]))return $rules;
    [$definition,$unit]=staffingCompensationFieldDefinitions()[$field];
    foreach($rules['remote_contracts']as&$remote){
        if(!empty($remote['mappings']))continue;
        $remote=['path'=>null,'direction'=>null,'status'=>'unsupported_with_gap','sync_enabled_by_dictionary'=>false,
            'reason'=>'No dated employment-compensation receiving contract is implemented. Existing placement rates and held payroll earnings are separate records and do not establish compensation-profile sync.'];
    }unset($remote);
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition','owner'=>'core_employment_compensation','owner_directory_module'=>'people',
        'ownership_status'=>'source_backed_native_workflow','disposition'=>'specialized_workflow',
        'ownership_evidence'=>[['source'=>'modules/people/lib/compensation.php'],['source'=>'modules/people/lib/employees.php'],['source'=>'modules/people/api/compensation.php','route'=>'/modules/people/api/compensation.php']],
        'write_policy'=>'People_manage; existing_employee; current_directory_and_history_locks; expected_history_revision; append_and_predecessor_close_and_audit_atomic',
        'missing_value_policy'=>'required_type_rate_frequency_currency_start_date_refused_if_missing; optional_bonus_end_reason_notes_are_null',
        'null_policy'=>'open_end_and_optional_bonus_reason_notes_only; required_compensation_values_cannot_clear',
        'conflict_policy'=>'stale_history_and_changed_retry_refused; exact_actor_and_workspace_request_reuses_original_audit_receipt; overlapping_legacy_history_requires_review',
        'effective_date_policy'=>'inclusive_start_and_end; append_only_new_version; no_rewrite_of_approved_placement_rates_payroll_results_or_financial_history',
        'exchange_boundary'=>'Native employee compensation is distinct from person identity, assignment commercial terms and provider payroll execution. Provider profile exchange remains an explicit implementation gap.',
    ]);
}
