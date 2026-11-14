<?php
/** Native Core calculation records are not the PurePayroll provider's run records. */
declare(strict_types=1);

function staffingNativePayrollFieldDefinitions(): array
{
    return [
        'payroll_runs'=>[
            'artifact_id'=>['Core review artifact representing this run; not a provider run or a payroll payment.','reference:core_artifact'],
            'pay_period_id'=>['Core pay period supplying schedule, work dates and pay date; the dates are not stored in this field.','reference:payroll_pay_period'],
            'run_type'=>['Purpose of this native run. A correction label alone neither reverses another run nor changes its source claims.','regular|off_cycle|correction|final'],
            'status'=>['Native calculation/review/payment lifecycle. Provider observations do not overwrite this status. A paid label alone is not bank settlement evidence.','draft|computed|approved|paid|voided'],
            'employee_count'=>['Count of saved employee calculation lines, validated with the exact calculation fingerprint.','count'],
            'gross_total_cents'=>['Sum of native employee gross amounts. Current computation requires explicit USD source currency; this does not establish currency for imported historical provider runs.','USD-cents'],
            'taxes_total_cents'=>['Sum of calculated employee taxes; employer taxes are retained separately. These are native calculations, not provider tax-filing receipts.','USD-cents'],
            'deductions_total_cents'=>['Sum of calculated pretax and posttax deductions; not a remittance confirmation.','USD-cents'],
            'net_total_cents'=>['Sum of calculated employee net amounts; distinct from cash delivered.','USD-cents'],
            'employer_taxes_cents'=>['Sum of calculated employer taxes; distinct from employee withholding and tax remittances.','USD-cents'],
            'journal_entry_id'=>['Native payroll accrual journal link; provider result review does not create this journal.','nullable-reference:accounting_journal'],
            'cash_journal_entry_id'=>['Native payroll cash journal link maintained by its accounting workflow; not an external bank receipt.','nullable-reference:accounting_journal'],
            'computed_at'=>['Time the current native calculation was saved; not the work date, approval date or pay date.','nullable-timestamp'],
            'computed_by_user_id'=>['Authenticated preparer of the current calculation; excluded from independent review of that calculation.','nullable-reference:user'],
            'approved_at'=>['Time Core accepted the native calculation review; independent from provider approval.','nullable-timestamp'],
            'approved_by'=>['Core reviewer recorded for the native payroll decision.','nullable-reference:user'],
            'workflow_instance_id'=>['Core approval workflow bound to this exact run, computation revision and fingerprint.','nullable-reference:workflow'],
            'paid_at'=>['Time Core marked its run paid; does not independently prove provider or bank settlement.','nullable-timestamp'],
            'paid_by_user_id'=>['Actor recorded by the native mark-paid workflow.','nullable-reference:user'],
            'notes'=>['Private native payroll operator notes; not copied to the worker portal or a provider.','private-text'],
            'gusto_run_id'=>['Retained Gusto run reference from the separate Gusto workflow; never a PurePayroll run ID.','nullable-source-reference:gusto'],
            'gusto_payroll_uuid'=>['Gusto API payroll UUID retained by the Gusto submission adapter.','nullable-source-reference:gusto'],
            'gusto_submission_status'=>['State of the separate Gusto submission attempt; not the PurePayroll reservation state.','nullable-provider-status'],
            'gusto_submitted_at'=>['Time the Gusto submission workflow recorded submission.','nullable-timestamp'],
            'gusto_submitted_by_user_id'=>['Core actor recorded for the Gusto submission.','nullable-reference:user'],
            'gusto_submission_error'=>['Private Gusto submission diagnostic; no automatic transfer to another provider.','private-text'],
            'gusto_payroll_url'=>['Link recorded for the Gusto payroll; never used as Core or PurePayroll identity.','nullable-source-url'],
            'gusto_status'=>['Retained Gusto lifecycle status; its native sync/mark workflow is separate from held PurePayroll result review.','nullable-provider-status'],
            'gusto_synced_at'=>['Time the Gusto workflow recorded its latest synchronization.','nullable-timestamp'],
            'gusto_synced_by'=>['Actor recorded for the Gusto synchronization.','nullable-reference:user'],
            'gusto_paid_at'=>['Paid timestamp recorded by the Gusto workflow, retaining that source and not asserting a PurePayroll payment.','nullable-timestamp'],
            'disbursement_rail'=>['Native payment-rail choice. The field does not grant payment execution authority.','nullable-rail-selector'],
            'rail_external_ref'=>['Original reference from the native payroll disbursement rail; not a provider assignment or held payroll authority.','nullable-source-reference:payment_rail'],
            'rail_status'=>['Native rail status retained separately from calculation and provider result status.','nullable-provider-status'],
            'rail_originated_at'=>['Time the native rail origination workflow recorded its request; not proof of cleared cash.','nullable-timestamp'],
            'computation_revision'=>['Monotonic native calculation revision used to refuse stale recomputation and approval.','nonnegative-integer'],
            'computation_sha256'=>['Fingerprint of the saved run, employee lines and earnings/tax/deduction components. It is not a provider result hash.','nullable-sha256'],
            'approved_computation_revision'=>['Exact native revision accepted by review; later revisions cannot inherit this approval.','nullable-integer'],
            'approved_computation_sha256'=>['Exact calculation fingerprint accepted by review and checked before accounting.','nullable-sha256'],
        ],
        'payroll_line_items'=>[
            'run_id'=>['Owning native Core payroll run in the same operating company.','reference:payroll_run'],
            'employee_id'=>['Employment record resolved in the authorized People directory; not the canonical person number or a provider employee ID.','reference:people_employee'],
            'work_state'=>['State used by native calculation from the payroll profile; not inferred from person residence or a staffing assignment label.','state-code'],
            'pay_type'=>['Native calculation pay basis, retained from compensation context.','salary|hourly'],
            'pay_rate_cents'=>['Annual salary cents or hourly compensation cents. For approved_time earnings it is zero because component source rates are authoritative; zero must not be exported as the worker rate.','USD-cents; basis-dependent'],
            'pay_frequency'=>['Native compensation frequency used for salary proration and tax calculation; distinct from each source timesheet calendar.','weekly|biweekly|semimonthly|monthly'],
            'hours_regular'=>['Native regular-hour rollup. Approved source categories and their original rates remain in the earning components.','decimal-hours'],
            'hours_overtime'=>['Native nonregular-hour rollup includes approved overtime and double time. Component codes preserve that distinction; this column alone cannot support an exact provider time export.','decimal-hours'],
            'gross_cents'=>['Saved native gross from the earning components, including separately modeled extras.','USD-cents'],
            'pretax_cents'=>['Calculated pretax deduction total; no remittance is implied.','USD-cents'],
            'taxable_cents'=>['Native taxable wage calculation; not an external tax-provider certification.','USD-cents'],
            'employee_taxes_cents'=>['Native employee withholding total, separate from employer taxes.','USD-cents'],
            'posttax_cents'=>['Native posttax deduction total.','USD-cents'],
            'net_cents'=>['Calculated employee net amount; distinct from paid or cleared cash.','USD-cents'],
            'employer_taxes_cents'=>['Native employer tax total; not deducted from employee net.','USD-cents'],
            'payment_method'=>['Saved native payment preference. Does not establish a valid bank account or execute payment.','direct_deposit|check'],
            'status'=>['Native line lifecycle managed with its run; does not inherit provider draft or payment status.','computed|approved|paid|voided'],
            'notes'=>['Private native line notes, excluded from general provider exchange.','private-text'],
            'earnings_basis'=>['Selects compensation-based calculation or the retained approved-time component amounts.','compensation|approved_time'],
            'source_time_json'=>['Private original approved-time facts, identities, source/rate fingerprints and component evidence; never a generic replicated JSON field.','private-source-snapshot'],
            'source_time_sha256'=>['Fingerprint of retained approved-time evidence; does not replace the original source or the whole-calculation fingerprint.','nullable-sha256'],
        ],
    ];
}

function staffingNativePayrollFieldRules(string $table,string $field,array $rules): array
{
    $definitions=staffingNativePayrollFieldDefinitions();
    if(!isset($definitions[$table][$field]))return $rules;
    [$definition,$unit]=$definitions[$table][$field];
    $restricted=str_starts_with($unit,'private-')||$rules['disposition']==='restricted';
    foreach($rules['remote_contracts'] as $provider=>&$remote){
        // Preserve a future concrete adapter rather than silently erasing it.
        if(!empty($remote['mappings']))continue;
        $remote=['path'=>null,'direction'=>null,'status'=>$restricted?'restricted_not_exchanged':'core_internal_only',
            'sync_enabled_by_dictionary'=>false,
            'reason'=>'Native Core calculation state is retained locally. PurePayroll receives reviewed original time and reservations; its returned results are separate encrypted payroll_provider_results observations. No native-run mirroring is implemented.',
            'source_evidence'=>['native'=>'modules/payroll/lib/run_compute.php','result'=>'core/integrations/purepay_payroll_results.php']];
    }
    unset($remote);
    $policy='native_calculation_and_exact_review_workflow_only; preserve_source_components; no_generic_provider_overwrite';
    if(str_starts_with($field,'gusto_'))$policy='separate_Gusto_adapter_or_authorized_native_Gusto_review; preserve_original_provider_identity';
    elseif(str_starts_with($field,'rail_')||$field==='disbursement_rail')$policy='dedicated_authorized_native_disbursement_workflow; no_execution_from_field_alignment';
    elseif(in_array($field,['journal_entry_id','cash_journal_entry_id'],true))$policy='native_accounting_workflow_only; provider_observation_does_not_post';
    return array_replace($rules,[
        'definition'=>$definition,'unit'=>$unit,'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_native_payroll','owner_directory_module'=>'payroll','ownership_status'=>'source_backed_native_workflow',
        'ownership_evidence'=>[
            ['source'=>'modules/payroll/lib/run_compute.php','route'=>'/modules/payroll/api/runs.php'],
            ['source'=>'modules/payroll/lib/calculation_review.php'],
            ['source'=>'modules/payroll/lib/source_earnings.php'],
            ['source'=>'modules/payroll/lib/accounting_posting.php'],
            ['source'=>'core/gusto_track_b.php'],
        ],
        'disposition'=>$restricted?'restricted':(str_starts_with($field,'gusto_')&&in_array($field,['gusto_run_id','gusto_payroll_uuid'],true)?'source_reference':'specialized_workflow'),
        'write_policy'=>$policy,'effective_date_policy'=>'retain_original_work_period_pay_date_source_rates_and_calculation_review; no_retroactive_provider_overwrite',
        'exchange_boundary'=>'Native calculations, provider results, accounting postings and cleared cash are separate facts. This metadata does not certify tax accuracy or enable execution.',
    ]);
}
