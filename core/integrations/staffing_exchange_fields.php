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
        'native_payroll_employee_provisioning' => 'Not implemented by identity links or input packets; requires a reviewed onboarding workflow.',
        'native_commercial_assignment_updates' => 'Not implemented by metadata exchange; provider assignments can create bills and change terms.',
        'quanta_worker_provisioning' => 'Shared person links require an existing reviewed worker; they do not create or invite one.',
        'portal_access_and_onboarding' => 'Assignment metadata does not grant staff access or complete onboarding.',
        'tax_bank_and_identity_documents' => 'Restricted; require dedicated permissioned workflows.',
        'payroll_settlement_and_liability_attribution' => 'Input receipt is not payroll execution, settlement proof or complete liability attribution.',
        'jobdiva_field_alignment' => 'Connector mappings need separate source-field verification; no inferred mapping from names or numbers.',
    ];
}
