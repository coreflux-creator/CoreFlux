<?php
/** Source-backed exchange inventory. Describes payloads; never authorizes or performs a write. */
declare(strict_types=1);

function staffingExchangeFieldMappings(): array
{
    static $fields;
    if ($fields !== null) return $fields;
    $fields = [];
    $add = static function (string $key, string $provider, string $route, string $path,
        string $transform, string $policy, string $version, array $evidence) use (&$fields): void {
        $fields[$key][$provider][] = [
            'route' => $route, 'path' => $path, 'transform' => $transform,
            'write_policy' => $policy, 'contract_version' => $version,
            'source_evidence' => $evidence,
        ];
    };
    $quantaEvidence = ['core' => 'core/quanta/metadata_delivery.php',
        'provider' => 'Quanta:backend/app/coreflux_contract.py'];
    $quanta = static function (string $key, string $path, string $transform, string $policy = 'immutable_after_create') use ($add, $quantaEvidence): void {
        $add($key, 'quanta', '/api/v1/integrations/coreflux/assignments', $path,
            $transform, $policy, '2026-10-20.1', $quantaEvidence);
    };
    // These are complete typed references, never raw SQL IDs or provider IDs.
    foreach ([
        'placements.placement_number' => ['placement', 'placement'],
        'people.person_number' => ['person', 'person'],
        'placements.person_id' => ['person', 'person'],
        'staffing_clients.client_number' => ['client', 'client'],
        'placements.client_id' => ['client', 'client'],
        'ap_vendors_index.vendor_number' => ['vendor', 'vendor'],
        'placement_corp_details.ap_vendor_id' => ['vendor', 'vendor'],
        'companies.company_number' => ['end_client', 'company'],
        'placements.end_client_company_id' => ['end_client', 'company'],
    ] as $key => [$path, $kind]) {
        $quanta($key, $path, 'resolve_authorized_directory; typed_reference:' . $kind);
    }
    foreach ([
        'placements.title' => ['title', 'printable_text_max_200', 'conditional_versioned_update'],
        'placements.engagement_type' => ['engagement_type', 'w2|1099|c2c; reject_unmapped_enum', 'immutable_after_create'],
        'placements.start_date' => ['start_date', 'required_calendar_date_YYYY-MM-DD', 'immutable_after_create'],
        'placements.end_date' => ['end_date', 'COALESCE(actual_end_date,end_date); nullable_calendar_date', 'conditional_versioned_update'],
        'placements.actual_end_date' => ['end_date', 'COALESCE(actual_end_date,end_date); nullable_calendar_date', 'conditional_versioned_update'],
        'placements.status' => ['status', 'pending_start_to_draft; explicit_lifecycle_transitions; reject_unmapped_enum', 'conditional_versioned_update'],
    ] as $key => [$path, $transform, $policy]) $quanta($key, $path, $transform, $policy);
    $add('people.person_number', 'quanta', '/api/v1/integrations/coreflux/person-links', 'person',
        'resolve_authorized_directory; typed_reference:person; reviewed_existing_worker_required',
        'immutable_link', '2026-10-20.1', $quantaEvidence);

    foreach ([
        'people.person_number'=>['person','resolve_authorized_directory; typed_reference:person'],
        'people.first_name'=>['full_name','join_trimmed_first_and_last_name; printable_text_max_120'],
        'people.last_name'=>['full_name','join_trimmed_first_and_last_name; printable_text_max_120'],
        'people.email_primary'=>['email','lowercase_trimmed_valid_email_max_254; conflict_check_only_never_identity_match'],
        'people.phone_primary'=>['phone','trimmed_printable_text_max_100; empty_preserves_existing_phone'],
    ] as $key=>[$path,$transform]) {
        $add($key,'quanta','/api/v1/integrations/coreflux/worker-profiles',$path,$transform,
            'reviewed_disabled_membership_create_or_conditional_contact_update; provider_ids_preserved; native_activation_separate',
            '2026-10-22.1',['core'=>'core/quanta/worker_delivery.php','provider'=>'Quanta:backend/app/coreflux_workers.py']);
    }

    foreach ([
        'people.person_number' => ['person', 'resolve_authorized_directory; typed_reference:person'],
        'people.first_name' => ['first_name', 'trimmed_printable_text_max_200; at_least_one_name'],
        'people.last_name' => ['last_name', 'trimmed_printable_text_max_200; at_least_one_name'],
        'people.email_primary' => ['email', 'trimmed_email_max_254; empty_to_null'],
        'people.phone_primary' => ['phone', 'trimmed_printable_text_max_40; empty_to_null'],
        'placements.engagement_type' => ['employment_type', 'w2_only; current_placement_must_belong_to_person'],
    ] as $key => [$path, $transform]) {
        $add($key, 'purepayroll', '/api/v1/integrations/coreflux/people/inputs', $path,
            $transform, 'immutable_review_packet; separate_provider_review_creates_inactive_employee; no_activation',
            '2026-10-22.1', ['core' => 'core/integrations/purepay_people_inputs.php', 'provider' => 'PurePay:backend/coreflux_people.py']);
    }
    $inputEvidence = ['core' => 'core/integrations/purepay_payroll_inputs.php',
        'provider' => 'PurePay:backend/coreflux_payroll_inputs.py'];
    foreach ([
        'people.person_number' => ['person', 'typed_reference:person; delivered_employee_link_required'],
        'placements.placement_number' => ['placement', 'typed_reference:placement; delivered_assignment_link_required'],
        'time_entries.id' => ['source.time_entry_id', 'integer; scoped_by_instance_and_time_tenant'],
        'time_entries.work_date' => ['source.work_date', 'calendar_date_YYYY-MM-DD'],
        'time_entries.category' => ['source.category', 'explicit_earning_category; reject_unmapped_category'],
        'time_entries.hours' => ['source.hours', 'positive_exact_decimal_string_scale_2; hours_not_minutes'],
        'time_entries.source_system' => ['source.source_system', 'source_system_fallback_to_source; quanta_provenance_required'],
        'time_entries.source' => ['source.source_system', 'source_system_fallback_to_source; quanta_provenance_required'],
        'time_entries.rate_snapshot_id' => ['rate.snapshot_id', 'resolve_exact_approved_placement_rate_snapshot'],
        'placement_rates.id' => ['rate.snapshot_id', 'integer; approved_time_frozen_snapshot_only'],
        'placement_rates.pay_rate' => ['rate.pay_rate', 'exact_decimal_string_scale_4; never_employee_default'],
        'placement_rates.pay_rate_unit' => ['rate.pay_rate_unit', 'preserve_unit; provider_review_requires_hour'],
        'placement_rates.currency' => ['rate.currency', 'preserve_currency; provider_review_requires_USD'],
        'placement_rates.ot_multiplier' => ['rate.multiplier', 'OT_categories_only; exact_decimal_string_scale_2; regular_categories_use_1.00'],
        'quanta_time_imports.quanta_entry_id' => ['source.source_entry_id', 'persisted_origin; quanta_only'],
        'quanta_time_imports.component' => ['source.source_component', 'persisted_origin; quanta_only'],
        'quanta_time_imports.source_hash' => ['source.source_revision', 'persisted_origin; quanta_only'],
        'quanta_time_imports.source_workspace_id' => ['source.source_workspace_id', 'persisted_origin; never_infer_current_workspace'],
    ] as $key => [$path, $transform]) {
        $add($key, 'purepayroll', '/api/v1/integrations/coreflux/payroll/inputs', 'components[].' . $path,
            $transform, 'immutable_review_packet; no_native_employee_or_assignment_update',
            '2026-10-21.1', $inputEvidence);
    }
    foreach ([
        'people.person_number' => ['person', 'purepayroll'],
        'placements.placement_number' => ['placement', 'purepay'],
        'staffing_clients.client_number' => ['client', 'purepay'],
        'ap_vendors_index.vendor_number' => ['vendor', 'purepay'],
    ] as $key => [$kind, $provider]) {
        $add($key, $provider, '/api/v1/integrations/coreflux/links', 'reference',
            'typed_reference:' . $kind . '; reviewed_existing_provider_record_and_version_required',
            'immutable_link; does_not_provision_native_record', '2026-10-21.1',
            ['core' => 'core/integrations/purepay_links.php', 'provider' => 'PurePay:backend/coreflux_exchange.py']);
    }
    $commercial=static function(string $key,string $path,string $transform)use($add):void {
        $add($key,'purepayroll','/api/v1/integrations/coreflux/assignments/inputs',$path,$transform,
            'immutable_commercial_review_packet; separate_native_create_revise_activate; no_automatic_billing_or_payroll',
            '2026-10-22.2',['core'=>'core/integrations/purepay_assignment_contract.php','provider'=>'PurePay:backend/coreflux_assignments.py']);
    };
    foreach(['placements.placement_number'=>'placement','people.person_number'=>'person','placements.person_id'=>'person',
        'placements.client_id'=>'client','staffing_clients.client_number'=>'client'] as $key=>$path)$commercial($key,$path,'resolve_canonical_number_in_authorized_module_directory; preserve_instance_and_owner');
    $commercial('ap_vendors_index.vendor_number','primary_vendor','c2c_primary_pay_rate_party_only; W2_null; immutable_across_revision');
    foreach(['title'=>'title','engagement_type'=>'employment_type','status'=>'source_status','start_date'=>'start_date',
        'end_date'=>'end_date','actual_end_date'=>'end_date'] as $column=>$path)$commercial('placements.'.$column,$path,
            'w2_or_c2c; pending_start_active_ended; approved_period_coverage_required; end_uses_actual_end_date_when_present');
    foreach(['external_id','jobdiva_job_id','end_client_name','staffing_job_id','accounting_entity_id','due_date','worksite_country',
        'worksite_state','branch','service_line','workers_comp_class','department','cost_center','remote_policy'] as $column)
        $commercial('placements.'.$column,'work_context.'.$column,'preserve_source_value_and_null; no_name_based_identity_guess');
    $commercial('placements.end_client_company_id','work_context.end_client','authorized_typed_company_reference_or_null');
    $commercial('companies.company_number','work_context.end_client','authorized_typed_company_reference_or_null');
    foreach(['billing','ap','payroll'] as $purpose)$commercial('placements.'.$purpose.'_operating_cycle_id',
        'current_commercial_context.'.$purpose.'_cycle_id','scoped_cycle_id; purpose_must_match; current_settings_not_historic_defaults');
    foreach(['pay','bill'] as $kind) {
        $commercial('placement_rates.'.$kind.'_rate','terms[].'.$kind.'_rates.regular','approved_only; exact_decimal_string_scale_4; no_employee_or_catalog_default');
        $commercial('placement_rates.'.$kind.'_rate_unit','terms[].'.$kind.'_rate_unit','preserve_hour_day_week_month_project; no_unit_conversion');
    }
    foreach(['ot'=>'overtime','dt'=>'double_time'] as $source=>$target)$commercial('placement_rates.'.$source.'_multiplier',
        'terms[].economics.'.$target.'_multiplier','exact_decimal_scale_2; derive_pay_and_bill_rate_by_exact_integer_product; null_preserved');
    foreach(['id'=>'rate_snapshot_id','approved_at'=>'approved_at','effective_from'=>'rate_effective_from','effective_to'=>'rate_effective_to'] as $column=>$path)
        $commercial('placement_rates.'.$column,'terms[].economics.'.$path,'approved_source_only; retain_original_rate_period; clip_term_coverage_to_placement; approved_at_source_timezone_unspecified');
    $commercial('placement_rates.currency','terms[].currency','USD_only; reject_other_currency');
    foreach(['bill_adder_pct'=>'bill_adder_fraction','bill_adder_flat'=>'bill_adder_amount','bill_discount_pct'=>'bill_discount_fraction',
        'bill_discount_flat'=>'bill_discount_amount','adder_pct'=>'employer_load_override','workers_comp_pct'=>'workers_comp_override',
        'benefits_load_pct'=>'benefits_load_override','c2c_overhead_pct'=>'c2c_overhead_override','other_cost_per_hour'=>'other_cost_per_hour',
        'other_cost_flat'=>'other_cost_fixed','background_fee_total'=>'background_fee_total'] as $source=>$target)
        $commercial('placement_rates.'.$source,'terms[].economics.adjustments.'.$target,'exact_decimal_string; stored_fraction_not_percent_points; null_inherits_zero_overrides');
    $commercial('placement_rates.economics_snapshot_json','terms[].economics',
        'extract_frozen_contract_parties_resolved_overheads_and_modeled_totals; preserve_JSON_decimal_lexemes; historic_cycle_anchor_and_override_intent_not_retained; no_recalculation_from_current_defaults');
    foreach(['id'=>'id','source_ref'=>'source_ref','source_type'=>'source_type','role'=>'role','display_name'=>'display_name',
        'user_id'=>'source_user_id','money_flow'=>'money_flow','settlement_channel'=>'settlement_channel','fee_basis'=>'fee_basis',
        'fee_pct'=>'fee_fraction','fee_flat'=>'fee_amount','payment_terms'=>'payment_terms','pwp_enabled'=>'pwp_enabled',
        'payment_terms_overridden'=>'payment_terms_overridden','pwp_overridden'=>'pwp_overridden','cycle_overridden'=>'cycle_overridden',
        'effective_from'=>'effective_from','effective_to'=>'effective_to','active'=>'active'] as $source=>$target)
        $commercial('placement_economic_parties.'.$source,'current_commercial_context.parties[].'.$target,
            'current_configuration_only; explicit_fee_basis; nullable_exact_decimals; resolve_terms_and_PWP_using_current_vendor_defaults');
    foreach(['person_id'=>'person','company_id'=>'company','ap_vendor_id'=>'vendor'] as $source=>$target)
        $commercial('placement_economic_parties.'.$source,'current_commercial_context.parties[].roots.'.$target,'authorized_canonical_typed_reference; no_merged_or_foreign_directory_records');
    $commercial('placement_economic_parties.operating_cycle_id','current_commercial_context.parties[].cycle','resolve_current_cycle_id_name_purpose_cadence; preserve_historic_cycle_separately');
    foreach(['id','name','purpose','cadence','anchor_date','settlement_offset_days','default_payment_terms','payroll_pay_cycle_id','active','source_system','external_id'] as $column)
        $commercial('staffing_operating_cycles.'.$column,'current_commercial_context.cycles[].'.$column,'only_cycles_referenced_by_current_placement_or_parties; scoped_to_placement_owner');
    foreach(['id','economic_party_id','description','settlement_channel','direction','amount','currency','apply_on','taxable','status','source_system','source_external_id'] as $column)
        $commercial('placement_economic_items.'.$column,'current_commercial_context.one_time_items[].'.$column,'preserve_active_and_void_configuration; exact_decimal_amount; matching_party_channel; never_posting_or_payment_evidence');
    return $fields;
}

function staffingExchangeRemoteContracts(string $key, array $contracts): array
{
    foreach (staffingExchangeFieldMappings()[$key] ?? [] as $provider => $mappings) {
        $contracts[$provider] = [
            'path' => count($mappings) === 1 ? $mappings[0]['path'] : null,
            'direction' => 'core_to_' . $provider,
            'status' => 'source_verified_hosted_acceptance_pending',
            'mappings' => $mappings,
            'sync_enabled_by_dictionary' => false,
        ];
    }
    return $contracts;
}

function staffingExchangeWorkflowGaps(): array
{
    return [
        'native_payroll_employee_provisioning' => 'Reviewed W-2 contact snapshots can create inactive profiles in candidate source; hosted acceptance, payroll setup, activation and complete onboarding remain open.',
        'native_commercial_assignment_updates' => 'Candidate source supports full approved and current graphs, reviewed draft creation, same-party revisions and separate activation. Core-managed assignments are excluded from native automatic billing. Hosted acceptance and changes to the primary parties remain open.',
        'quanta_worker_provisioning' => 'Candidate source supports reviewed disabled worker preparation and conditional contacts with receipt recovery. Native identity attachment, activation and invitation are separate; provider publication and hosted acceptance remain open.',
        'portal_access_and_onboarding' => 'Assignment metadata does not grant staff access or complete onboarding.',
        'tax_bank_and_identity_documents' => 'Restricted; require dedicated permissioned workflows.',
        'payroll_settlement_and_liability_attribution' => 'Input receipt is not payroll execution, settlement proof or complete liability attribution.',
        'jobdiva_field_alignment' => 'Connector mappings need separate source-field verification; no inferred mapping from names or numbers.',
    ];
}
