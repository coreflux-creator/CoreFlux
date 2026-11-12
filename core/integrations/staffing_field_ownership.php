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
