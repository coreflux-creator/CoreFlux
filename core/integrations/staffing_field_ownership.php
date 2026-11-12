<?php
/** Ownership of fields with implemented adapters. This inventory grants no write permission. */
declare(strict_types=1);

function staffingFieldExchangeOwnership(string $table, array $remoteContracts): ?array
{
    $owners = [
        'people' => ['core_people_directory', 'people'],
        'companies' => ['core_company_directory', 'companies'],
        'staffing_clients' => ['core_client_directory', 'placements'],
        'ap_vendors_index' => ['core_vendor_directory', 'ap'],
        'placements' => ['core_placement_directory', 'placements'],
        'placement_corp_details' => ['core_placement_contract', 'placements'],
        'placement_rates' => ['core_placement_contract', 'placements'],
        'placement_economic_parties' => ['core_placement_contract', 'placements'],
        'placement_economic_items' => ['core_placement_contract', 'placements'],
        'staffing_operating_cycles' => ['core_placement_contract', 'placements'],
        'time_entries' => ['core_time_review', 'time'],
        'quanta_time_imports' => ['quanta_source_evidence', 'time'],
    ];
    if (!isset($owners[$table])) return null;
    $evidence = [];
    foreach ($remoteContracts as $provider => $contract) {
        foreach ($contract['mappings'] ?? [] as $mapping) {
            $source = $mapping['source_evidence']['core'] ?? null;
            if (!is_string($source) || $source === '' || empty($mapping['direction']) || empty($mapping['route'])
                || empty($mapping['path']) || empty($mapping['write_policy'])) continue;
            $evidence[] = ['provider' => $provider, 'source' => $source,
                'direction' => $mapping['direction'], 'route' => $mapping['route'],
                'path' => $mapping['path'], 'write_policy' => $mapping['write_policy']];
        }
    }
    if (!$evidence) return null;
    return ['owner' => $owners[$table][0], 'owner_directory_module' => $owners[$table][1],
        'ownership_status' => 'source_backed_adapter_ownership', 'ownership_evidence' => $evidence,
        'ownership_policy' => $table === 'quanta_time_imports'
            ? 'retain_original_provider_evidence; corrections_are_new_reviewed_revisions'
            : 'Core_owns_the_accepted_record; inbound_changes_follow_the_adapter_review_and_conflict_policy',
        'disposition' => 'specialized_workflow',
        'write_policy' => 'reviewed_adapter_or_native_owner_workflow; dictionary_grants_no_permission'];
}

/** Approval setup is Core configuration, separate from provider approval actors. */
function staffingPlacementControlFieldRules(string $table, string $field, array $rules): array
{
    $definitions = [
        'coreflux_overridden_fields' => ['Core-owned placement fields retained during JobDiva refresh. Native edits merge ownership; explicit reviewed handback releases it. Approval contact name and email are one choice.', 'json-list:field-name'],
        'client_approver_name' => ['Client time-approval contact display name. A name does not establish a Core user, Quanta approver membership, or evidence that time was approved.', 'nullable-printable-text-max-200'],
        'client_approver_email' => ['Client time-approval contact address. An explicit Core clear remains owned; changing the contact does not grant portal access or approve existing time.', 'nullable-email-max-255'],
        'tokenized_email_approval_enabled' => ['Placement setting allowing the separate secure-email approval workflow. This boolean contains no token and is not an approval, invitation or provider permission.', 'boolean'],
        'bulk_uploads_can_be_pre_approved' => ['Placement setting consulted by the separately authorized time import workflow. Saving the setting changes no time entry or existing approval.', 'boolean'],
    ];
    if ($table !== 'placements' || !isset($definitions[$field])) return $rules;
    [$definition, $unit] = $definitions[$field];
    foreach ($rules['remote_contracts'] as &$contract) {
        if (empty($contract['mappings'])) $contract = ['path'=>null, 'direction'=>null,
            'status'=>'core_internal_only', 'sync_enabled_by_dictionary'=>false];
    }
    unset($contract);
    return array_replace($rules, [
        'definition'=>$definition, 'unit'=>$unit, 'definition_status'=>'reviewed_core_definition',
        'owner'=>'core_placement_approval_setup', 'owner_directory_module'=>'placements',
        'ownership_status'=>'source_backed_native_workflow',
        'ownership_evidence'=>[['source'=>'modules/placements/lib/edit.php', 'route'=>'/modules/placements/api/approval_contact.php'],
            ['source'=>'modules/placements/lib/ownership.php'], ['source'=>'core/jobdiva/sync.php']],
        'disposition'=>$field==='coreflux_overridden_fields' ? 'system_owned' : 'specialized_workflow',
        'write_policy'=>$field==='coreflux_overridden_fields'
            ? 'native_edit_or_reviewed_handback_only; never_accept_caller_supplied_ownership'
            : 'authorized_native_setup; atomic_contact_ownership_and_audit; no_time_approval_or_provider_permission',
        'effective_date_policy'=>'current_setup_only; preserve_original_time_approvals',
        'exchange_boundary'=>'Core approval setup is not copied into provider access controls. JobDiva contact suggestions remain source-owned until native review; a native choice protects both name and email.',
    ]);
}
