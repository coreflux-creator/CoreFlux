<?php
/** Metadata-only inventory for the Thunderhawk operating pilot. Does not enable sync. */
declare(strict_types=1);

require_once __DIR__ . '/../record_numbers.php';

function staffingFieldContractTables(): array
{
    return [
        'people', 'companies', 'company_roles', 'company_addresses', 'company_contacts',
        'staffing_clients', 'ap_vendors_index', 'staffing_jobs', 'placements',
        'placement_rates', 'placement_client_chain', 'placement_commissions', 'placement_referrals',
        'placement_corp_details', 'placement_economic_parties', 'placement_economic_obligations',
        'staffing_operating_cycles', 'people_documents', 'placement_documents',
        'people_banking', 'people_tax', 'people_employees', 'people_employment_history',
        'people_compensation', 'people_i9', 'people_tax_federal', 'people_tax_state', 'people_bank_accounts',
        'time_entries', 'time_periods', 'time_downstream_feed', 'staffing_timesheets',
        'payroll_runs', 'payroll_line_items', 'ap_bills', 'ap_payments', 'billing_invoices',
        'billing_payments', 'external_entity_mappings',
    ];
}

function staffingFieldContractRules(string $table, string $field): array
{
    $key = $table . '.' . $field;
    $definitions = [
        'placements.person_id' => ['Canonical person assigned to this placement.', 'reference:person'],
        'placements.end_client_company_id' => ['Company receiving the work; distinct from the bill-to client.', 'reference:company'],
        'placements.client_id' => ['Staffing client billing profile.', 'reference:client'],
        'staffing_clients.company_id' => ['Canonical company underlying the client role.', 'reference:company'],
        'ap_vendors_index.company_id' => ['Canonical business company; nullable for individual vendors.', 'reference:company'],
        'placement_economic_parties.person_id' => ['Person linked to an individual economic party.', 'reference:person'],
        'placement_economic_parties.ap_vendor_id' => ['AP vendor used to settle this economic party.', 'reference:vendor'],
        'placement_rates.bill_rate' => ['Client charge in currency per bill_rate_unit; effective dated.', 'currency/bill_rate_unit'],
        'placement_rates.pay_rate' => ['Worker pay in currency per pay_rate_unit; effective dated.', 'currency/pay_rate_unit'],
        'placement_rates.currency' => ['Currency of this rate agreement.', 'ISO-4217'],
        'placements.worksite_country' => ['Country where the assignment is performed.', 'ISO-3166-1-alpha-2'],
        'time_entries.hours' => ['Decimal hours for the work date and category, not minutes.', 'hours'],
        'time_entries.status' => ['Core review status; source approval does not itself approve Core time.', 'enum'],
        'people_employees.employee_number' => ['Legacy employment number; separate from the canonical person number.', 'source_reference'],
    ];
    $restricted = preg_match('/(?:ssn|tax_id|bank|routing|account_number|password|credential|token|secret|dob|birth|_ct$)/i', $field)
        || in_array($table, ['people_banking', 'people_tax', 'people_bank_accounts', 'people_tax_federal', 'people_tax_state', 'people_i9'], true);
    $system = in_array($field, ['id', 'tenant_id', 'created_at', 'updated_at', 'deleted_at', 'created_by_user_id', 'updated_by_user_id'], true);
    $source = in_array($field, ['external_id', 'source_system', 'source_ref_id', 'jobdiva_job_id', 'connecteam_user_id'], true);
    $approval = preg_match('/(?:approved|approval|superseded|posted|paid|locked|correction)/i', $field);
    [$definition, $unit] = $definitions[$key] ?? [null, null];
    $remoteContracts = array_fill_keys(['jobdiva', 'quanta', 'purepay', 'purepayroll'], [
        'path' => null, 'direction' => null, 'status' => 'unverified', 'sync_enabled_by_dictionary' => false,
    ]);
    $quantaFields = [
        'people.first_name' => ['workers.full_name', 'join_with_last_name; reviewed_name_change'],
        'people.last_name' => ['workers.full_name', 'join_with_first_name; reviewed_name_change'],
        'people.email_primary' => ['workers.email', 'create_only; existing_email_requires_identity_review'],
        'people.phone_primary' => ['workers.phone', 'reviewed_change; missing_preserves_remote'],
    ];
    if (isset($quantaFields[$key])) {
        [$path, $transform] = $quantaFields[$key];
        $remoteContracts['quanta'] = ['path' => $path, 'direction' => 'core_to_quanta_preview',
            'status' => 'schema_verified_delivery_not_enabled', 'transform' => $transform,
            'observed_version' => '1.1.0', 'evidence_url' => 'https://helloquanta.app/api/v1/openapi.json',
            'write_scope' => 'write:workers', 'sync_enabled_by_dictionary' => false];
    }
    return [
        'definition' => $definition,
        'definition_status' => $definition !== null ? 'reviewed_core_definition' : 'review_required',
        'unit' => $unit,
        'owner' => $system ? 'core' : ($source ? 'source_connector' : 'review_required'),
        'disposition' => $restricted ? 'restricted' : ($system ? 'system_owned' : ($source ? 'source_reference' : 'review_required')),
        'write_policy' => ($restricted || $system || $source || $approval) ? 'specialized_workflow_only' : 'verified_mapping_and_owner_required',
        'missing_value_policy' => 'preserve_existing',
        'null_policy' => 'explicit_clear_requires_field_permission_and_nullable_destination',
        'conflict_policy' => 'queue_for_review; no last-writer-wins',
        'effective_date_policy' => str_starts_with($table, 'placement_') || $table === 'people_compensation'
            ? 'preserve_effective_dates_and_approved_history' : 'review_required',
        'remote_contracts' => $remoteContracts,
    ];
}

/** All columns of the allowlisted tables, including newly added fields, are accounted for. No record values. */
function staffingFieldContractInventory(PDO $pdo, int $tenantId): array
{
    if ($tenantId <= 0) throw new InvalidArgumentException('Tenant required');
    $tables = staffingFieldContractTables();
    $stmt = $pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
        FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN (' . implode(',', array_fill(0, count($tables), '?')) . ')
        ORDER BY TABLE_NAME, ORDINAL_POSITION');
    $stmt->execute($tables);
    $fields = [];
    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $table = $column['TABLE_NAME'];
        $field = $column['COLUMN_NAME'];
        $found[$table] = true;
        $fields[] = array_merge([
            'key' => $table . '.' . $field, 'core_path' => $table . '.' . $field,
            'type' => $column['COLUMN_TYPE'], 'nullable' => $column['IS_NULLABLE'] === 'YES',
            'core_schema_status' => 'verified_present', 'kind' => 'standard',
        ], staffingFieldContractRules($table, $field));
    }
    foreach (coreRecordNumberDefinitions() as $type => $definition) {
        $fields[] = array_merge(staffingFieldContractRules('core_record_numbers', 'id'), [
            'key' => $definition['table'] . '.' . $definition['field'],
            'core_path' => 'core_record_numbers.record_number',
            'entity_type' => $type, 'kind' => 'business_reference', 'type' => 'string', 'nullable' => false,
            'definition' => 'Immutable ' . $type . ' reference owned by the record directory tenant.',
            'definition_status' => 'reviewed_core_definition', 'prefix' => $definition['prefix'],
            'core_schema_status' => 'requires_record_number_migrations',
            'write_policy' => 'assigned_by_core; imports_may_lookup_but_never_assign',
        ]);
    }
    return [
        'contract_version' => '2026-10-20.2', 'tenant_id' => $tenantId,
        'status' => 'inventory_for_mapping_review', 'activates_sync' => false,
        'identity_scope' => 'Core instance + directory owner tenant + entity type + immutable number',
        'source_identity_scope' => 'provider + connection/workspace + entity type + external ID',
        'unverified_tables' => array_values(array_diff($tables, array_keys($found))),
        'fields' => $fields,
        'custom_fields_status' => 'not_loaded',
        'provider_coverage_status' => 'remote schemas and configured mappings require separate verification',
    ];
}

/** Read only definition metadata, including on installs with the legacy custom-field registry. */
function staffingFieldContractCustomDefinitions(PDO $pdo, int $owner, string $entity): array
{
    $table = $entity === 'people' ? 'people_custom_field_defs' : 'custom_fields';
    if ($owner <= 0 || !in_array($entity, ['people', 'placements', 'companies'], true)) {
        throw new InvalidArgumentException('Invalid custom field owner/entity');
    }
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$columns) throw new RuntimeException('Custom field definitions unavailable');
    $choices = [
        'id' => ['id'], 'field_key' => ['field_key', 'field_name', 'label'],
        'field_label' => ['field_label', 'label'], 'field_type' => ['field_type', 'type'],
        'required' => ['required', 'is_required'], 'pii' => ['pii'],
        'visible_to_roles_json' => ['visible_to_roles_json'], 'options_json' => ['options_json', 'options'],
    ];
    $select = [];
    foreach ($choices as $alias => $candidates) {
        $available = array_values(array_intersect($candidates, $columns));
        $select[] = ($available ? '`' . $available[0] . '`' : 'NULL') . ' AS `' . $alias . '`';
    }
    $where = ['tenant_id = :tenant'];
    $params = ['tenant' => $owner];
    if ($table === 'custom_fields') { $where[] = 'module = :module'; $params['module'] = $entity; }
    if (in_array('deleted_at', $columns, true)) $where[] = 'deleted_at IS NULL';
    if (in_array('is_active', $columns, true)) $where[] = 'is_active = 1';
    $stmt = $pdo->prepare('SELECT ' . implode(',', $select) . ' FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY id');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Custom field definitions follow existing per-field visibility and module ownership rules. */
function staffingFieldContractWithCustomFields(array $contract, array $user): array
{
    require_once __DIR__ . '/../custom_fields.php';
    require_once __DIR__ . '/../sub_tenants.php';
    $incomplete = [];
    foreach (['people' => 'people', 'placements' => 'placements', 'companies' => 'people'] as $entity => $module) {
        $owner = effectiveTenantIdForModule($module, $contract['tenant_id']) ?? $contract['tenant_id'];
        try {
            $definitions = staffingFieldContractCustomDefinitions(getDB(), $owner, $entity);
        } catch (Throwable $e) {
            error_log('[staffing_field_contract] custom schema unavailable: ' . $entity);
            $incomplete[] = $entity;
            continue;
        }
        foreach ($definitions as $definition) {
            if (!customFieldUserCanViewDefinition($user, $definition)) continue;
            $contract['fields'][] = array_merge(staffingFieldContractRules($entity, 'custom'), [
                'key' => $entity . '.custom.' . $definition['field_key'],
                'core_path' => $entity . '.custom.' . $definition['field_key'],
                'kind' => 'custom', 'definition_id' => $definition['id'], 'owner_tenant_id' => $owner,
                'label' => $definition['field_label'], 'type' => $definition['field_type'],
                'options' => json_decode((string) ($definition['options_json'] ?? ''), true),
                'nullable' => empty($definition['required']), 'core_schema_status' => 'verified_present',
                'disposition' => !empty($definition['pii']) ? 'restricted' : 'review_required',
                'write_policy' => !empty($definition['pii']) ? 'specialized_workflow_only' : 'verified_mapping_and_owner_required',
            ]);
        }
    }
    $contract['custom_fields_status'] = $incomplete ? 'partial' : 'visible_definitions_included';
    $contract['unverified_custom_entities'] = $incomplete;
    return $contract;
}
